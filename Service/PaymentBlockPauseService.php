<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Pausa os envios de um número que a 360dialog bloqueou por falta de pagamento
 * (HTTP 403 "This number is blocked due to lack of payment on client side").
 *
 * Antes, o plugin seguia disparando e cada mensagem virava falha — 21 mil falhas em 40 dias num
 * único bloqueio. Agora:
 *
 *   1. O primeiro 403 de pagamento grava a pausa no cache, com TTL = intervalo de nova tentativa
 *      (configurável, padrão 30 min), e avisa os destinatários do alerta de saldo.
 *   2. Enquanto a pausa existe, o handler não chama a API: a mensagem volta para a fila com
 *      atraso (DelayStamp) e o log continua "queued" — nada vira falha.
 *   3. Quando o TTL vence, a próxima mensagem sai normalmente e serve de envio de teste. Se der
 *      403 de novo, volta para a fila e a pausa recomeça; se passar, o número é liberado e o
 *      aviso de liberação é enviado.
 *   4. A sincronização de saldo (dialoghsm:balance:sync) com saldo positivo encerra a pausa na
 *      hora, para o envio de teste não esperar o TTL. Saldo positivo não prova o desbloqueio
 *      (o bloqueio pode ser de cobrança, não de saldo), por isso quem libera de fato é o envio.
 *
 * O bloqueio é por conta/número, então a chave é o hash da apiKey — o mesmo critério do cache de
 * templates. O cache (cache.app) é compartilhado pelos workers do mesmo servidor; um cache:clear
 * apaga a pausa, e o pior caso é um envio de teste antes da hora.
 */
class PaymentBlockPauseService
{
    private const PAUSE_PREFIX = 'dialoghsm_payment_pause_';
    private const STATE_PREFIX = 'dialoghsm_payment_block_';

    // O estado "bloqueado" vive além da pausa: é ele que diz, no envio que passar, que o número
    // estava bloqueado e precisa do aviso de liberação.
    private const STATE_TTL = 30 * 86400;

    /**
     * Intervalo entre consultas ao estado "bloqueado" após um envio bem-sucedido, por número e
     * por processo: sem isto, cada mensagem enviada leria o cache mais uma vez.
     */
    private const SUCCESS_CHECK_INTERVAL = 60;

    private const CONFIG_CACHE_SECONDS = 30;

    private ?bool $cachedEnabled   = null;
    private int $cachedMinutes     = 0;
    private int $configExpiresAt   = 0;

    /** @var array<string, int> hash → timestamp da última consulta sem bloqueio */
    private array $lastSuccessCheck = [];

    public function __construct(
        private CacheItemPoolInterface $cache,
        private PartnerConfigProvider $configProvider,
        private BalanceAlertService $alertService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * O resultado de DialogHSMApi::sendMessage() é o bloqueio por falta de pagamento?
     *
     * @param array<string, mixed> $result
     */
    public static function isPaymentBlock(array $result): bool
    {
        if (403 !== ($result['http_status'] ?? null)) {
            return false;
        }

        $text = (string) ($result['error'] ?? '').' '.json_encode($result['response'] ?? null);

        return false !== stripos($text, 'lack of payment');
    }

    public function isEnabled(): bool
    {
        $this->refreshConfig();

        return (bool) $this->cachedEnabled;
    }

    public function getRecheckSeconds(): int
    {
        $this->refreshConfig();

        return $this->cachedMinutes * 60;
    }

    public function isPaused(string $apiKey): bool
    {
        if ('' === $apiKey || !$this->isEnabled()) {
            return false;
        }

        try {
            return $this->cache->getItem(self::PAUSE_PREFIX.$this->hash($apiKey))->isHit();
        } catch (\Throwable $e) {
            // Cache indisponível: sem pausa. O pior caso é o comportamento anterior (falha).
            $this->logger->warning('DialogHSM: falha ao ler a pausa por pagamento', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Pausa o número por mais um intervalo. Só a primeira pausa de um bloqueio gera aviso.
     */
    public function pause(string $apiKey, ?WhatsAppNumber $number, string $error): void
    {
        if ('' === $apiKey) {
            return;
        }

        $hash    = $this->hash($apiKey);
        $seconds = $this->getRecheckSeconds();

        unset($this->lastSuccessCheck[$hash]);

        try {
            $pause = $this->cache->getItem(self::PAUSE_PREFIX.$hash);
            $pause->set(['since' => time(), 'error' => mb_substr($error, 0, 255)]);
            $pause->expiresAfter($seconds);
            $this->cache->save($pause);

            $state = $this->cache->getItem(self::STATE_PREFIX.$hash);
            if ($state->isHit()) {
                $this->logger->info('DialogHSM: número ainda bloqueado por falta de pagamento — pausa renovada', [
                    'number'          => $number?->getName(),
                    'recheck_seconds' => $seconds,
                ]);

                return;
            }

            $state->set(['since' => time()]);
            $state->expiresAfter(self::STATE_TTL);
            $this->cache->save($state);
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM: falha ao gravar a pausa por pagamento', ['error' => $e->getMessage()]);

            return;
        }

        $this->logger->error('DialogHSM: número bloqueado pela 360dialog por falta de pagamento — envios pausados', [
            'number'          => $number?->getName(),
            'recheck_seconds' => $seconds,
            'error'           => $error,
        ]);

        $this->notify($number, true, intdiv($seconds, 60));
    }

    /**
     * Chamado a cada envio aceito pela API: se o número estava bloqueado, libera e avisa.
     */
    public function noteSuccess(string $apiKey, ?WhatsAppNumber $number): void
    {
        if ('' === $apiKey || !$this->isEnabled()) {
            return;
        }

        $hash = $this->hash($apiKey);

        if (time() - ($this->lastSuccessCheck[$hash] ?? 0) < self::SUCCESS_CHECK_INTERVAL) {
            return;
        }

        try {
            $wasBlocked = $this->cache->getItem(self::STATE_PREFIX.$hash)->isHit();

            if ($wasBlocked) {
                $this->cache->deleteItem(self::PAUSE_PREFIX.$hash);
                $this->cache->deleteItem(self::STATE_PREFIX.$hash);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: falha ao consultar o bloqueio por pagamento', ['error' => $e->getMessage()]);

            return;
        }

        $this->lastSuccessCheck[$hash] = time();

        if (!$wasBlocked) {
            return;
        }

        $this->logger->notice('DialogHSM: número voltou a enviar depois do bloqueio por falta de pagamento — pausa encerrada', [
            'number' => $number?->getName(),
        ]);

        $this->notify($number, false);
    }

    /**
     * Encerra a pausa sem esperar o TTL (ex.: saldo positivo na sincronização), mantendo o
     * estado "bloqueado": a próxima mensagem sai como envio de teste e é ela que confirma a
     * liberação (noteSuccess) ou renova a pausa.
     *
     * @return bool true se havia pausa ativa
     */
    public function allowRecheck(string $apiKey, string $source): bool
    {
        if ('' === $apiKey) {
            return false;
        }

        try {
            $key = self::PAUSE_PREFIX.$this->hash($apiKey);
            if (!$this->cache->getItem($key)->isHit()) {
                return false;
            }

            $this->cache->deleteItem($key);
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: falha ao encerrar a pausa por pagamento', ['error' => $e->getMessage()]);

            return false;
        }

        $this->logger->info('DialogHSM: pausa por pagamento encerrada antes do prazo — próximo envio confirma a liberação', [
            'source' => $source,
        ]);

        return true;
    }

    private function notify(?WhatsAppNumber $number, bool $blocked, int $recheckMinutes = 0): void
    {
        if (null === $number) {
            return;
        }

        // O aviso é complementar: uma falha nele (SMTP fora do ar) não pode impedir a pausa
        // nem derrubar o envio que a detectou.
        try {
            $this->alertService->notifyPaymentBlock($number, $blocked, $recheckMinutes);
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: falha ao avisar o bloqueio por pagamento', [
                'number' => $number->getName(),
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function refreshConfig(): void
    {
        if (null !== $this->cachedEnabled && time() < $this->configExpiresAt) {
            return;
        }

        $this->cachedEnabled   = $this->configProvider->isPaymentPauseEnabled();
        $this->cachedMinutes   = $this->configProvider->getPaymentPauseMinutes();
        $this->configExpiresAt = time() + self::CONFIG_CACHE_SECONDS;
    }

    private function hash(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }
}

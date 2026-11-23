<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Service\PaymentBlockPauseService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Processa um lote de mensagens WhatsApp.
 *
 * Usado por dois modos de envio:
 *   - Redis (async): dispatched via whatsapp_direct stream, consumido por worker dedicado
 *   - Direto (sync): chamado inline pelo CampaignSubscriber quando Redis não está configurado
 *
 * Throttle:
 *   batchLimit=10, sendDelay=2 → envia 10, aguarda 2s, envia 10, aguarda 2s...
 *   batchLimit=0,  sendDelay=2 → aguarda 2s após cada mensagem individual
 *   batchLimit=0,  sendDelay=0 → envia sem pausa
 *
 * Redução de carga no banco: prune() é chamado uma única vez ao final do lote,
 * em vez de uma vez por mensagem. Para isso, cada item é processado com
 * skipHousekeeping=true e o handler chama prune() ao terminar.
 */
#[AsMessageHandler]
class SendWhatsAppDirectBatchMessageHandler
{
    /** @var callable(int): void */
    private $sleepFn;

    /**
     * Profundidade de __invoke em andamento. Com o transporte whatsapp_direct síncrono, o lote
     * republicado durante a pausa por pagamento seria processado na hora, dentro deste mesmo
     * __invoke — e republicaria de novo, sem fim. Republicar só no nível 1 corta o laço.
     */
    private int $depth = 0;

    /**
     * @param (callable(int): void)|null $sleepFn
     */
    public function __construct(
        private SendWhatsAppMessageHandler $handler,
        private LoggerInterface $logger,
        private MessageLogRepository $messageLogRepository,
        ?callable $sleepFn = null,
        private ?EntityManagerInterface $entityManager = null,
        private ?MessageBusInterface $bus = null,
        private ?PaymentBlockPauseService $paymentPause = null,
    ) {
        $this->sleepFn = $sleepFn ?? 'usleep';
    }

    /**
     * @param bool $inline true quando chamado direto pelo CampaignSubscriber (envio síncrono no
     *                     trigger da campanha), sem fila para onde devolver mensagens
     *
     * @return array<int, array{success: bool}> resultado de cada item, pela posição no lote —
     *                                          item não enviado (lote interrompido) fica sem entrada
     */
    public function __invoke(SendWhatsAppDirectBatchMessage $batch, bool $inline = false): array
    {
        ++$this->depth;

        try {
            return $this->process($batch, $inline);
        } finally {
            --$this->depth;
        }
    }

    /**
     * @return array<int, array{success: bool}>
     */
    private function process(SendWhatsAppDirectBatchMessage $batch, bool $inline): array
    {
        $effectiveBatch = $batch->batchLimit > 0 ? $batch->batchLimit : 1;
        $sentCount      = 0;
        $results        = [];
        $position       = -1;

        foreach ($batch->items as $i => $item) {
            ++$position;

            if (!$item instanceof SendWhatsAppMessage) {
                $this->logger->warning('DialogHSM: Item inválido no lote, ignorado', [
                    'type' => get_debug_type($item),
                ]);
                continue;
            }

            // O EntityManager fecha em qualquer erro de flush (deadlock, constraint) e só é
            // reaberto entre mensagens do Messenger — e o lote inteiro é UMA mensagem. Seguir
            // chamaria a API sem conseguir gravar o log: envio sem wamid, webhooks perdidos.
            if (null !== $this->entityManager && !$this->entityManager->isOpen()) {
                $this->logger->error('DialogHSM: banco indisponível no meio do lote — envio interrompido', [
                    'lead_id' => $item->leadId,
                ]);
                break;
            }

            try {
                $results[$i] = $this->handler->__invoke($item, skipHousekeeping: true, skipRateLimit: true, skipRetry: true);

                // Número pausado por falta de pagamento: este item e os seguintes não saem agora
                // (todo o lote é do mesmo número). Nenhum deles foi registrado ainda.
                if (true === ($results[$i]['payment_blocked'] ?? false)) {
                    $remaining = array_slice($batch->items, $position, null, true);
                    foreach (array_keys($remaining) as $key) {
                        $results[$key] = ['success' => false, 'paused' => true];
                    }
                    $this->deferPaymentBlocked($batch, $remaining, $inline);
                    break;
                }
            } catch (\Throwable $e) {
                $results[$i] = ['success' => false];
                $this->logger->error('DialogHSM: Erro ao processar item do lote', [
                    'lead_id' => $item->leadId,
                    'error'   => $e->getMessage(),
                ]);
            }

            ++$sentCount;

            if ($batch->sendDelay > 0 && $sentCount % $effectiveBatch === 0) {
                ($this->sleepFn)((int) ($batch->sendDelay * 1_000_000));
            }
        }

        // Prune executado uma única vez ao final do lote em vez de por mensagem,
        // reduzindo drasticamente a carga de SELECT COUNT + DELETE no banco.
        try {
            $this->messageLogRepository->prune($this->handler->getLogMaxRecords(), $this->handler->getLogMaxDays());
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Falha ao executar prune após lote', [
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * Lote consumido do transporte assíncrono: republica o restante com atraso igual ao
     * intervalo da pausa — os logs "queued" continuam como estão e nada vira falha.
     *
     * Envio inline (síncrono, no trigger da campanha): não há fila. O CampaignSubscriber já não
     * monta lotes inline com o número pausado (ver onCampaignTriggerAction), então isto só pega
     * o restante do lote em que o bloqueio foi detectado — registrado com o motivo.
     *
     * @param array<int|string, mixed> $remaining
     */
    private function deferPaymentBlocked(SendWhatsAppDirectBatchMessage $batch, array $remaining, bool $inline): void
    {
        $items = array_values(array_filter($remaining, static fn ($item): bool => $item instanceof SendWhatsAppMessage));

        if ([] === $items) {
            return;
        }

        if (!$inline && 1 === $this->depth && null !== $this->bus) {
            $delayMs = ($this->paymentPause?->getRecheckSeconds() ?? 1800) * 1000;

            try {
                $this->bus->dispatch(
                    new SendWhatsAppDirectBatchMessage(items: $items, batchLimit: $batch->batchLimit, sendDelay: $batch->sendDelay),
                    [new DelayStamp($delayMs)]
                );

                $this->logger->info('DialogHSM: número pausado por falta de pagamento — restante do lote republicado com atraso', [
                    'items'    => count($items),
                    'delay_ms' => $delayMs,
                ]);

                return;
            } catch (\Throwable $e) {
                $this->logger->error('DialogHSM: falha ao republicar o lote pausado por falta de pagamento', [
                    'items' => count($items),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($items as $item) {
            $this->handler->logPaymentBlocked($item);
        }
    }
}

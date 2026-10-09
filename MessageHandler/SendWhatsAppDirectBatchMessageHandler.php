<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

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
     * @param (callable(int): void)|null $sleepFn
     */
    public function __construct(
        private SendWhatsAppMessageHandler $handler,
        private LoggerInterface $logger,
        private MessageLogRepository $messageLogRepository,
        ?callable $sleepFn = null,
        private ?EntityManagerInterface $entityManager = null,
    ) {
        $this->sleepFn = $sleepFn ?? 'usleep';
    }

    /**
     * @return array<int, array{success: bool}> resultado de cada item, pela posição no lote —
     *                                          item não enviado (lote interrompido) fica sem entrada
     */
    public function __invoke(SendWhatsAppDirectBatchMessage $batch): array
    {
        $effectiveBatch = $batch->batchLimit > 0 ? $batch->batchLimit : 1;
        $sentCount      = 0;
        $results        = [];

        foreach ($batch->items as $i => $item) {
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
}

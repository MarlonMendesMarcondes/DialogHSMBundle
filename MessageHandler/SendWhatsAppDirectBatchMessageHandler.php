<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\MessageHandler;

use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

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
class SendWhatsAppDirectBatchMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private SendWhatsAppMessageHandler $handler,
        private LoggerInterface $logger,
        private MessageLogRepository $messageLogRepository,
    ) {
    }

    public function __invoke(SendWhatsAppDirectBatchMessage $batch): void
    {
        $effectiveBatch = $batch->batchLimit > 0 ? $batch->batchLimit : 1;
        $sentCount      = 0;

        foreach ($batch->items as $item) {
            if (!$item instanceof SendWhatsAppMessage) {
                $this->logger->warning('DialogHSM: Item inválido no lote, ignorado', [
                    'type' => get_debug_type($item),
                ]);
                continue;
            }

            try {
                $this->handler->__invoke($item, skipHousekeeping: true, skipRateLimit: true, skipRetry: true);
            } catch (\Throwable $e) {
                $this->logger->error('DialogHSM: Erro ao processar item do lote', [
                    'lead_id' => $item->leadId,
                    'error'   => $e->getMessage(),
                ]);
            }

            ++$sentCount;

            if ($batch->sendDelay > 0 && $sentCount % $effectiveBatch === 0) {
                usleep($batch->sendDelay * 1_000_000);
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
    }
}

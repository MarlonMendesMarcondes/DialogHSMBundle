<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\MessageHandler;

use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Processa um lote de mensagens WhatsApp via transporte whatsapp_direct (Redis Streams).
 *
 * Replica a semântica do modo inline com controle de throttle:
 *   batchLimit=10, sendDelay=2 → envia 10, aguarda 2s, envia 10, aguarda 2s...
 *   batchLimit=0,  sendDelay=2 → aguarda 2s após cada mensagem individual
 *   batchLimit=0,  sendDelay=0 → envia sem pausa
 *
 * Um único entry no Redis Stream por execução de campanha garante que um
 * worker cuida do lote inteiro sem interferência de outros workers.
 * Campanhas concorrentes são processadas por workers distintos.
 */
class SendWhatsAppDirectBatchMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private SendWhatsAppMessageHandler $handler,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendWhatsAppDirectBatchMessage $batch): void
    {
        $effectiveBatch = $batch->batchLimit > 0 ? $batch->batchLimit : 1;
        $sentCount      = 0;

        foreach ($batch->items as $item) {
            if (!$item instanceof SendWhatsAppMessage) {
                $this->logger->warning('DialogHSM: Item inválido no lote Redis, ignorado', [
                    'type' => get_debug_type($item),
                ]);
                continue;
            }

            try {
                ($this->handler)($item);
            } catch (\Throwable $e) {
                $this->logger->error('DialogHSM: Erro ao processar item do lote Redis', [
                    'lead_id' => $item->leadId,
                    'error'   => $e->getMessage(),
                ]);
            }

            ++$sentCount;

            if ($batch->sendDelay > 0 && $sentCount % $effectiveBatch === 0) {
                usleep($batch->sendDelay * 1_000_000);
            }
        }
    }
}

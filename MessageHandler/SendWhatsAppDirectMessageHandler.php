<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\MessageHandler;

use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectMessage;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Handler para o transporte whatsapp_direct (Redis).
 * Delega ao handler base que realiza a chamada à API.
 *
 * O rate limit é lido diretamente da mensagem (rateDelayMs), calculado pelo
 * subscriber a partir dos campos send_delay e batch_limit do formulário da campanha.
 */
class SendWhatsAppDirectMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private SendWhatsAppMessageHandler $handler,
    ) {
    }

    public function __invoke(SendWhatsAppDirectMessage $message): array
    {
        $result = ($this->handler)($message);

        if ($message->rateDelaySeconds > 0.0) {
            usleep((int) ($message->rateDelaySeconds * 1_000_000));
        }

        return $result;
    }
}

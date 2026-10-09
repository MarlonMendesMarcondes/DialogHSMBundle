<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

/**
 * Fila do RabbitMQ de onde veio a mensagem que o worker está processando agora.
 *
 * O handler não recebe o envelope do Messenger, e a fila não dá para deduzir da mensagem
 * (Marketing Messages publica na fila bulk com isBatch=true). WorkerQueueSubscriber
 * preenche isto a cada mensagem recebida, a partir do AmqpReceivedStamp.
 */
class WorkerQueueContext
{
    private ?string $currentQueueName = null;

    public function setCurrentQueueName(?string $queueName): void
    {
        $this->currentQueueName = $queueName;
    }

    public function getCurrentQueueName(): ?string
    {
        return $this->currentQueueName;
    }
}

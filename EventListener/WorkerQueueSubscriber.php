<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use MauticPlugin\DialogHSMBundle\Service\WorkerQueueContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpReceivedStamp;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Integra o worker do messenger:consume com o pause por campanha:
 *
 * 1. Guarda em WorkerQueueContext a fila de cada mensagem recebida, para o handler devolver
 *    a mensagem de campanha desativada à mesma fila.
 * 2. Limite de mensagens por execução que não conta as devolvidas por campanha desativada.
 *    O --limit nativo conta toda mensagem recebida: numa fila compartilhada (filas são por
 *    número, não por campanha), mensagens de uma campanha pausada consumiriam o limite e
 *    travariam as campanhas ativas da mesma fila. Por isso o dialoghsm:consume passa o
 *    limite pela variável de ambiente SEND_LIMIT_ENV em vez de --limit.
 */
class WorkerQueueSubscriber implements EventSubscriberInterface
{
    public const SEND_LIMIT_ENV = 'DIALOGHSM_SEND_LIMIT';

    private int $processed = 0;

    public function __construct(private WorkerQueueContext $queueContext)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onMessageReceived',
            WorkerMessageHandledEvent::class  => 'onMessageHandled',
            WorkerMessageFailedEvent::class   => 'onMessageFailed',
            WorkerRunningEvent::class         => 'onWorkerRunning',
        ];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $this->queueContext->setCurrentQueueName($event->getEnvelope()->last(AmqpReceivedStamp::class)?->getQueueName());
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $result = $event->getEnvelope()->last(HandledStamp::class)?->getResult();

        if (is_array($result) && true === ($result['paused'] ?? false)) {
            return;
        }

        ++$this->processed;
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        ++$this->processed;
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $limit = (int) getenv(self::SEND_LIMIT_ENV);

        if ($limit > 0 && $this->processed >= $limit) {
            $this->processed = 0;
            $event->getWorker()->stop();
        }
    }
}

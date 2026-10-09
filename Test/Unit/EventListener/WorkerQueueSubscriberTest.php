<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\EventListener\WorkerQueueSubscriber;
use MauticPlugin\DialogHSMBundle\Service\WorkerQueueContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpReceivedStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Worker;

class WorkerQueueSubscriberTest extends TestCase
{
    private WorkerQueueContext $context;
    private WorkerQueueSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->context    = new WorkerQueueContext();
        $this->subscriber = new WorkerQueueSubscriber($this->context);
        putenv(WorkerQueueSubscriber::SEND_LIMIT_ENV.'=2');
    }

    protected function tearDown(): void
    {
        putenv(WorkerQueueSubscriber::SEND_LIMIT_ENV);
    }

    private function handled(array $result): WorkerMessageHandledEvent
    {
        return new WorkerMessageHandledEvent(
            (new Envelope(new \stdClass()))->with(new HandledStamp($result, 'handler')),
            'whatsapp'
        );
    }

    private function runningWorkerStops(): bool
    {
        $worker = $this->createMock(Worker::class);
        $stopped = false;
        $worker->method('stop')->willReturnCallback(function () use (&$stopped): void {
            $stopped = true;
        });

        $this->subscriber->onWorkerRunning(new WorkerRunningEvent($worker, false));

        return $stopped;
    }

    public function testStoresQueueOfReceivedMessage(): void
    {
        $amqpEnvelope = $this->createMock(\AMQPEnvelope::class);
        $envelope     = (new Envelope(new \stdClass()))->with(new AmqpReceivedStamp($amqpEnvelope, 'wpp_unimax_lote'));

        $this->subscriber->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'whatsapp'));

        $this->assertSame('wpp_unimax_lote', $this->context->getCurrentQueueName());
    }

    public function testPausedMessagesDoNotCountTowardsLimit(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->subscriber->onMessageHandled($this->handled(['success' => false, 'paused' => true]));
        }

        $this->assertFalse($this->runningWorkerStops(), 'Mensagens de campanha desativada não podem consumir o limite da execução.');
    }

    public function testStopsWorkerWhenProcessedMessagesReachLimit(): void
    {
        $this->subscriber->onMessageHandled($this->handled(['success' => true]));
        $this->assertFalse($this->runningWorkerStops());

        $this->subscriber->onMessageFailed(new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'whatsapp', new \RuntimeException('x')));
        $this->assertTrue($this->runningWorkerStops());
    }

    public function testNoLimitWithoutEnvironmentVariable(): void
    {
        putenv(WorkerQueueSubscriber::SEND_LIMIT_ENV);

        for ($i = 0; $i < 5; ++$i) {
            $this->subscriber->onMessageHandled($this->handled(['success' => true]));
        }

        $this->assertFalse($this->runningWorkerStops());
    }
}

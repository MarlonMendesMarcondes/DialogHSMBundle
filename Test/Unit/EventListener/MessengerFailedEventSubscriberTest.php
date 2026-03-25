<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\EventListener\MessengerFailedEventSubscriber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

class MessengerFailedEventSubscriberTest extends TestCase
{
    private EntityManagerInterface&MockObject $mockEm;
    private LoggerInterface&MockObject $mockLogger;
    private MessengerFailedEventSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->mockEm     = $this->createMock(EntityManagerInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new MessengerFailedEventSubscriber($this->mockEm, $this->mockLogger);
    }

    private function makeMessage(
        int $leadId = 1,
        string $phone = '+5511999999999',
        string $template = 'promo_hsm',
        string $senderName = 'Vendas',
    ): SendWhatsAppMessage {
        return new SendWhatsAppMessage(
            leadId:             $leadId,
            phone:              $phone,
            apiKey:             'key',
            baseUrl:            'https://api.360dialog.com/v1/messages',
            payloadData:        ['content' => $template],
            templateName:       $template,
            whatsAppNumberName: $senderName,
        );
    }

    private function makeEvent(
        SendWhatsAppMessage $message,
        \Throwable $throwable,
        bool $willRetry = false,
    ): WorkerMessageFailedEvent {
        $envelope = new Envelope($message);
        $event    = new WorkerMessageFailedEvent($envelope, 'whatsapp', $throwable);

        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    // =========================================================================
    // getSubscribedEvents
    // =========================================================================

    public function testSubscribesToWorkerMessageFailedEvent(): void
    {
        $events = MessengerFailedEventSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(WorkerMessageFailedEvent::class, $events);
        $this->assertSame('onMessageFailed', $events[WorkerMessageFailedEvent::class]);
    }

    // =========================================================================
    // onMessageFailed — ignorar quando willRetry = true
    // =========================================================================

    public function testDoesNotPersistWhenMessageWillBeRetried(): void
    {
        $this->mockEm->expects($this->never())->method('persist');

        $event = $this->makeEvent($this->makeMessage(), new \RuntimeException('timeout'), willRetry: true);
        $this->subscriber->onMessageFailed($event);
    }

    // =========================================================================
    // onMessageFailed — ignorar mensagens de outros tipos
    // =========================================================================

    public function testDoesNotPersistForUnknownMessageType(): void
    {
        $this->mockEm->expects($this->never())->method('persist');

        $envelope = new Envelope(new \stdClass());
        $event    = new WorkerMessageFailedEvent($envelope, 'other_transport', new \RuntimeException('err'));

        $this->subscriber->onMessageFailed($event);
    }

    // =========================================================================
    // onMessageFailed — persiste log DLQ quando esgotadas as tentativas
    // =========================================================================

    public function testPersistsDlqLogWhenRetriesExhausted(): void
    {
        $persisted = null;

        $this->mockEm
            ->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (MessageLog $log) use (&$persisted): void {
                $persisted = $log;
            });

        $this->mockEm->expects($this->once())->method('flush');

        $message = $this->makeMessage(leadId: 42, phone: '+5511999999999', template: 'promo_hsm', senderName: 'Vendas');
        $event   = $this->makeEvent($message, new \RuntimeException('Connection refused'));

        $this->subscriber->onMessageFailed($event);

        $this->assertInstanceOf(MessageLog::class, $persisted);
        $this->assertSame(42, $persisted->getLeadId());
        $this->assertSame('+5511999999999', $persisted->getPhoneNumber());
        $this->assertSame('promo_hsm', $persisted->getTemplateName());
        $this->assertSame('Vendas', $persisted->getSenderName());
        $this->assertSame(MessageLog::STATUS_DLQ, $persisted->getStatus());
        $this->assertStringContainsString('Connection refused', $persisted->getErrorMessage());
        $this->assertNotNull($persisted->getDateSent());
    }

    public function testDlqLogStatusIsDlqConstant(): void
    {
        $persisted = null;

        $this->mockEm->method('persist')->willReturnCallback(function (MessageLog $log) use (&$persisted): void {
            $persisted = $log;
        });

        $event = $this->makeEvent($this->makeMessage(), new \RuntimeException('err'));
        $this->subscriber->onMessageFailed($event);

        $this->assertSame('dlq', $persisted->getStatus());
    }

    public function testDlqLogErrorMessageIsTruncatedTo255Chars(): void
    {
        $persisted = null;

        $this->mockEm->method('persist')->willReturnCallback(function (MessageLog $log) use (&$persisted): void {
            $persisted = $log;
        });

        $longError = str_repeat('x', 500);
        $event     = $this->makeEvent($this->makeMessage(), new \RuntimeException($longError));
        $this->subscriber->onMessageFailed($event);

        $this->assertLessThanOrEqual(255, strlen($persisted->getErrorMessage()));
    }

    public function testDlqLogSenderNameIsNullWhenWhatsAppNumberNameIsEmpty(): void
    {
        $persisted = null;

        $this->mockEm->method('persist')->willReturnCallback(function (MessageLog $log) use (&$persisted): void {
            $persisted = $log;
        });

        $message = $this->makeMessage(senderName: '');
        $event   = $this->makeEvent($message, new \RuntimeException('err'));
        $this->subscriber->onMessageFailed($event);

        $this->assertNull($persisted->getSenderName());
    }

    // =========================================================================
    // onMessageFailed — tratamento de erro ao persistir
    // =========================================================================

    public function testLogsErrorWhenPersistFails(): void
    {
        $this->mockEm
            ->method('persist')
            ->willThrowException(new \RuntimeException('DB down'));

        $this->mockLogger
            ->expects($this->once())
            ->method('error')
            ->with('DialogHSM: falha ao registrar mensagem DLQ no log', $this->anything());

        $event = $this->makeEvent($this->makeMessage(), new \RuntimeException('original error'));
        $this->subscriber->onMessageFailed($event);
    }

    public function testDoesNotRethrowWhenPersistFails(): void
    {
        $this->mockEm->method('persist')->willThrowException(new \RuntimeException('DB down'));

        // Não deve lançar exceção
        $this->expectNotToPerformAssertions();
        $event = $this->makeEvent($this->makeMessage(), new \RuntimeException('err'));
        $this->subscriber->onMessageFailed($event);
    }
}

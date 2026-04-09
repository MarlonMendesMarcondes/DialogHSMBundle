<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\EventListener\MessengerFailedEventSubscriber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
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
    private MessageLogRepository&MockObject $mockRepo;
    private MessengerFailedEventSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->mockEm     = $this->createMock(EntityManagerInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);
        $this->mockRepo   = $this->createMock(MessageLogRepository::class);

        // Por padrão: sem log queued existente
        $this->mockRepo->method('findByWamid')->willReturn(null);

        $this->subscriber = new MessengerFailedEventSubscriber($this->mockEm, $this->mockLogger, $this->mockRepo);
    }

    private function makeMessage(
        int $leadId = 1,
        string $phone = '+5511999999999',
        string $template = 'promo_hsm',
        string $senderName = 'Vendas',
        ?string $queueLogId = null,
    ): SendWhatsAppMessage {
        return new SendWhatsAppMessage(
            leadId:             $leadId,
            phone:              $phone,
            apiKey:             'key',
            baseUrl:            'https://api.360dialog.com/v1/messages',
            payloadData:        ['content' => $template],
            templateName:       $template,
            whatsAppNumberName: $senderName,
            queueLogId:         $queueLogId,
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

    // =========================================================================
    // onMessageFailed — reutilização do log queued via queueLogId
    // =========================================================================

    public function testUpdatesExistingQueuedLogInsteadOfCreatingNew(): void
    {
        $existingLog = new MessageLog();
        $existingLog->setLeadId(42);
        $existingLog->setStatus(MessageLog::STATUS_QUEUED);
        $existingLog->setWamid('uuid-abc');

        // Mock dedicado para este teste, evitando conflito com o stub de setUp
        $mockRepo = $this->createMock(MessageLogRepository::class);
        $mockRepo->expects($this->once())
            ->method('findByWamid')
            ->with('uuid-abc')
            ->willReturn($existingLog);

        $persisted  = null;
        $mockEm     = $this->createMock(EntityManagerInterface::class);
        $mockEm->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function (MessageLog $log) use (&$persisted): void {
                $persisted = $log;
            });
        $mockEm->expects($this->once())->method('flush');

        $subscriber = new MessengerFailedEventSubscriber($mockEm, $this->mockLogger, $mockRepo);

        $message = $this->makeMessage(leadId: 42, queueLogId: 'uuid-abc');
        $event   = $this->makeEvent($message, new \RuntimeException('API timeout'));
        $subscriber->onMessageFailed($event);

        $this->assertSame($existingLog, $persisted);
        $this->assertSame(MessageLog::STATUS_DLQ, $persisted->getStatus());
        $this->assertNull($persisted->getWamid());
        $this->assertStringContainsString('API timeout', $persisted->getErrorMessage());
    }

    public function testCreatesNewLogWhenQueuedLogNotFound(): void
    {
        // mockRepo já retorna null por padrão (setUp)
        $persisted = null;
        $this->mockEm->method('persist')->willReturnCallback(function (MessageLog $log) use (&$persisted): void {
            $persisted = $log;
        });

        $message = $this->makeMessage(leadId: 99, queueLogId: 'unknown-uuid');
        $event   = $this->makeEvent($message, new \RuntimeException('err'));
        $this->subscriber->onMessageFailed($event);

        $this->assertSame(99, $persisted->getLeadId());
        $this->assertSame(MessageLog::STATUS_DLQ, $persisted->getStatus());
    }

    public function testDoesNotLookupRepoWhenQueueLogIdIsNull(): void
    {
        $this->mockRepo->expects($this->never())->method('findByWamid');

        $this->mockEm->method('persist')->willReturnCallback(function (): void {});

        $message = $this->makeMessage(queueLogId: null);
        $event   = $this->makeEvent($message, new \RuntimeException('err'));
        $this->subscriber->onMessageFailed($event);
    }

    // =========================================================================
    // onMessageFailed — SendWhatsAppDirectBatchMessage
    // =========================================================================

    private function makeBatchEvent(
        SendWhatsAppDirectBatchMessage $batch,
        \Throwable $throwable,
        bool $willRetry = false,
    ): WorkerMessageFailedEvent {
        $envelope = new Envelope($batch);
        $event    = new WorkerMessageFailedEvent($envelope, 'whatsapp', $throwable);

        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    public function testDoesNotPersistBatchWhenWillRetry(): void
    {
        $this->mockEm->expects($this->never())->method('persist');

        $batch = new SendWhatsAppDirectBatchMessage(
            items:      [$this->makeMessage(leadId: 1), $this->makeMessage(leadId: 2)],
            batchLimit: 0,
            sendDelay:  0,
        );
        $event = $this->makeBatchEvent($batch, new \RuntimeException('timeout'), willRetry: true);
        $this->subscriber->onMessageFailed($event);
    }

    public function testPersistsDlqLogForEachItemInBatchWhenRetriesExhausted(): void
    {
        $persisted = [];

        $this->mockEm
            ->expects($this->exactly(3))
            ->method('persist')
            ->willReturnCallback(function (MessageLog $log) use (&$persisted): void {
                $persisted[] = $log;
            });

        $this->mockEm->expects($this->exactly(3))->method('flush');

        $batch = new SendWhatsAppDirectBatchMessage(
            items: [
                $this->makeMessage(leadId: 10, phone: '+5511111111111', template: 'tmpl_a'),
                $this->makeMessage(leadId: 20, phone: '+5522222222222', template: 'tmpl_b'),
                $this->makeMessage(leadId: 30, phone: '+5533333333333', template: 'tmpl_c'),
            ],
            batchLimit: 0,
            sendDelay:  0,
        );
        $event = $this->makeBatchEvent($batch, new \RuntimeException('Redis down'));
        $this->subscriber->onMessageFailed($event);

        $this->assertCount(3, $persisted);

        $this->assertSame(10, $persisted[0]->getLeadId());
        $this->assertSame('+5511111111111', $persisted[0]->getPhoneNumber());
        $this->assertSame('tmpl_a', $persisted[0]->getTemplateName());
        $this->assertSame(MessageLog::STATUS_DLQ, $persisted[0]->getStatus());
        $this->assertStringContainsString('Redis down', $persisted[0]->getErrorMessage());

        $this->assertSame(20, $persisted[1]->getLeadId());
        $this->assertSame(30, $persisted[2]->getLeadId());
    }

    public function testEmptyBatchDoesNotPersistAnything(): void
    {
        $this->mockEm->expects($this->never())->method('persist');

        $batch = new SendWhatsAppDirectBatchMessage(items: [], batchLimit: 0, sendDelay: 0);
        $event = $this->makeBatchEvent($batch, new \RuntimeException('err'));
        $this->subscriber->onMessageFailed($event);
    }

    public function testBatchDlqContinuesWhenOneItemPersistFails(): void
    {
        $persistCount = 0;

        $this->mockEm
            ->method('persist')
            ->willReturnCallback(function () use (&$persistCount): void {
                ++$persistCount;
                if (1 === $persistCount) {
                    throw new \RuntimeException('DB error on first item');
                }
            });

        $this->mockLogger
            ->expects($this->once())
            ->method('error')
            ->with('DialogHSM: falha ao registrar mensagem DLQ no log', $this->anything());

        $batch = new SendWhatsAppDirectBatchMessage(
            items: [
                $this->makeMessage(leadId: 1),
                $this->makeMessage(leadId: 2),
            ],
            batchLimit: 0,
            sendDelay:  0,
        );
        $event = $this->makeBatchEvent($batch, new \RuntimeException('timeout'));
        $this->subscriber->onMessageFailed($event);

        // Segundo item foi processado (persist chamado 2 vezes, mesmo que 1ª tenha falhado)
        $this->assertSame(2, $persistCount);
    }
}

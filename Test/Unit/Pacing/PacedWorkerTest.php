<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\DTO\BusinessHoursCalendar;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Exception\TransientApiException;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Pacing\PacedQueue;
use MauticPlugin\DialogHSMBundle\Pacing\PacedScheduler;
use MauticPlugin\DialogHSMBundle\Pacing\PacedSettings;
use MauticPlugin\DialogHSMBundle\Pacing\PacedWorker;
use MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter;
use MauticPlugin\DialogHSMBundle\Service\CampaignActivityChecker;
use MauticPlugin\DialogHSMBundle\Service\PaymentBlockPauseService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

class PacedWorkerTest extends TestCase
{
    private PacedQueue&MockObject $queue;
    private PacedSettings&MockObject $settings;
    private SendWhatsAppMessageHandler&MockObject $handler;
    private WhatsAppNumberRepository&MockObject $numbers;
    private MessageLogRepository&MockObject $logs;
    private CampaignActivityChecker&MockObject $campaigns;
    private BulkRateLimiter&MockObject $rateLimiter;
    private EntityManagerInterface&MockObject $entityManager;
    private PaymentBlockPauseService&MockObject $paymentPause;

    protected function setUp(): void
    {
        $this->queue         = $this->createMock(PacedQueue::class);
        $this->settings      = $this->createMock(PacedSettings::class);
        $this->handler       = $this->createMock(SendWhatsAppMessageHandler::class);
        $this->numbers       = $this->createMock(WhatsAppNumberRepository::class);
        $this->logs          = $this->createMock(MessageLogRepository::class);
        $this->campaigns     = $this->createMock(CampaignActivityChecker::class);
        $this->rateLimiter   = $this->createMock(BulkRateLimiter::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->paymentPause  = $this->createMock(PaymentBlockPauseService::class);

        $this->openWindow(true);
        $this->settings->method('getRatePerMinute')->willReturn(60);
        $this->campaigns->method('getStatus')->willReturn(CampaignActivityChecker::ACTIVE);
        $this->numbers->method('find')->willReturn($this->number());
    }

    private function worker(): PacedWorker
    {
        return new PacedWorker(
            $this->queue,
            $this->settings,
            $this->handler,
            $this->numbers,
            $this->logs,
            $this->campaigns,
            $this->rateLimiter,
            $this->entityManager,
            new NullLogger(),
            $this->paymentPause,
            static function (int $seconds): void {
            },
        );
    }

    private function openWindow(bool $open): void
    {
        // Janela do dia inteiro em todos os dias, ou vazia (só domingo 00:00–00:01 de um fuso distante)
        $calendar = $open
            ? new BusinessHoursCalendar(0, 1440, [], new DateTimeZone('UTC'))
            : new BusinessHoursCalendar(0, 1, [((int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('N')) % 7 + 1], new DateTimeZone('UTC'));

        $this->settings->method('calendar')->willReturn($calendar);
    }

    private function number(): WhatsAppNumber
    {
        $number = new WhatsAppNumber();
        $number->setName('Numero Teste');
        $number->setApiKeyRaw('api-key-real');

        return $number;
    }

    /**
     * @return array{id: int, lot_id: int, whatsapp_number_id: int, queue_log_id: string, payload: string, attempts: int}
     */
    private function row(int $id = 7, int $attempts = 0): array
    {
        $message = new SendWhatsAppMessage(
            leadId: 42,
            phone: '+5544999990000',
            apiKey: 'nunca-gravada',
            baseUrl: 'https://waba.example/messages',
            payloadData: ['content' => 'template_x', 'params' => ['a']],
            templateName: 'template_x',
            whatsAppNumberName: 'Numero Teste',
            campaignId: 3,
            campaignEventId: 9,
            queueLogId: 'uuid-1',
            legacy: false,
        );

        return [
            'id'                 => $id,
            'lot_id'             => 1,
            'whatsapp_number_id' => 5,
            'queue_log_id'       => 'uuid-1',
            'payload'            => json_encode(PacedScheduler::toPayload($message)),
            'attempts'           => $attempts,
        ];
    }

    private function tick(): ?int
    {
        return $this->worker()->tick('token', time() + 60);
    }

    public function testSendsDueMessageThroughTheHandlerWithTheNumberApiKey(): void
    {
        $this->queue->method('claimDue')->willReturn([$this->row()]);
        $this->rateLimiter->expects($this->once())->method('throttlePaced')->with('Numero Teste', 60);
        $this->handler->expects($this->once())->method('__invoke')
            ->with(
                $this->callback(static fn (SendWhatsAppMessage $m): bool => 'api-key-real' === $m->apiKey
                    && 'uuid-1' === $m->queueLogId && 42 === $m->leadId && false === $m->legacy && false === $m->isBatch
                    && ['content' => 'template_x', 'params' => ['a']] === $m->payloadData),
                false,
                true,
                false,
            )
            ->willReturn(['success' => true]);
        $this->queue->expects($this->once())->method('delete')->with(7);
        $this->queue->expects($this->once())->method('release')->with('token');

        $this->assertSame(1, $this->tick());
    }

    public function testTheScheduledPayloadNeverContainsTheApiKey(): void
    {
        $payload = json_decode($this->row()['payload'], true);

        $this->assertArrayNotHasKey('apiKey', $payload);
        $this->assertStringNotContainsString('nunca-gravada', $this->row()['payload']);
    }

    public function testDoesNothingOutsideTheWindow(): void
    {
        $this->settings = $this->createMock(PacedSettings::class);
        $this->openWindow(false);

        $this->queue->expects($this->never())->method('claimDue');

        $this->assertNull($this->tick());
    }

    public function testNothingDueReturnsNull(): void
    {
        $this->queue->method('claimDue')->willReturn([]);
        $this->handler->expects($this->never())->method('__invoke');

        $this->assertNull($this->tick());
    }

    public function testInactiveCampaignIsHeldWithoutSpendingAnAttempt(): void
    {
        $this->campaigns = $this->createMock(CampaignActivityChecker::class);
        $this->campaigns->method('getStatus')->willReturn(CampaignActivityChecker::INACTIVE);
        $this->queue->method('claimDue')->willReturn([$this->row()]);

        $this->handler->expects($this->never())->method('__invoke');
        $this->queue->expects($this->once())->method('postpone')->with(7, $this->isInstanceOf(DateTimeImmutable::class), false);
        $this->queue->expects($this->never())->method('delete');

        $this->assertSame(0, $this->tick());
    }

    public function testTransientErrorIsRetriedWithBackoff(): void
    {
        $this->queue->method('claimDue')->willReturn([$this->row(attempts: 0)]);
        $this->handler->method('__invoke')->willThrowException(new TransientApiException('HTTP 503'));

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->queue->expects($this->once())->method('postpone')->with(
            7,
            $this->callback(static fn (DateTimeImmutable $due): bool => $due >= $before->modify('+59 seconds') && $due <= $before->modify('+65 seconds')),
            true,
        );
        $this->queue->expects($this->never())->method('delete');

        $this->assertSame(0, $this->tick());
    }

    public function testLastAttemptMarksTheLogFailedAndRemovesTheMessage(): void
    {
        $this->queue->method('claimDue')->willReturn([$this->row(attempts: PacedWorker::MAX_ATTEMPTS - 1)]);
        $this->handler->method('__invoke')->willThrowException(new TransientApiException('HTTP 503'));

        $log = new MessageLog();
        $log->setStatus(MessageLog::STATUS_QUEUED);
        $log->setWamid('uuid-1');
        $this->logs->method('findByWamid')->with('uuid-1')->willReturn($log);

        $this->queue->expects($this->once())->method('delete')->with(7);
        $this->queue->expects($this->never())->method('postpone');

        $this->assertSame(1, $this->tick());
        $this->assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
        $this->assertNull($log->getWamid());
        $this->assertSame('HTTP 503', $log->getErrorMessage());
    }

    public function testPaymentPauseHoldsUntilTheNextRecheck(): void
    {
        $this->queue->method('claimDue')->willReturn([$this->row()]);
        $this->handler->method('__invoke')->willThrowException(new RecoverableMessageHandlingException('bloqueado'));
        $this->paymentPause->method('isPaused')->with('api-key-real')->willReturn(true);
        $this->paymentPause->method('getRecheckSeconds')->willReturn(1800);

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->queue->expects($this->once())->method('postpone')->with(
            7,
            $this->callback(static fn (DateTimeImmutable $due): bool => $due >= $before->modify('+29 minutes') && $due <= $before->modify('+31 minutes')),
            false,
        );

        $this->assertSame(0, $this->tick());
    }

    public function testMissingNumberFailsTheLogAndRemovesTheMessage(): void
    {
        $this->numbers = $this->createMock(WhatsAppNumberRepository::class);
        $this->numbers->method('find')->willReturn(null);
        $this->queue->method('claimDue')->willReturn([$this->row()]);

        $log = new MessageLog();
        $log->setWamid('uuid-1');
        $this->logs->method('findByWamid')->willReturn($log);

        $this->handler->expects($this->never())->method('__invoke');
        $this->queue->expects($this->once())->method('delete')->with(7);

        $this->assertSame(1, $this->tick());
        $this->assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
    }

    public function testAnErrorOnOneMessageDoesNotStopTheOthers(): void
    {
        $this->queue->method('claimDue')->willReturn([$this->row(id: 1), $this->row(id: 2)]);
        $this->handler->method('__invoke')->willReturnOnConsecutiveCalls(
            $this->throwException(new RuntimeException('falha inesperada')),
            ['success' => true],
        );

        $this->queue->expects($this->once())->method('postpone')->with(1, $this->anything(), true);
        $this->queue->expects($this->once())->method('delete')->with(2);

        $this->assertSame(1, $this->tick());
    }

    public function testShouldRunWhenEnabledOrWithPendingMessages(): void
    {
        $this->settings->method('isEnabled')->willReturnOnConsecutiveCalls(true, false, false);
        $this->queue->method('hasPending')->willReturnOnConsecutiveCalls(true, false);

        $worker = $this->worker();

        $this->assertTrue($worker->shouldRun(), 'chave ligada');
        $this->assertTrue($worker->shouldRun(), 'chave desligada, mas com lotes agendados');
        $this->assertFalse($worker->shouldRun(), 'nada a fazer');
    }

    public function testShouldRunIsFalseWhenTheTableIsMissing(): void
    {
        $this->queue->method('hasPending')->willThrowException(new RuntimeException("Table doesn't exist"));

        $this->assertFalse($this->worker()->shouldRun());
    }
}

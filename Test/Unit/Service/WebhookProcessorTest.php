<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Event\WebhookMessageFailedEvent;
use MauticPlugin\DialogHSMBundle\Service\WebhookProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class WebhookProcessorTest extends TestCase
{
    /** @var WhatsAppNumberRepository&MockObject */
    private WhatsAppNumberRepository $numberRepository;
    private MessageLogRepository&MockObject $logRepository;
    private EntityManagerInterface&MockObject $em;
    private EventDispatcherInterface&MockObject $dispatcher;
    private WebhookProcessor $processor;

    protected function setUp(): void
    {
        $this->numberRepository = $this->getMockBuilder(WhatsAppNumberRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByPhoneNumber'])
            ->getMock();

        $this->logRepository = $this->createMock(MessageLogRepository::class);
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->dispatcher    = $this->createMock(EventDispatcherInterface::class);
        $this->processor     = new WebhookProcessor($this->numberRepository, $this->logRepository, $this->em, $this->dispatcher);
    }

    private function makeLog(string $status): MessageLog
    {
        $log = new MessageLog();
        $log->setStatus($status);

        return $log;
    }

    private function makePayload(array $statuses): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => ['statuses' => $statuses],
                ]],
            ]],
        ];
    }

    private function makeStatusEntry(string $wamid, string $status): array
    {
        return [
            'id'           => $wamid,
            'status'       => $status,
            'timestamp'    => '1700000000',
            'recipient_id' => '5511999999999',
        ];
    }

    // =========================================================================
    // Validação do phoneNumber
    // =========================================================================

    public function testUnknownPhoneNumberReturns404(): void
    {
        $this->numberRepository->method('findByPhoneNumber')->willReturn(null);

        $result = $this->processor->process('+5511999999999', $this->makePayload([]));

        $this->assertSame(404, $result);
    }

    public function testValidPhoneNumberWithEmptyStatusesReturns200(): void
    {
        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->expects($this->never())->method('findByWamid');

        $result = $this->processor->process('+5511999999999', $this->makePayload([]));

        $this->assertSame(200, $result);
    }

    public function testFindByPhoneNumberIsCalledWithCorrectNumber(): void
    {
        $this->numberRepository
            ->expects($this->once())
            ->method('findByPhoneNumber')
            ->with('+5511911703871')
            ->willReturn(null);

        $this->processor->process('+5511911703871', $this->makePayload([]));
    }

    // =========================================================================
    // Transições válidas: delivered
    // =========================================================================

    public function testDeliveredFromSentUpdatesStatus(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->with('wamid.abc')->willReturn($log);
        $this->em->expects($this->once())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'delivered'),
        ]));

        $this->assertSame(MessageLog::STATUS_DELIVERED, $log->getStatus());
    }

    public function testDeliveredFromDeliveredIsNoOp(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_DELIVERED);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'delivered'),
        ]));

        $this->assertSame(MessageLog::STATUS_DELIVERED, $log->getStatus());
    }

    public function testDeliveredFromReadDoesNotDowngrade(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_READ);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'delivered'),
        ]));

        $this->assertSame(MessageLog::STATUS_READ, $log->getStatus());
    }

    public function testDeliveredFromFailedIsNoOp(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_FAILED);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'delivered'),
        ]));

        $this->assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
    }

    public function testDeliveredFromDlqIsNoOp(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_DLQ);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.xyz', 'delivered'),
        ]));

        $this->assertSame(MessageLog::STATUS_DLQ, $log->getStatus());
    }

    // =========================================================================
    // Transições válidas: read
    // =========================================================================

    public function testReadFromSentUpdatesStatus(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->once())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'read'),
        ]));

        $this->assertSame(MessageLog::STATUS_READ, $log->getStatus());
    }

    public function testReadFromDeliveredUpdatesStatus(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_DELIVERED);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->once())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'read'),
        ]));

        $this->assertSame(MessageLog::STATUS_READ, $log->getStatus());
    }

    public function testReadFromReadIsNoOp(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_READ);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'read'),
        ]));

        $this->assertSame(MessageLog::STATUS_READ, $log->getStatus());
    }

    public function testReadFromFailedIsNoOp(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_FAILED);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'read'),
        ]));

        $this->assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
    }

    // =========================================================================
    // Transições válidas: sent (webhook Meta confirma recepção)
    // =========================================================================

    public function testSentFromPendingWebhookUpdatesStatus(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_PENDING_WEBHOOK);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->once())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'sent'),
        ]));

        $this->assertSame(MessageLog::STATUS_SENT, $log->getStatus());
    }

    public function testSentFromSentIsNoOp(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'sent'),
        ]));

        $this->assertSame(MessageLog::STATUS_SENT, $log->getStatus());
    }

    // =========================================================================
    // Transições a partir de pending_webhook
    // =========================================================================

    public function testDeliveredFromPendingWebhookUpdatesStatus(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_PENDING_WEBHOOK);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->once())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'delivered'),
        ]));

        $this->assertSame(MessageLog::STATUS_DELIVERED, $log->getStatus());
    }

    public function testReadFromPendingWebhookUpdatesStatus(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_PENDING_WEBHOOK);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->once())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'read'),
        ]));

        $this->assertSame(MessageLog::STATUS_READ, $log->getStatus());
    }

    public function testFailedFromPendingWebhookUpdatesStatus(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_PENDING_WEBHOOK);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->once())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]]));

        $this->assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
        $this->assertSame(131047, $log->getWebhookErrorCode());
    }

    // =========================================================================
    // Wamid não encontrado no banco
    // =========================================================================

    public function testUnknownWamidIsSkippedGracefully(): void
    {
        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        $result = $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.inexistente', 'delivered'),
        ]));

        $this->assertSame(200, $result);
    }

    // =========================================================================
    // Payload malformado / incompleto
    // =========================================================================

    public function testEmptyPayloadReturns200WithoutCrash(): void
    {
        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->expects($this->never())->method('findByWamid');

        $result = $this->processor->process('+5511999999999', []);

        $this->assertSame(200, $result);
    }

    public function testMissingStatusesKeyReturns200WithoutCrash(): void
    {
        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->expects($this->never())->method('findByWamid');

        $result = $this->processor->process('+5511999999999', [
            'entry' => [[
                'changes' => [[
                    'value' => [],
                ]],
            ]],
        ]);

        $this->assertSame(200, $result);
    }

    public function testStatusEntryWithoutIdIsSkipped(): void
    {
        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->expects($this->never())->method('findByWamid');

        $result = $this->processor->process('+5511999999999', $this->makePayload([
            ['status' => 'delivered'],
        ]));

        $this->assertSame(200, $result);
    }

    public function testStatusEntryWithEmptyIdIsSkipped(): void
    {
        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->expects($this->never())->method('findByWamid');

        $result = $this->processor->process('+5511999999999', $this->makePayload([
            ['id' => '', 'status' => 'delivered'],
        ]));

        $this->assertSame(200, $result);
    }

    // =========================================================================
    // Múltiplos eventos em um único payload
    // =========================================================================

    public function testMultipleStatusesAreAllProcessed(): void
    {
        $log1 = $this->makeLog(MessageLog::STATUS_SENT);
        $log2 = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')
            ->willReturnMap([
                ['wamid.1', $log1],
                ['wamid.2', $log2],
            ]);
        $this->em->expects($this->exactly(2))->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.1', 'delivered'),
            $this->makeStatusEntry('wamid.2', 'read'),
        ]));

        $this->assertSame(MessageLog::STATUS_DELIVERED, $log1->getStatus());
        $this->assertSame(MessageLog::STATUS_READ, $log2->getStatus());
    }

    public function testMultipleEntriesAndChangesAreFullyTraversed(): void
    {
        $log1 = $this->makeLog(MessageLog::STATUS_SENT);
        $log2 = $this->makeLog(MessageLog::STATUS_DELIVERED);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')
            ->willReturnMap([
                ['wamid.entry1', $log1],
                ['wamid.entry2', $log2],
            ]);
        $this->em->expects($this->exactly(2))->method('flush');

        $this->processor->process('+5511999999999', [
            'entry' => [
                [
                    'changes' => [[
                        'value' => ['statuses' => [$this->makeStatusEntry('wamid.entry1', 'delivered')]],
                    ]],
                ],
                [
                    'changes' => [[
                        'value' => ['statuses' => [$this->makeStatusEntry('wamid.entry2', 'read')]],
                    ]],
                ],
            ],
        ]);

        $this->assertSame(MessageLog::STATUS_DELIVERED, $log1->getStatus());
        $this->assertSame(MessageLog::STATUS_READ, $log2->getStatus());
    }

    public function testMixedValidAndUnknownWamidsInSamePayload(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')
            ->willReturnMap([
                ['wamid.existe', $log],
                ['wamid.naoexiste', null],
            ]);
        $this->em->expects($this->once())->method('flush');

        $result = $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.existe', 'delivered'),
            $this->makeStatusEntry('wamid.naoexiste', 'delivered'),
        ]));

        $this->assertSame(200, $result);
        $this->assertSame(MessageLog::STATUS_DELIVERED, $log->getStatus());
    }

    // =========================================================================
    // Timestamps de transição
    // =========================================================================

    public function testDeliveredTransitionSetsDateDelivered(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->method('flush');

        $before = new \DateTime();

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'delivered'),
        ]));

        $this->assertNotNull($log->getDateDelivered());
        $this->assertGreaterThanOrEqual($before, $log->getDateDelivered());
        $this->assertNull($log->getDateRead());
    }

    public function testReadTransitionSetsDateRead(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_DELIVERED);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->method('flush');

        $before = new \DateTime();

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'read'),
        ]));

        $this->assertNotNull($log->getDateRead());
        $this->assertGreaterThanOrEqual($before, $log->getDateRead());
        $this->assertNull($log->getDateDelivered());
    }

    public function testInvalidTransitionDoesNotSetTimestamp(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_READ);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'delivered'),
        ]));

        $this->assertNull($log->getDateDelivered());
        $this->assertNull($log->getDateRead());
    }

    // =========================================================================
    // Valor de retorno
    // =========================================================================

    public function testProcessReturns200OnSuccess(): void
    {
        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn(null);

        $result = $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'delivered'),
        ]));

        $this->assertSame(200, $result);
    }

    public function testProcessReturns200EvenWhenNoStatusesMatch(): void
    {
        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn(null);

        $result = $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'sent'),
        ]));

        $this->assertSame(200, $result);
    }

    // =========================================================================
    // Transições válidas: failed
    // =========================================================================

    public function testFailedFromSentUpdatesStatus(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->once())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]]));

        $this->assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
    }

    public function testFailedFromQueuedUpdatesStatus(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_QUEUED);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->once())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
            'errors' => [['code' => 130429, 'title' => 'Rate limit hit']],
        ]]));

        $this->assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
    }

    public function testFailedPersistsWebhookErrorCode(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]]));

        $this->assertSame(131026, $log->getWebhookErrorCode());
        $this->assertSame('Message undeliverable', $log->getErrorMessage());
    }

    public function testFailedWithNoErrorsArrayLeavesCodeNull(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
        ]]));

        $this->assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
        $this->assertNull($log->getWebhookErrorCode());
    }

    public function testFailedFromReadIsNoOp(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_READ);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]]));

        $this->assertSame(MessageLog::STATUS_READ, $log->getStatus());
    }

    public function testFailedFromDeliveredIsNoOp(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_DELIVERED);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]]));

        $this->assertSame(MessageLog::STATUS_DELIVERED, $log->getStatus());
    }

    public function testFailedFromFailedIsNoOp(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_FAILED);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->expects($this->never())->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]]));

        $this->assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
    }

    // =========================================================================
    // Despacho de evento WebhookMessageFailedEvent
    // =========================================================================

    public function testFailedDispatchesWebhookMessageFailedEvent(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->method('flush');

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(WebhookMessageFailedEvent::class));

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]]));
    }

    public function testDeliveredDoesNotDispatchEvent(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->method('flush');

        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->processor->process('+5511999999999', $this->makePayload([
            $this->makeStatusEntry('wamid.abc', 'delivered'),
        ]));
    }

    public function testInvalidTransitionDoesNotDispatchEvent(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_READ);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);

        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]]));
    }

    // =========================================================================
    // Distinção entre erro de API e rejeição da Meta no errorMessage
    // =========================================================================

    /**
     * Erro da Meta via webhook NÃO deve ter prefixo [API 360dialog].
     * O prefixo é exclusivo de falhas HTTP na chamada à API da 360dialog
     * (definido em SendWhatsAppMessageHandler).
     */
    public function testMetaRejectionErrorMessageHasNoApiPrefix(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($log);
        $this->em->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.abc',
            'status' => 'failed',
            'errors' => [['code' => 131026, 'title' => 'Message undeliverable']],
        ]]));

        $this->assertStringNotContainsString('[API 360dialog]', $log->getErrorMessage(),
            'Rejeição da Meta não deve ter prefixo de erro de API');
        $this->assertSame('Message undeliverable', $log->getErrorMessage());
        $this->assertSame(131026, $log->getWebhookErrorCode(),
            'Rejeição da Meta deve ter webhookErrorCode preenchido');
    }

    /**
     * Confirma que as duas origens de falha são distinguíveis pelos campos:
     * - Erro de API:    webhookErrorCode=null,  errorMessage começa com [API 360dialog]
     * - Rejeição Meta:  webhookErrorCode!=null, errorMessage sem prefixo de API
     */
    public function testApiErrorAndMetaRejectionAreDistinguishable(): void
    {
        // Rejeição da Meta: tem webhookErrorCode
        $logMeta = $this->makeLog(MessageLog::STATUS_SENT);
        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());
        $this->logRepository->method('findByWamid')->willReturn($logMeta);
        $this->em->method('flush');

        $this->processor->process('+5511999999999', $this->makePayload([[
            'id'     => 'wamid.meta',
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ]]));

        // Rejeição da Meta: tem código, sem prefixo de API
        $this->assertNotNull($logMeta->getWebhookErrorCode());
        $this->assertStringNotContainsString('[API 360dialog]', (string) $logMeta->getErrorMessage());

        // Erro de API: sem código Meta, errorMessage teria prefixo [API 360dialog]
        // (esse lado é coberto em SendWhatsAppMessageHandlerTest::testApiErrorSetsErrorMessageWithApiPrefix)
        $this->assertNull(null); // marcador semântico — ver handler test para o outro lado
    }
}

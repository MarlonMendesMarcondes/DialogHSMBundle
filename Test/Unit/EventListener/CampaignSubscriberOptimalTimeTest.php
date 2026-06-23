<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\Executioner\Scheduler\EventScheduler;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Service\OptimalTimeResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Testa o mecanismo de agendamento via OptimalTimeResolver no CampaignSubscriber.
 *
 * Cenários cobertos:
 *  1. Primeira execução + optimal time + futuro  → reschedule(), sem envio
 *  2. Primeira execução + optimal time + passado → envia imediatamente, sem reschedule()
 *  3. Re-entrada (log STATUS_OPTIMAL_TIME_SCHEDULED) → envia, reschedule() NÃO chamado novamente
 *  4. Sem optimal time                           → comportamento normal, reschedule() nunca chamado
 *  5. Redis + optimal time                       → reschedule(), items vazio, batch NÃO despachado
 *  6. Queue + optimal time                       → reschedule(), bus NÃO chamado
 *  7. Múltiplos contatos                         → cada um agendado individualmente
 *  8. Inline + optimal time                      → reschedule() funciona igual (mecanismo nativo)
 *  9. restrict_business_hours=true               → resolve() chamado com flag=true, horário respeitado
 * 10. restrict_business_hours=false              → resolve() chamado com flag=false
 */
class CampaignSubscriberOptimalTimeTest extends TestCase
{
    private IntegrationsHelper&MockObject $mockIntegrationsHelper;
    private MessageBusInterface&MockObject $mockBus;
    private LoggerInterface&MockObject $mockLogger;
    private WhatsAppNumberModel&MockObject $mockNumberModel;
    private SendWhatsAppMessageHandler&MockObject $mockHandler;
    private EntityManagerInterface&MockObject $mockEntityManager;
    private SendWhatsAppDirectBatchMessageHandler&MockObject $mockDirectBatchHandler;
    private MessageLogRepository&MockObject $mockMessageLogRepository;
    private OptimalTimeResolver&MockObject $mockOptimalTimeResolver;
    private EventScheduler&MockObject $mockEventScheduler;

    protected function setUp(): void
    {
        $this->mockIntegrationsHelper   = $this->createMock(IntegrationsHelper::class);
        $this->mockBus                  = $this->createMock(MessageBusInterface::class);
        $this->mockLogger               = $this->createMock(LoggerInterface::class);
        $this->mockNumberModel          = $this->createMock(WhatsAppNumberModel::class);
        $this->mockHandler              = $this->createMock(SendWhatsAppMessageHandler::class);
        $this->mockEntityManager        = $this->createMock(EntityManagerInterface::class);
        $this->mockDirectBatchHandler   = $this->createMock(SendWhatsAppDirectBatchMessageHandler::class);
        $this->mockMessageLogRepository = $this->createMock(MessageLogRepository::class);
        $this->mockOptimalTimeResolver  = $this->createMock(OptimalTimeResolver::class);
        $this->mockEventScheduler       = $this->createMock(EventScheduler::class);

        $this->mockMessageLogRepository
            ->method('findByCampaignEventAndLead')
            ->willReturn(null);
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    private function makeSubscriber(string $directTransportDsn = 'null://null'): CampaignSubscriber
    {
        return new CampaignSubscriber(
            $this->mockIntegrationsHelper,
            $this->mockBus,
            $this->mockLogger,
            $this->mockNumberModel,
            $this->mockHandler,
            $this->mockEntityManager,
            $this->mockDirectBatchHandler,
            $this->mockMessageLogRepository,
            $this->mockOptimalTimeResolver,
            $this->mockEventScheduler,
            $directTransportDsn,
        );
    }

    private function makeIntegration(): object
    {
        $config = new class {
            public function getIsPublished(): bool { return true; }
            public function getApiKeys(): array { return ['base_url' => '']; }
        };

        return new class($config) {
            public function __construct(private $c) {}
            public function getIntegrationConfiguration() { return $this->c; }
        };
    }

    private function makeWhatsAppNumber(
        string $apiKey = 'VALID_KEY',
        string $queueName = 'whatsapp_bulk',
        ?string $batchQueueName = 'whatsapp_batch',
    ): WhatsAppNumber&MockObject {
        $mock = $this->createMock(WhatsAppNumber::class);
        $mock->method('getApiKey')->willReturn($apiKey);
        $mock->method('getBaseUrl')->willReturn('https://api.360dialog.com/v1/messages');
        $mock->method('getIsPublished')->willReturn(true);
        $mock->method('getQueueName')->willReturn($queueName);
        $mock->method('getBatchQueueName')->willReturn($batchQueueName);
        $mock->method('getName')->willReturn('Número Teste');

        return $mock;
    }

    private function makeContact(string $phone = '+5511999999999', int $id = 1): Lead&MockObject
    {
        $mock = $this->createMock(Lead::class);
        $mock->method('getLeadPhoneNumber')->willReturn($phone);
        $mock->method('getId')->willReturn($id);
        $mock->method('getProfileFields')->willReturn([]);

        return $mock;
    }

    /**
     * @param \DateTime|null $logTriggerDate null = primeira execução, DateTime = segunda execução
     */
    private function buildPendingEvent(
        string $context,
        array $contacts,
        array $configOverrides = [],
        ?\DateTime $logTriggerDate = null,
    ): PendingEvent&MockObject {
        $defaultConfig = [
            'whatsapp_number'  => 1,
            'payload_data'     => ['list' => [['label' => 'content', 'value' => 'template_teste']]],
            'send_delay'       => 0,
            'batch_limit'      => 0,
            'use_optimal_time' => false,
        ];

        $mockCampaign = $this->createMock(Campaign::class);
        $mockCampaign->method('getId')->willReturn(10);

        $mockCampaignEvent = $this->createMock(CampaignEvent::class);
        $mockCampaignEvent->method('getProperties')->willReturn(array_merge($defaultConfig, $configOverrides));
        $mockCampaignEvent->method('getId')->willReturn(20);
        $mockCampaignEvent->method('getCampaign')->willReturn($mockCampaign);

        $mockLog = $this->createMock(LeadEventLog::class);
        $mockLog->method('getTriggerDate')->willReturn($logTriggerDate);

        $mockPending = $this->createMock(ArrayCollection::class);
        $mockPending->method('get')->willReturn($mockLog);

        $event = $this->createMock(PendingEvent::class);
        $event->method('checkContext')->with($context)->willReturn(true);
        $event->method('getEvent')->willReturn($mockCampaignEvent);
        $event->method('getContacts')->willReturn($contacts);
        $event->method('getPending')->willReturn($mockPending);

        return $event;
    }

    // =========================================================================
    // Cenário 1: Primeira execução + optimal time + horário futuro → reschedule
    // =========================================================================

    public function testFirstExecutionOptimalTimeFutureReschedulesAndDoesNotSend(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $optimalTime = new \DateTime('+1 hour');
        $this->mockOptimalTimeResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn($optimalTime);

        $rescheduled = null;
        $this->mockEventScheduler
            ->expects($this->once())
            ->method('reschedule')
            ->willReturnCallback(function ($log, \DateTimeInterface $date) use (&$rescheduled): void {
                $rescheduled = $date;
            });

        $contact = $this->makeContact('+5511999999999', 1);
        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true],
            null,
        );

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');
        $this->mockBus->expects($this->never())->method('dispatch');

        $this->makeSubscriber()->onCampaignTriggerAction($event);

        // Valida que o horário passado ao reschedule é exatamente o que o resolver retornou
        $this->assertSame(
            $optimalTime->format('Y-m-d H:i:s'),
            $rescheduled->format('Y-m-d H:i:s'),
            'O horário agendado deve ser exatamente o que o OptimalTimeResolver retornou'
        );
    }

    // =========================================================================
    // Cenário 2: Primeira execução + optimal time + horário passado → envia imediatamente
    // =========================================================================

    public function testFirstExecutionOptimalTimePastSendsImmediately(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $this->mockOptimalTimeResolver
            ->expects($this->once())
            ->method('resolve')
            ->willReturn(new \DateTime('-1 hour'));

        $this->mockEventScheduler->expects($this->never())->method('reschedule');

        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppDirectBatchMessage::class));

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true],
            null,
        );

        $this->makeSubscriber()->onCampaignTriggerAction($event);
    }

    // =========================================================================
    // Cenário 3: Re-entrada com STATUS_OPTIMAL_TIME_SCHEDULED → envia, sem reschedule
    // (corrige slip-forward: o marcador persistente impede recálculo do horário ideal)
    // =========================================================================

    public function testReentryWithOptimalTimeScheduledLogSendsWithoutReschedule(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        // Simula re-entrada: log com STATUS_OPTIMAL_TIME_SCHEDULED existe no banco
        $scheduledMarker = $this->createMock(MessageLog::class);
        $scheduledMarker->method('getStatus')->willReturn(MessageLog::STATUS_OPTIMAL_TIME_SCHEDULED);

        $freshRepo = $this->createMock(MessageLogRepository::class);
        $freshRepo->method('findByCampaignEventAndLead')->willReturn($scheduledMarker);
        $this->mockMessageLogRepository = $freshRepo;

        // Resolver e reschedule NÃO devem ser chamados — marcador já indica que foi agendado
        $this->mockOptimalTimeResolver->expects($this->never())->method('resolve');
        $this->mockEventScheduler->expects($this->never())->method('reschedule');

        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppDirectBatchMessage::class));

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true],
        );

        $this->makeSubscriber()->onCampaignTriggerAction($event);
    }

    // =========================================================================
    // Cenário 4: Sem optimal time → comportamento normal, reschedule nunca chamado
    // =========================================================================

    public function testWithoutOptimalTimeNeverCallsReschedule(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $this->mockOptimalTimeResolver->expects($this->never())->method('resolve');
        $this->mockEventScheduler->expects($this->never())->method('reschedule');

        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppDirectBatchMessage::class));

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => false],
        );

        $this->makeSubscriber()->onCampaignTriggerAction($event);
    }

    // =========================================================================
    // Cenário 5: Redis + optimal time → reschedule, batch NÃO despachado
    // =========================================================================

    public function testRedisOptimalTimeReschedulesAndDoesNotDispatchBatch(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $optimalTime = new \DateTime('+2 hours');
        $this->mockOptimalTimeResolver->method('resolve')->willReturn($optimalTime);

        $rescheduled = null;
        $this->mockEventScheduler
            ->expects($this->once())
            ->method('reschedule')
            ->willReturnCallback(function ($log, \DateTimeInterface $date) use (&$rescheduled): void {
                $rescheduled = $date;
            });

        $this->mockBus->expects($this->never())->method('dispatch');

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true],
            null,
        );

        $this->makeSubscriber('redis://localhost:6379')->onCampaignTriggerAction($event);

        $this->assertSame(
            $optimalTime->format('Y-m-d H:i:s'),
            $rescheduled->format('Y-m-d H:i:s'),
            'Horário agendado deve corresponder ao retorno do resolver'
        );
    }

    // =========================================================================
    // Cenário 6: Queue (AMQP) + optimal time → reschedule, bus NÃO chamado
    // =========================================================================

    public function testQueueOptimalTimeReschedulesAndDoesNotDispatchToQueue(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $optimalTime = new \DateTime('+3 hours');
        $this->mockOptimalTimeResolver->method('resolve')->willReturn($optimalTime);

        $rescheduled = null;
        $this->mockEventScheduler
            ->expects($this->once())
            ->method('reschedule')
            ->willReturnCallback(function ($log, \DateTimeInterface $date) use (&$rescheduled): void {
                $rescheduled = $date;
            });

        $this->mockBus->expects($this->never())->method('dispatch');

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            [1 => $contact],
            ['queue_override' => 'bulk', 'use_optimal_time' => true],
            null,
        );

        $this->makeSubscriber()->onCampaignTriggerActionQueue($event);

        $this->assertSame(
            $optimalTime->format('Y-m-d H:i:s'),
            $rescheduled->format('Y-m-d H:i:s'),
            'Horário agendado deve corresponder ao retorno do resolver'
        );
    }

    // =========================================================================
    // Cenário 7: Múltiplos contatos → cada um agendado individualmente
    // =========================================================================

    public function testMultipleContactsEachRescheduledIndividually(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $time1 = new \DateTime('+1 hour');
        $time2 = new \DateTime('+2 hours');
        $time3 = new \DateTime('+3 hours');

        $this->mockOptimalTimeResolver
            ->expects($this->exactly(3))
            ->method('resolve')
            ->willReturnOnConsecutiveCalls($time1, $time2, $time3);

        $rescheduledTimes = [];
        $this->mockEventScheduler
            ->expects($this->exactly(3))
            ->method('reschedule')
            ->willReturnCallback(function ($log, \DateTimeInterface $date) use (&$rescheduledTimes): void {
                $rescheduledTimes[] = $date->format('Y-m-d H:i:s');
            });

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');
        $this->mockBus->expects($this->never())->method('dispatch');

        $contacts = [
            1 => $this->makeContact('+5511999999991', 1),
            2 => $this->makeContact('+5511999999992', 2),
            3 => $this->makeContact('+5511999999993', 3),
        ];

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            $contacts,
            ['use_optimal_time' => true],
            null,
        );

        $this->makeSubscriber()->onCampaignTriggerAction($event);

        // Valida que cada contato foi agendado no horário correto
        $this->assertSame($time1->format('Y-m-d H:i:s'), $rescheduledTimes[0], 'Contato 1');
        $this->assertSame($time2->format('Y-m-d H:i:s'), $rescheduledTimes[1], 'Contato 2');
        $this->assertSame($time3->format('Y-m-d H:i:s'), $rescheduledTimes[2], 'Contato 3');
    }

    // =========================================================================
    // Cenário 8: Inline + optimal time → reschedule funciona igual ao Redis
    // =========================================================================

    public function testInlineOptimalTimeAlsoReschedulesViaNativeMechanism(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $optimalTime = new \DateTime('+30 minutes');
        $this->mockOptimalTimeResolver->method('resolve')->willReturn($optimalTime);

        $rescheduled = null;
        $this->mockEventScheduler
            ->expects($this->once())
            ->method('reschedule')
            ->willReturnCallback(function ($log, \DateTimeInterface $date) use (&$rescheduled): void {
                $rescheduled = $date;
            });

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true],
            null,
        );

        $this->makeSubscriber('null://null')->onCampaignTriggerAction($event);

        $this->assertSame(
            $optimalTime->format('Y-m-d H:i:s'),
            $rescheduled->format('Y-m-d H:i:s')
        );
    }

    // =========================================================================
    // Cenário 9: restrict_business_hours=true → resolve() chamado com flag=true
    // e horário retornado (já ajustado pelo resolver) é passado ao reschedule
    // =========================================================================

    public function testRestrictBusinessHoursTruePassesFlagToResolver(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        // O resolver já retorna um horário ajustado (segunda às 8h)
        $mondayAt8 = new \DateTime('next Monday 08:00:00');
        $this->mockOptimalTimeResolver
            ->expects($this->once())
            ->method('resolve')
            ->with(
                $this->isInstanceOf(Lead::class),
                true  // ← flag restrict_business_hours deve ser true
            )
            ->willReturn($mondayAt8);

        $rescheduled = null;
        $this->mockEventScheduler
            ->expects($this->once())
            ->method('reschedule')
            ->willReturnCallback(function ($log, \DateTimeInterface $date) use (&$rescheduled): void {
                $rescheduled = $date;
            });

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true, 'restrict_business_hours' => true],
        );

        $this->makeSubscriber()->onCampaignTriggerAction($event);

        $this->assertSame(
            $mondayAt8->format('Y-m-d H:i:s'),
            $rescheduled->format('Y-m-d H:i:s'),
            'O horário agendado deve ser o que o resolver retornou (segunda 8h)'
        );
        $this->assertSame('8', $rescheduled->format('G'),  'Deve ser às 8h');
        $this->assertSame('1', $rescheduled->format('N'),  'Deve ser segunda (ISO 1)');
    }

    // =========================================================================
    // Cenário 10: restrict_business_hours=false → resolve() chamado com flag=false
    // =========================================================================

    public function testRestrictBusinessHoursFalsePassesFlagToResolver(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $saturdayNight = new \DateTime('next Saturday 21:00:00');
        $this->mockOptimalTimeResolver
            ->expects($this->once())
            ->method('resolve')
            ->with(
                $this->isInstanceOf(Lead::class),
                false  // ← flag deve ser false
            )
            ->willReturn($saturdayNight);

        $rescheduled = null;
        $this->mockEventScheduler
            ->method('reschedule')
            ->willReturnCallback(function ($log, \DateTimeInterface $date) use (&$rescheduled): void {
                $rescheduled = $date;
            });

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true, 'restrict_business_hours' => false],
        );

        $this->makeSubscriber()->onCampaignTriggerAction($event);

        $this->assertSame(
            $saturdayNight->format('Y-m-d H:i:s'),
            $rescheduled->format('Y-m-d H:i:s'),
            'Sem restrict: horário deve ser mantido exatamente como o resolver retornou'
        );
    }

    // =========================================================================
    // Fix: pass() deve ser chamado ANTES de reschedule()
    // =========================================================================

    public function testOptimalTimeRescheduleCallsPassBeforeReschedule(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $optimalTime = new \DateTime('+1 hour');
        $this->mockOptimalTimeResolver->method('resolve')->willReturn($optimalTime);

        $callOrder = [];

        $mockLog = $this->createMock(LeadEventLog::class);
        $mockLog->method('getTriggerDate')->willReturn(null);

        $mockPending = $this->createMock(ArrayCollection::class);
        $mockPending->method('get')->willReturn($mockLog);

        $mockCampaign = $this->createMock(Campaign::class);
        $mockCampaign->method('getId')->willReturn(10);
        $mockCampaignEvent = $this->createMock(CampaignEvent::class);
        $mockCampaignEvent->method('getProperties')->willReturn([
            'whatsapp_number'  => 1,
            'payload_data'     => ['list' => [['label' => 'content', 'value' => 'tpl']]],
            'send_delay'       => 0,
            'batch_limit'      => 0,
            'use_optimal_time' => true,
        ]);
        $mockCampaignEvent->method('getId')->willReturn(20);
        $mockCampaignEvent->method('getCampaign')->willReturn($mockCampaign);

        $contact = $this->makeContact('+5511999999999', 1);

        $event = $this->createMock(PendingEvent::class);
        $event->method('checkContext')->with('dialoghsm.send_whatsapp')->willReturn(true);
        $event->method('getEvent')->willReturn($mockCampaignEvent);
        $event->method('getContacts')->willReturn([1 => $contact]);
        $event->method('getPending')->willReturn($mockPending);
        $event->expects($this->once())
            ->method('pass')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'pass';
            });

        $this->mockEventScheduler
            ->expects($this->once())
            ->method('reschedule')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'reschedule';
            });

        $this->makeSubscriber()->onCampaignTriggerAction($event);

        $this->assertSame(['pass', 'reschedule'], $callOrder,
            'pass() deve ser chamado ANTES de reschedule() para evitar LogNotProcessedException'
        );
    }

    // =========================================================================
    // Regressão: existingLog presente → resolveFromWebhookLog, resolver NÃO chamado
    // =========================================================================

    public function testExistingMessageLogWithOptimalTimeSkipsResolver(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $existingLog = $this->createMock(MessageLog::class);
        $existingLog->method('getStatus')->willReturn(MessageLog::STATUS_PENDING_WEBHOOK);
        $existingLog->method('getDateSent')->willReturn(new \DateTime());

        $freshRepo = $this->createMock(MessageLogRepository::class);
        $freshRepo->method('findByCampaignEventAndLead')->willReturn($existingLog);
        $this->mockMessageLogRepository = $freshRepo;

        $this->mockOptimalTimeResolver->expects($this->never())->method('resolve');
        $this->mockEventScheduler->expects($this->never())->method('reschedule');
        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true],
            null,
        );

        $this->makeSubscriber()->onCampaignTriggerAction($event);
    }

    // =========================================================================
    // Regressão: Queue sem optimal time → dispatch normal (AmqpStamp)
    // =========================================================================

    public function testQueueWithoutOptimalTimeDispatchesNormally(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $this->mockOptimalTimeResolver->expects($this->never())->method('resolve');
        $this->mockEventScheduler->expects($this->never())->method('reschedule');

        $capturedStamps = [];
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg, array $stamps) use (&$capturedStamps): Envelope {
                $capturedStamps = $stamps;

                return new Envelope($msg);
            });

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            [1 => $contact],
            ['queue_override' => 'bulk', 'use_optimal_time' => false],
        );

        $this->makeSubscriber()->onCampaignTriggerActionQueue($event);

        $amqpStamps = array_filter($capturedStamps, fn ($s) => $s instanceof AmqpStamp);
        $this->assertCount(1, $amqpStamps, 'Sem optimal time: apenas AmqpStamp');
    }

    // =========================================================================
    // Regressão: Redis sem optimal time → SendWhatsAppDirectBatchMessage
    // =========================================================================

    public function testRedisWithoutOptimalTimeDispatchesBatchMessage(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $this->mockOptimalTimeResolver->expects($this->never())->method('resolve');
        $this->mockEventScheduler->expects($this->never())->method('reschedule');

        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SendWhatsAppDirectBatchMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => false],
        );

        $this->makeSubscriber('redis://localhost:6379')->onCampaignTriggerAction($event);
    }
}

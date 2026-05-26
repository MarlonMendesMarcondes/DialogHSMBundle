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
use Mautic\LeadBundle\Services\PeakInteractionTimer;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Testa o mecanismo nativo de agendamento (EventScheduler::reschedule) para use_optimal_time.
 *
 * Cenários cobertos:
 *  1. Primeira execução + optimal time + futuro  → reschedule(), sem envio
 *  2. Primeira execução + optimal time + passado → envia imediatamente, sem reschedule()
 *  3. Segunda execução (trigger_date definido)   → envia, reschedule() NÃO chamado novamente
 *  4. Sem optimal time                           → comportamento normal, reschedule() nunca chamado
 *  5. Redis + optimal time                       → reschedule(), items vazio, batch NÃO despachado
 *  6. Queue + optimal time                       → reschedule(), bus NÃO chamado
 *  7. Múltiplos contatos                         → cada um agendado individualmente
 *  8. Inline + optimal time                      → reschedule() funciona igual (mecanismo nativo)
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
    private PeakInteractionTimer&MockObject $mockPeakInteractionTimer;
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
        $this->mockPeakInteractionTimer = $this->createMock(PeakInteractionTimer::class);
        $this->mockEventScheduler       = $this->createMock(EventScheduler::class);

        $this->mockMessageLogRepository
            ->method('findByCampaignEventAndLead')
            ->willReturn(null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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
            $this->mockPeakInteractionTimer,
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
     * Constrói um PendingEvent mockado.
     *
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

    // -------------------------------------------------------------------------
    // Cenário 1: Primeira execução + optimal time + horário futuro → reschedule
    // -------------------------------------------------------------------------

    public function testFirstExecutionOptimalTimeFutureReschedulesAndDoesNotSend(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $optimalTime = new \DateTime('+1 hour');
        $this->mockPeakInteractionTimer
            ->expects($this->once())
            ->method('getOptimalTimeAndDay')
            ->willReturn($optimalTime);

        $this->mockEventScheduler
            ->expects($this->once())
            ->method('reschedule')
            ->with(
                $this->isInstanceOf(LeadEventLog::class),
                $this->equalTo($optimalTime)
            );

        $contact = $this->makeContact('+5511999999999', 1);
        // trigger_date = null → primeira execução
        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true],
            null,
        );

        // Nenhuma mensagem deve ser enviada
        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');
        $this->mockBus->expects($this->never())->method('dispatch');

        $this->makeSubscriber()->onCampaignTriggerAction($event);
    }

    // -------------------------------------------------------------------------
    // Cenário 2: Primeira execução + optimal time + horário passado → envia imediatamente
    // -------------------------------------------------------------------------

    public function testFirstExecutionOptimalTimePastSendsImmediately(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        // Horário ideal já passou → deve enviar imediatamente
        $pastTime = new \DateTime('-1 hour');
        $this->mockPeakInteractionTimer
            ->expects($this->once())
            ->method('getOptimalTimeAndDay')
            ->willReturn($pastTime);

        // reschedule NÃO deve ser chamado
        $this->mockEventScheduler->expects($this->never())->method('reschedule');

        // directBatchHandler deve ser chamado (envio imediato)
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

    // -------------------------------------------------------------------------
    // Cenário 3: Segunda execução (trigger_date definido) → envia, sem reschedule
    // -------------------------------------------------------------------------

    public function testSecondExecutionWithTriggerDateSendsWithoutReschedule(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        // PeakInteractionTimer NÃO deve ser consultado na segunda execução
        $this->mockPeakInteractionTimer->expects($this->never())->method('getOptimalTimeAndDay');

        // reschedule NÃO deve ser chamado
        $this->mockEventScheduler->expects($this->never())->method('reschedule');

        // directBatchHandler deve ser chamado (é a segunda execução, envia normalmente)
        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppDirectBatchMessage::class));

        $contact = $this->makeContact('+5511999999999', 1);
        // trigger_date definido → segunda execução
        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true],
            new \DateTime('-1 minute'),
        );

        $this->makeSubscriber()->onCampaignTriggerAction($event);
    }

    // -------------------------------------------------------------------------
    // Cenário 4: Sem optimal time → comportamento normal, reschedule nunca chamado
    // -------------------------------------------------------------------------

    public function testWithoutOptimalTimeNeverCallsReschedule(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        // PeakInteractionTimer e reschedule nunca devem ser chamados
        $this->mockPeakInteractionTimer->expects($this->never())->method('getOptimalTimeAndDay');
        $this->mockEventScheduler->expects($this->never())->method('reschedule');

        // directBatchHandler deve ser chamado normalmente
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

    // -------------------------------------------------------------------------
    // Cenário 5: Redis + optimal time → reschedule, items vazio, batch NÃO despachado
    // -------------------------------------------------------------------------

    public function testRedisOptimalTimeReschedulesAndDoesNotDispatchBatch(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $optimalTime = new \DateTime('+2 hours');
        $this->mockPeakInteractionTimer
            ->expects($this->once())
            ->method('getOptimalTimeAndDay')
            ->willReturn($optimalTime);

        $this->mockEventScheduler
            ->expects($this->once())
            ->method('reschedule');

        // Bus NÃO deve despachar nenhuma mensagem (items vazio, contato agendado)
        $this->mockBus->expects($this->never())->method('dispatch');

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true],
            null,
        );

        $this->makeSubscriber('redis://localhost:6379')->onCampaignTriggerAction($event);
    }

    // -------------------------------------------------------------------------
    // Cenário 6: Queue (AMQP) + optimal time → reschedule, bus NÃO chamado
    // -------------------------------------------------------------------------

    public function testQueueOptimalTimeReschedulesAndDoesNotDispatchToQueue(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $optimalTime = new \DateTime('+3 hours');
        $this->mockPeakInteractionTimer
            ->expects($this->once())
            ->method('getOptimalTimeAndDay')
            ->willReturn($optimalTime);

        $this->mockEventScheduler
            ->expects($this->once())
            ->method('reschedule')
            ->with(
                $this->isInstanceOf(LeadEventLog::class),
                $this->equalTo($optimalTime)
            );

        // Bus NÃO deve ser chamado
        $this->mockBus->expects($this->never())->method('dispatch');

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            [1 => $contact],
            ['queue_override' => 'bulk', 'use_optimal_time' => true],
            null,
        );

        $this->makeSubscriber()->onCampaignTriggerActionQueue($event);
    }

    // -------------------------------------------------------------------------
    // Cenário 7: Múltiplos contatos → cada um agendado individualmente
    // -------------------------------------------------------------------------

    public function testMultipleContactsEachRescheduledIndividually(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $time1 = new \DateTime('+1 hour');
        $time2 = new \DateTime('+2 hours');
        $time3 = new \DateTime('+3 hours');

        $this->mockPeakInteractionTimer
            ->expects($this->exactly(3))
            ->method('getOptimalTimeAndDay')
            ->willReturnOnConsecutiveCalls($time1, $time2, $time3);

        $this->mockEventScheduler
            ->expects($this->exactly(3))
            ->method('reschedule');

        // Nenhuma mensagem enviada (todos agendados)
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
    }

    // -------------------------------------------------------------------------
    // Cenário 8: Inline + optimal time → reschedule funciona igual (mecanismo nativo)
    // -------------------------------------------------------------------------

    public function testInlineOptimalTimeAlsoReschedulesViaNativeMechanism(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $optimalTime = new \DateTime('+30 minutes');
        $this->mockPeakInteractionTimer
            ->expects($this->once())
            ->method('getOptimalTimeAndDay')
            ->willReturn($optimalTime);

        $this->mockEventScheduler
            ->expects($this->once())
            ->method('reschedule')
            ->with(
                $this->isInstanceOf(LeadEventLog::class),
                $this->equalTo($optimalTime)
            );

        // directBatchHandler NÃO deve ser chamado (contato foi agendado)
        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');
        $this->mockBus->expects($this->never())->method('dispatch');

        $contact = $this->makeContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['use_optimal_time' => true],
            null,
        );

        // null://null = modo inline
        $this->makeSubscriber('null://null')->onCampaignTriggerAction($event);
    }

    // -------------------------------------------------------------------------
    // Regressão: Queue sem optimal time → comportamento original (só AmqpStamp)
    // -------------------------------------------------------------------------

    public function testQueueWithoutOptimalTimeDispatchesNormally(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $this->mockPeakInteractionTimer->expects($this->never())->method('getOptimalTimeAndDay');
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

    // -------------------------------------------------------------------------
    // Regressão: Redis sem optimal time → SendWhatsAppDirectBatchMessage (batch)
    // -------------------------------------------------------------------------

    public function testRedisWithoutOptimalTimeDispatchesBatchMessage(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        $this->mockPeakInteractionTimer->expects($this->never())->method('getOptimalTimeAndDay');
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

    // -------------------------------------------------------------------------
    // Regressão: existingLog presente + optimal time → resolveFromWebhookLog, sem reschedule
    // -------------------------------------------------------------------------

    public function testExistingMessageLogWithOptimalTimeSkipsReschedule(): void
    {
        $this->mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());
        $this->mockNumberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        // Simula que já existe um MessageLog (mensagem já enviada, aguardando webhook)
        $existingLog = $this->createMock(\MauticPlugin\DialogHSMBundle\Entity\MessageLog::class);
        $existingLog->method('getStatus')->willReturn(\MauticPlugin\DialogHSMBundle\Entity\MessageLog::STATUS_PENDING_WEBHOOK);
        $existingLog->method('getDateSent')->willReturn(new \DateTime());

        // Reassigna o mock do repo para que findByCampaignEventAndLead retorne o log existente.
        // Necessário porque o setUp() já configurou o mock compartilhado com willReturn(null)
        // e PHPUnit não permite sobrescrever stubs no mesmo objeto.
        $freshRepo = $this->createMock(MessageLogRepository::class);
        $freshRepo->method('findByCampaignEventAndLead')->willReturn($existingLog);
        $this->mockMessageLogRepository = $freshRepo;

        // PeakInteractionTimer e reschedule NUNCA devem ser chamados
        $this->mockPeakInteractionTimer->expects($this->never())->method('getOptimalTimeAndDay');
        $this->mockEventScheduler->expects($this->never())->method('reschedule');

        // directBatchHandler NÃO deve ser chamado (contato está aguardando webhook)
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
}

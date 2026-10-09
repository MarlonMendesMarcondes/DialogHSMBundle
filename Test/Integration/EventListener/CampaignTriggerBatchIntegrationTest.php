<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\CampaignBundle\Executioner\Dispatcher\ActionDispatcher;
use Mautic\CampaignBundle\Executioner\Dispatcher\LegacyEventDispatcher;
use Mautic\CampaignBundle\Executioner\Scheduler\EventScheduler;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\DialogHSMBundle\DialogHSMEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lote do mautic:campaigns:trigger passando pelo ActionDispatcher REAL do core.
 *
 * O core exige que todo LeadEventLog do lote receba pass() ou fail()
 * (ActionDispatcher::validateProcessedLogs). Se algum ficar sem decisão, ele lança
 * LogNotProcessedException, o TriggerCampaignCommand captura em prod e abandona a
 * campanha até o próximo cron — antes de persistir as decisões do lote. Na prática
 * o disparo sai picado: um lote de --batch-limit contatos por execução do cron.
 *
 * Por isso a ação HSM decide o contato assim que a mensagem é entregue à fila ou ao
 * envio direto; o status real da Meta chega depois pelo webhook (metadata da ação e
 * nós de decisão entregue/lido/respondeu).
 */
class CampaignTriggerBatchIntegrationTest extends TestCase
{
    private const CONTACTS = 5;

    /** @var object[] mensagens publicadas no bus */
    private array $dispatched = [];

    public function testFirstExecutionOfQueueActionDecidesEveryContact(): void
    {
        ['event' => $event, 'logs' => $logs] = $this->buildCampaignWithContacts('dialoghsm.send_whatsapp_queue');

        $pendingEvent = $this->dispatch(
            DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION_QUEUE,
            $event,
            $logs,
            $this->makePluginSubscriber([])
        );

        self::assertCount(self::CONTACTS, $pendingEvent->getSuccessful());
        self::assertCount(0, $pendingEvent->getFailures());
        self::assertCount(self::CONTACTS, $this->dispatched, 'Cada contato deve ser publicado uma vez no RabbitMQ.');
    }

    public function testFirstExecutionOfDirectActionDecidesEveryContact(): void
    {
        ['event' => $event, 'logs' => $logs] = $this->buildCampaignWithContacts('dialoghsm.send_whatsapp');

        $directHandler = $this->createMock(SendWhatsAppDirectBatchMessageHandler::class);
        $directHandler->expects(self::once())->method('__invoke');

        $pendingEvent = $this->dispatch(
            DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION,
            $event,
            $logs,
            $this->makePluginSubscriber([], $directHandler)
        );

        self::assertCount(self::CONTACTS, $pendingEvent->getSuccessful());
        self::assertCount(0, $pendingEvent->getFailures());
    }

    /**
     * Contatos que ficaram is_scheduled=1 pelo comportamento antigo (mensagem já na fila
     * ou aguardando webhook) voltam no próximo trigger: são decididos sem reenvio.
     */
    public function testReExecutionWithMessageStillInFlightDecidesWithoutResending(): void
    {
        ['event' => $event, 'logs' => $logs] = $this->buildCampaignWithContacts('dialoghsm.send_whatsapp_queue');

        $logsByLead = [];
        foreach ([MessageLog::STATUS_QUEUED, MessageLog::STATUS_PENDING_WEBHOOK] as $i => $status) {
            $logsByLead[$i + 1] = $this->buildMessageLog($status);
        }

        $pendingEvent = $this->dispatch(
            DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION_QUEUE,
            $event,
            $logs,
            $this->makePluginSubscriber($logsByLead)
        );

        self::assertCount(self::CONTACTS, $pendingEvent->getSuccessful());
        self::assertCount(
            self::CONTACTS - count($logsByLead),
            $this->dispatched,
            'Só os contatos sem MessageLog devem ser publicados; os que já estão em trânsito não podem ser reenviados.'
        );
    }

    /**
     * @param ArrayCollection<int, LeadEventLog> $logs
     */
    private function dispatch(string $batchEventName, CampaignEvent $event, ArrayCollection $logs, CampaignSubscriber $subscriber): \Mautic\CampaignBundle\Event\PendingEvent
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);

        $actionDispatcher = new ActionDispatcher(
            $dispatcher,
            new NullLogger(),
            $this->createMock(EventScheduler::class),
            $this->createMock(LegacyEventDispatcher::class),
        );

        return $actionDispatcher->dispatchEvent(new ActionAccessor(['batchEventName' => $batchEventName]), $event, $logs);
    }

    /**
     * @param array<int, MessageLog> $logsByLeadId
     */
    private function makePluginSubscriber(array $logsByLeadId, ?SendWhatsAppDirectBatchMessageHandler $directHandler = null): CampaignSubscriber
    {
        $messageLogRepository = $this->createMock(MessageLogRepository::class);
        $messageLogRepository->method('findByCampaignEventAndLead')
            ->willReturnCallback(fn (int $eventId, int $leadId) => $logsByLeadId[$leadId] ?? null);

        $integrationsHelper = $this->createMock(IntegrationsHelper::class);
        $integrationsHelper->method('getIntegration')->willReturn($this->makeIntegrationMock());

        $numberModel = $this->createMock(WhatsAppNumberModel::class);
        $numberModel->method('getEntity')->willReturn($this->buildWhatsAppNumber());

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new CampaignSubscriber(
            $integrationsHelper,
            $bus,
            $this->createMock(LoggerInterface::class),
            $numberModel,
            $this->createMock(SendWhatsAppMessageHandler::class),
            $this->createMock(EntityManagerInterface::class),
            $directHandler ?? $this->createMock(SendWhatsAppDirectBatchMessageHandler::class),
            $messageLogRepository,
            $this->createMock(LeadEventLogWriter::class),
        );
    }

    private function makeIntegrationMock(): object
    {
        $config = new class {
            public function getIsPublished(): bool
            {
                return true;
            }

            public function getApiKeys(): array
            {
                return ['base_url' => ''];
            }
        };

        return new class($config) {
            public function __construct(private $config)
            {
            }

            public function getIntegrationConfiguration()
            {
                return $this->config;
            }
        };
    }

    private function buildWhatsAppNumber(): WhatsAppNumber&MockObject
    {
        $mock = $this->createMock(WhatsAppNumber::class);
        $mock->method('getApiKey')->willReturn('VALID_API_KEY_12345');
        $mock->method('getBaseUrl')->willReturn('https://api.360dialog.com/v1/messages');
        $mock->method('getIsPublished')->willReturn(true);
        $mock->method('getQueueName')->willReturn('whatsapp_bulk');
        $mock->method('getBatchQueueName')->willReturn('whatsapp_batch');

        return $mock;
    }

    /**
     * @return array{event: CampaignEvent, logs: ArrayCollection<int, LeadEventLog>}
     */
    private function buildCampaignWithContacts(string $actionType): array
    {
        $campaign = new Campaign();
        $campaign->setIsPublished(true);

        $event = new CampaignEvent();
        $event->setCampaign($campaign);
        $event->setType($actionType);
        $this->setEntityId($event, 999);
        $event->setProperties([
            'whatsapp_number' => 1,
            'payload_data'    => ['list' => [['label' => 'content', 'value' => 'meu_template']]],
            'send_delay'      => 0,
            'batch_limit'     => 0,
            'queue_override'  => 'batch',
        ]);

        $logs = new ArrayCollection();

        for ($i = 1; $i <= self::CONTACTS; ++$i) {
            $lead = new Lead();
            $lead->setId($i);
            $lead->setMobile(sprintf('+55119999900%02d', $i));

            $log = new LeadEventLog();
            $log->setEvent($event);
            $log->setLead($lead);
            // PendingEvent indexa os contatos por LeadEventLog::getId(), que só o Doctrine preenche
            $this->setEntityId($log, $i);
            $logs->set($i, $log);
        }

        return ['event' => $event, 'logs' => $logs];
    }

    private function buildMessageLog(string $status): MessageLog
    {
        $log = new MessageLog();
        $log->setStatus($status);
        $log->setDateSent(new \DateTime());

        return $log;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}

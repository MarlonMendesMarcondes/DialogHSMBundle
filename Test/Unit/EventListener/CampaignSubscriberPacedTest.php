<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Pacing\PacedScheduler;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Ação "Enviar WhatsApp com RabbitMQ" com Tipo de Fila "Batch — lotes programados".
 */
class CampaignSubscriberPacedTest extends TestCase
{
    private IntegrationsHelper&MockObject $integrationsHelper;
    private MessageBusInterface&MockObject $bus;
    private WhatsAppNumberModel&MockObject $numberModel;
    private EntityManagerInterface&MockObject $entityManager;
    private MessageLogRepository&MockObject $messageLogRepository;
    private PacedScheduler&MockObject $scheduler;

    /** @var MessageLog[] */
    private array $persistedLogs = [];

    protected function setUp(): void
    {
        $this->integrationsHelper   = $this->createMock(IntegrationsHelper::class);
        $this->bus                  = $this->createMock(MessageBusInterface::class);
        $this->numberModel          = $this->createMock(WhatsAppNumberModel::class);
        $this->entityManager        = $this->createMock(EntityManagerInterface::class);
        $this->messageLogRepository = $this->createMock(MessageLogRepository::class);
        $this->scheduler            = $this->createMock(PacedScheduler::class);
        $this->persistedLogs        = [];

        $this->messageLogRepository->method('findByCampaignEventAndLead')->willReturn(null);
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof MessageLog) {
                $this->persistedLogs[] = $entity;
            }
        });

        $config = new class {
            public function getIsPublished(): bool
            {
                return true;
            }

            public function getApiKeys(): array
            {
                return [];
            }
        };
        $this->integrationsHelper->method('getIntegration')->willReturn(new class($config) {
            public function __construct(private object $config)
            {
            }

            public function getIntegrationConfiguration(): object
            {
                return $this->config;
            }
        });

        $this->numberModel->method('getEntity')->willReturn($this->number());
    }

    private function subscriber(?PacedScheduler $scheduler): CampaignSubscriber
    {
        return new CampaignSubscriber(
            $this->integrationsHelper,
            $this->bus,
            new NullLogger(),
            $this->numberModel,
            $this->createMock(SendWhatsAppMessageHandler::class),
            $this->entityManager,
            $this->createMock(SendWhatsAppDirectBatchMessageHandler::class),
            $this->messageLogRepository,
            $this->createMock(LeadEventLogWriter::class),
            pacedScheduler: $scheduler,
        );
    }

    private function number(?string $batchQueueName = 'batch_q', ?string $queueName = 'bulk_q'): WhatsAppNumber&MockObject
    {
        $number = $this->createMock(WhatsAppNumber::class);
        $number->method('getId')->willReturn(5);
        $number->method('getName')->willReturn('Numero Teste');
        $number->method('getApiKey')->willReturn('VALID_API_KEY');
        $number->method('getBaseUrl')->willReturn('https://waba.example/messages');
        $number->method('getIsPublished')->willReturn(true);
        $number->method('getQueueName')->willReturn($queueName);
        $number->method('getBatchQueueName')->willReturn($batchQueueName);

        return $number;
    }

    private function contact(int $id, string $phone): Lead&MockObject
    {
        $contact = $this->createMock(Lead::class);
        $contact->method('getId')->willReturn($id);
        $contact->method('getLeadPhoneNumber')->willReturn($phone);
        $contact->method('getProfileFields')->willReturn(['id' => $id]);

        return $contact;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pendingEvent(array $config): PendingEvent&MockObject
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(10);

        $campaignEvent = $this->createMock(CampaignEvent::class);
        $campaignEvent->method('getProperties')->willReturn(array_merge([
            'whatsapp_number' => 1,
            'payload_data'    => ['list' => [['label' => 'content', 'value' => 'meu_template']]],
        ], $config));
        $campaignEvent->method('getId')->willReturn(20);
        $campaignEvent->method('getCampaign')->willReturn($campaign);

        $pending = $this->createMock(ArrayCollection::class);
        $pending->method('get')->willReturn($this->createMock(LeadEventLog::class));

        $event = $this->createMock(PendingEvent::class);
        $event->method('checkContext')->willReturnCallback(static fn (string $type): bool => CampaignSubscriber::ACTION_SEND_QUEUE === $type);
        $event->method('getEvent')->willReturn($campaignEvent);
        $event->method('getContacts')->willReturn([1 => $this->contact(1, '+5511999999999'), 2 => $this->contact(2, '+5511988888888')]);
        $event->method('getPending')->willReturn($pending);

        return $event;
    }

    private function pacedConfig(int $lotSize = 500, int $interval = 30): array
    {
        return ['queue_override' => 'batch_paced', 'paced_lot_size' => $lotSize, 'paced_lot_interval' => $interval];
    }

    public function testSchedulesEachContactInsteadOfPublishingToRabbitMq(): void
    {
        $this->scheduler->method('isEnabled')->willReturn(true);
        $this->scheduler->expects($this->exactly(2))->method('schedule')
            ->with(
                $this->callback(static fn (SendWhatsAppMessage $m): bool => null !== $m->queueLogId && 32 === strlen($m->queueLogId)
                    && 10 === $m->campaignId && 20 === $m->campaignEventId && false === $m->legacy),
                5,
                500,
                30,
            )
            ->willReturn(new DateTimeImmutable());
        $this->bus->expects($this->never())->method('dispatch');

        $event = $this->pendingEvent($this->pacedConfig());
        $event->expects($this->exactly(2))->method('pass');
        $event->expects($this->never())->method('fail');

        $this->subscriber($this->scheduler)->onCampaignTriggerActionQueue($event);

        // Log 'queued' gravado antes do agendamento, com o UUID que vai no payload
        $this->assertCount(2, $this->persistedLogs);
        $this->assertSame(MessageLog::STATUS_QUEUED, $this->persistedLogs[0]->getStatus());
    }

    public function testDoesNotRequireTheNumberQueues(): void
    {
        // Lotes programados não usam o RabbitMQ: número sem fila bulk/batch envia normalmente
        $this->numberModel = $this->createMock(WhatsAppNumberModel::class);
        $this->numberModel->method('getEntity')->willReturn($this->number(batchQueueName: null, queueName: null));
        $this->scheduler->method('isEnabled')->willReturn(true);
        $this->scheduler->expects($this->exactly(2))->method('schedule')->willReturn(new DateTimeImmutable());

        $event = $this->pendingEvent($this->pacedConfig());
        $event->expects($this->exactly(2))->method('pass');

        $this->subscriber($this->scheduler)->onCampaignTriggerActionQueue($event);
    }

    public function testInvalidLotConfigIsABuildErrorThatKeepsTheContacts(): void
    {
        $this->scheduler->method('isEnabled')->willReturn(true);
        $this->scheduler->expects($this->never())->method('schedule');

        $event = $this->pendingEvent($this->pacedConfig(lotSize: 0));
        $event->expects($this->exactly(2))->method('fail')
            ->with($this->anything(), $this->stringContains('Lotes programados'), $this->isInstanceOf(DateInterval::class));
        $event->expects($this->never())->method('pass');

        $this->subscriber($this->scheduler)->onCampaignTriggerActionQueue($event);

        $this->assertSame([], $this->persistedLogs);
    }

    public function testIntervalBelowTheMinimumIsABuildError(): void
    {
        $this->scheduler->method('isEnabled')->willReturn(true);
        $this->scheduler->expects($this->never())->method('schedule');

        $event = $this->pendingEvent($this->pacedConfig(interval: 2));
        $event->expects($this->exactly(2))->method('fail');

        $this->subscriber($this->scheduler)->onCampaignTriggerActionQueue($event);
    }

    public function testSchedulingFailureFailsTheQueuedLogAndClosesTheContactWithError(): void
    {
        $this->scheduler->method('isEnabled')->willReturn(true);
        $this->scheduler->method('schedule')->willThrowException(new RuntimeException('deadlock'));

        $event = $this->pendingEvent($this->pacedConfig());
        $event->expects($this->exactly(2))->method('passWithError');
        $event->expects($this->never())->method('pass');

        $this->subscriber($this->scheduler)->onCampaignTriggerActionQueue($event);

        $this->assertSame(MessageLog::STATUS_FAILED, $this->persistedLogs[0]->getStatus());
        $this->assertNull($this->persistedLogs[0]->getWamid());
    }

    // Cai no caminho do RabbitMQ (AmqpStamp precisa da extensão amqp, presente no container)
    #[RequiresPhpExtension('amqp')]
    public function testFeatureDisabledFallsBackToTheRegularBatchQueue(): void
    {
        $this->scheduler->method('isEnabled')->willReturn(false);
        $this->scheduler->expects($this->never())->method('schedule');
        $this->bus->expects($this->exactly(2))->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $event = $this->pendingEvent($this->pacedConfig());
        $event->expects($this->exactly(2))->method('pass');

        $this->subscriber($this->scheduler)->onCampaignTriggerActionQueue($event);
    }

    // Cai no caminho do RabbitMQ (AmqpStamp precisa da extensão amqp, presente no container)
    #[RequiresPhpExtension('amqp')]
    public function testWithoutTheSchedulerServiceFallsBackToTheRegularBatchQueue(): void
    {
        $this->bus->expects($this->exactly(2))->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $event = $this->pendingEvent($this->pacedConfig());
        $event->expects($this->exactly(2))->method('pass');

        $this->subscriber(null)->onCampaignTriggerActionQueue($event);
    }
}

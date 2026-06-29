<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\Executioner\Scheduler\EventScheduler;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Service\OptimalTimeResolver;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Service\RedisContactCache;
use MauticPlugin\DialogHSMBundle\Service\WebhookProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Mautic\PointBundle\Model\PointModel;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Testes de fluxo completo: Batch #1 → Webhook → Batch #2.
 *
 * Cada teste simula os três momentos em sequência usando os componentes reais
 * (CampaignSubscriber + WebhookProcessor + MessageLog) e mocks apenas para
 * dependências externas (API, banco de dados, fila).
 *
 * O MessageLog é o objeto de estado compartilhado entre os três momentos,
 * exatamente como acontece em produção via banco de dados.
 */
class WebhookSentFlowTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers compartilhados
    // -------------------------------------------------------------------------

    private function makeWhatsAppNumber(): WhatsAppNumber&MockObject
    {
        $mock = $this->createMock(WhatsAppNumber::class);
        $mock->method('getApiKey')->willReturn('VALID_API_KEY');
        $mock->method('getBaseUrl')->willReturn('https://api.360dialog.com/v1/messages');
        $mock->method('getIsPublished')->willReturn(true);
        $mock->method('getQueueName')->willReturn('whatsapp_bulk');
        $mock->method('getName')->willReturn('Número Teste');

        return $mock;
    }

    private function makeContact(int $id = 1, string $phone = '+5511999999999'): Lead&MockObject
    {
        $mock = $this->createMock(Lead::class);
        $mock->method('getId')->willReturn($id);
        $mock->method('getLeadPhoneNumber')->willReturn($phone);
        $mock->method('getProfileFields')->willReturn([]);

        return $mock;
    }

    private function makePendingEvent(
        string $context,
        array $contacts,
        int $campaignEventId = 20,
    ): PendingEvent&MockObject {
        $mockCampaign = $this->createMock(\Mautic\CampaignBundle\Entity\Campaign::class);
        $mockCampaign->method('getId')->willReturn(10);

        $mockCampaignEvent = $this->createMock(CampaignEvent::class);
        $mockCampaignEvent->method('getId')->willReturn($campaignEventId);
        $mockCampaignEvent->method('getCampaign')->willReturn($mockCampaign);
        $mockCampaignEvent->method('getProperties')->willReturn([
            'whatsapp_number' => 1,
            'payload_data'    => ['list' => [['label' => 'content', 'value' => 'template_teste']]],
            'send_delay'      => 0,
            'batch_limit'     => 0,
        ]);

        $mockLog     = $this->createMock(LeadEventLog::class);
        $mockPending = $this->createMock(ArrayCollection::class);
        $mockPending->method('get')->willReturn($mockLog);

        $event = $this->createMock(PendingEvent::class);
        $event->method('checkContext')->with($context)->willReturn(true);
        $event->method('getEvent')->willReturn($mockCampaignEvent);
        $event->method('getContacts')->willReturn($contacts);
        $event->method('getPending')->willReturn($mockPending);

        return $event;
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

    private function makeSubscriber(
        MessageLogRepository&MockObject $logRepo,
        SendWhatsAppDirectBatchMessageHandler&MockObject $batchHandler,
    ): CampaignSubscriber {
        $integrationsHelper = $this->createMock(IntegrationsHelper::class);
        $integrationsHelper->method('getIntegration')->willReturn($this->makeIntegration());

        $numberModel = $this->createMock(WhatsAppNumberModel::class);
        $numberModel->method('getEntity')->willReturn($this->makeWhatsAppNumber());

        return new CampaignSubscriber(
            $integrationsHelper,
            $this->createMock(MessageBusInterface::class),
            $this->createMock(LoggerInterface::class),
            $numberModel,
            $this->createMock(SendWhatsAppMessageHandler::class),
            $this->createMock(EntityManagerInterface::class),
            $batchHandler,
            $logRepo,
            $this->createMock(OptimalTimeResolver::class),
            $this->createMock(EventScheduler::class),
            $this->createMock(LeadEventLogWriter::class),
        );
    }

    private function makeWebhookProcessor(MessageLog $sharedLog): WebhookProcessor
    {
        $numberRepo = $this->getMockBuilder(WhatsAppNumberRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByPhoneNumber'])
            ->getMock();
        $numberRepo->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());

        $logRepo = $this->createMock(MessageLogRepository::class);
        $logRepo->method('findByWamid')->willReturn($sharedLog);

        $meta = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $meta->method('getTableName')->willReturn('campaign_lead_event_log');

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($meta);
        $em->method('getConnection')->willReturn($connection);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $leadModel      = $this->createMock(LeadModel::class);
        $eventLogWriter = $this->createMock(LeadEventLogWriter::class);

        return new WebhookProcessor($numberRepo, $logRepo, $em, $dispatcher, $leadModel, $eventLogWriter, $this->createMock(PointModel::class), $this->createMock(RedisContactCache::class), $this->createMock(LoggerInterface::class));
    }

    private function makeWebhookPayload(string $wamid, string $status, array $errors = []): array
    {
        $entry = ['id' => $wamid, 'status' => $status, 'timestamp' => (string) time(), 'recipient_id' => '+5511999999999'];
        if (!empty($errors)) {
            $entry['errors'] = $errors;
        }

        return ['entry' => [['changes' => [['value' => ['statuses' => [$entry]]]]]]];
    }

    // =========================================================================
    // Cenário 1: pending_webhook → sent (webhook) → pass() no Batch #2
    // =========================================================================

    /**
     * Fluxo completo happy path:
     *
     * Batch #1: log inexistente → envia → (handler cria log pending_webhook)
     * Webhook:  pending_webhook → sent
     * Batch #2: log com sent → pass()
     */
    public function testBatch1WebhookSentBatch2CallsPass(): void
    {
        $wamid   = 'wamid_sent_abc123';
        $contact = $this->makeContact(id: 1);

        // Estado compartilhado — representa o log persistido pelo handler no Batch #1.
        // Em produção o banco conecta os dois momentos; aqui usamos o objeto em memória.
        $sharedLog = new MessageLog();
        $sharedLog->setStatus(MessageLog::STATUS_PENDING_WEBHOOK);
        $sharedLog->setCampaignEventId(20);
        $sharedLog->setLeadId(1);
        $sharedLog->setWamid($wamid);
        $sharedLog->setDateSent(new \DateTime());

        // --- BATCH #1 ---
        $batchHandler1 = $this->createMock(SendWhatsAppDirectBatchMessageHandler::class);
        $batchHandler1->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppDirectBatchMessage::class));

        $logRepo1 = $this->createMock(MessageLogRepository::class);
        $logRepo1->method('findByCampaignEventAndLead')->willReturn(null);

        $subscriber1  = $this->makeSubscriber($logRepo1, $batchHandler1);
        $pendingEvent1 = $this->makePendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        // Primeira execução: pass() NÃO é chamado — aguarda webhook
        $pendingEvent1->expects($this->never())->method('pass');
        $pendingEvent1->expects($this->never())->method('fail');

        $subscriber1->onCampaignTriggerAction($pendingEvent1);

        // --- WEBHOOK: Meta confirma sent ---
        $processor = $this->makeWebhookProcessor($sharedLog);
        $processor->process('+5511999999999', $this->makeWebhookPayload($wamid, 'sent'));

        $this->assertSame(MessageLog::STATUS_SENT, $sharedLog->getStatus(),
            'WebhookProcessor deve atualizar o log para sent');

        // --- BATCH #2 ---
        $batchHandler2 = $this->createMock(SendWhatsAppDirectBatchMessageHandler::class);
        $batchHandler2->expects($this->never())->method('__invoke'); // não reenvia

        $logRepo2 = $this->createMock(MessageLogRepository::class);
        $logRepo2->method('findByCampaignEventAndLead')->willReturn($sharedLog);

        $subscriber2   = $this->makeSubscriber($logRepo2, $batchHandler2);
        $pendingEvent2 = $this->makePendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        // Re-execução com sent: pass() é chamado, contato avança na campanha
        $pendingEvent2->expects($this->once())->method('pass');
        $pendingEvent2->expects($this->never())->method('fail');

        $subscriber2->onCampaignTriggerAction($pendingEvent2);
    }

    // =========================================================================
    // Cenário 2: pending_webhook → failed (webhook) → fail() no Batch #2
    // =========================================================================

    /**
     * Fluxo de falha:
     *
     * Batch #1: log inexistente → envia → (handler cria log pending_webhook)
     * Webhook:  pending_webhook → failed (ex: código 131047, janela expirada)
     * Batch #2: log com failed → fail()
     */
    public function testBatch1WebhookFailedBatch2CallsFail(): void
    {
        $wamid   = 'wamid_failed_xyz789';
        $contact = $this->makeContact(id: 1);

        $sharedLog = new MessageLog();
        $sharedLog->setStatus(MessageLog::STATUS_PENDING_WEBHOOK);
        $sharedLog->setCampaignEventId(20);
        $sharedLog->setLeadId(1);
        $sharedLog->setWamid($wamid);
        $sharedLog->setDateSent(new \DateTime());

        // --- BATCH #1 ---
        $batchHandler1 = $this->createMock(SendWhatsAppDirectBatchMessageHandler::class);
        $batchHandler1->expects($this->once())->method('__invoke');

        $logRepo1 = $this->createMock(MessageLogRepository::class);
        $logRepo1->method('findByCampaignEventAndLead')->willReturn(null);

        $subscriber1   = $this->makeSubscriber($logRepo1, $batchHandler1);
        $pendingEvent1 = $this->makePendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $pendingEvent1->expects($this->never())->method('pass');
        $pendingEvent1->expects($this->never())->method('fail');

        $subscriber1->onCampaignTriggerAction($pendingEvent1);

        // --- WEBHOOK: Meta reporta falha com código de erro ---
        $errors = [['code' => 131047, 'title' => 'Re-engagement message']];

        // O dispatcher dispara o WebhookMessageFailedEvent nesse caso
        $numberRepo = $this->getMockBuilder(WhatsAppNumberRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByPhoneNumber'])
            ->getMock();
        $numberRepo->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());

        $logRepo = $this->createMock(MessageLogRepository::class);
        $logRepo->method('findByWamid')->willReturn($sharedLog);

        $em         = $this->createMock(EntityManagerInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch'); // evento de falha é despachado

        $leadModel      = $this->createMock(LeadModel::class);
        $eventLogWriter = $this->createMock(LeadEventLogWriter::class);
        $processor      = new WebhookProcessor($numberRepo, $logRepo, $em, $dispatcher, $leadModel, $eventLogWriter, $this->createMock(PointModel::class), $this->createMock(RedisContactCache::class), $this->createMock(LoggerInterface::class));
        $processor->process('+5511999999999', $this->makeWebhookPayload($wamid, 'failed', $errors));

        $this->assertSame(MessageLog::STATUS_FAILED, $sharedLog->getStatus(),
            'WebhookProcessor deve atualizar o log para failed');
        $this->assertSame(131047, $sharedLog->getWebhookErrorCode(),
            'Código de erro da Meta deve ser persistido no log');

        // --- BATCH #2 ---
        $batchHandler2 = $this->createMock(SendWhatsAppDirectBatchMessageHandler::class);
        $batchHandler2->expects($this->never())->method('__invoke');

        $logRepo2 = $this->createMock(MessageLogRepository::class);
        $logRepo2->method('findByCampaignEventAndLead')->willReturn($sharedLog);

        $subscriber2   = $this->makeSubscriber($logRepo2, $batchHandler2);
        $pendingEvent2 = $this->makePendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        // Re-execução com failed: fail() é chamado, campanha roteia para ramo de erro
        $pendingEvent2->expects($this->never())->method('pass');
        $pendingEvent2->expects($this->once())->method('fail');

        $subscriber2->onCampaignTriggerAction($pendingEvent2);
    }

    // =========================================================================
    // Cenário 3: pending_webhook → timeout (webhook nunca chegou) → fail()
    // =========================================================================

    /**
     * Fluxo de timeout:
     *
     * Batch #1: log inexistente → envia → (handler cria log pending_webhook)
     * (webhook nunca chega — número inexistente, problema na 360dialog, etc.)
     * Batch #2 (após 120s): log ainda pending_webhook mas dateSent antiga → fail()
     */
    public function testBatch1NoWebhookTimeoutBatch2CallsFail(): void
    {
        $contact = $this->makeContact(id: 1);

        // dateSent antiga simula que o webhook nunca chegou
        $sharedLog = new MessageLog();
        $sharedLog->setStatus(MessageLog::STATUS_PENDING_WEBHOOK);
        $sharedLog->setCampaignEventId(20);
        $sharedLog->setLeadId(1);
        $sharedLog->setDateSent(new \DateTime('-1 hour'));

        // --- BATCH #1 ---
        $batchHandler1 = $this->createMock(SendWhatsAppDirectBatchMessageHandler::class);
        $batchHandler1->expects($this->once())->method('__invoke');

        $logRepo1 = $this->createMock(MessageLogRepository::class);
        $logRepo1->method('findByCampaignEventAndLead')->willReturn(null);

        $subscriber1   = $this->makeSubscriber($logRepo1, $batchHandler1);
        $pendingEvent1 = $this->makePendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $pendingEvent1->expects($this->never())->method('pass');
        $pendingEvent1->expects($this->never())->method('fail');

        $subscriber1->onCampaignTriggerAction($pendingEvent1);

        // (webhook nunca chega — log permanece pending_webhook com dateSent antiga)
        $this->assertSame(MessageLog::STATUS_PENDING_WEBHOOK, $sharedLog->getStatus());

        // --- BATCH #2: timeout expirado ---
        $batchHandler2 = $this->createMock(SendWhatsAppDirectBatchMessageHandler::class);
        $batchHandler2->expects($this->never())->method('__invoke');

        $logRepo2 = $this->createMock(MessageLogRepository::class);
        $logRepo2->method('findByCampaignEventAndLead')->willReturn($sharedLog);

        $subscriber2   = $this->makeSubscriber($logRepo2, $batchHandler2);
        $pendingEvent2 = $this->makePendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        // Timeout: fail() é chamado após 120s sem webhook
        $pendingEvent2->expects($this->never())->method('pass');
        $pendingEvent2->expects($this->once())->method('fail');

        $subscriber2->onCampaignTriggerAction($pendingEvent2);
    }

    // =========================================================================
    // Cenário 4: pending_webhook dentro do timeout → nenhuma decisão ainda
    // =========================================================================

    /**
     * Batch #2 roda rapidamente após o Batch #1 (< 120s) e o webhook ainda não chegou.
     * O contato deve permanecer aguardando — nem pass() nem fail().
     */
    public function testBatch2WithinTimeoutTakesNoDecision(): void
    {
        $contact = $this->makeContact(id: 1);

        // dateSent recente — dentro da janela de 120s
        $sharedLog = new MessageLog();
        $sharedLog->setStatus(MessageLog::STATUS_PENDING_WEBHOOK);
        $sharedLog->setCampaignEventId(20);
        $sharedLog->setLeadId(1);
        $sharedLog->setDateSent(new \DateTime('-30 seconds'));

        $batchHandler = $this->createMock(SendWhatsAppDirectBatchMessageHandler::class);
        $batchHandler->expects($this->never())->method('__invoke');

        $logRepo = $this->createMock(MessageLogRepository::class);
        $logRepo->method('findByCampaignEventAndLead')->willReturn($sharedLog);

        $subscriber   = $this->makeSubscriber($logRepo, $batchHandler);
        $pendingEvent = $this->makePendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        // Dentro do timeout: nenhuma decisão — contato permanece is_scheduled=1
        $pendingEvent->expects($this->never())->method('pass');
        $pendingEvent->expects($this->never())->method('fail');

        $subscriber->onCampaignTriggerAction($pendingEvent);
    }
}

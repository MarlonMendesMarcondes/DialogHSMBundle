<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
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
use Symfony\Component\Messenger\MessageBusInterface;

class CampaignSubscriberActionTest extends TestCase
{
    private IntegrationsHelper&MockObject $mockIntegrationsHelper;
    private MessageBusInterface&MockObject $mockBus;
    private LoggerInterface&MockObject $mockLogger;
    private WhatsAppNumberModel&MockObject $mockNumberModel;
    private SendWhatsAppMessageHandler&MockObject $mockHandler;
    private EntityManagerInterface&MockObject $mockEntityManager;
    private SendWhatsAppDirectBatchMessageHandler&MockObject $mockDirectBatchHandler;
    private CampaignSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->mockIntegrationsHelper  = $this->createMock(IntegrationsHelper::class);
        $this->mockBus                 = $this->createMock(MessageBusInterface::class);
        $this->mockLogger              = $this->createMock(LoggerInterface::class);
        $this->mockNumberModel         = $this->createMock(WhatsAppNumberModel::class);
        $this->mockHandler             = $this->createMock(SendWhatsAppMessageHandler::class);
        $this->mockEntityManager       = $this->createMock(EntityManagerInterface::class);
        $this->mockDirectBatchHandler  = $this->createMock(SendWhatsAppDirectBatchMessageHandler::class);

        $this->subscriber = $this->makeSubscriber();
    }

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
            $directTransportDsn,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeIntegrationMock(bool $published = true, string $baseUrl = ''): object
    {
        $mockConfig = new class($published, $baseUrl) {
            public function __construct(private bool $pub, private string $url) {}
            public function getIsPublished(): bool { return $this->pub; }
            public function getApiKeys(): array { return ['base_url' => $this->url]; }
        };

        return new class($mockConfig) {
            public function __construct(private $config) {}
            public function getIntegrationConfiguration() { return $this->config; }
        };
    }

    private function enableIntegration(string $baseUrl = ''): void
    {
        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegrationMock(published: true, baseUrl: $baseUrl));
    }

    private function disableIntegration(): void
    {
        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willThrowException(new \Exception('Integration not found'));
    }

    private function buildWhatsAppNumber(string $apiKey = 'VALID_API_KEY_12345', string $baseUrl = 'https://api.360dialog.com/v1/messages'): WhatsAppNumber&MockObject
    {
        $mock = $this->createMock(WhatsAppNumber::class);
        $mock->method('getApiKey')->willReturn($apiKey);
        $mock->method('getBaseUrl')->willReturn($baseUrl);
        $mock->method('getIsPublished')->willReturn(true);

        return $mock;
    }

    private function buildContact(string $phone = '+5511999999999', int $id = 1): Lead&MockObject
    {
        $mock = $this->createMock(Lead::class);
        $mock->method('getLeadPhoneNumber')->willReturn($phone);
        $mock->method('getId')->willReturn($id);
        $mock->method('getProfileFields')->willReturn([]);

        return $mock;
    }

    private function buildPendingEvent(
        string $context,
        array $contacts = [],
        array $config = []
    ): PendingEvent&MockObject {
        $defaultConfig = [
            'whatsapp_number' => 1,
            'payload_data'    => ['list' => [['label' => 'content', 'value' => 'meu_template']]],
            'send_delay'      => 0,
            'batch_limit'     => 0,
        ];

        $mockCampaign = $this->createMock(\Mautic\CampaignBundle\Entity\Campaign::class);
        $mockCampaign->method('getId')->willReturn(10);

        $mockCampaignEvent = $this->createMock(CampaignEvent::class);
        $mockCampaignEvent->method('getProperties')->willReturn(array_merge($defaultConfig, $config));
        $mockCampaignEvent->method('getId')->willReturn(20);
        $mockCampaignEvent->method('getCampaign')->willReturn($mockCampaign);

        $mockLog     = $this->createMock(LeadEventLog::class);
        $mockPending = $this->createMock(ArrayCollection::class);
        $mockPending->method('get')->willReturn($mockLog);

        $mockPendingEvent = $this->createMock(PendingEvent::class);
        $mockPendingEvent->method('checkContext')->with($context)->willReturn(true);
        $mockPendingEvent->method('getEvent')->willReturn($mockCampaignEvent);
        $mockPendingEvent->method('getContacts')->willReturn($contacts);
        $mockPendingEvent->method('getPending')->willReturn($mockPending);

        return $mockPendingEvent;
    }

    // -------------------------------------------------------------------------
    // Testes: envio direto (síncrono via directBatchHandler)
    // -------------------------------------------------------------------------

    public function testDirectSendCallsDirectBatchHandlerNotBusNorHandlerDirectly(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        // directBatchHandler deve ser chamado com um lote de 1 item
        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppDirectBatchMessage::class));

        // handler individual e Bus NÃO devem ser chamados
        $this->mockHandler->expects($this->never())->method('__invoke');
        $this->mockBus->expects($this->never())->method('dispatch');

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testQueueSendDispatchesToBusNotHandler(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp_queue', [1 => $contact]);

        // Bus deve ser chamado uma vez
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SendWhatsAppMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

        // Handler e directBatchHandler NÃO devem ser chamados
        $this->mockHandler->expects($this->never())->method('__invoke');
        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerActionQueue($event);
    }

    public function testDirectSendPassesBatchLimitAndDelayToBatchHandler(): void
    {
        // GUARD: no modo inline (null://null), send_delay > 0 é zerado para não bloquear
        // o processo mautic:campaigns:trigger. O logger emite um warning operacional.
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('+5511999999991', 1),
            2 => $this->buildContact('+5511999999992', 2),
        ];

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            $contacts,
            ['batch_limit' => 5, 'send_delay' => 2]
        );

        $capturedBatch = null;
        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedBatch): void {
                $capturedBatch = $batch;
            });

        $this->mockLogger->expects($this->once())->method('warning');

        $event->expects($this->exactly(2))->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertNotNull($capturedBatch);
        $this->assertSame(5, $capturedBatch->batchLimit);
        $this->assertSame(0, $capturedBatch->sendDelay, 'Guard: send_delay deve ser zerado no modo inline');
        $this->assertCount(2, $capturedBatch->items);
    }

    public function testDirectSendCollectsAllValidContactsIntoBatch(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber('VALID_API_KEY_12345', 'https://api.360dialog.com'));

        $contacts = [
            1 => $this->buildContact('+5511999999991', 1),
            2 => $this->buildContact('+5511999999992', 2),
            3 => $this->buildContact('+5511999999993', 3),
        ];

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp', $contacts);

        $capturedBatch = null;
        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedBatch): void {
                $capturedBatch = $batch;
            });

        $event->expects($this->exactly(3))->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertCount(3, $capturedBatch->items);
        $this->assertSame('+5511999999991', $capturedBatch->items[0]->phone);
        $this->assertSame('+5511999999992', $capturedBatch->items[1]->phone);
        $this->assertSame('+5511999999993', $capturedBatch->items[2]->phone);
        $this->assertSame('VALID_API_KEY_12345', $capturedBatch->items[0]->apiKey);
    }

    public function testDirectSendDoesNotCallBatchHandlerWhenAllContactsAreInvalid(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('invalid', 1),
            2 => $this->buildContact('', 2),
        ];

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp', $contacts);

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');
        $event->expects($this->exactly(2))->method('fail');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendFailsAllWhenIntegrationDisabled(): void
    {
        $this->disableIntegration();

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp');

        $event->expects($this->once())
            ->method('failAll')
            ->with('dialoghsm.campaign.error.integration_disabled');

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendFailsAllWhenNumberNotFound(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn(null);

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp');

        $event->expects($this->once())
            ->method('failAll')
            ->with('dialoghsm.campaign.error.missing_number');

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendFailsAllWhenApiKeyEmpty(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber(apiKey: ''));

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp');

        $event->expects($this->once())
            ->method('failAll')
            ->with('dialoghsm.campaign.error.missing_api_key');

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendFailsWhenContactHasNoPhone(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact(phone: '');
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $event->expects($this->once())
            ->method('fail')
            ->with($this->anything(), 'dialoghsm.campaign.error.invalid_phone');

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendFailsWhenPhoneIsNotE164(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact(phone: '5511999999999');
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $event->expects($this->once())
            ->method('fail')
            ->with($this->anything(), 'dialoghsm.campaign.error.invalid_phone');

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendSkipsInvalidPhoneButIncludesValidOnesInBatch(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('+5511999999991', 1),
            2 => $this->buildContact('', 2),
            3 => $this->buildContact('+5511999999993', 3),
        ];

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp', $contacts);

        $capturedBatch = null;
        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedBatch): void {
                $capturedBatch = $batch;
            });

        $event->expects($this->exactly(2))->method('pass');
        $event->expects($this->once())
            ->method('fail')
            ->with($this->anything(), 'dialoghsm.campaign.error.invalid_phone');

        $this->subscriber->onCampaignTriggerAction($event);

        // Apenas 2 itens válidos no lote
        $this->assertCount(2, $capturedBatch->items);
    }

    // -------------------------------------------------------------------------
    // Testes: guard inline — send_delay zerado no modo null://null
    // -------------------------------------------------------------------------

    public function testInlineGuardZerosSendDelayAndLogsWarningWhenDelayConfigured(): void
    {
        // send_delay=3 no modo null://null → guard zera o delay e loga warning
        // para não bloquear o processo mautic:campaigns:trigger
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $this->buildContact('+5511999999991', 1)],
            ['batch_limit' => 5, 'send_delay' => 3]
        );

        $capturedBatch = null;
        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedBatch): void {
                $capturedBatch = $batch;
            });

        $this->mockLogger->expects($this->once())->method('warning');

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertSame(0, $capturedBatch->sendDelay, 'Guard deve zerar sendDelay no modo inline');
        $this->assertSame(5, $capturedBatch->batchLimit, 'batchLimit não deve ser alterado pelo guard');
    }

    public function testInlineGuardDoesNotLogWhenDelayIsZero(): void
    {
        // send_delay=0 → nenhum warning (guard só age quando delay > 0)
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $this->buildContact('+5511999999991', 1)],
            ['batch_limit' => 5, 'send_delay' => 0]
        );

        $this->mockDirectBatchHandler->method('__invoke');
        $this->mockLogger->expects($this->never())->method('warning');

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testRedisTransportPassesSendDelayUnchanged(): void
    {
        // No modo Redis o guard não se aplica — send_delay chega intacto ao worker
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('+5511999999991', 1),
            2 => $this->buildContact('+5511999999992', 2),
        ];

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            $contacts,
            ['batch_limit' => 5, 'send_delay' => 3]
        );

        $capturedMsg = null;
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$capturedMsg): \Symfony\Component\Messenger\Envelope {
                $capturedMsg = $msg;

                return new \Symfony\Component\Messenger\Envelope($msg);
            });

        $this->mockLogger->expects($this->never())->method('warning');

        $event->expects($this->exactly(2))->method('pass');

        $subscriber = $this->makeSubscriber('redis://localhost:6379');
        $subscriber->onCampaignTriggerAction($event);

        $this->assertInstanceOf(SendWhatsAppDirectBatchMessage::class, $capturedMsg);
        $this->assertSame(3, $capturedMsg->sendDelay, 'Redis: sendDelay deve chegar intacto ao worker');
        $this->assertSame(5, $capturedMsg->batchLimit);
    }

    // -------------------------------------------------------------------------
    // Testes: send_delay e batch timing (síncrono) — delegados ao BatchHandler
    // -------------------------------------------------------------------------

    public function testDirectSendPassesZeroDelayToBatchHandler(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $this->buildContact('+5511999999991', 1)],
            ['batch_limit' => 1, 'send_delay' => 0]
        );

        $capturedBatch = null;
        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedBatch): void {
                $capturedBatch = $batch;
            });

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertSame(0, $capturedBatch->sendDelay);
    }

    // -------------------------------------------------------------------------
    // Testes: fila nunca dorme (applyBatchSleep=false)
    // -------------------------------------------------------------------------

    public function testQueueSendNeverSleepsEvenWithNonZeroDelay(): void
    {
        // Queue usa applyBatchSleep=false → usleep jamais chamado, mesmo com send_delay=5
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('+5511999999991', 1),
            2 => $this->buildContact('+5511999999992', 2),
            3 => $this->buildContact('+5511999999993', 3),
        ];

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            $contacts,
            ['batch_limit' => 1, 'send_delay' => 5]
        );

        $this->mockBus
            ->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $event->expects($this->exactly(3))->method('pass');

        $start = microtime(true);
        $this->subscriber->onCampaignTriggerActionQueue($event);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, 'Fila nunca deve dormir independente do send_delay');
    }

    // -------------------------------------------------------------------------
    // Testes: envio assíncrono (fila RabbitMQ) — payload e timing
    // -------------------------------------------------------------------------

    public function testQueueSendPayloadCarriesCorrectData(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber('VALID_API_KEY_12345', 'https://api.360dialog.com/v1/messages'));

        $contact = $this->buildContact('+5511888888888', 5);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            [5 => $contact],
            ['payload_data' => ['list' => [['label' => 'content', 'value' => 'template_queue']]]]
        );

        $capturedMessage = null;
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (SendWhatsAppMessage $msg) use (&$capturedMessage): Envelope {
                $capturedMessage = $msg;

                return new Envelope($msg);
            });

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerActionQueue($event);

        $this->assertInstanceOf(SendWhatsAppMessage::class, $capturedMessage);
        $this->assertEquals('+5511888888888', $capturedMessage->phone);
        $this->assertEquals('VALID_API_KEY_12345', $capturedMessage->apiKey);
        $this->assertEquals('https://api.360dialog.com/v1/messages', $capturedMessage->baseUrl);
        $this->assertEquals(5, $capturedMessage->leadId);
        $this->assertEquals('template_queue', $capturedMessage->payloadData['content'] ?? null);
    }

    public function testQueueSendWithDelayConfigStillDispatchesMessage(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            [1 => $contact],
            ['send_delay' => 0]
        );

        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SendWhatsAppMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerActionQueue($event);
    }

    public function testQueueSendMultipleContactsAllReceiveSameSendDelay(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('+5511999999991', 1),
            2 => $this->buildContact('+5511999999992', 2),
            3 => $this->buildContact('+5511999999993', 3),
        ];

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            $contacts,
            ['send_delay' => 0]
        );

        $capturedMessages = [];
        $this->mockBus
            ->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturnCallback(function (SendWhatsAppMessage $msg) use (&$capturedMessages): Envelope {
                $capturedMessages[] = $msg;

                return new Envelope($msg);
            });

        $event->expects($this->exactly(3))->method('pass');

        $this->subscriber->onCampaignTriggerActionQueue($event);

        $this->assertCount(3, $capturedMessages);
    }

    public function testQueueSendBatchLimitWithDelayCombination(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('+5511999999991', 1),
            2 => $this->buildContact('+5511999999992', 2),
            3 => $this->buildContact('+5511999999993', 3),
        ];

        // batch_limit=2 + send_delay=0: todos os 3 contatos despachados (queue nunca dorme)
        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            $contacts,
            ['batch_limit' => 2, 'send_delay' => 0]
        );

        $capturedMessages = [];
        $this->mockBus
            ->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturnCallback(function (SendWhatsAppMessage $msg) use (&$capturedMessages): Envelope {
                $capturedMessages[] = $msg;

                return new Envelope($msg);
            });

        $event->expects($this->exactly(3))->method('pass');

        $this->subscriber->onCampaignTriggerActionQueue($event);

        $this->assertCount(3, $capturedMessages);
    }

    public function testQueueSendModeBulkUsesQueueName(): void
    {
        $this->enableIntegration();

        $number = $this->buildWhatsAppNumber();
        $number->method('getQueueName')->willReturn('bulk');

        $this->mockNumberModel->method('getEntity')->willReturn($number);

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            [1 => $contact],
            ['queue_override' => 'bulk']
        );

        $capturedStamps = null;
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg, array $stamps) use (&$capturedStamps): Envelope {
                $capturedStamps = $stamps;

                return new Envelope($msg);
            });

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerActionQueue($event);

        $this->assertCount(1, $capturedStamps);
        $this->assertInstanceOf(AmqpStamp::class, $capturedStamps[0]);
        $this->assertEquals('bulk', $capturedStamps[0]->getRoutingKey());
    }

    public function testQueueSendFallsBackToQueueNameWhenModeIsEmpty(): void
    {
        $this->enableIntegration();

        $number = $this->buildWhatsAppNumber();
        $number->method('getQueueName')->willReturn('bulk');

        $this->mockNumberModel->method('getEntity')->willReturn($number);

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            [1 => $contact],
            ['queue_override' => '']
        );

        $capturedStamps = null;
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg, array $stamps) use (&$capturedStamps): Envelope {
                $capturedStamps = $stamps;

                return new Envelope($msg);
            });

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerActionQueue($event);

        $this->assertCount(1, $capturedStamps);
        $this->assertInstanceOf(AmqpStamp::class, $capturedStamps[0]);
        $this->assertEquals('bulk', $capturedStamps[0]->getRoutingKey());
    }

    public function testQueueSendBatchModeUsesBatchQueueName(): void
    {
        $this->enableIntegration();

        $number = $this->buildWhatsAppNumber();
        $number->method('getQueueName')->willReturn('bulk');
        $number->method('getBatchQueueName')->willReturn('batch');

        $this->mockNumberModel->method('getEntity')->willReturn($number);

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            [1 => $contact],
            ['queue_override' => 'batch']
        );

        $capturedStamps = null;
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg, array $stamps) use (&$capturedStamps): Envelope {
                $capturedStamps = $stamps;

                return new Envelope($msg);
            });

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerActionQueue($event);

        $this->assertCount(1, $capturedStamps);
        $this->assertInstanceOf(AmqpStamp::class, $capturedStamps[0]);
        $this->assertEquals('batch', $capturedStamps[0]->getRoutingKey());
    }

    public function testQueueSendBatchModeFallsBackToQueueNameWhenBatchQueueNameIsEmpty(): void
    {
        $this->enableIntegration();

        $number = $this->buildWhatsAppNumber();
        $number->method('getQueueName')->willReturn('bulk');
        $number->method('getBatchQueueName')->willReturn(null);

        $this->mockNumberModel->method('getEntity')->willReturn($number);

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            [1 => $contact],
            ['queue_override' => 'batch']
        );

        $capturedStamps = null;
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($msg, array $stamps) use (&$capturedStamps): Envelope {
                $capturedStamps = $stamps;

                return new Envelope($msg);
            });

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerActionQueue($event);

        $this->assertCount(1, $capturedStamps);
        $this->assertInstanceOf(AmqpStamp::class, $capturedStamps[0]);
        $this->assertEquals('bulk', $capturedStamps[0]->getRoutingKey());
    }

    public function testQueueSendFailsAllWhenIntegrationDisabled(): void
    {
        $this->disableIntegration();

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp_queue');

        $event->expects($this->once())
            ->method('failAll')
            ->with('dialoghsm.campaign.error.integration_disabled');

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->subscriber->onCampaignTriggerActionQueue($event);
    }

    public function testQueueSendFailsWhenContactHasInvalidPhone(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact(phone: '');
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp_queue', [1 => $contact]);

        $event->expects($this->once())
            ->method('fail')
            ->with($this->anything(), 'dialoghsm.campaign.error.invalid_phone');

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->subscriber->onCampaignTriggerActionQueue($event);
    }

    public function testDirectSendPayloadCarriesPhoneAndTemplate(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber('VALID_API_KEY_12345', 'https://api.360dialog.com/v1/messages'));

        $contact = $this->buildContact('+5511888888888', 5);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [5 => $contact],
            ['payload_data' => ['list' => [['label' => 'content', 'value' => 'template_teste']]]]
        );

        $capturedBatch = null;
        $this->mockDirectBatchHandler
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedBatch): void {
                $capturedBatch = $batch;
            });

        $event->expects($this->once())->method('pass');
        $this->subscriber->onCampaignTriggerAction($event);

        $item = $capturedBatch->items[0];
        $this->assertInstanceOf(SendWhatsAppMessage::class, $item);
        $this->assertEquals('+5511888888888', $item->phone);
        $this->assertEquals('VALID_API_KEY_12345', $item->apiKey);
        $this->assertEquals('https://api.360dialog.com/v1/messages', $item->baseUrl);
        $this->assertEquals(5, $item->leadId);
    }

    // -------------------------------------------------------------------------
    // Testes: cenários de erro no modo fila (espelhos dos testes de envio direto)
    // -------------------------------------------------------------------------

    public function testQueueSendFailsAllWhenNumberNotFound(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn(null);

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp_queue');

        $event->expects($this->once())
            ->method('failAll')
            ->with('dialoghsm.campaign.error.missing_number');

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->subscriber->onCampaignTriggerActionQueue($event);
    }

    public function testQueueSendFailsAllWhenApiKeyEmpty(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber(apiKey: ''));

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp_queue');

        $event->expects($this->once())
            ->method('failAll')
            ->with('dialoghsm.campaign.error.missing_api_key');

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->subscriber->onCampaignTriggerActionQueue($event);
    }

    public function testDirectSendFailsAllWhenNumberIsUnpublished(): void
    {
        $this->enableIntegration();

        $unpublishedNumber = $this->createMock(WhatsAppNumber::class);
        $unpublishedNumber->method('getIsPublished')->willReturn(false);

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($unpublishedNumber);

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp');

        $event->expects($this->once())
            ->method('failAll')
            ->with('dialoghsm.campaign.error.missing_number');

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendFailsAllWhenIntegrationFoundButNotPublished(): void
    {
        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegrationMock(published: false));

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp');

        $event->expects($this->once())
            ->method('failAll')
            ->with('dialoghsm.campaign.error.integration_disabled');

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testQueueSendFailsAllWhenIntegrationFoundButNotPublished(): void
    {
        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegrationMock(published: false));

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp_queue');

        $event->expects($this->once())
            ->method('failAll')
            ->with('dialoghsm.campaign.error.integration_disabled');

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->subscriber->onCampaignTriggerActionQueue($event);
    }

    // -------------------------------------------------------------------------
    // Testes: fetchEnabledIntegration chamada apenas uma vez
    // -------------------------------------------------------------------------

    public function testGetIntegrationIsCalledExactlyOnceEvenWhenBaseUrlFallsBackToPluginConfig(): void
    {
        $this->mockIntegrationsHelper
            ->expects($this->once())
            ->method('getIntegration')
            ->willReturn($this->makeIntegrationMock(published: true, baseUrl: 'https://custom.plugin.url/messages'));

        $number = $this->buildWhatsAppNumber('VALID_API_KEY_12345', '');
        $this->mockNumberModel->method('getEntity')->willReturn($number);

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    // -------------------------------------------------------------------------
    // Testes: resolveBaseUrl — fallback para plugin config e default
    // -------------------------------------------------------------------------

    public function testBaseUrlFallsBackToPluginConfigWhenNumberHasNoBaseUrl(): void
    {
        $this->enableIntegration(baseUrl: 'https://custom.plugin.url/messages');

        $number = $this->buildWhatsAppNumber('VALID_API_KEY_12345', '');
        $this->mockNumberModel->method('getEntity')->willReturn($number);

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $capturedBatch = null;
        $this->mockDirectBatchHandler
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedBatch): void {
                $capturedBatch = $batch;
            });

        $event->expects($this->once())->method('pass');
        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertEquals('https://custom.plugin.url/messages', $capturedBatch->items[0]->baseUrl);
    }

    public function testBaseUrlFallsBackToHardcodedDefaultWhenBothNumberAndPluginConfigAreEmpty(): void
    {
        $this->enableIntegration(baseUrl: '');

        $number = $this->buildWhatsAppNumber('VALID_API_KEY_12345', '');
        $this->mockNumberModel->method('getEntity')->willReturn($number);

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $capturedBatch = null;
        $this->mockDirectBatchHandler
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedBatch): void {
                $capturedBatch = $batch;
            });

        $event->expects($this->once())->method('pass');
        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertEquals('https://waba-v2.360dialog.io/messages', $capturedBatch->items[0]->baseUrl);
    }

    // -------------------------------------------------------------------------
    // Testes: envio direto com Redis configurado
    // -------------------------------------------------------------------------

    public function testDirectSendWithRedisDispatchesToBusNotHandler(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        // Handler NÃO deve ser chamado diretamente
        $this->mockHandler->expects($this->never())->method('__invoke');

        // Bus deve ser chamado UMA vez com SendWhatsAppDirectBatchMessage contendo 1 item
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($msg) {
                return $msg instanceof SendWhatsAppDirectBatchMessage && count($msg->items) === 1;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $event->expects($this->once())->method('pass');

        $subscriber = $this->makeSubscriber('redis://localhost:6379');
        $subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendWithRedisNeverSleeps(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('+5511999999991', 1),
            2 => $this->buildContact('+5511999999992', 2),
            3 => $this->buildContact('+5511999999993', 3),
        ];

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            $contacts,
            ['batch_limit' => 1, 'send_delay' => 5]
        );

        // Um único dispatch de SendWhatsAppDirectBatchMessage com 3 itens e parâmetros corretos
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($msg) {
                return $msg instanceof SendWhatsAppDirectBatchMessage
                    && count($msg->items) === 3
                    && $msg->batchLimit === 1
                    && $msg->sendDelay === 5;
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $event->expects($this->exactly(3))->method('pass');

        $start      = microtime(true);
        $subscriber = $this->makeSubscriber('redis://localhost:6379');
        $subscriber->onCampaignTriggerAction($event);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, 'CampaignSubscriber nunca deve dormir ao usar Redis');
    }

    // -------------------------------------------------------------------------
    // Testes: buildPayloadFromConfig — cobre os `continue` no loop
    // -------------------------------------------------------------------------

    public function testPayloadSkipsNonArrayItemsButKeepsValidOnes(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact], [
            'payload_data' => [
                'list' => [
                    'not_an_array',
                    ['label' => 'content', 'value' => 'tpl'],
                ],
            ],
        ]);

        $capturedPayload = null;
        $this->mockDirectBatchHandler
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedPayload): void {
                $capturedPayload = $batch->items[0]->payloadData;
            });

        $event->method('pass');
        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertArrayHasKey('content', $capturedPayload);
    }

    public function testPayloadSkipsItemsMissingLabelOrValue(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact], [
            'payload_data' => [
                'list' => [
                    ['value' => 'sem_label'],
                    ['label' => 'content', 'value' => 'tpl'],
                ],
            ],
        ]);

        $capturedPayload = null;
        $this->mockDirectBatchHandler
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedPayload): void {
                $capturedPayload = $batch->items[0]->payloadData;
            });

        $event->method('pass');
        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertArrayHasKey('content', $capturedPayload);
        $this->assertArrayNotHasKey('', $capturedPayload);
    }

    public function testPayloadSkipsItemsWithEmptyLabel(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact], [
            'payload_data' => [
                'list' => [
                    ['label' => '   ', 'value' => 'v1'],
                    ['label' => 'content', 'value' => 'tpl'],
                ],
            ],
        ]);

        $capturedPayload = null;
        $this->mockDirectBatchHandler
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedPayload): void {
                $capturedPayload = $batch->items[0]->payloadData;
            });

        $event->method('pass');
        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertArrayHasKey('content', $capturedPayload);
        $this->assertArrayNotHasKey('', $capturedPayload);
        $this->assertArrayNotHasKey('   ', $capturedPayload);
    }

    public function testDirectSendWithNullTransportUsesDirectBatchHandler(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $this->mockDirectBatchHandler->expects($this->once())->method('__invoke');
        $this->mockBus->expects($this->never())->method('dispatch');
        $this->mockHandler->expects($this->never())->method('__invoke');

        $event->expects($this->once())->method('pass');

        $subscriber = $this->makeSubscriber('null://null');
        $subscriber->onCampaignTriggerAction($event);
    }

    // -------------------------------------------------------------------------
    // Testes: validação E.164
    // -------------------------------------------------------------------------

    /**
     * @dataProvider validE164Provider
     */
    public function testDirectSendAcceptsValidE164Phone(string $phone): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact(phone: $phone);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $this->mockDirectBatchHandler->expects($this->once())->method('__invoke');

        $event->expects($this->never())->method('fail');
        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validE164Provider(): array
    {
        return [
            'brasil celular'                  => ['+5511999999999'],
            'brasil fixo'                     => ['+551133334444'],
            'EUA'                             => ['+12025551234'],
            'mínimo 7 dígitos'                => ['+1234567'],
            'máximo 15 dígitos'               => ['+123456789012345'],
            // Normalizados automaticamente
            'com espaços (caso produção)'     => ['+55 44 999067833'],
            'com espaços brasil celular'      => ['+55 11 99999 9999'],
            'com traços'                      => ['+55-11-99999-9999'],
            'com parênteses e espaços'        => ['+55 (11) 98765-4321'],
        ];
    }

    /**
     * @dataProvider invalidE164Provider
     */
    public function testDirectSendRejectsInvalidE164Phone(string $phone): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact(phone: $phone);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $event->expects($this->once())
            ->method('fail')
            ->with($this->anything(), 'dialoghsm.campaign.error.invalid_phone');

        $this->mockDirectBatchHandler->expects($this->never())->method('__invoke');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    // -------------------------------------------------------------------------
    // Testes: correlação campanha → log (v1.1.3)
    // -------------------------------------------------------------------------

    public function testDirectSendMessageCarriesCampaignId(): void
    {
        $this->enableIntegration();
        $this->mockNumberModel->method('getEntity')->willReturn($this->buildWhatsAppNumber());

        $capturedBatch = null;
        $this->mockDirectBatchHandler
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedBatch): void {
                $capturedBatch = $batch;
            });

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);
        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertNotNull($capturedBatch);
        $this->assertSame(10, $capturedBatch->items[0]->campaignId);
        $this->assertSame(20, $capturedBatch->items[0]->campaignEventId);
    }

    public function testQueueSendMessageCarriesCampaignId(): void
    {
        $this->enableIntegration();
        $this->mockNumberModel->method('getEntity')->willReturn($this->buildWhatsAppNumber());

        $captured = null;
        $this->mockBus
            ->method('dispatch')
            ->willReturnCallback(function (SendWhatsAppMessage $msg) use (&$captured): \Symfony\Component\Messenger\Envelope {
                $captured = $msg;

                return new \Symfony\Component\Messenger\Envelope($msg);
            });

        $contact = $this->buildContact('+5511999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp_queue', [1 => $contact]);
        $this->subscriber->onCampaignTriggerActionQueue($event);

        $this->assertNotNull($captured);
        $this->assertSame(10, $captured->campaignId);
        $this->assertSame(20, $captured->campaignEventId);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidE164Provider(): array
    {
        return [
            'vazio'           => [''],
            'sem +'           => ['5511999999999'],
            'só +'            => ['+'],
            'apenas zeros'    => ['+0000000'],
            'começa com +0'   => ['+0123456789'],
            'curto demais'    => ['+12345'],
            'longo demais'    => ['+1234567890123456'],
            'letras'          => ['+5511abc9999'],
        ];
    }

    /**
     * Garante que o telefone normalizado é passado ao handler, não o original com espaços.
     */
    public function testNormalizedPhoneIsUsedInMessage(): void
    {
        $this->enableIntegration();
        $this->mockNumberModel->method('getEntity')->willReturn($this->buildWhatsAppNumber());

        $capturedBatch = null;
        $this->mockDirectBatchHandler
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppDirectBatchMessage $batch) use (&$capturedBatch): void {
                $capturedBatch = $batch;
            });

        $contact = $this->buildContact('+55 44 999067833', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $event->expects($this->never())->method('fail');
        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertNotNull($capturedBatch);
        $this->assertSame('+5544999067833', $capturedBatch->items[0]->phone);
    }
}

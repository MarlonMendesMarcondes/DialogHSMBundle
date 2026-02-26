<?php
declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Symfony\Component\Messenger\Envelope;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class CampaignSubscriberActionTest extends TestCase
{
    private IntegrationsHelper&MockObject $mockIntegrationsHelper;
    private MessageBusInterface&MockObject $mockBus;
    private LoggerInterface&MockObject $mockLogger;
    private WhatsAppNumberModel&MockObject $mockNumberModel;
    private SendWhatsAppMessageHandler&MockObject $mockHandler;
    private KernelInterface&MockObject $mockKernel;
    private CampaignSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->mockIntegrationsHelper = $this->createMock(IntegrationsHelper::class);
        $this->mockBus                = $this->createMock(MessageBusInterface::class);
        $this->mockLogger             = $this->createMock(LoggerInterface::class);
        $this->mockNumberModel        = $this->createMock(WhatsAppNumberModel::class);
        $this->mockHandler            = $this->createMock(SendWhatsAppMessageHandler::class);
        $this->mockKernel             = $this->createMock(KernelInterface::class);

        $this->subscriber = new CampaignSubscriber(
            $this->mockIntegrationsHelper,
            $this->mockBus,
            $this->mockLogger,
            $this->mockNumberModel,
            $this->mockHandler,
            $this->mockKernel,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function enableIntegration(): void
    {
        $mockConfig = new class {
            public function getIsPublished(): bool { return true; }
            public function getApiKeys(): array { return ['base_url' => '']; }
        };

        $mockIntegration = new class($mockConfig) {
            public function __construct(private $config) {}
            public function getIntegrationConfiguration() { return $this->config; }
        };

        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willReturn($mockIntegration);
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

    private function buildContact(string $phone = '11999999999', int $id = 1): Lead&MockObject
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

        $mockCampaignEvent = $this->createMock(CampaignEvent::class);
        $mockCampaignEvent->method('getProperties')->willReturn(array_merge($defaultConfig, $config));

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
    // Testes: envio direto (síncrono)
    // -------------------------------------------------------------------------

    public function testDirectSendCallsHandlerDirectlyNotBus(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('11999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        // Handler deve ser chamado uma vez e retornar sucesso
        $this->mockHandler
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppMessage::class))
            ->willReturn(['success' => true, 'error' => null, 'http_status' => 200, 'response' => null]);

        // Bus NÃO deve ser chamado
        $this->mockBus
            ->expects($this->never())
            ->method('dispatch');

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testQueueSendDispatchesToBusNotHandler(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('11999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp_queue', [1 => $contact]);

        // Bus deve ser chamado uma vez
        $this->mockBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SendWhatsAppMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

        // Handler NÃO deve ser chamado diretamente
        $this->mockHandler
            ->expects($this->never())
            ->method('__invoke');

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerActionQueue($event);
    }

    public function testDirectSendFailsAllWhenIntegrationDisabled(): void
    {
        $this->disableIntegration();

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp');

        $event->expects($this->once())
            ->method('failAll')
            ->with('dialoghsm.campaign.error.integration_disabled');

        $this->mockHandler->expects($this->never())->method('__invoke');

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

        $this->mockHandler->expects($this->never())->method('__invoke');

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

        $this->mockHandler->expects($this->never())->method('__invoke');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendPassesWithErrorWhenContactHasNoPhone(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact(phone: '');
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $event->expects($this->once())
            ->method('passWithError')
            ->with($this->anything(), 'dialoghsm.campaign.error.missing_phone');

        $this->mockHandler->expects($this->never())->method('__invoke');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendProcessesMultipleContacts(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('11999999991', 1),
            2 => $this->buildContact('11999999992', 2),
            3 => $this->buildContact('11999999993', 3),
        ];

        $event = $this->buildPendingEvent('dialoghsm.send_whatsapp', $contacts);

        // Handler deve ser chamado 3 vezes, uma por contato
        $this->mockHandler
            ->expects($this->exactly(3))
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppMessage::class))
            ->willReturn(['success' => true, 'error' => null, 'http_status' => 200, 'response' => null]);

        $event->expects($this->exactly(3))->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendProcessesAllContactsEvenWithBatchLimit(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('11999999991', 1),
            2 => $this->buildContact('11999999992', 2),
            3 => $this->buildContact('11999999993', 3),
        ];

        // batch_limit=2: agrupa envios, mas todos os 3 contatos devem ser processados
        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            $contacts,
            ['batch_limit' => 2]
        );

        $this->mockHandler
            ->expects($this->exactly(3))
            ->method('__invoke')
            ->willReturn(['success' => true, 'error' => null, 'http_status' => 200, 'response' => null]);

        $event->expects($this->exactly(3))->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendCallsPassWithErrorWhenApiFails(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('11999999999', 1);
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp', [1 => $contact]);

        $this->mockHandler
            ->expects($this->once())
            ->method('__invoke')
            ->willReturn(['success' => false, 'error' => 'HTTP 400: Bad request', 'http_status' => 400, 'response' => null]);

        $event->expects($this->never())->method('pass');
        $event->expects($this->once())
            ->method('passWithError')
            ->with($this->anything(), 'dialoghsm.campaign.error.send_failed');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    // -------------------------------------------------------------------------
    // Testes: send_delay e batch timing (síncrono)
    // -------------------------------------------------------------------------

    public function testDirectSendWithDelayConfigStillProcessesContact(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact('11999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [1 => $contact],
            ['send_delay' => 1500]
        );

        $this->mockHandler
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppMessage::class))
            ->willReturn(['success' => true, 'error' => null, 'http_status' => 200, 'response' => null]);

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);
    }

    public function testDirectSendMultipleContactsAllReceiveSameSendDelay(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('11999999991', 1),
            2 => $this->buildContact('11999999992', 2),
            3 => $this->buildContact('11999999993', 3),
        ];

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            $contacts,
            ['send_delay' => 300]
        );

        $capturedMessages = [];
        $this->mockHandler
            ->expects($this->exactly(3))
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppMessage $msg) use (&$capturedMessages): array {
                $capturedMessages[] = $msg;

                return ['success' => true, 'error' => null, 'http_status' => 200, 'response' => null];
            });

        $event->expects($this->exactly(3))->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertCount(3, $capturedMessages);
    }

    public function testDirectSendBatchLimitWithDelayCombination(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contacts = [
            1 => $this->buildContact('11999999991', 1),
            2 => $this->buildContact('11999999992', 2),
            3 => $this->buildContact('11999999993', 3),
        ];

        // batch_limit=2 + send_delay=200: todos os 3 contatos processados, cada um com delay=200
        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            $contacts,
            ['batch_limit' => 2, 'send_delay' => 200]
        );

        $capturedMessages = [];
        $this->mockHandler
            ->expects($this->exactly(3))
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppMessage $msg) use (&$capturedMessages): array {
                $capturedMessages[] = $msg;

                return ['success' => true, 'error' => null, 'http_status' => 200, 'response' => null];
            });

        $event->expects($this->exactly(3))->method('pass');

        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertCount(3, $capturedMessages);
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

        $contact = $this->buildContact('11888888888', 5);
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
        $this->assertEquals('11888888888', $capturedMessage->phone);
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

        $contact = $this->buildContact('11999999999', 1);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            [1 => $contact],
            ['send_delay' => 2000]
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
            1 => $this->buildContact('11999999991', 1),
            2 => $this->buildContact('11999999992', 2),
            3 => $this->buildContact('11999999993', 3),
        ];

        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            $contacts,
            ['send_delay' => 500]
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
            1 => $this->buildContact('11999999991', 1),
            2 => $this->buildContact('11999999992', 2),
            3 => $this->buildContact('11999999993', 3),
        ];

        // batch_limit=2 + send_delay=400: todos os 3 contatos despachados, cada um com delay=400
        $event = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp_queue',
            $contacts,
            ['batch_limit' => 2, 'send_delay' => 400]
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

    public function testQueueSendPassesWithErrorWhenContactHasNoPhone(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber());

        $contact = $this->buildContact(phone: '');
        $event   = $this->buildPendingEvent('dialoghsm.send_whatsapp_queue', [1 => $contact]);

        $event->expects($this->once())
            ->method('passWithError')
            ->with($this->anything(), 'dialoghsm.campaign.error.missing_phone');

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->subscriber->onCampaignTriggerActionQueue($event);
    }

    public function testDirectSendPayloadCarriesPhoneAndTemplate(): void
    {
        $this->enableIntegration();

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->buildWhatsAppNumber('VALID_API_KEY_12345', 'https://api.360dialog.com/v1/messages'));

        $contact = $this->buildContact('11888888888', 5);
        $event   = $this->buildPendingEvent(
            'dialoghsm.send_whatsapp',
            [5 => $contact],
            ['payload_data' => ['list' => [['label' => 'content', 'value' => 'template_teste']]]]
        );

        $capturedMessage = null;
        $this->mockHandler
            ->expects($this->once())
            ->method('__invoke')
            ->willReturnCallback(function (SendWhatsAppMessage $msg) use (&$capturedMessage): array {
                $capturedMessage = $msg;

                return ['success' => true, 'error' => null, 'http_status' => 200, 'response' => null];
            });

        $this->subscriber->onCampaignTriggerAction($event);

        $this->assertInstanceOf(SendWhatsAppMessage::class, $capturedMessage);
        $this->assertEquals('11888888888', $capturedMessage->phone);
        $this->assertEquals('VALID_API_KEY_12345', $capturedMessage->apiKey);
        $this->assertEquals('https://api.360dialog.com/v1/messages', $capturedMessage->baseUrl);
        $this->assertEquals(5, $capturedMessage->leadId);
    }

    // -------------------------------------------------------------------------
    // Testes: consume queue
    // -------------------------------------------------------------------------

    public function testConsumeQueueSkipsWhenContextDoesNotMatch(): void
    {
        $mockPendingEvent = $this->createMock(PendingEvent::class);
        $mockPendingEvent->method('checkContext')
            ->with('dialoghsm.consume_queue')
            ->willReturn(false);
        $mockPendingEvent->expects($this->never())->method('getEvent');
        $mockPendingEvent->expects($this->never())->method('pass');

        $this->subscriber->onCampaignTriggerConsumeQueue($mockPendingEvent);
    }

    public function testConsumeQueuePassesAllContactsAfterConsume(): void
    {
        $fakeCommand = new Command('dialoghsm:consume');
        $fakeCommand->setCode(function (): int { return 0; });

        $mockBundle = $this->createMock(Bundle::class);
        $mockBundle->method('registerCommands')
            ->willReturnCallback(function ($app) use ($fakeCommand): void {
                $app->add($fakeCommand);
            });

        $mockContainer = $this->createMock(ContainerInterface::class);
        $mockContainer->method('has')->willReturn(false);

        $this->mockKernel->method('getBundles')->willReturn([$mockBundle]);
        $this->mockKernel->method('getContainer')->willReturn($mockContainer);

        $contacts = [
            1 => $this->buildContact('11999999991', 1),
            2 => $this->buildContact('11999999992', 2),
        ];

        $event = $this->buildPendingEvent('dialoghsm.consume_queue', $contacts, [
            'whatsapp_number' => 0,
            'limit'           => 0,
            'time_limit'      => 0,
        ]);

        $event->expects($this->exactly(2))->method('pass');

        $this->subscriber->onCampaignTriggerConsumeQueue($event);
    }

    public function testConsumeQueuePassesQueueOptionWhenNumberHasQueueName(): void
    {
        $capturedInput = null;
        $fakeCommand   = new Command('dialoghsm:consume');
        $fakeCommand->addOption('queue', null, InputOption::VALUE_OPTIONAL);
        $fakeCommand->setCode(function (InputInterface $input) use (&$capturedInput): int {
            $capturedInput = $input;

            return 0;
        });

        $mockBundle = $this->createMock(Bundle::class);
        $mockBundle->method('registerCommands')
            ->willReturnCallback(function ($app) use ($fakeCommand): void {
                $app->add($fakeCommand);
            });

        $mockContainer = $this->createMock(ContainerInterface::class);
        $mockContainer->method('has')->willReturn(false);

        $this->mockKernel->method('getBundles')->willReturn([$mockBundle]);
        $this->mockKernel->method('getContainer')->willReturn($mockContainer);

        $number = $this->buildWhatsAppNumber();
        $number->method('getQueueName')->willReturn('minha_fila');

        $this->mockNumberModel->method('getEntity')->willReturn($number);

        $contacts = [1 => $this->buildContact('11999999991', 1)];

        $event = $this->buildPendingEvent('dialoghsm.consume_queue', $contacts, [
            'whatsapp_number' => 1,
            'limit'           => 0,
            'time_limit'      => 0,
        ]);

        $event->expects($this->once())->method('pass');

        $this->subscriber->onCampaignTriggerConsumeQueue($event);

        $this->assertNotNull($capturedInput);
        $this->assertEquals('minha_fila', $capturedInput->getOption('queue'));
    }
}

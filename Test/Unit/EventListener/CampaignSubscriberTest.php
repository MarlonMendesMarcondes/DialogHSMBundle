<?php

declare(strict_types=1);

use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class CampaignSubscriberTest extends TestCase
{
    private function makeSubscriber(): CampaignSubscriber
    {
        return new CampaignSubscriber(
            $this->createMock(IntegrationsHelper::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(WhatsAppNumberModel::class),
            $this->createMock(SendWhatsAppMessageHandler::class),
        );
    }

    public function testGetSubscribedEvents(): void
    {
        $events = CampaignSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey('mautic.campaign_on_build', $events);
        $this->assertEquals(['onCampaignBuild', 0], $events['mautic.campaign_on_build']);

        $this->assertArrayHasKey('mautic.dialoghsm.on_campaign_trigger_action', $events);
        $this->assertEquals(['onCampaignTriggerAction', 0], $events['mautic.dialoghsm.on_campaign_trigger_action']);

        $this->assertArrayHasKey('mautic.dialoghsm.on_campaign_trigger_action_queue', $events);
        $this->assertEquals(['onCampaignTriggerActionQueue', 0], $events['mautic.dialoghsm.on_campaign_trigger_action_queue']);

        $this->assertCount(3, $events);
    }

    public function testEventNamesMatchDialogHSMEventsConstants(): void
    {
        $events = CampaignSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(\MauticPlugin\DialogHSMBundle\DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION, $events);
        $this->assertArrayHasKey(\MauticPlugin\DialogHSMBundle\DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION_QUEUE, $events);
    }

    public function testSubscribedHandlerMethodsExistOnClass(): void
    {
        $events = CampaignSubscriber::getSubscribedEvents();

        foreach ($events as [$method]) {
            $this->assertTrue(
                method_exists(CampaignSubscriber::class, $method),
                "Método '{$method}' não existe em CampaignSubscriber"
            );
        }
    }

    public function testOnCampaignBuildRegistersDirectAndQueueActions(): void
    {
        $registeredActions = [];

        $builderEvent = $this->createMock(CampaignBuilderEvent::class);
        $builderEvent
            ->expects($this->exactly(2))
            ->method('addAction')
            ->willReturnCallback(function (string $key) use (&$registeredActions): void {
                $registeredActions[] = $key;
            });

        $this->makeSubscriber()->onCampaignBuild($builderEvent);

        $this->assertContains('dialoghsm.send_whatsapp', $registeredActions);
        $this->assertContains('dialoghsm.send_whatsapp_queue', $registeredActions);
    }

    public function testOnCampaignBuildPassesCorrectMetadataForDirectAction(): void
    {
        $capturedOptions = [];

        $builderEvent = $this->createMock(CampaignBuilderEvent::class);
        $builderEvent
            ->method('addAction')
            ->willReturnCallback(function (string $key, array $options) use (&$capturedOptions): void {
                $capturedOptions[$key] = $options;
            });

        $this->makeSubscriber()->onCampaignBuild($builderEvent);

        $opts = $capturedOptions['dialoghsm.send_whatsapp'];
        $this->assertSame('whatsapp', $opts['channel']);
        $this->assertArrayHasKey('batchEventName', $opts);
        $this->assertArrayHasKey('formType', $opts);
        $this->assertArrayHasKey('label', $opts);
    }
}

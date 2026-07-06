<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
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
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(SendWhatsAppDirectBatchMessageHandler::class),
            $this->createMock(MessageLogRepository::class),
            $this->createMock(LeadEventLogWriter::class),
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

        $this->assertArrayHasKey('mautic.dialoghsm.on_campaign_trigger_decision', $events);
        $this->assertEquals(['onCampaignTriggerDecision', 0], $events['mautic.dialoghsm.on_campaign_trigger_decision']);

        $this->assertCount(4, $events);
    }

    public function testEventNamesMatchDialogHSMEventsConstants(): void
    {
        $events = CampaignSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(\MauticPlugin\DialogHSMBundle\DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION, $events);
        $this->assertArrayHasKey(\MauticPlugin\DialogHSMBundle\DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION_QUEUE, $events);
        $this->assertArrayHasKey(\MauticPlugin\DialogHSMBundle\DialogHSMEvents::ON_CAMPAIGN_TRIGGER_DECISION, $events);
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
            ->expects($this->exactly(3))
            ->method('addAction')
            ->willReturnCallback(function (string $key) use (&$registeredActions): void {
                $registeredActions[] = $key;
            });

        $this->makeSubscriber()->onCampaignBuild($builderEvent);

        $this->assertContains('dialoghsm.send_whatsapp', $registeredActions);
        $this->assertContains('dialoghsm.send_whatsapp_queue', $registeredActions);
        $this->assertContains('dialoghsm.send_whatsapp_message', $registeredActions);
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

    public function testOnCampaignBuildRegistersDeliveredReadAndRepliedDecisions(): void
    {
        $registeredDecisions = [];

        $builderEvent = $this->createMock(CampaignBuilderEvent::class);
        $builderEvent
            ->expects($this->exactly(3))
            ->method('addDecision')
            ->willReturnCallback(function (string $key, array $options) use (&$registeredDecisions): void {
                $registeredDecisions[$key] = $options;
            });

        $this->makeSubscriber()->onCampaignBuild($builderEvent);

        $this->assertArrayHasKey('dialoghsm.decision_delivered', $registeredDecisions);
        $this->assertArrayHasKey('dialoghsm.decision_read', $registeredDecisions);
        $this->assertArrayHasKey('dialoghsm.decision_replied', $registeredDecisions);

        foreach ($registeredDecisions as $key => $options) {
            $this->assertSame(
                \MauticPlugin\DialogHSMBundle\DialogHSMEvents::ON_CAMPAIGN_TRIGGER_DECISION,
                $options['eventName'],
                "Decision '$key' deveria usar ON_CAMPAIGN_TRIGGER_DECISION"
            );
            $this->assertSame('whatsapp', $options['channel']);
            $this->assertSame(
                ['dialoghsm.send_whatsapp', 'dialoghsm.send_whatsapp_queue', 'dialoghsm.send_whatsapp_message'],
                $options['connectionRestrictions']['source']['action']
            );
        }
    }

    private function makeDecisionEvent(
        string $eventType,
        ?MessageLog $eventDetails,
        ?int $parentEventId,
    ): CampaignExecutionEvent {
        return new CampaignExecutionEvent(
            [
                'lead'            => null,
                'event'           => [
                    'type'   => $eventType,
                    'parent' => null === $parentEventId ? null : ['id' => $parentEventId],
                ],
                'eventDetails'    => $eventDetails,
                'systemTriggered' => false,
                'eventSettings'   => [],
            ],
            null
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function decisionKeyProvider(): iterable
    {
        yield 'delivered' => ['dialoghsm.decision_delivered'];
        yield 'read'      => ['dialoghsm.decision_read'];
        yield 'replied'   => ['dialoghsm.decision_replied'];
    }

    /**
     * @dataProvider decisionKeyProvider
     */
    public function testOnCampaignTriggerDecisionPassesWhenParentMatchesLogCampaignEvent(string $decisionKey): void
    {
        $log = new MessageLog();
        $log->setCampaignEventId(42);

        $event = $this->makeDecisionEvent($decisionKey, $log, 42);

        $result = $this->makeSubscriber()->onCampaignTriggerDecision($event);

        $this->assertTrue($result->getResult());
    }

    /**
     * @dataProvider decisionKeyProvider
     */
    public function testOnCampaignTriggerDecisionFailsWhenParentDoesNotMatchLogCampaignEvent(string $decisionKey): void
    {
        $log = new MessageLog();
        $log->setCampaignEventId(42);

        // Nó de decisão pertence a outro nó de envio (id 99) na mesma campanha —
        // não deve avançar contatos de um fluxo diferente.
        $event = $this->makeDecisionEvent($decisionKey, $log, 99);

        $result = $this->makeSubscriber()->onCampaignTriggerDecision($event);

        $this->assertFalse($result->getResult());
    }

    public function testOnCampaignTriggerDecisionFailsForUnrelatedEventType(): void
    {
        $log = new MessageLog();
        $log->setCampaignEventId(42);

        $event = $this->makeDecisionEvent('email.open', $log, 42);

        $result = $this->makeSubscriber()->onCampaignTriggerDecision($event);

        $this->assertFalse($result->getResult());
    }

    public function testOnCampaignTriggerDecisionFailsWhenEventDetailsIsNotMessageLog(): void
    {
        $event = $this->makeDecisionEvent('dialoghsm.decision_read', null, 42);

        $result = $this->makeSubscriber()->onCampaignTriggerDecision($event);

        $this->assertFalse($result->getResult());
    }

    public function testOnCampaignTriggerDecisionFailsWhenParentEventIsMissing(): void
    {
        $log = new MessageLog();
        $log->setCampaignEventId(42);

        $event = $this->makeDecisionEvent('dialoghsm.decision_replied', $log, null);

        $result = $this->makeSubscriber()->onCampaignTriggerDecision($event);

        $this->assertFalse($result->getResult());
    }
}

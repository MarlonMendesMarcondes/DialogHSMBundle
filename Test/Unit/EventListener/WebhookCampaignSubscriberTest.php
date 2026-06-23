<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Event\WebhookMessageFailedEvent;
use MauticPlugin\DialogHSMBundle\EventListener\WebhookCampaignSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WebhookCampaignSubscriberTest extends TestCase
{
    private Connection&MockObject $connection;
    private WebhookCampaignSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->connection  = $this->createMock(Connection::class);
        $this->subscriber  = new WebhookCampaignSubscriber($this->connection);
    }

    private function makeLog(?int $campaignEventId, ?int $leadId): MessageLog
    {
        $log = new MessageLog();
        if ($campaignEventId !== null) {
            $log->setCampaignEventId($campaignEventId);
        }
        if ($leadId !== null) {
            $log->setLeadId($leadId);
        }

        return $log;
    }

    // =========================================================================
    // getSubscribedEvents
    // =========================================================================

    public function testSubscribesToWebhookMessageFailedEvent(): void
    {
        $events = WebhookCampaignSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(WebhookMessageFailedEvent::class, $events);
        $this->assertSame('onMessageFailed', $events[WebhookMessageFailedEvent::class]);
    }

    // =========================================================================
    // Skip quando faltam IDs de campanha
    // =========================================================================

    public function testSkipsWhenNoCampaignEventId(): void
    {
        $log = $this->makeLog(null, 42);

        $this->connection->expects($this->never())->method('executeStatement');

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($log));
    }

    public function testSkipsWhenNoLeadId(): void
    {
        $log = new MessageLog();
        $log->setCampaignEventId(10);

        $this->connection->expects($this->never())->method('executeStatement');

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($log));
    }

    // =========================================================================
    // Executa UPDATE quando ambos os IDs estão presentes
    // =========================================================================

    public function testExecutesUpdateWhenBothIdsPresent(): void
    {
        $log = $this->makeLog(10, 42);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('JSON_SET'),
                $this->equalTo(['eventId' => 10, 'leadId' => 42])
            );

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($log));
    }

    public function testUpdateTargetsCampaignLeadEventLogTable(): void
    {
        $log = $this->makeLog(7, 99);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('campaign_lead_event_log'));

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($log));
    }

    public function testUpdateOnlyAffectsNonFailedEntries(): void
    {
        $log = $this->makeLog(7, 99);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('JSON_VALID'),
                    $this->stringContains('JSON_EXTRACT'),
                    $this->stringContains('$.failed')
                )
            );

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($log));
    }

    public function testUpdateOnlyAffectsAlreadyTriggeredEntries(): void
    {
        $log = $this->makeLog(7, 99);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('is_scheduled = 0'),
                    $this->stringContains('date_triggered IS NOT NULL')
                )
            );

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($log));
    }
}

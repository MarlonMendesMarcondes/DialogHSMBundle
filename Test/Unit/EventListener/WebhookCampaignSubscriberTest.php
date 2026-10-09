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
        $this->connection->expects($this->never())->method('fetchAssociative');
        $this->connection->expects($this->never())->method('update');

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($this->makeLog(null, 42)));
    }

    public function testSkipsWhenNoLeadId(): void
    {
        $this->connection->expects($this->never())->method('fetchAssociative');
        $this->connection->expects($this->never())->method('update');

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($this->makeLog(10, null)));
    }

    // =========================================================================
    // Marca failed=1 no log mais recente já disparado
    // =========================================================================

    public function testLooksUpTheMostRecentTriggeredLogOfTheContact(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('campaign_lead_event_log'),
                    $this->stringContains('is_scheduled = 0'),
                    $this->stringContains('date_triggered IS NOT NULL'),
                    $this->stringContains('ORDER BY id DESC')
                ),
                ['eventId' => 10, 'leadId' => 42]
            )
            ->willReturn(false);
        $this->connection->expects($this->never())->method('update');

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($this->makeLog(10, 42)));
    }

    public function testWritesFailedFlagInMauticSerializedFormatKeepingExistingMetadata(): void
    {
        $this->connection->method('fetchAssociative')
            ->willReturn(['id' => 5, 'metadata' => serialize(['whatsapp' => ['status' => 'sent']])]);

        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                'campaign_lead_event_log',
                ['metadata' => serialize([
                    'whatsapp' => ['status' => 'sent'],
                    'failed'   => 1,
                    'reason'   => 'dialoghsm.campaign.error.webhook_failed',
                ])],
                ['id' => 5]
            );

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($this->makeLog(10, 42)));
    }

    public function testConvertsLegacyJsonMetadataToSerializedFormat(): void
    {
        $this->connection->method('fetchAssociative')
            ->willReturn(['id' => 5, 'metadata' => json_encode(['whatsapp' => ['status' => 'sent']])]);

        $this->connection->expects($this->once())
            ->method('update')
            ->with('campaign_lead_event_log', $this->callback(function (array $data): bool {
                $this->assertSame(['status' => 'sent'], unserialize($data['metadata'])['whatsapp']);

                return true;
            }), ['id' => 5]);

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($this->makeLog(10, 42)));
    }

    public function testDoesNotRewriteLogAlreadyMarkedAsFailed(): void
    {
        $this->connection->method('fetchAssociative')
            ->willReturn(['id' => 5, 'metadata' => serialize(['failed' => 1])]);
        $this->connection->expects($this->never())->method('update');

        $this->subscriber->onMessageFailed(new WebhookMessageFailedEvent($this->makeLog(10, 42)));
    }
}

<?php
declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use PHPUnit\Framework\TestCase;

class CampaignSubscriberTest extends TestCase
{
    public function testGetSubscribedEvents(): void
    {
        $events = CampaignSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey('mautic.campaign_on_build', $events);
        $this->assertEquals(['onCampaignBuild', 0], $events['mautic.campaign_on_build']);

        $this->assertArrayHasKey('mautic.dialoghsm.on_campaign_trigger_action', $events);
        $this->assertEquals(['onCampaignTriggerAction', 0], $events['mautic.dialoghsm.on_campaign_trigger_action']);

        $this->assertArrayHasKey('mautic.dialoghsm.on_campaign_trigger_action_queue', $events);
        $this->assertEquals(['onCampaignTriggerActionQueue', 0], $events['mautic.dialoghsm.on_campaign_trigger_action_queue']);

        $this->assertArrayHasKey('mautic.dialoghsm.on_campaign_trigger_consume_queue', $events);
        $this->assertEquals(['onCampaignTriggerConsumeQueue', 0], $events['mautic.dialoghsm.on_campaign_trigger_consume_queue']);
    }
}

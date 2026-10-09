<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\Service;

use MauticPlugin\DialogHSMBundle\Service\CampaignActivityChecker;
use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;

class CampaignActivityCheckerTest extends DatabaseTestCase
{
    private function campaignStatus(int $campaignId): string
    {
        // Instância nova: o serviço guarda o status em cache por 15s
        return (new CampaignActivityChecker($this->connection))->getStatus($campaignId);
    }

    public function testPublishedWithoutPeriodIsActive(): void
    {
        $this->assertSame(CampaignActivityChecker::ACTIVE, $this->campaignStatus($this->createCampaign()));
    }

    public function testInsidePeriodIsActive(): void
    {
        $id = $this->createCampaign(['publish_up' => $this->utcNow('-1 hour'), 'publish_down' => $this->utcNow('+1 hour')]);

        $this->assertSame(CampaignActivityChecker::ACTIVE, $this->campaignStatus($id));
    }

    public function testExpiredIsInactive(): void
    {
        $id = $this->createCampaign(['publish_down' => $this->utcNow('-1 minute')]);

        $this->assertSame(CampaignActivityChecker::INACTIVE, $this->campaignStatus($id));
    }

    public function testNotStartedYetIsInactive(): void
    {
        $id = $this->createCampaign(['publish_up' => $this->utcNow('+1 hour')]);

        $this->assertSame(CampaignActivityChecker::INACTIVE, $this->campaignStatus($id));
    }

    public function testUnpublishedIsInactive(): void
    {
        $this->assertSame(CampaignActivityChecker::INACTIVE, $this->campaignStatus($this->createCampaign(['is_published' => 0])));
    }

    public function testSoftDeletedAndMissingAreDeleted(): void
    {
        $id = $this->createCampaign(['deleted' => $this->utcNow()]);

        $this->assertSame(CampaignActivityChecker::DELETED, $this->campaignStatus($id));
        $this->assertSame(CampaignActivityChecker::DELETED, $this->campaignStatus(999999999));
    }
}

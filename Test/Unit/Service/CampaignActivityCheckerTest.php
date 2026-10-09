<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use MauticPlugin\DialogHSMBundle\Service\CampaignActivityChecker;
use PHPUnit\Framework\TestCase;

class CampaignActivityCheckerTest extends TestCase
{
    /**
     * @param array<string, mixed>|false $row
     */
    private function makeChecker(array|false $row, int $expectedQueries = 1): CampaignActivityChecker
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly($expectedQueries))
            ->method('fetchAssociative')
            ->with($this->stringContains('UTC_TIMESTAMP()'), ['id' => 7])
            ->willReturn($row);

        return new CampaignActivityChecker($connection);
    }

    public function testPublishedCampaignInsidePeriodIsActive(): void
    {
        $checker = $this->makeChecker(['is_published' => 1, 'deleted' => null, 'in_period' => 1]);

        $this->assertSame(CampaignActivityChecker::ACTIVE, $checker->getStatus(7));
    }

    public function testUnpublishedCampaignIsInactive(): void
    {
        $checker = $this->makeChecker(['is_published' => 0, 'deleted' => null, 'in_period' => 1]);

        $this->assertSame(CampaignActivityChecker::INACTIVE, $checker->getStatus(7));
    }

    public function testPublishedCampaignOutsidePeriodIsInactive(): void
    {
        // Ex.: publish_down já passou ("Expirado no prazo")
        $checker = $this->makeChecker(['is_published' => 1, 'deleted' => null, 'in_period' => 0]);

        $this->assertSame(CampaignActivityChecker::INACTIVE, $checker->getStatus(7));
    }

    public function testMissingCampaignIsDeleted(): void
    {
        $checker = $this->makeChecker(false);

        $this->assertSame(CampaignActivityChecker::DELETED, $checker->getStatus(7));
    }

    public function testSoftDeletedCampaignIsDeleted(): void
    {
        $checker = $this->makeChecker(['is_published' => 1, 'deleted' => '2026-10-09 12:00:00', 'in_period' => 1]);

        $this->assertSame(CampaignActivityChecker::DELETED, $checker->getStatus(7));
    }

    public function testStatusIsCachedBetweenMessagesOfTheSameCampaign(): void
    {
        $checker = $this->makeChecker(['is_published' => 1, 'deleted' => null, 'in_period' => 1], expectedQueries: 1);

        $checker->getStatus(7);
        $checker->getStatus(7);

        $this->assertSame(CampaignActivityChecker::ACTIVE, $checker->getStatus(7));
    }
}

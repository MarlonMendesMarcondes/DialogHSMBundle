<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Database\Pacing;

use MauticPlugin\DialogHSMBundle\DTO\BusinessHoursCalendar;
use MauticPlugin\DialogHSMBundle\Pacing\PacedLotAllocator;
use MauticPlugin\DialogHSMBundle\Pacing\PacedQueue;
use MauticPlugin\DialogHSMBundle\Pacing\PacedSettings;
use MauticPlugin\DialogHSMBundle\Test\Database\DatabaseTestCase;
use Psr\Log\NullLogger;

/**
 * SQL dos lotes programados contra MySQL real: reserva da fila (UPDATE … ORDER BY … LIMIT com
 * token), expiração de reserva, adiamento e o teto atômico de vaga por lote.
 */
class PacedQueueTest extends DatabaseTestCase
{
    private PacedQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new PacedQueue($this->connection);
    }

    private function enqueue(string $due, string $uuid = 'uuid'): int
    {
        $this->queue->enqueue(1, new \DateTimeImmutable($due, new \DateTimeZone('UTC')), 5, $uuid, ['leadId' => 1]);

        return (int) $this->connection->lastInsertId();
    }

    public function testClaimsOnlyDueMessagesAndNeverTheSameRowTwice(): void
    {
        $due    = $this->enqueue('-2 minutes', 'a');
        $future = $this->enqueue('+1 hour', 'b');

        $first  = $this->queue->claimDue('token-1', 50);
        $second = $this->queue->claimDue('token-2', 50);

        $this->assertSame([$due], array_map('intval', array_column($first, 'id')));
        $this->assertSame([], $second, 'linha reservada por outro worker não é pega');
        $this->assertNotContains($future, array_map('intval', array_column($first, 'id')));
    }

    public function testExpiredClaimIsTakenOver(): void
    {
        $id = $this->enqueue('-2 minutes');
        $this->connection->executeStatement(
            'UPDATE dialog_hsm_paced_message SET claimed_by = ?, claimed_at = ? WHERE id = ?',
            ['worker-morto', $this->utcNow('-'.(PacedQueue::CLAIM_TTL_MINUTES + 1).' minutes'), $id]
        );

        $this->assertCount(1, $this->queue->claimDue('token-novo', 50));
    }

    public function testClaimRespectsTheLimitInDueOrder(): void
    {
        $older = $this->enqueue('-10 minutes', 'a');
        $this->enqueue('-5 minutes', 'b');

        $rows = $this->queue->claimDue('token', 1);

        $this->assertSame([$older], array_map('intval', array_column($rows, 'id')));
    }

    public function testPostponeReleasesTheClaimAndCountsTheAttempt(): void
    {
        $id = $this->enqueue('-2 minutes');
        $this->queue->claimDue('token', 50);

        $this->queue->postpone($id, new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC')), true);

        $row = $this->connection->fetchAssociative('SELECT claimed_by, attempts FROM dialog_hsm_paced_message WHERE id = ?', [$id]);
        $this->assertNull($row['claimed_by']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame([], $this->queue->claimDue('token', 50), 'adiada para o futuro');
    }

    public function testSecondsUntilNextDue(): void
    {
        $this->assertNull($this->queue->secondsUntilNextDue());

        $this->enqueue('+90 seconds');
        $seconds = $this->queue->secondsUntilNextDue();
        $this->assertGreaterThanOrEqual(85, $seconds);
        $this->assertLessThanOrEqual(90, $seconds);

        $this->enqueue('-1 minute', 'vencida');
        $this->assertSame(0, $this->queue->secondsUntilNextDue());
    }

    public function testReleaseReturnsLeftoverClaims(): void
    {
        $this->enqueue('-2 minutes');
        $this->queue->claimDue('token', 50);

        $this->queue->release('token');

        $this->assertCount(1, $this->queue->claimDue('outro', 50));
    }

    public function testAllocatorNeverExceedsTheLotSize(): void
    {
        $campaignId = $this->createCampaign();
        $settings   = $this->createMock(PacedSettings::class);
        $settings->method('calendar')->willReturn(new BusinessHoursCalendar(0, 1440, [], new \DateTimeZone('UTC')));

        $allocator = new PacedLotAllocator($this->connection, $settings, new NullLogger());

        for ($i = 0; $i < 25; ++$i) {
            $allocator->allocate($campaignId, 777, 10, 30);
        }

        $allocated = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT allocated FROM dialog_hsm_paced_lot WHERE campaign_event_id = 777 ORDER BY lot_index'
        ));

        $this->assertSame(25, array_sum($allocated));
        $this->assertLessThanOrEqual(10, max($allocated));
    }
}

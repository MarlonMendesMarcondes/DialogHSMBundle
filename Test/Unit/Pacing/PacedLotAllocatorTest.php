<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use MauticPlugin\DialogHSMBundle\DTO\BusinessHoursCalendar;
use MauticPlugin\DialogHSMBundle\Pacing\PacedLotAllocator;
use MauticPlugin\DialogHSMBundle\Pacing\PacedSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * PacedLotAllocator contra uma tabela de lotes em memória: a conexão falsa interpreta só as
 * consultas que o alocador faz.
 */
class PacedLotAllocatorTest extends TestCase
{
    /** @var array<int, array{id: int, campaign_event_id: int, lot_index: int, lot_size: int, due_at: string, allocated: int}> */
    private array $lots = [];

    private int $campaignLeads = 0;
    private int $eventLogs     = 0;
    private int $locks         = 0;

    /** Sexta 2026-11-27 16:10 em São Paulo */
    private const NOW_UTC = '2026-11-27 19:10:00';

    private function allocator(): PacedLotAllocator
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params): array {
            $rows = array_filter($this->lots, static fn (array $lot): bool => $lot['campaign_event_id'] === $params['event']
                && $lot['due_at'] >= $params['since'] && $lot['allocated'] < $lot['lot_size']);

            return array_values(array_map(static fn (array $lot): array => ['id' => $lot['id'], 'due_at' => $lot['due_at']], $rows));
        });

        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            $lot = &$this->lots[$params['id']];

            if ($lot['allocated'] >= $lot['lot_size']) {
                return 0;
            }

            ++$lot['allocated'];

            return 1;
        });

        $connection->method('fetchOne')->willReturnCallback(function (string $sql): int|string|false {
            if (str_contains($sql, 'GET_LOCK')) {
                ++$this->locks;

                return 1;
            }

            return match (true) {
                str_contains($sql, 'RELEASE_LOCK')            => 1,
                str_contains($sql, 'campaign_leads')          => $this->campaignLeads,
                str_contains($sql, 'campaign_lead_event_log') => $this->eventLogs,
                default                                       => false,
            };
        });

        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql, array $params): array {
            $lots = array_filter($this->lots, static fn (array $lot): bool => $lot['campaign_event_id'] === $params['event']);

            return [
                'last_due'   => [] === $lots ? null : max(array_column($lots, 'due_at')),
                'last_index' => [] === $lots ? null : max(array_column($lots, 'lot_index')),
            ];
        });

        $connection->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $id              = count($this->lots) + 1;
            $this->lots[$id] = ['id' => $id] + $data;

            return 1;
        });

        $settings = $this->createMock(PacedSettings::class);
        $settings->method('calendar')->willReturn(new BusinessHoursCalendar(480, 1080, [1, 2, 3, 4, 5], new DateTimeZone('America/Sao_Paulo')));

        return new PacedLotAllocator(
            $connection,
            $settings,
            new NullLogger(),
            static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW_UTC, new DateTimeZone('UTC')),
        );
    }

    /**
     * @return string[] horários dos lotes do evento, em São Paulo
     */
    private function localDueTimes(): array
    {
        return array_values(array_map(
            static fn (array $lot): string => (new DateTimeImmutable($lot['due_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('D H:i'),
            $this->lots
        ));
    }

    public function testFirstContactCreatesTheLotsFromTheEstimate(): void
    {
        // 10.000 na campanha, 100 já com log (o pedaço atual) → 9.900 ÷ 500 = 20 lotes
        $this->campaignLeads = 10000;
        $this->eventLogs     = 100;

        $lot = $this->allocator()->allocate(10, 20, 500, 30);

        $this->assertCount(20, $this->lots);
        $this->assertSame(1, $this->locks);
        $this->assertSame(['Fri 16:10', 'Fri 16:40', 'Fri 17:10', 'Fri 17:40', 'Mon 08:10'], array_slice($this->localDueTimes(), 0, 5));
        $this->assertSame(range(0, 19), array_column($this->lots, 'lot_index'));
        $this->assertSame(1, array_sum(array_column($this->lots, 'allocated')));
        $this->assertSame($this->lots[$lot['id']]['due_at'], $lot['due_at']->format('Y-m-d H:i:s'));
    }

    public function testNoLotEverExceedsItsSizeAndContactsAreSpread(): void
    {
        $this->campaignLeads = 1000;
        $allocator           = $this->allocator();

        for ($i = 0; $i < 1000; ++$i) {
            $allocator->allocate(10, 20, 100, 30);
        }

        $this->assertCount(10, $this->lots);
        $this->assertSame(array_fill(0, 10, 100), array_column($this->lots, 'allocated'));
    }

    public function testContactsAreRandomizedAcrossLots(): void
    {
        $this->campaignLeads = 1000;
        $allocator           = $this->allocator();
        $firstLots           = [];

        for ($i = 0; $i < 20; ++$i) {
            $firstLots[] = $allocator->allocate(10, 20, 100, 30)['id'];
        }

        // Em ordem sequencial os 20 primeiros cairiam todos no lote 1
        $this->assertGreaterThan(3, count(array_unique($firstLots)));
    }

    public function testUnderestimateAppendsLotsAfterTheLastOne(): void
    {
        // Estimativa de 1 lote, mas chegam 3 lotes de contatos
        $this->campaignLeads = 100;
        $allocator           = $this->allocator();

        for ($i = 0; $i < 250; ++$i) {
            $allocator->allocate(10, 20, 100, 30);
        }

        $this->assertSame(['Fri 16:10', 'Fri 16:40', 'Fri 17:10'], $this->localDueTimes());
        $this->assertSame([100, 100, 50], array_column($this->lots, 'allocated'));
        $this->assertSame([0, 1, 2], array_column($this->lots, 'lot_index'));
    }

    public function testLotsAlreadyPastAreNotOffered(): void
    {
        // Lote de quinta-feira, encerrado: o contato novo vai para uma leva nova
        $this->lots[1] = ['id' => 1, 'campaign_event_id' => 20, 'lot_index' => 0, 'lot_size' => 100, 'due_at' => '2026-11-26 15:00:00', 'allocated' => 3];
        $this->campaignLeads = 50;

        $lot = $this->allocator()->allocate(10, 20, 100, 30);

        $this->assertSame(2, $lot['id']);
        $this->assertSame(1, $this->lots[2]['lot_index']);
        $this->assertSame(3, $this->lots[1]['allocated']);
    }

    public function testTheLotInProgressStillReceivesContacts(): void
    {
        // Lote que saiu há 10 min (intervalo de 30): ainda elegível
        $this->lots[1] = ['id' => 1, 'campaign_event_id' => 20, 'lot_index' => 0, 'lot_size' => 100, 'due_at' => '2026-11-27 19:00:00', 'allocated' => 3];

        $lot = $this->allocator()->allocate(10, 20, 100, 30);

        $this->assertSame(1, $lot['id']);
        $this->assertSame(0, $this->locks, 'não cria leva nova');
    }

    public function testLotsOfAnotherActionAreIgnored(): void
    {
        $this->lots[1] = ['id' => 1, 'campaign_event_id' => 99, 'lot_index' => 0, 'lot_size' => 100, 'due_at' => '2026-11-27 19:30:00', 'allocated' => 0];
        $this->campaignLeads = 10;

        $lot = $this->allocator()->allocate(10, 20, 100, 30);

        $this->assertSame(2, $lot['id']);
        $this->assertSame(0, $this->lots[1]['allocated']);
    }
}

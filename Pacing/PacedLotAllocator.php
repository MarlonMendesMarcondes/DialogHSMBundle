<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Pacing;

use Doctrine\DBAL\Connection;
use MauticPlugin\DialogHSMBundle\Entity\PacedLot;
use Psr\Log\LoggerInterface;

/**
 * Sorteia o lote de cada contato de um disparo "Batch — lotes programados".
 *
 * Os lotes pertencem à ação da campanha (campaign_event_id). Quando não há lote com vaga, cria uma
 * leva nova: estima quantos contatos ainda vão passar pela ação, divide pelo tamanho do lote e
 * distribui os horários dentro da janela comercial, a partir da próxima abertura (ou do último
 * lote existente + intervalo).
 *
 * A vaga é reservada com um UPDATE atômico (allocated < lot_size): mesmo com o trigger da
 * campanha rodando em vários processos, nenhum lote passa do tamanho.
 */
class PacedLotAllocator
{
    /** Teto de lotes por leva: protege contra estimativa absurda (ex.: lote de 1 contato numa base de 1M). */
    public const MAX_LOTS_PER_ROUND = 2000;

    private const LOCK_TIMEOUT_SECONDS = 10;

    /** @var callable(): \DateTimeImmutable */
    private $clock;

    public function __construct(
        private Connection $connection,
        private PacedSettings $settings,
        private LoggerInterface $logger,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Reserva uma vaga num lote sorteado e devolve o lote.
     *
     * Lotes elegíveis: com vaga e cujo horário ainda não passou de um intervalo (o lote em
     * andamento ainda recebe contatos; os encerrados não).
     *
     * @return array{id: int, due_at: \DateTimeImmutable}
     */
    public function allocate(?int $campaignId, int $campaignEventId, int $lotSize, int $interval): array
    {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $lot = $this->reserveRandomLot($campaignEventId, $interval);

            if (null !== $lot) {
                return $lot;
            }

            $this->createLots($campaignId, $campaignEventId, $lotSize, $interval);
        }

        throw new \RuntimeException(sprintf('Nenhum lote programado com vaga para a ação #%d.', $campaignEventId));
    }

    /**
     * @return array{id: int, due_at: \DateTimeImmutable}|null
     */
    private function reserveRandomLot(int $campaignEventId, int $interval): ?array
    {
        $candidates = $this->eligibleLots($campaignEventId, $interval);
        shuffle($candidates);

        foreach ($candidates as $lot) {
            $reserved = $this->connection->executeStatement(
                "UPDATE `{$this->table()}` SET allocated = allocated + 1 WHERE id = :id AND allocated < lot_size",
                ['id' => (int) $lot['id']]
            );

            if (1 === $reserved) {
                return [
                    'id'     => (int) $lot['id'],
                    'due_at' => new \DateTimeImmutable((string) $lot['due_at'], new \DateTimeZone('UTC')),
                ];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{id: int|string, due_at: string}>
     */
    private function eligibleLots(int $campaignEventId, int $interval): array
    {
        $since = ($this->clock)()->modify('-'.max(1, $interval).' minutes');

        return $this->connection->fetchAllAssociative(
            "SELECT id, due_at FROM `{$this->table()}`
             WHERE campaign_event_id = :event AND due_at >= :since AND allocated < lot_size",
            ['event' => $campaignEventId, 'since' => $since->format('Y-m-d H:i:s')]
        );
    }

    private function createLots(?int $campaignId, int $campaignEventId, int $lotSize, int $interval): void
    {
        $lockName = 'dialoghsm_paced_'.$campaignEventId;
        $locked   = 1 === (int) $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [$lockName, self::LOCK_TIMEOUT_SECONDS]);

        try {
            // Outro processo do trigger pode ter criado a leva enquanto esperávamos o lock
            if ([] !== $this->eligibleLots($campaignEventId, $interval)) {
                return;
            }

            $estimate = $this->estimatePending($campaignId, $campaignEventId);
            $count    = min(self::MAX_LOTS_PER_ROUND, max(1, (int) ceil($estimate / max(1, $lotSize))));

            $last = $this->connection->fetchAssociative(
                "SELECT MAX(due_at) AS last_due, MAX(lot_index) AS last_index FROM `{$this->table()}` WHERE campaign_event_id = :event",
                ['event' => $campaignEventId]
            );

            $calendar = $this->settings->calendar();
            $now      = ($this->clock)();
            $due      = $calendar->nextOpening($now);

            if (is_array($last) && null !== $last['last_due']) {
                $afterLast = $calendar->addWorkingMinutes(new \DateTimeImmutable((string) $last['last_due'], new \DateTimeZone('UTC')), $interval);

                if ($afterLast > $due) {
                    $due = $afterLast;
                }
            }

            $index = is_array($last) && null !== $last['last_index'] ? (int) $last['last_index'] + 1 : 0;
            $added = $now->format('Y-m-d H:i:s');
            $utc   = new \DateTimeZone('UTC');

            for ($i = 0; $i < $count; ++$i) {
                $this->connection->insert($this->table(), [
                    'campaign_id'       => $campaignId,
                    'campaign_event_id' => $campaignEventId,
                    'lot_index'         => $index + $i,
                    'lot_size'          => $lotSize,
                    'lot_interval'      => $interval,
                    'due_at'            => $due->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'allocated'         => 0,
                    'date_added'        => $added,
                ]);

                $due = $calendar->addWorkingMinutes($due, $interval);
            }

            $this->logger->info('DialogHSM: lotes programados criados', [
                'campaign_id'       => $campaignId,
                'campaign_event_id' => $campaignEventId,
                'estimated'         => $estimate,
                'lots'              => $count,
                'lot_size'          => $lotSize,
                'interval_minutes'  => $interval,
            ]);
        } finally {
            if ($locked) {
                $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }

    /**
     * Contatos da campanha que ainda não passaram por esta ação. É só uma estimativa: se faltar
     * vaga, outra leva é criada depois do último lote.
     */
    private function estimatePending(?int $campaignId, int $campaignEventId): int
    {
        if (null === $campaignId) {
            return 1;
        }

        try {
            $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';

            $total = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM `{$prefix}campaign_leads` WHERE campaign_id = :campaign AND manually_removed = 0",
                ['campaign' => $campaignId]
            );
            $done = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM `{$prefix}campaign_lead_event_log` WHERE event_id = :event",
                ['event' => $campaignEventId]
            );

            return max(1, $total - $done);
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: falha ao estimar contatos dos lotes programados', ['error' => $e->getMessage()]);

            return 1;
        }
    }

    private function table(): string
    {
        return (defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '').PacedLot::TABLE;
    }
}

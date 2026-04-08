<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MessageLog>
 */
class MessageLogRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'dhml';
    }

    /**
     * @param array{status?:string, dateFrom?:string, dateTo?:string, senderName?:string, contact?:string} $filters
     *
     * @return MessageLog[]
     */
    public function getLogs(int $start = 0, int $limit = 50, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('dhml');
        $this->applyFilters($qb, $filters);

        return $qb
            ->orderBy('dhml.dateSent', 'DESC')
            ->addOrderBy('dhml.id', 'DESC')
            ->setFirstResult($start)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{status?:string, dateFrom?:string, dateTo?:string, senderName?:string, contact?:string} $filters
     */
    public function countAll(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('dhml')
            ->select('COUNT(dhml.id)');
        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array{status?:string, dateFrom?:string, dateTo?:string, senderName?:string, contact?:string} $filters
     */
    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['status'])) {
            $qb->andWhere('dhml.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['dateFrom'])) {
            $qb->andWhere('dhml.dateSent >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($filters['dateFrom'] . ' 00:00:00'));
        }

        if (!empty($filters['dateTo'])) {
            $qb->andWhere('dhml.dateSent <= :dateTo')
               ->setParameter('dateTo', new \DateTime($filters['dateTo'] . ' 23:59:59'));
        }

        if (!empty($filters['senderName'])) {
            $qb->andWhere('dhml.senderName LIKE :senderName')
               ->setParameter('senderName', '%' . $filters['senderName'] . '%');
        }

        if (!empty($filters['contact'])) {
            $contact = $filters['contact'];
            if (ctype_digit($contact)) {
                $qb->andWhere('dhml.leadId = :leadId')
                   ->setParameter('leadId', (int) $contact);
            } else {
                $qb->andWhere('dhml.phoneNumber LIKE :phone')
                   ->setParameter('phone', '%' . $contact . '%');
            }
        }
    }

    /**
     * Retorna contagens por status para o período a partir de $from.
     *
     * @return array{total:int, sent:int, delivered:int, read:int, failed:int, dlq:int}
     */
    public function getStatsByPeriod(\DateTimeInterface $from): array
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();

        $rows = $conn->fetchAllAssociative(
            "SELECT status, COUNT(*) AS cnt FROM `{$tableName}` WHERE date_sent >= ? GROUP BY status",
            [$from->format('Y-m-d H:i:s')]
        );

        $stats = ['total' => 0, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0];
        foreach ($rows as $row) {
            $status       = (string) $row['status'];
            $count        = (int) $row['cnt'];
            $stats['total'] += $count;
            if (array_key_exists($status, $stats)) {
                $stats[$status] = $count;
            }
        }

        return $stats;
    }

    /**
     * Retorna envios agrupados por dia e status para os últimos N dias.
     *
     * @return array<string, array{sent:int, delivered:int, read:int, failed:int, dlq:int}>
     */
    public function getChartData(int $days = 7): array
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();
        $from      = (new \DateTime())->modify("-{$days} days")->setTime(0, 0, 0);

        $rows = $conn->fetchAllAssociative(
            "SELECT DATE(date_sent) AS day, status, COUNT(*) AS cnt
             FROM `{$tableName}`
             WHERE date_sent >= ?
             GROUP BY DATE(date_sent), status
             ORDER BY day ASC",
            [$from->format('Y-m-d H:i:s')]
        );

        $empty = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'dlq' => 0];
        $data  = [];

        // Preenche todos os dias do período com zeros para o gráfico ficar contínuo
        for ($i = $days - 1; $i >= 0; --$i) {
            $day        = (new \DateTime())->modify("-{$i} days")->format('Y-m-d');
            $data[$day] = $empty;
        }

        foreach ($rows as $row) {
            $day    = (string) $row['day'];
            $status = (string) $row['status'];
            if (isset($data[$day]) && array_key_exists($status, $data[$day])) {
                $data[$day][$status] = (int) $row['cnt'];
            }
        }

        return $data;
    }

    public function findByWamid(string $wamid): ?MessageLog
    {
        return $this->findOneBy(['wamid' => $wamid]);
    }

    /**
     * Remove os registros mais antigos, mantendo no máximo $maxRecords.
     */
    public function prune(int $maxRecords = 10000): void
    {
        $conn      = $this->getEntityManager()->getConnection();
        $tableName = $this->getEntityManager()->getClassMetadata(MessageLog::class)->getTableName();

        $count = (int) $conn->fetchOne("SELECT COUNT(*) FROM `{$tableName}`");

        if ($count <= $maxRecords) {
            return;
        }

        $toDelete = $count - $maxRecords;
        $conn->executeStatement(
            "DELETE FROM `{$tableName}` ORDER BY date_sent ASC, id ASC LIMIT {$toDelete}"
        );
    }
}

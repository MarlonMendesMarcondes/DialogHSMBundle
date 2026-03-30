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

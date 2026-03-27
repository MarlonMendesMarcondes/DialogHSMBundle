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
     * @return MessageLog[]
     */
    public function getLogs(int $start = 0, int $limit = 50): array
    {
        return $this->createQueryBuilder('dhml')
            ->orderBy('dhml.dateSent', 'DESC')
            ->addOrderBy('dhml.id', 'DESC')
            ->setFirstResult($start)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('dhml')
            ->select('COUNT(dhml.id)')
            ->getQuery()
            ->getSingleScalarResult();
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

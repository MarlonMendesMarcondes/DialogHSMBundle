<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WhatsAppNumberBalanceHistory>
 */
class WhatsAppNumberBalanceHistoryRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'bh';
    }

    public function findLatestForNumber(int $whatsAppNumberId): ?WhatsAppNumberBalanceHistory
    {
        $results = $this->createQueryBuilder('bh')
            ->andWhere('bh.whatsAppNumberId = :id')
            ->setParameter('id', $whatsAppNumberId)
            ->orderBy('bh.rechargeDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $results[0] ?? null;
    }

    /**
     * @return WhatsAppNumberBalanceHistory[]
     */
    public function findAllForNumber(int $whatsAppNumberId, int $limit = 24): array
    {
        return $this->createQueryBuilder('bh')
            ->andWhere('bh.whatsAppNumberId = :id')
            ->setParameter('id', $whatsAppNumberId)
            ->orderBy('bh.rechargeDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

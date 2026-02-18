<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WhatsAppNumber>
 */
class WhatsAppNumberRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'wn';
    }

    /**
     * @return array<array<string>>
     */
    protected function getDefaultOrder(): array
    {
        return [
            ['wn.name', 'ASC'],
        ];
    }

    /**
     * @param string|array $search
     *
     * @return array<array{id: int, name: string, phoneNumber: string}>
     */
    public function getNumberList(string|array $search = '', int $limit = 10, int $start = 0): array
    {
        $q = $this->createQueryBuilder('wn');
        $q->select('partial wn.{id, name, phoneNumber}');

        if (!empty($search)) {
            if (is_array($search)) {
                $q->andWhere($q->expr()->in('wn.id', ':search'))
                    ->setParameter('search', $search);
            } else {
                $q->andWhere(
                    $q->expr()->orX(
                        $q->expr()->like('wn.name', ':search'),
                        $q->expr()->like('wn.phoneNumber', ':search')
                    )
                )
                    ->setParameter('search', "%{$search}%");
            }
        }

        $q->andWhere('wn.isPublished = :published')
            ->setParameter('published', true);

        $q->orderBy('wn.name');

        if (!empty($limit)) {
            $q->setFirstResult($start)
                ->setMaxResults($limit);
        }

        return $q->getQuery()->getArrayResult();
    }
}

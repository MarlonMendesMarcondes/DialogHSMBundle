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
     * Returns distinct non-empty queue_name values from published numbers.
     *
     * @return string[]
     */
    public function getDistinctBulkQueueNames(): array
    {
        return $this->getDistinctQueueField('queueName');
    }

    /**
     * Returns distinct non-empty batch_queue_name values from published numbers.
     *
     * @return string[]
     */
    public function getDistinctBatchQueueNames(): array
    {
        return $this->getDistinctQueueField('batchQueueName');
    }

    /**
     * @return string[]
     */
    private function getDistinctQueueField(string $field): array
    {
        $rows = $this->createQueryBuilder('wn')
            ->select("DISTINCT wn.{$field} AS q")
            ->where("wn.{$field} IS NOT NULL")
            ->andWhere("wn.{$field} != ''")
            ->andWhere('wn.isPublished = :published')
            ->setParameter('published', true)
            ->getQuery()
            ->getArrayResult();

        return array_values(array_filter(array_column($rows, 'q')));
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

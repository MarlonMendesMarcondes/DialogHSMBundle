<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WhatsAppMessage>
 */
class WhatsAppMessageRepository extends CommonRepository
{
    /**
     * @return Paginator<WhatsAppMessage>
     */
    public function getEntities(array $args = [])
    {
        $q = $this->_em
            ->createQueryBuilder()
            ->select($this->getTableAlias())
            ->from(WhatsAppMessage::class, $this->getTableAlias(), $this->getTableAlias().'.id');

        $args['qb'] = $q;

        return parent::getEntities($args);
    }

    /**
     * @return iterable<WhatsAppMessage>
     */
    public function getPublishedBroadcastsIterable(?int $id = null): iterable
    {
        return $this->getPublishedBroadcastsQuery($id)->toIterable();
    }

    private function getPublishedBroadcastsQuery(?int $id = null): Query
    {
        $qb   = $this->createQueryBuilder($this->getTableAlias());
        $expr = $this->getPublishedByDateExpression($qb, null, true, true, true);

        if (null !== $id && 0 !== $id) {
            $expr->add(
                $qb->expr()->eq($this->getTableAlias().'.id', (int) $id)
            );
        }

        $qb->where($expr);

        return $qb->getQuery();
    }

    /**
     * Returns the base DBAL query for contacts in the segments linked to this message.
     * Excludes contacts that already have a log entry for this message (already sent).
     *
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    public function getSegmentsContactsQuery(int $messageId)
    {
        $q = $this->getEntityManager()->getConnection()->createQueryBuilder();

        $q->from(MAUTIC_TABLE_PREFIX.'dialog_hsm_wa_msg_list_xref', 'wml')
            ->join('wml', MAUTIC_TABLE_PREFIX.'lead_lists', 'll', 'll.id = wml.leadlist_id AND ll.is_published = 1')
            ->join('ll', MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'lll', 'lll.leadlist_id = wml.leadlist_id AND lll.manually_removed = 0')
            ->join('lll', MAUTIC_TABLE_PREFIX.'leads', 'l', 'lll.lead_id = l.id')
            ->where(
                $q->expr()->eq('wml.whatsapp_message_id', ':messageId')
            )
            ->setParameter('messageId', $messageId)
            ->orderBy('l.id');

        return $q;
    }

    /**
     * Returns pending contacts that have not yet received this broadcast.
     *
     * @return array<int, array{id: int, listId: int, phone: string}>
     */
    public function getPendingContacts(int $messageId, int $batchMinId = 0, int $batchSize = 100): array
    {
        $q = $this->getSegmentsContactsQuery($messageId);

        $q->select('DISTINCT l.id, ll.id as listId, COALESCE(l.mobile, l.phone) as phone');

        // Exclude contacts that already received this broadcast
        $sentQb = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $sentQb->select('null')
            ->from(MAUTIC_TABLE_PREFIX.'dialog_hsm_message_log', 'ml')
            ->where(
                $sentQb->expr()->and(
                    $sentQb->expr()->eq('ml.lead_id', 'l.id'),
                    $sentQb->expr()->eq('ml.whatsapp_message_id', $messageId)
                )
            );

        $q->andWhere(sprintf('NOT EXISTS (%s)', $sentQb->getSQL()));

        if ($batchMinId > 0) {
            $q->andWhere($q->expr()->gte('l.id', ':batchMinId'))
              ->setParameter('batchMinId', $batchMinId);
        }

        $q->setMaxResults($batchSize);

        return $q->executeQuery()->fetchAllAssociative();
    }

    public function getTableAlias(): string
    {
        return 'wm';
    }
}

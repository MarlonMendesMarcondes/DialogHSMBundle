<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class LeadTimelineSubscriber implements EventSubscriberInterface
{
    private const STATUS_CONFIG = [
        MessageLog::STATUS_PENDING_WEBHOOK => ['icon' => 'ri-time-line',              'color' => '#888888'],
        MessageLog::STATUS_SENT            => ['icon' => 'ri-checkbox-circle-line',   'color' => '#5cb85c'],
        MessageLog::STATUS_DELIVERED       => ['icon' => 'ri-check-double-line',      'color' => '#17a2b8'],
        MessageLog::STATUS_READ            => ['icon' => 'ri-eye-line',               'color' => '#0275d8'],
        MessageLog::STATUS_FAILED          => ['icon' => 'ri-close-circle-line',      'color' => '#d9534f'],
        MessageLog::STATUS_DLQ             => ['icon' => 'ri-error-warning-line',     'color' => '#f0ad4e'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::TIMELINE_ON_GENERATE => ['onTimelineGenerate', 0],
        ];
    }

    public function onTimelineGenerate(LeadTimelineEvent $event): void
    {
        foreach (self::STATUS_CONFIG as $status => $cfg) {
            $event->addEventType('dialoghsm.'.$status, $this->translator->trans('dialoghsm.log.status.'.$status));
        }

        $leadId = $event->getLeadId();
        if (null === $leadId) {
            return;
        }

        $applicableStatuses = array_filter(
            array_keys(self::STATUS_CONFIG),
            fn (string $s) => $event->isApplicable('dialoghsm.'.$s)
        );

        if (empty($applicableStatuses)) {
            return;
        }

        $allRows = $this->queryEventLog($leadId, $event->getQueryOptions());

        // Agrupar por action e paginar
        $options     = $event->getQueryOptions();
        $limit       = !empty($options['limit']) ? (int) $options['limit'] : null;
        $start       = !empty($options['start']) ? (int) $options['start'] : 0;
        $isPaginated = !empty($options['paginated']);

        $grouped = [];
        foreach ($allRows as $row) {
            $grouped[$row['action']][] = $row;
        }

        foreach (self::STATUS_CONFIG as $status => $cfg) {
            $eventTypeKey  = 'dialoghsm.'.$status;
            $eventTypeName = $this->translator->trans('dialoghsm.log.status.'.$status);
            $rows          = $grouped[$status] ?? [];

            $total  = count($rows);
            $sliced = $limit !== null ? array_slice($rows, $start, $limit) : array_slice($rows, $start);
            $stats  = $isPaginated
                ? ['total' => $total, 'results' => $sliced]
                : ['total' => $total, 'results' => $rows];

            $event->addToCounter($eventTypeKey, $stats);

            if (!$event->isApplicable($eventTypeKey) || $event->isEngagementCount()) {
                continue;
            }

            foreach ($stats['results'] as $row) {
                $props     = $row['properties'];
                $timestamp = \DateTime::createFromFormat('Y-m-d H:i:s', $row['date_added']) ?: new \DateTime($row['date_added']);

                $event->addEvent([
                    'event'           => $eventTypeKey,
                    'eventId'         => $eventTypeKey.$row['id'],
                    'eventLabel'      => !empty($props['template_name']) ? $eventTypeName.' — '.$props['template_name'] : $eventTypeName,
                    'eventType'       => $eventTypeName,
                    'timestamp'       => $timestamp,
                    'extra'           => $props,
                    'contentTemplate' => '@DialogHSM/Timeline/whatsapp_message.html.twig',
                    'icon'            => $cfg['icon'],
                    'contactId'       => $leadId,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<int, array{id: string, action: string, date_added: string, properties: array<string, mixed>}>
     */
    private function queryEventLog(int $leadId, array $options): array
    {
        $conn  = $this->em->getConnection();
        $table = $this->em->getClassMetadata(LeadEventLog::class)->getTableName();

        $qb = $conn->createQueryBuilder()
            ->select('el.id, el.action, el.date_added, el.properties')
            ->from($table, 'el')
            ->where('el.lead_id = :leadId')
            ->andWhere('el.bundle = :bundle')
            ->andWhere('el.object = :object')
            ->andWhere('el.action IN (:actions)')
            ->setParameter('leadId', $leadId)
            ->setParameter('bundle', LeadEventLogWriter::BUNDLE)
            ->setParameter('object', LeadEventLogWriter::OBJECT)
            ->setParameter('actions', array_keys(self::STATUS_CONFIG), ArrayParameterType::STRING)
            ->orderBy('el.date_added', 'DESC');

        if (!empty($options['fromDate'])) {
            $qb->andWhere('el.date_added >= :dateFrom')
               ->setParameter('dateFrom', $options['fromDate']->format('Y-m-d H:i:s'));
        }
        if (!empty($options['toDate'])) {
            $qb->andWhere('el.date_added <= :dateTo')
               ->setParameter('dateTo', $options['toDate']->format('Y-m-d H:i:s'));
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        // Decodificar properties JSON
        foreach ($rows as &$row) {
            if (is_string($row['properties'])) {
                $row['properties'] = json_decode($row['properties'], true) ?? [];
            }
        }
        unset($row);

        return $rows;
    }
}

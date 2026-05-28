<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
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
        private MessageLogRepository $repository,
        private TranslatorInterface $translator,
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
        // Registrar todos os tipos antes de verificar aplicabilidade
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

        // Uma única query para todos os status aplicáveis
        $allStats = $this->repository->getAllLogsForTimeline($leadId, $event->getQueryOptions());

        foreach (self::STATUS_CONFIG as $status => $cfg) {
            $eventTypeKey  = 'dialoghsm.'.$status;
            $eventTypeName = $this->translator->trans('dialoghsm.log.status.'.$status);
            $stats         = $allStats[$status] ?? ['total' => 0, 'results' => []];

            $event->addToCounter($eventTypeKey, $stats);

            if (!$event->isApplicable($eventTypeKey) || $event->isEngagementCount()) {
                continue;
            }

            foreach ($stats['results'] as $row) {
                $timestamp = match ($status) {
                    MessageLog::STATUS_DELIVERED => $row['date_delivered'] ?? $row['date_sent'],
                    MessageLog::STATUS_READ      => $row['date_read']      ?? $row['date_sent'],
                    default                      => $row['date_sent'],
                };

                $event->addEvent([
                    'event'           => $eventTypeKey,
                    'eventId'         => $eventTypeKey.$row['id'],
                    'eventLabel'      => $row['template_name'] ? $eventTypeName.' — '.$row['template_name'] : $eventTypeName,
                    'eventType'       => $eventTypeName,
                    'timestamp'       => $timestamp,
                    'extra'           => $row,
                    'contentTemplate' => '@DialogHSM/Timeline/whatsapp_message.html.twig',
                    'icon'            => $cfg['icon'],
                    'contactId'       => $leadId,
                ]);
            }
        }
    }
}

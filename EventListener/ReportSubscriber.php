<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\ReportEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ReportSubscriber implements EventSubscriberInterface
{
    public const CONTEXT = 'dialoghsm.message_log';

    private const ML  = 'ml';
    private const CAM = 'c';
    private const WM  = 'wm';

    public static function getSubscribedEvents(): array
    {
        return [
            ReportEvents::REPORT_ON_BUILD    => ['onReportBuilder', 0],
            ReportEvents::REPORT_ON_GENERATE => ['onReportGenerate', 0],
        ];
    }

    public function onReportBuilder(ReportBuilderEvent $event): void
    {
        if (!$event->checkContext([self::CONTEXT])) {
            return;
        }

        $columns = [
            self::ML.'.template_name' => [
                'label' => 'dialoghsm.report.column.template_name',
                'type'  => 'string',
            ],
            self::ML.'.phone_number' => [
                'label' => 'dialoghsm.report.column.phone_number',
                'type'  => 'string',
            ],
            self::ML.'.status' => [
                'label' => 'dialoghsm.report.column.status',
                'type'  => 'string',
            ],
            self::ML.'.date_sent' => [
                'label'          => 'dialoghsm.report.column.date_sent',
                'type'           => 'datetime',
                'groupByFormula' => 'DATE('.self::ML.'.date_sent)',
            ],
            self::ML.'.date_delivered' => [
                'label' => 'dialoghsm.report.column.date_delivered',
                'type'  => 'datetime',
            ],
            self::ML.'.date_read' => [
                'label' => 'dialoghsm.report.column.date_read',
                'type'  => 'datetime',
            ],
            self::ML.'.lead_id' => [
                'label' => 'dialoghsm.report.column.lead_id',
                'type'  => 'int',
                'link'  => 'mautic_contact_action',
            ],
            self::ML.'.wamid' => [
                'label' => 'dialoghsm.report.column.wamid',
                'type'  => 'string',
            ],
            self::ML.'.http_status_code' => [
                'label' => 'dialoghsm.report.column.http_status_code',
                'type'  => 'int',
            ],
            self::ML.'.error_message' => [
                'label' => 'dialoghsm.report.column.error_message',
                'type'  => 'string',
            ],
            self::CAM.'.name' => [
                'alias' => 'campaign_name',
                'label' => 'dialoghsm.report.column.campaign',
                'type'  => 'string',
            ],
            self::WM.'.name' => [
                'alias' => 'whatsapp_message_name',
                'label' => 'dialoghsm.report.column.whatsapp_message',
                'type'  => 'string',
            ],
            // Aggregate metrics — meaningful when report is grouped (e.g. by template or date)
            'sent_count' => [
                'alias'   => 'sent_count',
                'label'   => 'dialoghsm.report.column.sent_count',
                'type'    => 'int',
                'formula' => 'SUM('.self::ML.'.status IN (\'sent\', \'delivered\', \'read\'))',
            ],
            'failed_count' => [
                'alias'   => 'failed_count',
                'label'   => 'dialoghsm.report.column.failed_count',
                'type'    => 'int',
                'formula' => 'SUM('.self::ML.'.status IN (\'failed\', \'dlq\'))',
            ],
            'delivery_rate' => [
                'alias'   => 'delivery_rate',
                'label'   => 'dialoghsm.report.column.delivery_rate',
                'type'    => 'string',
                'formula' => 'CONCAT(IFNULL(ROUND(SUM('.self::ML.'.status IN (\'delivered\', \'read\')) / NULLIF(SUM('.self::ML.'.status IN (\'sent\', \'delivered\', \'read\')), 0) * 100, 1), 0), \'%\')',
            ],
            'read_rate' => [
                'alias'   => 'read_rate',
                'label'   => 'dialoghsm.report.column.read_rate',
                'type'    => 'string',
                'formula' => 'CONCAT(IFNULL(ROUND(SUM('.self::ML.'.status = \'read\') / NULLIF(SUM('.self::ML.'.status IN (\'sent\', \'delivered\', \'read\')), 0) * 100, 1), 0), \'%\')',
            ],
        ];

        $filters = [
            self::ML.'.template_name' => [
                'label' => 'dialoghsm.report.column.template_name',
                'type'  => 'text',
            ],
            self::ML.'.phone_number' => [
                'label' => 'dialoghsm.report.column.phone_number',
                'type'  => 'text',
            ],
            self::ML.'.status' => [
                'label' => 'dialoghsm.report.column.status',
                'type'  => 'select',
                'list'  => [
                    'queued'    => 'Queued',
                    'sent'      => 'Sent',
                    'delivered' => 'Delivered',
                    'read'      => 'Read',
                    'failed'    => 'Failed',
                    'dlq'       => 'Discarded',
                ],
            ],
            self::ML.'.date_sent' => [
                'label' => 'dialoghsm.report.column.date_sent',
                'type'  => 'datetime',
            ],
            self::ML.'.lead_id' => [
                'label' => 'dialoghsm.report.column.lead_id',
                'type'  => 'int',
            ],
            self::CAM.'.name' => [
                'alias' => 'campaign_name',
                'label' => 'dialoghsm.report.column.campaign',
                'type'  => 'text',
            ],
        ];

        $event->addTable(self::CONTEXT, [
            'display_name' => 'dialoghsm.report.context',
            'columns'      => $columns,
            'filters'      => $filters,
        ], 'channels');
    }

    public function onReportGenerate(ReportGeneratorEvent $event): void
    {
        if (!$event->checkContext([self::CONTEXT])) {
            return;
        }

        $qb = $event->getQueryBuilder();

        $qb->from(MAUTIC_TABLE_PREFIX.'dialog_hsm_message_log', self::ML);

        if ($event->usesColumnWithPrefix(self::CAM)) {
            $qb->leftJoin(
                self::ML,
                MAUTIC_TABLE_PREFIX.'campaigns',
                self::CAM,
                self::CAM.'.id = '.self::ML.'.campaign_id'
            );
        }

        if ($event->usesColumnWithPrefix(self::WM)) {
            $qb->leftJoin(
                self::ML,
                MAUTIC_TABLE_PREFIX.'dialog_hsm_whatsapp_messages',
                self::WM,
                self::WM.'.id = '.self::ML.'.whatsapp_message_id'
            );
        }

        $event->applyDateFilters($qb, 'date_sent', self::ML);
    }
}

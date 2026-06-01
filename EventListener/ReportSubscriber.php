<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\CoreBundle\Helper\Chart\LineChart;
use Mautic\CoreBundle\Helper\Chart\PieChart;
use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\Event\ReportGraphEvent;
use Mautic\ReportBundle\ReportEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ReportSubscriber implements EventSubscriberInterface
{
    public const CONTEXT = 'dialoghsm.message_log';

    private const ML  = 'ml';
    private const CAM = 'c';
    private const WM  = 'wm';

    public const GRAPH_LINE_SENDS_PER_DAY      = 'dialoghsm.graph.line.sends_per_day';
    public const GRAPH_LINE_DELIVERY_RATE      = 'dialoghsm.graph.line.delivery_rate';
    public const GRAPH_PIE_STATUS              = 'dialoghsm.graph.pie.status_distribution';
    public const GRAPH_TABLE_TOP_TEMPLATES     = 'dialoghsm.graph.table.top_templates';
    public const GRAPH_TABLE_TOP_READ_RATE     = 'dialoghsm.graph.table.top_read_rate';

    public static function getSubscribedEvents(): array
    {
        return [
            ReportEvents::REPORT_ON_BUILD          => ['onReportBuilder', 0],
            ReportEvents::REPORT_ON_GENERATE       => ['onReportGenerate', 0],
            ReportEvents::REPORT_ON_GRAPH_GENERATE => ['onReportGraphGenerate', 0],
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

        $event->addGraph(self::CONTEXT, 'line',  self::GRAPH_LINE_SENDS_PER_DAY);
        $event->addGraph(self::CONTEXT, 'line',  self::GRAPH_LINE_DELIVERY_RATE);
        $event->addGraph(self::CONTEXT, 'pie',   self::GRAPH_PIE_STATUS);
        $event->addGraph(self::CONTEXT, 'table', self::GRAPH_TABLE_TOP_TEMPLATES);
        $event->addGraph(self::CONTEXT, 'table', self::GRAPH_TABLE_TOP_READ_RATE);
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

    public function onReportGraphGenerate(ReportGraphEvent $event): void
    {
        if (!$event->checkContext(self::CONTEXT)) {
            return;
        }

        $graphs = $event->getRequestedGraphs();
        $qb     = $event->getQueryBuilder();

        foreach ($graphs as $g) {
            $options      = $event->getOptions($g);
            /** @var ChartQuery $chartQuery */
            $chartQuery = clone $options['chartQuery'];

            switch ($g) {
                case self::GRAPH_LINE_SENDS_PER_DAY:
                    $chart = new LineChart(null, $options['dateFrom'], $options['dateTo']);

                    $sentQuery = clone $qb;
                    $sentQuery->andWhere(self::ML.'.status = :s_sent')
                        ->setParameter('s_sent', 'sent');

                    $deliveredQuery = clone $qb;
                    $deliveredQuery->andWhere(self::ML.'.status = :s_delivered')
                        ->setParameter('s_delivered', 'delivered');

                    $readQuery = clone $qb;
                    $readQuery->andWhere(self::ML.'.status = :s_read')
                        ->setParameter('s_read', 'read');

                    $failedQuery = clone $qb;
                    $failedQuery->andWhere(
                        $failedQuery->expr()->in(self::ML.'.status', [':s_failed', ':s_dlq'])
                    )
                    ->setParameter('s_failed', 'failed')
                    ->setParameter('s_dlq', 'dlq');

                    $chartQuery->modifyTimeDataQuery($sentQuery, 'date_sent', self::ML);
                    $chartQuery->modifyTimeDataQuery($deliveredQuery, 'date_delivered', self::ML);
                    $chartQuery->modifyTimeDataQuery($readQuery, 'date_read', self::ML);
                    $chartQuery->modifyTimeDataQuery($failedQuery, 'date_sent', self::ML);

                    $chart->setDataset($options['translator']->trans('dialoghsm.report.graph.sent'), $chartQuery->loadAndBuildTimeData($sentQuery));
                    $chart->setDataset($options['translator']->trans('dialoghsm.report.graph.delivered'), $chartQuery->loadAndBuildTimeData($deliveredQuery));
                    $chart->setDataset($options['translator']->trans('dialoghsm.report.graph.read'), $chartQuery->loadAndBuildTimeData($readQuery));
                    $chart->setDataset($options['translator']->trans('dialoghsm.report.graph.failed'), $chartQuery->loadAndBuildTimeData($failedQuery));

                    // Mesma paleta de cores do dashboard de disparos
                    $palette = [
                        ['r' => 92,  'g' => 184, 'b' => 92],   // sent      #5cb85c
                        ['r' => 23,  'g' => 162, 'b' => 184],  // delivered #17a2b8
                        ['r' => 2,   'g' => 117, 'b' => 216],  // read      #0275d8
                        ['r' => 217, 'g' => 83,  'b' => 79],   // failed    #d9534f
                    ];

                    $data = $chart->render();
                    foreach ($palette as $i => $rgb) {
                        if (!isset($data['datasets'][$i])) {
                            break;
                        }
                        $base = "rgba({$rgb['r']},{$rgb['g']},{$rgb['b']}";
                        $data['datasets'][$i]['backgroundColor']           = "$base,0.1)";
                        $data['datasets'][$i]['borderColor']               = "$base,0.9)";
                        $data['datasets'][$i]['pointHoverBackgroundColor'] = "$base,1)";
                        $data['datasets'][$i]['pointHoverBorderColor']     = "$base,1)";
                    }

                    $data['name'] = $g;
                    $event->setGraph($g, $data);
                    break;

                case self::GRAPH_PIE_STATUS:
                    $conn = $event->getQueryBuilder()->getConnection();
                    $sq   = clone $qb;
                    $sq->select(
                        self::ML.'.status',
                        'COUNT(*) AS cnt'
                    )
                    ->groupBy(self::ML.'.status');

                    $rows    = $conn->fetchAllAssociative($sq->getSQL(), $sq->getParameters());
                    $buckets = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0];
                    foreach ($rows as $row) {
                        $status = $row['status'];
                        $count  = (int) $row['cnt'];
                        if (in_array($status, ['sent', 'delivered', 'read'], true)) {
                            $buckets[$status] += $count;
                        } elseif (in_array($status, ['failed', 'dlq'], true)) {
                            $buckets['failed'] += $count;
                        }
                    }

                    $pie = new PieChart();
                    $pie->setDataset($options['translator']->trans('dialoghsm.report.graph.sent'),      $buckets['sent']);
                    $pie->setDataset($options['translator']->trans('dialoghsm.report.graph.delivered'), $buckets['delivered']);
                    $pie->setDataset($options['translator']->trans('dialoghsm.report.graph.read'),      $buckets['read']);
                    $pie->setDataset($options['translator']->trans('dialoghsm.report.graph.failed'),    $buckets['failed']);

                    $pieRender = $pie->render();
                    // Paleta de cores do dashboard
                    $pieRender['labels']                        = array_keys($buckets);
                    $pieRender['datasets'][0]['backgroundColor'] = [
                        'rgba(92,184,92,0.8)',   // sent
                        'rgba(23,162,184,0.8)',  // delivered
                        'rgba(2,117,216,0.8)',   // read
                        'rgba(217,83,79,0.8)',   // failed
                    ];
                    $event->setGraph($g, ['data' => $pieRender, 'name' => $g]);
                    break;

                case self::GRAPH_TABLE_TOP_TEMPLATES:
                    $sq = clone $qb;
                    $sq->select(
                        self::ML.'.template_name AS template',
                        'SUM('.self::ML.'.status IN (\'sent\',\'delivered\',\'read\')) AS sent',
                        'SUM('.self::ML.'.status IN (\'delivered\',\'read\'))          AS delivered',
                        'SUM('.self::ML.'.status = \'read\')                           AS `read`',
                        'SUM('.self::ML.'.status IN (\'failed\',\'dlq\'))              AS failed',
                        'COUNT(*) AS total'
                    )
                    ->groupBy(self::ML.'.template_name')
                    ->orderBy('total', 'DESC')
                    ->setMaxResults(10);

                    $rows = $event->getQueryBuilder()->getConnection()
                        ->fetchAllAssociative($sq->getSQL(), $sq->getParameters());

                    $tplLabel       = $options['translator']->trans('dialoghsm.report.column.template_name');
                    $sentLabel      = $options['translator']->trans('dialoghsm.report.column.sent_count');
                    $deliveredLabel = $options['translator']->trans('dialoghsm.report.graph.delivered');
                    $readLabel      = $options['translator']->trans('dialoghsm.report.graph.read');
                    $failedLabel    = $options['translator']->trans('dialoghsm.report.column.failed_count');

                    $data = array_map(fn ($r, $i) => [
                        'id'          => $i + 1,
                        $tplLabel     => $r['template'],
                        $sentLabel    => (int) $r['sent'],
                        $deliveredLabel => (int) $r['delivered'],
                        $readLabel    => (int) $r['read'],
                        $failedLabel  => (int) $r['failed'],
                    ], $rows, array_keys($rows));

                    $tableData = [
                        'data' => $data,
                        'name' => $g,
                    ];

                    $event->setGraph($g, $tableData);
                    break;

                case self::GRAPH_LINE_DELIVERY_RATE:
                    // Taxa de entrega e leitura por dia (%) — custom SQL por dia
                    $conn   = $event->getQueryBuilder()->getConnection();
                    $sq     = clone $qb;
                    $sq->select(
                        'DATE('.self::ML.'.date_sent) AS day',
                        'SUM('.self::ML.'.status IN (\'sent\',\'delivered\',\'read\')) AS sent_plus',
                        'SUM('.self::ML.'.status IN (\'delivered\',\'read\'))          AS delivered_plus',
                        'SUM('.self::ML.'.status = \'read\')                           AS read_count'
                    )
                    ->groupBy('DATE('.self::ML.'.date_sent)')
                    ->orderBy('day', 'ASC');

                    $dailyRows = $conn->fetchAllAssociative($sq->getSQL(), $sq->getParameters());

                    $rateChart = new LineChart(null, $options['dateFrom'], $options['dateTo']);
                    $chartQuery->modifyTimeDataQuery($sq, 'date_sent', self::ML);

                    $deliveryRates = [];
                    $readRates     = [];
                    foreach ($dailyRows as $row) {
                        $sentPlus = (int) $row['sent_plus'];
                        $deliveryRates[$row['day']] = $sentPlus > 0 ? round((int) $row['delivered_plus'] / $sentPlus * 100, 1) : 0;
                        $readRates[$row['day']]     = $sentPlus > 0 ? round((int) $row['read_count']    / $sentPlus * 100, 1) : 0;
                    }

                    // Monta datasets no mesmo formato de time series do ChartQuery
                    $deliveryData = $chartQuery->loadAndBuildTimeData(
                        (clone $qb)->andWhere(self::ML.'.status IN (\'delivered\',\'read\')')
                            ->select('DATE('.self::ML.'.date_sent) AS date, SUM(1) AS count')
                            ->groupBy('DATE('.self::ML.'.date_sent)')
                    );
                    $sentData = $chartQuery->loadAndBuildTimeData(
                        (clone $qb)->andWhere(self::ML.'.status IN (\'sent\',\'delivered\',\'read\')')
                            ->select('DATE('.self::ML.'.date_sent) AS date, SUM(1) AS count')
                            ->groupBy('DATE('.self::ML.'.date_sent)')
                    );

                    // Calcula % ponto a ponto
                    $deliveryPct = array_map(
                        fn ($d, $s) => $s > 0 ? round($d / $s * 100, 1) : 0,
                        $deliveryData,
                        $sentData
                    );
                    $readPctQuery = clone $qb;
                    $readPctQuery->andWhere(self::ML.'.status = \'read\'');
                    $chartQuery->modifyTimeDataQuery($readPctQuery, 'date_sent', self::ML);
                    $readData = $chartQuery->loadAndBuildTimeData($readPctQuery);
                    $readPct  = array_map(
                        fn ($r, $s) => $s > 0 ? round($r / $s * 100, 1) : 0,
                        $readData,
                        $sentData
                    );

                    $rateChart->setDataset($options['translator']->trans('dialoghsm.report.graph.delivery_rate_pct'), $deliveryPct);
                    $rateChart->setDataset($options['translator']->trans('dialoghsm.report.graph.read_rate_pct'),     $readPct);

                    $rateData = $rateChart->render();
                    $palette  = [
                        ['r' => 23,  'g' => 162, 'b' => 184],  // delivery #17a2b8
                        ['r' => 2,   'g' => 117, 'b' => 216],  // read     #0275d8
                    ];
                    foreach ($palette as $i => $rgb) {
                        if (!isset($rateData['datasets'][$i])) {
                            break;
                        }
                        $base = "rgba({$rgb['r']},{$rgb['g']},{$rgb['b']}";
                        $rateData['datasets'][$i]['backgroundColor']           = "$base,0.1)";
                        $rateData['datasets'][$i]['borderColor']               = "$base,0.9)";
                        $rateData['datasets'][$i]['pointHoverBackgroundColor'] = "$base,1)";
                        $rateData['datasets'][$i]['pointHoverBorderColor']     = "$base,1)";
                    }
                    $rateData['name'] = $g;
                    $event->setGraph($g, $rateData);
                    break;

                case self::GRAPH_TABLE_TOP_READ_RATE:
                    $sq = clone $qb;
                    $sq->select(
                        self::ML.'.template_name AS template',
                        'COUNT(*) AS total',
                        'SUM('.self::ML.'.status IN (\'sent\',\'delivered\',\'read\')) AS sent_plus',
                        'SUM('.self::ML.'.status IN (\'delivered\',\'read\'))          AS delivered_plus',
                        'SUM('.self::ML.'.status = \'read\')                           AS read_count'
                    )
                    ->groupBy(self::ML.'.template_name')
                    ->having('sent_plus > 0')
                    ->orderBy('read_count / sent_plus', 'DESC')
                    ->setMaxResults(10);

                    $rows = $event->getQueryBuilder()->getConnection()
                        ->fetchAllAssociative($sq->getSQL(), $sq->getParameters());

                    $tplLabel  = $options['translator']->trans('dialoghsm.report.column.template_name');
                    $sentLabel = $options['translator']->trans('dialoghsm.report.column.sent_count');
                    $delLabel  = $options['translator']->trans('dialoghsm.report.graph.delivery_rate_pct');
                    $readLabel = $options['translator']->trans('dialoghsm.report.graph.read_rate_pct');

                    $data = array_map(fn ($r, $i) => [
                        'id'       => $i + 1,
                        $tplLabel  => $r['template'],
                        $sentLabel => (int) $r['sent_plus'],
                        $delLabel  => round((int) $r['delivered_plus'] / (int) $r['sent_plus'] * 100, 1).'%',
                        $readLabel => round((int) $r['read_count']     / (int) $r['sent_plus'] * 100, 1).'%',
                    ], $rows, array_keys($rows));

                    $event->setGraph($g, ['data' => $data, 'name' => $g]);
                    break;
            }
        }
    }
}

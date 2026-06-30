<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

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
            $options = $event->getOptions($g);

            switch ($g) {
                case self::GRAPH_LINE_SENDS_PER_DAY:
                    // Raw SQL direto — loadAndBuildTimeData usa $query->execute() (removido
                    // no DBAL 3.x do Mautic 5.x) e retornaria vazio silenciosamente.
                    $conn  = $event->getQueryBuilder()->getConnection();
                    $sqLn  = clone $qb;
                    $sqLn->select(
                        'DATE('.self::ML.'.date_sent)                                    AS day',
                        'SUM('.self::ML.'.status IN (\'sent\',\'delivered\',\'read\'))   AS sent_cnt',
                        'SUM('.self::ML.'.status IN (\'delivered\',\'read\'))            AS del_cnt',
                        'SUM('.self::ML.'.status = \'read\')                            AS read_cnt',
                        'SUM('.self::ML.'.date_replied IS NOT NULL)                     AS replied_cnt',
                        'SUM('.self::ML.'.status IN (\'failed\',\'dlq\'))               AS failed_cnt'
                    )
                    ->groupBy('DATE('.self::ML.'.date_sent)')
                    ->orderBy('day', 'ASC');

                    $lnRows = $conn->fetchAllAssociative($sqLn->getSQL(), $sqLn->getParameters());
                    $lnMap  = array_column($lnRows, null, 'day');

                    $bSent = $bDel = $bRead = $bReplied = $bFailed = [];
                    $cur   = (clone $options['dateFrom'])->setTime(0, 0, 0);
                    $end   = (clone $options['dateTo'])->setTime(0, 0, 0);
                    while ($cur <= $end) {
                        $d          = $cur->format('Y-m-d');
                        $r          = $lnMap[$d] ?? [];
                        $bSent[]    = (int) ($r['sent_cnt']    ?? 0);
                        $bDel[]     = (int) ($r['del_cnt']     ?? 0);
                        $bRead[]    = (int) ($r['read_cnt']    ?? 0);
                        $bReplied[] = (int) ($r['replied_cnt'] ?? 0);
                        $bFailed[]  = (int) ($r['failed_cnt']  ?? 0);
                        $cur->modify('+1 day');
                    }

                    $chart = new LineChart(null, $options['dateFrom'], $options['dateTo']);
                    $trans = $options['translator'];
                    $chart->setDataset($trans->trans('dialoghsm.report.graph.sent'),      $bSent);
                    $chart->setDataset($trans->trans('dialoghsm.report.graph.delivered'), $bDel);
                    $chart->setDataset($trans->trans('dialoghsm.report.graph.read'),      $bRead);
                    $chart->setDataset($trans->trans('dialoghsm.report.graph.replied'),   $bReplied);
                    $chart->setDataset($trans->trans('dialoghsm.report.graph.failed'),    $bFailed);

                    $palette = [
                        ['r' => 92,  'g' => 184, 'b' => 92],   // sent      #5cb85c
                        ['r' => 23,  'g' => 162, 'b' => 184],  // delivered #17a2b8
                        ['r' => 2,   'g' => 117, 'b' => 216],  // read      #0275d8
                        ['r' => 111, 'g' => 66,  'b' => 193],  // replied   #6f42c1
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
                        'SUM('.self::ML.'.status IN (\'sent\',\'delivered\',\'read\'))  AS sent',
                        'SUM('.self::ML.'.status IN (\'delivered\',\'read\'))           AS delivered',
                        'SUM('.self::ML.'.status = \'read\')                            AS read_cnt',
                        'SUM('.self::ML.'.date_replied IS NOT NULL)                     AS replied',
                        'SUM('.self::ML.'.status IN (\'failed\',\'dlq\'))               AS failed'
                    );

                    $row     = $conn->fetchAssociative($sq->getSQL(), $sq->getParameters());
                    $buckets = [
                        'sent'      => (int) ($row['sent']      ?? 0),
                        'delivered' => (int) ($row['delivered'] ?? 0),
                        'read'      => (int) ($row['read_cnt']  ?? 0),
                        'replied'   => (int) ($row['replied']   ?? 0),
                        'failed'    => (int) ($row['failed']    ?? 0),
                    ];

                    $pie = new PieChart();
                    $pie->setDataset($options['translator']->trans('dialoghsm.report.graph.sent'),      $buckets['sent']);
                    $pie->setDataset($options['translator']->trans('dialoghsm.report.graph.delivered'), $buckets['delivered']);
                    $pie->setDataset($options['translator']->trans('dialoghsm.report.graph.read'),      $buckets['read']);
                    $pie->setDataset($options['translator']->trans('dialoghsm.report.graph.replied'),   $buckets['replied']);
                    $pie->setDataset($options['translator']->trans('dialoghsm.report.graph.failed'),    $buckets['failed']);

                    $pieRender                               = $pie->render();
                    $pieRender['labels']                     = array_keys($buckets);
                    $pieRender['datasets'][0]['backgroundColor'] = [
                        'rgba(92,184,92,0.8)',    // sent
                        'rgba(23,162,184,0.8)',   // delivered
                        'rgba(2,117,216,0.8)',    // read
                        'rgba(111,66,193,0.8)',   // replied
                        'rgba(217,83,79,0.8)',    // failed
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
                        'SUM('.self::ML.'.date_replied IS NOT NULL)                    AS replied',
                        'SUM('.self::ML.'.status IN (\'failed\',\'dlq\'))              AS failed',
                        'COUNT(*) AS total'
                    )
                    ->groupBy(self::ML.'.template_name')
                    ->orderBy('COUNT(*)', 'DESC')
                    ->setMaxResults(10);

                    $rows = $event->getQueryBuilder()->getConnection()
                        ->fetchAllAssociative($sq->getSQL(), $sq->getParameters());

                    $tplLabel       = $options['translator']->trans('dialoghsm.report.column.template_name');
                    $sentLabel      = $options['translator']->trans('dialoghsm.report.column.sent_count');
                    $deliveredLabel = $options['translator']->trans('dialoghsm.report.graph.delivered');
                    $readLabel      = $options['translator']->trans('dialoghsm.report.graph.read');
                    $repliedLabel   = $options['translator']->trans('dialoghsm.report.graph.replied');
                    $failedLabel    = $options['translator']->trans('dialoghsm.report.column.failed_count');

                    $data = array_map(fn ($r, $i) => [
                        'id'            => $i + 1,
                        $tplLabel       => $r['template'],
                        $sentLabel      => (int) $r['sent'],
                        $deliveredLabel => (int) $r['delivered'],
                        $readLabel      => (int) $r['read'],
                        $repliedLabel   => (int) $r['replied'],
                        $failedLabel    => (int) $r['failed'],
                    ], $rows, array_keys($rows));

                    $event->setGraph($g, ['data' => $data, 'name' => $g]);
                    break;

                case self::GRAPH_LINE_DELIVERY_RATE:
                    $conn  = $event->getQueryBuilder()->getConnection();
                    $sqRt  = clone $qb;
                    $sqRt->select(
                        'DATE('.self::ML.'.date_sent) AS day',
                        'SUM('.self::ML.'.status IN (\'sent\',\'delivered\',\'read\')) AS sent_plus',
                        'SUM('.self::ML.'.status IN (\'delivered\',\'read\'))          AS del_plus',
                        'SUM('.self::ML.'.status = \'read\')                           AS read_cnt'
                    )
                    ->groupBy('DATE('.self::ML.'.date_sent)')
                    ->orderBy('day', 'ASC');

                    $rateRows = $conn->fetchAllAssociative($sqRt->getSQL(), $sqRt->getParameters());
                    $rateMap  = array_column($rateRows, null, 'day');

                    $bDelRate = $bReadRate = [];
                    $cur = (clone $options['dateFrom'])->setTime(0, 0, 0);
                    $end = (clone $options['dateTo'])->setTime(0, 0, 0);
                    while ($cur <= $end) {
                        $d   = $cur->format('Y-m-d');
                        $r   = $rateMap[$d] ?? [];
                        $s   = (int) ($r['sent_plus'] ?? 0);
                        $bDelRate[]  = $s > 0 ? round((int) ($r['del_plus']  ?? 0) / $s * 100, 1) : 0;
                        $bReadRate[] = $s > 0 ? round((int) ($r['read_cnt']  ?? 0) / $s * 100, 1) : 0;
                        $cur->modify('+1 day');
                    }

                    $rateChart = new LineChart(null, $options['dateFrom'], $options['dateTo']);
                    $trans     = $options['translator'];
                    $rateChart->setDataset($trans->trans('dialoghsm.report.graph.delivery_rate_pct'), $bDelRate);
                    $rateChart->setDataset($trans->trans('dialoghsm.report.graph.read_rate_pct'),     $bReadRate);

                    $rateData = $rateChart->render();
                    $palette  = [
                        ['r' => 23, 'g' => 162, 'b' => 184],  // delivery #17a2b8
                        ['r' => 2,  'g' => 117, 'b' => 216],  // read     #0275d8
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
                    ->having('SUM('.self::ML.'.status IN (\'sent\',\'delivered\',\'read\')) > 0')
                    ->orderBy('SUM('.self::ML.'.status = \'read\') / NULLIF(SUM('.self::ML.'.status IN (\'sent\',\'delivered\',\'read\')), 0)', 'DESC')
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

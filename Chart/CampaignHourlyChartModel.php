<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Chart;

use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\CoreBundle\Helper\Chart\LineChart;

/**
 * Core's campaign model, with the hourly chart of the campaign page at the right hour (LocalTimeChartQuery).
 *
 * Used only where no other plugin replaced core's campaign model (CampaignHourlyChartPass).
 */
class CampaignHourlyChartModel extends CampaignModel
{
    /**
     * Core's getCampaignMetricsLineChartData() (Mautic 5.2), with LocalTimeChartQuery in place of ChartQuery: core
     * creates its chart query with `new`, so the method is repeated. Compare it with core's after a Mautic update.
     *
     * @param string|null          $unit
     * @param string|null          $dateFormat
     * @param array<string, mixed> $filter
     *
     * @return array<string, mixed>
     */
    public function getCampaignMetricsLineChartData($unit, \DateTime $dateFrom, \DateTime $dateTo, $dateFormat = null, $filter = []): array
    {
        $events = [];
        $chart  = new LineChart($unit, $dateFrom, $dateTo, $dateFormat);
        $query  = $this->createChartQuery($dateFrom, $dateTo);

        $contacts = $query->fetchTimeData('campaign_leads', 'date_added', $filter);
        $chart->setDataset($this->translator->trans('mautic.campaign.campaign.leads'), $contacts);

        if (isset($filter['campaign_id'])) {
            $rawEvents = $this->getEventRepository()->getCampaignEvents($filter['campaign_id']);

            foreach ($rawEvents as $event) {
                $events[$event['type']][] = $event['id'];
            }

            foreach ($events as $type => $eventIds) {
                $filter['event_id'] = $eventIds;

                if ($this->coreParametersHelper->get('campaign_use_summary')) {
                    $q = $query->prepareTimeDataQuery('campaign_summary', 'date_triggered', $filter, 'triggered_count + non_action_path_taken_count', 'sum');
                } else {
                    // Failed events are not counted.
                    $failedSq = $this->em->getConnection()->createQueryBuilder();
                    $failedSq->select('null')
                        ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_failed_log', 'fe')
                        ->where($failedSq->expr()->eq('fe.log_id', 't.id'));
                    $filter['failed_events'] = [
                        'subquery' => sprintf('NOT EXISTS (%s)', $failedSq->getSQL()),
                    ];

                    $q = $query->prepareTimeDataQuery('campaign_lead_event_log', 'date_triggered', $filter);
                }

                $rawData = $q->executeQuery()->fetchAllAssociative();

                if (!empty($rawData)) {
                    $chart->setDataset($this->translator->trans('mautic.campaign.'.$type), $query->completeTimeData($rawData));
                }
            }
        }

        return $chart->render();
    }

    protected function createChartQuery(\DateTime $dateFrom, \DateTime $dateTo): ChartQuery
    {
        return new LocalTimeChartQuery($this->em->getConnection(), $dateFrom, $dateTo);
    }
}

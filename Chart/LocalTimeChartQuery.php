<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Chart;

use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\CoreBundle\Helper\DateTimeHelper;

/**
 * Core's chart query, without the second time zone shift of the hourly charts.
 *
 * For an hour unit, core's SQL already gives the hours in the system time zone (CONVERT_TZ in getDateConstruct), and
 * completeTimeData() then reads them as UTC and moves them to the time zone again: in Brazil a send at 10am is drawn
 * at 7am. Here the hours go back to UTC before core's completeTimeData(), so core moves them once.
 *
 * Same fix as the MauticPatchesBundle one: see CampaignHourlyChartPass for which of the two is used.
 * Not a service: models create their chart query with `new`, so Config/services.php excludes this folder.
 */
class LocalTimeChartQuery extends ChartQuery
{
    /**
     * @param array<int|string, array<string, mixed>> $rawData
     */
    public function completeTimeData($rawData, $countAverage = false): array
    {
        if ($this->isTimeUnit) {
            $rawData = self::sqlLocalHoursToUtc($rawData, (new DateTimeHelper())->getLocalTimezoneOffset());
        }

        return parent::completeTimeData($rawData, $countAverage);
    }

    /**
     * @param array<int|string, array<string, mixed>> $rawData   rows of the SQL, with `date` in the time zone of $sqlOffset
     * @param string                                   $sqlOffset the offset the SQL converted to, such as "-03:00"
     *
     * @return array<int|string, array<string, mixed>> the same rows, with `date` in UTC
     */
    public static function sqlLocalHoursToUtc(array $rawData, string $sqlOffset): array
    {
        $sqlTz = new \DateTimeZone($sqlOffset);
        $utcTz = new \DateTimeZone('UTC');

        foreach ($rawData as &$item) {
            // A row already compared, or a date object, did not come from the SQL text.
            if (isset($item['date_comparison']) || !isset($item['date']) || !is_string($item['date'])) {
                continue;
            }

            $item['date'] = (new \DateTime($item['date'], $sqlTz))->setTimezone($utcTz)->format('Y-m-d H:i:s');
        }

        return $rawData;
    }
}

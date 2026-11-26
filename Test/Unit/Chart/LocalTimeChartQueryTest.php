<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Unit\Chart;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\Chart\ChartQuery;
use Mautic\CoreBundle\Helper\DateTimeHelper;
use MauticPlugin\DialogHSMBundle\Chart\LocalTimeChartQuery;
use PHPUnit\Framework\TestCase;

class LocalTimeChartQueryTest extends TestCase
{
    public function testTheSqlHoursGoBackToUtc(): void
    {
        $rows = LocalTimeChartQuery::sqlLocalHoursToUtc([['date' => '2026-11-26 10:00', 'count' => 796]], '-03:00');

        $this->assertSame([['date' => '2026-11-26 13:00:00', 'count' => 796]], $rows);
    }

    public function testRowsThatDidNotComeFromTheSqlTextAreNotTouched(): void
    {
        $date = new \DateTime('2026-11-26 10:00');
        $rows = [
            ['date' => $date, 'count' => 1],
            ['date' => '2026-11-26 10:00', 'date_comparison' => $date, 'count' => 2],
            ['count' => 3],
        ];

        $this->assertSame($rows, LocalTimeChartQuery::sqlLocalHoursToUtc($rows, '-03:00'));
    }

    /**
     * A contact added at 13:00 UTC: core's hourly chart puts it one time zone shift too early, this one at its hour.
     */
    public function testTheHourlyChartPutsTheCountAtTheHourItHappened(): void
    {
        // The SQL converts to the system time zone (as core's getDateConstruct does); the chart is in the same zone.
        $offset = (new DateTimeHelper())->getLocalTimezoneOffset();
        $tz     = new \DateTimeZone($offset);
        $sqlRow = (new \DateTime('2026-11-26 13:00:00', new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:00');
        $hour   = (int) substr($sqlRow, 11, 2);
        $from   = new \DateTime('2026-11-26 00:00:00', $tz);
        $to     = new \DateTime('2026-11-26 23:59:59', $tz);

        $fixed = (new LocalTimeChartQuery($this->createMock(Connection::class), $from, $to, 'H'))
            ->completeTimeData([['date' => $sqlRow, 'count' => 796]]);
        $this->assertSame(796, $fixed[$hour]);
        $this->assertSame(796, array_sum($fixed));

        if ('+00:00' === $offset) {
            return; // Without a shift, core's chart is right too.
        }

        $core = (new ChartQuery($this->createMock(Connection::class), $from, $to, 'H'))
            ->completeTimeData([['date' => $sqlRow, 'count' => 796]]);
        $this->assertSame(0, $core[$hour], 'core places the count at the hour: its double shift may have been fixed, and this patch may go');
    }

    public function testDailyChartsAreNotChanged(): void
    {
        $tz   = new \DateTimeZone('America/Sao_Paulo');
        $from = new \DateTime('2026-11-20 00:00:00', $tz);
        $to   = new \DateTime('2026-11-26 23:59:59', $tz);
        $rows = [['date' => '2026-11-26', 'count' => 796]];

        $this->assertSame(
            (new ChartQuery($this->createMock(Connection::class), $from, $to, 'd'))->completeTimeData($rows),
            (new LocalTimeChartQuery($this->createMock(Connection::class), $from, $to, 'd'))->completeTimeData($rows)
        );
    }
}

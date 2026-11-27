<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\DTO\BusinessHoursCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BusinessHoursCalendarTest extends TestCase
{
    private const TZ = 'America/Sao_Paulo';

    private function calendar(int $start = 480, int $end = 1080, array $days = [1, 2, 3, 4, 5]): BusinessHoursCalendar
    {
        return new BusinessHoursCalendar($start, $end, $days, new \DateTimeZone(self::TZ));
    }

    private function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone(self::TZ));
    }

    public function testIsOpenRespectsHoursAndDays(): void
    {
        $calendar = $this->calendar();

        // 2026-11-27 é sexta-feira
        $this->assertTrue($calendar->isOpen($this->at('2026-11-27 08:00')));
        $this->assertTrue($calendar->isOpen($this->at('2026-11-27 17:59')));
        $this->assertFalse($calendar->isOpen($this->at('2026-11-27 18:00')), 'o fechamento é exclusivo');
        $this->assertFalse($calendar->isOpen($this->at('2026-11-27 07:59')));
        $this->assertFalse($calendar->isOpen($this->at('2026-11-28 10:00')), 'sábado fora dos dias');
    }

    public function testIsOpenConvertsFromUtc(): void
    {
        // 11:00 UTC = 08:00 em São Paulo
        $this->assertTrue($this->calendar()->isOpen(new \DateTimeImmutable('2026-11-27 11:00', new \DateTimeZone('UTC'))));
        $this->assertFalse($this->calendar()->isOpen(new \DateTimeImmutable('2026-11-27 10:59', new \DateTimeZone('UTC'))));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nextOpeningProvider(): iterable
    {
        yield 'aberta: o próprio instante'       => ['2026-11-27 16:10', '2026-11-27 16:10'];
        yield 'antes de abrir: abre no mesmo dia' => ['2026-11-27 06:30', '2026-11-27 08:00'];
        yield 'depois de fechar: dia útil seguinte (sexta → segunda)' => ['2026-11-27 18:30', '2026-11-30 08:00'];
        yield 'fim de semana: segunda'            => ['2026-11-29 12:00', '2026-11-30 08:00'];
    }

    #[DataProvider('nextOpeningProvider')]
    public function testNextOpening(string $from, string $expected): void
    {
        $this->assertSame($expected, $this->calendar()->nextOpening($this->at($from))->format('Y-m-d H:i'));
    }

    public function testAddWorkingMinutesWithinTheSameDay(): void
    {
        $this->assertSame('2026-11-27 16:40', $this->calendar()->addWorkingMinutes($this->at('2026-11-27 16:10'), 30)->format('Y-m-d H:i'));
    }

    public function testAddWorkingMinutesSpillsToTheNextWorkingDay(): void
    {
        // 17:40 + 30 min: 20 min até fechar, 10 min a partir da abertura de segunda
        $this->assertSame('2026-11-30 08:10', $this->calendar()->addWorkingMinutes($this->at('2026-11-27 17:40'), 30)->format('Y-m-d H:i'));
    }

    public function testAddWorkingMinutesLandingExactlyOnClosingGoesToTheNextOpening(): void
    {
        $this->assertSame('2026-11-30 08:00', $this->calendar()->addWorkingMinutes($this->at('2026-11-27 17:30'), 30)->format('Y-m-d H:i'));
    }

    public function testAddWorkingMinutesLongerThanTheWindow(): void
    {
        // Janela de 10h: 16h a partir de segunda 08:00 = segunda inteira + 6h de terça
        $this->assertSame('2026-12-01 14:00', $this->calendar()->addWorkingMinutes($this->at('2026-11-30 08:00'), 16 * 60)->format('Y-m-d H:i'));
    }

    public function testAddWorkingMinutesFromOutsideTheWindowStartsAtTheOpening(): void
    {
        $this->assertSame('2026-11-30 08:30', $this->calendar()->addWorkingMinutes($this->at('2026-11-28 22:00'), 30)->format('Y-m-d H:i'));
    }

    public function testExampleFromThePlan(): void
    {
        // Disparo começando sexta 16:10, lotes a cada 30 min: 4 lotes na sexta, o resto na segunda
        $calendar = $this->calendar();
        $due      = $calendar->nextOpening($this->at('2026-11-27 16:10'));
        $times    = [];

        for ($i = 0; $i < 6; ++$i) {
            $times[] = $due->format('D H:i');
            $due     = $calendar->addWorkingMinutes($due, 30);
        }

        $this->assertSame(['Fri 16:10', 'Fri 16:40', 'Fri 17:10', 'Fri 17:40', 'Mon 08:10', 'Mon 08:40'], $times);
    }

    public function testFullDayWindowAndAllDays(): void
    {
        $calendar = $this->calendar(0, 1440, []);

        $this->assertTrue($calendar->isOpen($this->at('2026-11-29 23:59')));
        $this->assertSame('2026-11-30 00:20', $calendar->addWorkingMinutes($this->at('2026-11-29 23:50'), 30)->format('Y-m-d H:i'));
    }

    public function testInvalidWindowFallsBackToFullDay(): void
    {
        $calendar = $this->calendar(1080, 480);

        $this->assertTrue($calendar->isOpen($this->at('2026-11-27 03:00')));
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function parseTimeProvider(): iterable
    {
        yield 'HH:MM'          => ['08:00', 480];
        yield 'H:MM'           => ['8:30', 510];
        yield 'fim do dia'     => ['24:00', 1440];
        yield 'inválido'       => ['8h', 999];
        yield 'hora inválida'  => ['25:00', 999];
        yield 'não é string'   => [null, 999];
    }

    #[DataProvider('parseTimeProvider')]
    public function testParseTime(mixed $value, int $expected): void
    {
        $this->assertSame($expected, BusinessHoursCalendar::parseTime($value, 999));
    }
}

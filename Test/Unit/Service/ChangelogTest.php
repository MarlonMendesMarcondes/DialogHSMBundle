<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Service\Changelog;
use PHPUnit\Framework\TestCase;

class ChangelogTest extends TestCase
{
    public function testParsesDaysAndItemsInFileOrder(): void
    {
        $days = Changelog::parse("# Changelog\n\nTexto solto.\n\n## 2026-11-27\n- Primeira\n- Segunda\n\n## 2026-11-26\n- Terceira\n");

        $this->assertSame([
            ['date' => '2026-11-27', 'items' => ['Primeira', 'Segunda']],
            ['date' => '2026-11-26', 'items' => ['Terceira']],
        ], $days);
    }

    public function testIgnoresChangesOutsideADatedDay(): void
    {
        $days = Changelog::parse("- antes de qualquer data\n## 2026-11-27\n- Do dia\n## Não lançado\n- Fora do dia\n");

        $this->assertSame([['date' => '2026-11-27', 'items' => ['Do dia']]], $days);
    }

    public function testMissingFileGivesNoDays(): void
    {
        $this->assertSame([], Changelog::days('/caminho/que/nao/existe.md'));
    }

    public function testThePluginChangelogIsNewestFirstAndEveryDayHasChanges(): void
    {
        $days  = Changelog::days();
        $dates = array_column($days, 'date');

        $this->assertNotEmpty($days);
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, 'do mais novo para o mais antigo');

        foreach ($days as $day) {
            $this->assertNotEmpty($day['items'], $day['date'].' sem mudanças');
        }
    }
}

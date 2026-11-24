<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Service\Variable\DateFormatter;
use MauticPlugin\DialogHSMBundle\Service\Variable\DecimalFormatter;
use MauticPlugin\DialogHSMBundle\Service\Variable\FieldCalculator;
use MauticPlugin\DialogHSMBundle\Service\Variable\NumberCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Formatação e cálculo das variáveis de campo — mesmas regras do N8nDispatchBundle, para a mesma
 * variável sair igual nos dois plugins.
 */
class FormattersTest extends TestCase
{
    public function testDateFollowsTheTemplateLanguage(): void
    {
        $this->assertSame('25/12/2026', DateFormatter::format('2026-12-25', 'date', 'pt_BR'));
        $this->assertSame('12/25/2026', DateFormatter::format('2026-12-25', 'date', 'en_US'));
        $this->assertSame('25/12/2026', DateFormatter::format('2026-12-25', 'date', 'en_GB'));
        $this->assertSame('25/12/2026', DateFormatter::format('2026-12-25 00:00:00', 'date', 'pt_BR'), 'coluna DATETIME de campo date');
        $this->assertSame('25/12/2026 14:30:00', DateFormatter::format('2026-12-25 14:30:00', 'datetime', 'pt_BR'));
        $this->assertSame('25/12/2026', DateFormatter::format('2026-12-25', 'date', 'xx_YY'), 'idioma desconhecido usa pt_BR');
    }

    public function testDateCalculationMovesWholeDaysUpToTenYears(): void
    {
        $this->assertSame('04/01/2027', DateFormatter::format('2026-12-25', 'date', 'pt_BR', 10));
        $this->assertSame('15/12/2026', DateFormatter::format('2026-12-25', 'date', 'pt_BR', -10));
        $this->assertSame('25/12/2026 23:59:00', DateFormatter::format('2026-12-25 23:59:00', 'datetime', 'pt_BR', 0));
        $this->assertSame(DateFormatter::format('2026-01-01', 'date', 'pt_BR', 3650), DateFormatter::format('2026-01-01', 'date', 'pt_BR', 99999));
    }

    public function testValueThatIsNotADateIsKept(): void
    {
        $this->assertSame('amanhã', DateFormatter::format('amanhã', 'date'));
        $this->assertSame('2026-12-25', DateFormatter::format('2026-12-25', 'text'));
        $this->assertSame('', DateFormatter::format('', 'date'));
    }

    public function testDecimalKeepsTwoPlacesAndTheLanguageSeparator(): void
    {
        $this->assertSame('1,10', DecimalFormatter::format('1.100000', 'decimal', 'pt_BR'));
        $this->assertSame('3.75', DecimalFormatter::format('3.750000', 'decimal', 'en'));
        $this->assertSame('1,0001', DecimalFormatter::format('1.000100', 'decimal', 'pt_BR'));
        $this->assertSame('2,00', DecimalFormatter::format('2', 'decimal', 'pt_BR'));
        $this->assertSame('1.100000', DecimalFormatter::format('1.100000', 'int', 'pt_BR'), 'outro tipo fica como está');
    }

    public function testNumberCalculationWithoutFloatRounding(): void
    {
        $this->assertSame('15', NumberCalculator::add('5', 'number', '10'));
        $this->assertSame('-5', NumberCalculator::add('5', 'int', '-10'));
        $this->assertSame('15', NumberCalculator::add('5', 'int', '10.9'), 'inteiro usa só a parte inteira');
        $this->assertSame('0.300000', NumberCalculator::add('0.100000', 'decimal', '0.2'), 'sem o 0.30000000000000004 do float');
        $this->assertSame('2.5', NumberCalculator::add('3', 'decimal', '-0.5'));
        $this->assertSame('abc', NumberCalculator::add('abc', 'number', '1'));
        $this->assertSame('5', NumberCalculator::add('5', 'text', '1'));
    }

    public function testFieldCalculatorPicksThePathByType(): void
    {
        $this->assertSame('26/12/2026', FieldCalculator::apply('2026-12-25', 'date', 'pt_BR', '1'));
        $this->assertSame('11', FieldCalculator::apply('10', 'number', 'pt_BR', '1'));
        $this->assertSame('Maria', FieldCalculator::apply('Maria', 'text', 'pt_BR', '5'));
    }
}

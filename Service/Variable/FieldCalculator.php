<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service\Variable;

/**
 * Aplica o cálculo da variável a um valor gravado, de campo do contato ou de Custom Object: data
 * anda a parte inteira em dias (DateFormatter), número recebe a soma (NumberCalculator). Cada um
 * ignora o tipo do outro; qualquer outro tipo volta como está.
 */
final class FieldCalculator
{
    public static function apply(string $value, string $type, string $locale, string $calculation): string
    {
        return NumberCalculator::add(DateFormatter::format($value, $type, $locale, (int) $calculation), $type, $calculation);
    }
}

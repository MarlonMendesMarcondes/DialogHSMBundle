<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service\Variable;

/**
 * Campo decimal de Custom Object sai do banco com seis casas ("1.100000"). Vai com pelo menos duas
 * casas e, da terceira em diante, só até a última diferente de zero (1,10 · 3,75 · 1,0001), com o
 * separador do idioma. Trabalha no texto, nunca em float, para não arredondar.
 */
final class DecimalFormatter
{
    private const MIN_PLACES = 2;

    public static function format(string $value, string $type, string $locale = LocaleConventions::DEFAULT_LOCALE): string
    {
        if ('decimal' !== $type || 1 !== preg_match('/^(-?\d+)(?:\.(\d+))?$/', $value, $parts)) {
            return $value;
        }

        $fraction = str_pad(rtrim($parts[2] ?? '', '0'), self::MIN_PLACES, '0');

        return $parts[1].LocaleConventions::decimalSeparator($locale).$fraction;
    }
}

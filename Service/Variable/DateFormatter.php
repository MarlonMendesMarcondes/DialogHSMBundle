<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service\Variable;

/**
 * Data de campo do contato ou de Custom Object no formato do idioma do template: o Mautic guarda
 * 'Y-m-d' / 'Y-m-d H:i:s', e o contato tem de ler '25/12/2026' (pt_BR).
 *
 * O cálculo de uma data é em dias corridos (negativo volta), limitado a dez anos para os dois
 * lados — mais que isso sai da faixa que o PHP formata. Feito no relógio gravado, em UTC, para a
 * hora de um datetime não mudar.
 */
final class DateFormatter
{
    public const MAX_DAYS = 3650;

    /**
     * Formatos gravados, na ordem: a coluna de data do Mautic é DATETIME, então um campo 'date'
     * pode voltar como 'Y-m-d 00:00:00'.
     */
    private const SOURCE_FORMATS = [
        'date'     => ['Y-m-d', 'Y-m-d H:i:s'],
        'datetime' => ['Y-m-d H:i:s'],
    ];

    /**
     * Valor de outro tipo, ou que não está no formato gravado, volta como está: melhor enviar o
     * valor bruto do que perder a variável.
     */
    public static function format(string $value, string $type, string $locale = LocaleConventions::DEFAULT_LOCALE, int $addDays = 0): string
    {
        if ('' === $value || !isset(self::SOURCE_FORMATS[$type])) {
            return $value;
        }

        $date = false;
        foreach (self::SOURCE_FORMATS[$type] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value, new \DateTimeZone('UTC'));
            if (false !== $date && $date->format($format) === $value) {
                break;
            }
            $date = false;
        }

        if (false === $date) {
            return $value;
        }

        if (0 !== $addDays) {
            $date->modify(sprintf('%+d days', max(-self::MAX_DAYS, min(self::MAX_DAYS, $addDays))));
        }

        return $date->format(LocaleConventions::dateFormat($locale, $type));
    }
}

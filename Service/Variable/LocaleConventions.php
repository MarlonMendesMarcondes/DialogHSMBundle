<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service\Variable;

/**
 * Como datas e decimais são escritos em cada idioma do template (códigos de locale do Mautic:
 * 'pt_BR', 'en', 'en_GB'...).
 *
 * Mesmas convenções do N8nDispatchBundle, para a mesma variável sair igual nos dois plugins.
 * Busca: o código exato, depois a parte do idioma ('en_US' → 'en'), depois o padrão (pt_BR).
 */
final class LocaleConventions
{
    public const DEFAULT_LOCALE = 'pt_BR';

    private const CONVENTIONS = [
        'pt_BR' => ['date' => 'd/m/Y', 'decimalSeparator' => ','],
        'en'    => ['date' => 'm/d/Y', 'decimalSeparator' => '.'],
        'en_GB' => ['date' => 'd/m/Y', 'decimalSeparator' => '.'],
    ];

    public static function dateFormat(string $locale, string $type): string
    {
        $date = self::lookup($locale)['date'];

        return 'datetime' === $type ? $date.' H:i:s' : $date;
    }

    public static function decimalSeparator(string $locale): string
    {
        return self::lookup($locale)['decimalSeparator'];
    }

    /**
     * @return array{date: string, decimalSeparator: string}
     */
    private static function lookup(string $locale): array
    {
        return self::CONVENTIONS[$locale]
            ?? self::CONVENTIONS[explode('_', $locale)[0]]
            ?? self::CONVENTIONS[self::DEFAULT_LOCALE];
    }
}

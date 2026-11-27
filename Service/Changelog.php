<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

/**
 * O CHANGELOG.md do plugin, lido para a aba Atualizações da configuração: um bloco por dia, do
 * mais novo para o mais antigo. Formato: uma linha "## AAAA-MM-DD" e uma linha "- " por mudança.
 * O resto é ignorado, inclusive mudança fora de um dia (antes da primeira data ou sob outro "##").
 *
 * Mesmo formato do Changelog do MauticPatchesBundle.
 */
class Changelog
{
    public const FILE = __DIR__.'/../CHANGELOG.md';

    /**
     * @return list<array{date: string, items: list<string>}>
     */
    public static function days(string $path = self::FILE): array
    {
        $text = is_readable($path) ? file_get_contents($path) : false;

        return false === $text ? [] : self::parse($text);
    }

    /**
     * @return list<array{date: string, items: list<string>}>
     */
    public static function parse(string $markdown): array
    {
        $days   = [];
        $isOpen = false;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (1 === preg_match('/^##\s+(\d{4}-\d{2}-\d{2})\s*$/', $line, $match)) {
                $days[] = ['date' => $match[1], 'items' => []];
                $isOpen = true;

                continue;
            }

            // Outro título "##" (ex.: "## Não lançado") encerra o dia: as mudanças dele não são do dia anterior
            if (1 === preg_match('/^##(?!#)/', $line)) {
                $isOpen = false;

                continue;
            }

            if ($isOpen && 1 === preg_match('/^-\s+(.+?)\s*$/', $line, $match)) {
                $days[array_key_last($days)]['items'][] = $match[1];
            }
        }

        return $days;
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service\Variable;

/**
 * Cálculo de uma variável numérica: "campo - 10". Vale para o tipo 'number' do contato e para
 * 'int' e 'decimal' de Custom Object; outro tipo volta como está.
 *
 * Soma dígito a dígito sobre o texto gravado, nunca em float, para um DECIMAL(20,6) manter as
 * casas sem arredondar. Sem limite (o de dez anos é só para datas).
 */
final class NumberCalculator
{
    private const TYPES = ['number', 'int', 'decimal'];

    /**
     * $amount é o texto da caixa de cálculo ('13', '-0.5'): só 'decimal' usa as casas; os outros
     * tipos usam a parte inteira. O resultado mantém as casas do valor (ou mais, se $amount tiver).
     */
    public static function add(string $value, string $type, string $amount): string
    {
        if ('0' === $amount || !in_array($type, self::TYPES, true)
            || 1 !== preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $parts)
            || 1 !== preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $amount, $addParts)) {
            return $value;
        }

        $addFraction = 'decimal' === $type ? ($addParts[3] ?? '') : '';
        $fraction    = $parts[3] ?? '';
        $scale       = max(strlen($fraction), strlen($addFraction));
        $leftSign    = '-' === $parts[1] ? -1 : 1;
        $leftDigits  = self::stripZeros($parts[2].str_pad($fraction, $scale, '0'));
        $rightDigits = self::stripZeros($addParts[2].str_pad($addFraction, $scale, '0'));

        if ('0' === $rightDigits) {
            return $value;
        }

        [$sign, $digits] = self::signedAdd($leftSign, $leftDigits, '-' === $addParts[1] ? -1 : 1, $rightDigits);
        $digits          = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $result          = substr($digits, 0, strlen($digits) - $scale).(0 < $scale ? '.'.substr($digits, -$scale) : '');

        return (-1 === $sign && 1 === preg_match('/[1-9]/', $digits) ? '-' : '').$result;
    }

    private static function stripZeros(string $digits): string
    {
        $digits = ltrim($digits, '0');

        return '' === $digits ? '0' : $digits;
    }

    /**
     * @return array{0: int, 1: string} sinal e dígitos da soma
     */
    private static function signedAdd(int $leftSign, string $left, int $rightSign, string $right): array
    {
        if ($leftSign === $rightSign) {
            return [$leftSign, self::sum($left, $right)];
        }

        $order = self::compare($left, $right);
        if (0 === $order) {
            return [1, '0'];
        }

        return $order > 0
            ? [$leftSign, self::difference($left, $right)]
            : [$rightSign, self::difference($right, $left)];
    }

    private static function compare(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    private static function sum(string $a, string $b): string
    {
        $length = max(strlen($a), strlen($b));
        $a      = str_pad($a, $length, '0', STR_PAD_LEFT);
        $b      = str_pad($b, $length, '0', STR_PAD_LEFT);
        $carry  = 0;
        $out    = '';

        for ($i = $length - 1; $i >= 0; --$i) {
            $total = (int) $a[$i] + (int) $b[$i] + $carry;
            $out   = ($total % 10).$out;
            $carry = intdiv($total, 10);
        }

        return (0 < $carry ? (string) $carry : '').$out;
    }

    /** $a não é menor que $b. */
    private static function difference(string $a, string $b): string
    {
        $b      = str_pad($b, strlen($a), '0', STR_PAD_LEFT);
        $borrow = 0;
        $out    = '';

        for ($i = strlen($a) - 1; $i >= 0; --$i) {
            $step   = (int) $a[$i] - (int) $b[$i] - $borrow;
            $borrow = $step < 0 ? 1 : 0;
            $out    = ($step + 10 * $borrow).$out;
        }

        return self::stripZeros($out);
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\DTO;

/**
 * Janela comercial dos lotes programados: horário de início e fim do dia e os dias da semana em
 * que há envio, no fuso do Mautic. Só faz contas de data (sem I/O).
 *
 * Fica em DTO/ e não em Pacing/: o carregador global de serviços do Mautic registra tudo fora
 * dos diretórios excluídos, e um construtor com escalares quebra o cache:clear.
 */
final class BusinessHoursCalendar
{
    /** @var int[] dias ISO-8601 (1 = segunda … 7 = domingo) */
    private array $days;

    /**
     * @param int   $startMinute minuto do dia em que a janela abre (ex.: 480 = 08:00)
     * @param int   $endMinute   minuto do dia em que a janela fecha (exclusivo)
     * @param int[] $days        dias ISO-8601 com envio; vazio = todos
     */
    public function __construct(
        private int $startMinute,
        private int $endMinute,
        array $days,
        private \DateTimeZone $timezone,
    ) {
        if ($this->startMinute < 0 || $this->endMinute > 1440 || $this->startMinute >= $this->endMinute) {
            // Janela inválida não pode travar envio: vale o dia inteiro
            $this->startMinute = 0;
            $this->endMinute   = 1440;
        }

        $days       = array_values(array_unique(array_filter(array_map('intval', $days), static fn (int $d): bool => $d >= 1 && $d <= 7)));
        $this->days = [] === $days ? [1, 2, 3, 4, 5, 6, 7] : $days;
    }

    /**
     * "08:00" → 480. Valor inválido devolve o padrão.
     */
    public static function parseTime(mixed $value, int $default): int
    {
        if (!is_string($value) || !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m)) {
            return '24:00' === (is_string($value) ? trim($value) : '') ? 1440 : $default;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    public function isOpen(\DateTimeInterface $at): bool
    {
        $local = $this->toLocal($at);

        return $this->isWorkingDay($local) && $this->isWithinHours($local);
    }

    /**
     * O próprio instante se a janela estiver aberta; senão a próxima abertura.
     */
    public function nextOpening(\DateTimeInterface $at): \DateTimeImmutable
    {
        $local = $this->toLocal($at);

        if ($this->isWorkingDay($local)) {
            $minute = $this->minuteOfDay($local);

            if ($minute < $this->startMinute) {
                return $this->atMinute($local, $this->startMinute);
            }

            if ($minute < $this->endMinute) {
                return $local;
            }
        }

        // Fechada hoje: primeira abertura dos próximos dias úteis
        for ($i = 1; $i <= 7; ++$i) {
            $day = $local->modify('+'.$i.' day');

            if ($this->isWorkingDay($day)) {
                return $this->atMinute($day, $this->startMinute);
            }
        }

        return $local; // inalcançável: sempre há ao menos um dia útil
    }

    /**
     * Avança $minutes contando só o tempo dentro da janela: o que passa do fechamento continua
     * na próxima abertura.
     */
    public function addWorkingMinutes(\DateTimeInterface $from, int $minutes): \DateTimeImmutable
    {
        $current   = $this->nextOpening($from);
        $remaining = max(0, $minutes);

        while ($remaining > 0) {
            $untilClose = $this->endMinute - $this->minuteOfDay($current);

            if ($remaining < $untilClose) {
                return $current->modify('+'.$remaining.' minutes');
            }

            $remaining -= $untilClose;
            $current    = $this->nextOpening($this->atMinute($current, $this->endMinute - 1)->modify('+1 minute'));
        }

        return $current;
    }

    public function getTimezone(): \DateTimeZone
    {
        return $this->timezone;
    }

    private function toLocal(\DateTimeInterface $at): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($at)->setTimezone($this->timezone);
    }

    private function isWorkingDay(\DateTimeImmutable $local): bool
    {
        return in_array((int) $local->format('N'), $this->days, true);
    }

    private function isWithinHours(\DateTimeImmutable $local): bool
    {
        $minute = $this->minuteOfDay($local);

        return $minute >= $this->startMinute && $minute < $this->endMinute;
    }

    private function minuteOfDay(\DateTimeImmutable $local): int
    {
        return (int) $local->format('G') * 60 + (int) $local->format('i');
    }

    private function atMinute(\DateTimeImmutable $local, int $minute): \DateTimeImmutable
    {
        return $local->setTime(intdiv($minute, 60), $minute % 60);
    }
}

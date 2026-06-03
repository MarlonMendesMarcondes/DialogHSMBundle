<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;

class OptimalTimeResolver
{
    private const MIN_INTERACTIONS       = 5;
    private const FETCH_FROM_DAYS        = 60;
    private const MAX_OPTIMAL_DAYS       = 3;

    // Fallback: sem horário comercial → 9h–18h, qualquer dia
    private const FALLBACK_HOUR_START    = 9;
    private const FALLBACK_HOUR_END      = 18;

    // Fallback: com horário comercial → 8h–12h, seg–sex
    private const FALLBACK_BH_HOUR_START = 8;
    private const FALLBACK_BH_HOUR_END   = 12;

    // Aplicação do filtro de horário comercial sobre o horário calculado
    private const BUSINESS_HOUR_START    = 8;
    private const BUSINESS_HOUR_END      = 18;
    private const BUSINESS_DAYS          = [1, 2, 3, 4, 5]; // seg–sex ISO 8601

    public function __construct(
        private MessageLogRepository $messageLogRepository,
        private CoreParametersHelper $coreParametersHelper,
    ) {
    }

    public function resolve(Lead $contact, bool $restrictBusinessHours, ?\DateTime $now = null): \DateTime
    {
        $timezone = $contact->getTimezone()
            ? new \DateTimeZone($contact->getTimezone())
            : new \DateTimeZone($this->coreParametersHelper->get('default_timezone', 'UTC'));

        $now = $now !== null
            ? (clone $now)->setTimezone($timezone)
            : new \DateTime('now', $timezone);
        $fromDate = (clone $now)->modify('-' . self::FETCH_FROM_DAYS . ' days');

        $reads = $this->messageLogRepository->getReadInteractionsByLead($contact->getId(), $fromDate);

        if (count($reads) >= self::MIN_INTERACTIONS) {
            return $this->resolveFromData($reads, $now, $timezone, $restrictBusinessHours);
        }

        return $this->resolveFallback($now, $restrictBusinessHours, $contact->getId());
    }

    /**
     * @param array<int, array{dateRead: \DateTime}> $reads
     */
    private function resolveFromData(array $reads, \DateTime $now, \DateTimeZone $timezone, bool $restrictBusinessHours): \DateTime
    {
        $hours = [];
        $days  = [];

        foreach ($reads as $row) {
            $dt = clone $row['dateRead'];
            $dt->setTimezone($timezone);
            $hours[] = (int) $dt->format('G');
            $days[]  = (int) $dt->format('N');
        }

        [$hourStart, $hourEnd] = $this->calculateOptimalHourRange($hours);
        $bestDays              = $this->calculateOptimalDays($days);

        $currentHour    = (int) $now->format('G');
        $currentDay     = (int) $now->format('N');
        $alreadyOptimal = in_array($currentDay, $bestDays, true)
            && $this->isHourInRange($currentHour, $hourStart, $hourEnd);

        $optimal = $alreadyOptimal
            ? clone $now
            : $this->findNextSlot($now, $hourStart, $bestDays);

        return $restrictBusinessHours ? $this->applyBusinessHours($optimal) : $optimal;
    }

    private function resolveFallback(\DateTime $now, bool $restrictBusinessHours, int $contactId): \DateTime
    {
        return $restrictBusinessHours
            ? $this->fallbackBusinessHours($now, $contactId)
            : $this->fallbackOpen($now, $contactId);
    }

    /**
     * Sem horário comercial: distribui contatos ao longo de 9h–18h usando lead_id como semente.
     * Garante que cada contato tenha um slot único e determinístico dentro da janela de 540 min,
     * evitando concentração de carga num único horário fixo.
     */
    private function fallbackOpen(\DateTime $now, int $contactId): \DateTime
    {
        $windowMinutes = (self::FALLBACK_HOUR_END - self::FALLBACK_HOUR_START) * 60; // 540
        $slotMinutes   = $contactId % $windowMinutes;
        $targetHour    = self::FALLBACK_HOUR_START + intdiv($slotMinutes, 60);
        $targetMinute  = $slotMinutes % 60;

        $dt = clone $now;
        $dt->setTime($targetHour, $targetMinute);

        if ($dt <= $now) {
            $dt->modify('+1 day');
        }

        return $dt;
    }

    /**
     * Com horário comercial: distribui contatos ao longo de 8h–12h (seg–sex) usando lead_id.
     * Janela de 240 min → cada contato recebe um slot único dentro do período matutino.
     */
    private function fallbackBusinessHours(\DateTime $now, int $contactId): \DateTime
    {
        $windowMinutes = (self::FALLBACK_BH_HOUR_END - self::FALLBACK_BH_HOUR_START) * 60; // 240
        $slotMinutes   = $contactId % $windowMinutes;
        $targetHour    = self::FALLBACK_BH_HOUR_START + intdiv($slotMinutes, 60);
        $targetMinute  = $slotMinutes % 60;

        $dt = clone $now;
        $dt->setTime($targetHour, $targetMinute);

        if ($dt <= $now || !in_array((int) $dt->format('N'), self::BUSINESS_DAYS, true)) {
            $dt->modify('+1 day');
            while (!in_array((int) $dt->format('N'), self::BUSINESS_DAYS, true)) {
                $dt->modify('+1 day');
            }
            $dt->setTime($targetHour, $targetMinute);
        }

        return $dt;
    }

    private function findNextSlot(\DateTime $now, int $hourStart, array $bestDays): \DateTime
    {
        $dt = clone $now;
        $dt->setTime($hourStart, 0);

        if ($dt <= $now) {
            $dt->modify('+1 day');
        }

        while (!in_array((int) $dt->format('N'), $bestDays, true)) {
            $dt->modify('+1 day');
        }

        return $dt;
    }

    private function applyBusinessHours(\DateTime $dt): \DateTime
    {
        $result = clone $dt;

        while (!in_array((int) $result->format('N'), self::BUSINESS_DAYS, true)) {
            $result->modify('+1 day')->setTime(self::BUSINESS_HOUR_START, 0);
        }

        $hour = (int) $result->format('G');
        if ($hour < self::BUSINESS_HOUR_START) {
            $result->setTime(self::BUSINESS_HOUR_START, 0);
        } elseif ($hour >= self::BUSINESS_HOUR_END) {
            $result->modify('+1 day')->setTime(self::BUSINESS_HOUR_START, 0);
            while (!in_array((int) $result->format('N'), self::BUSINESS_DAYS, true)) {
                $result->modify('+1 day');
            }
        }

        return $result;
    }

    private function isHourInRange(int $hour, int $start, int $end): bool
    {
        if ($start <= $end) {
            return $hour >= $start && $hour < $end;
        }
        // Janela que atravessa meia-noite (ex: start=23, end=1)
        return $hour >= $start || $hour < $end;
    }

    /** @param int[] $hours */
    private function calculateOptimalHourRange(array $hours): array
    {
        sort($hours);
        $median = $hours[(int) floor((count($hours) - 1) / 2)];

        return [($median + 23) % 24, ($median + 1) % 24];
    }

    /** @param int[] $days */
    private function calculateOptimalDays(array $days): array
    {
        $frequency = array_count_values($days);
        arsort($frequency);

        return array_slice(array_keys($frequency), 0, self::MAX_OPTIMAL_DAYS);
    }
}

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

    // Fallback por perfil inferido — todos em seg–sex (HSM é intrusivo, evita fim de semana)
    private const FALLBACK_B2B_HOUR_START     = 9;   // B2B: 9h–11h — horário de trabalho, início do dia
    private const FALLBACK_B2B_HOUR_END       = 11;
    private const FALLBACK_B2C_HOUR_START     = 17;  // B2C: 17h–19h — pós-trabalho, celular na mão
    private const FALLBACK_B2C_HOUR_END       = 19;
    private const FALLBACK_UNKNOWN_HOUR_START = 10;  // Desconhecido: 10h–11h — denominador comum seguro
    private const FALLBACK_UNKNOWN_HOUR_END   = 11;

    // Fallback: com horário comercial → 8h–12h, seg–sex
    private const FALLBACK_BH_HOUR_START = 8;
    private const FALLBACK_BH_HOUR_END   = 12;

    // Aplicação do filtro de horário comercial sobre o horário calculado
    private const BUSINESS_HOUR_START    = 8;
    private const BUSINESS_HOUR_END      = 18;
    private const BUSINESS_DAYS          = [1, 2, 3, 4, 5]; // seg–sex ISO 8601

    // Domínios de email gratuitos: indicam B2C
    private const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com',
        'hotmail.com', 'hotmail.com.br',
        'outlook.com', 'outlook.com.br',
        'yahoo.com', 'yahoo.com.br',
        'icloud.com', 'me.com', 'mac.com',
        'live.com', 'live.com.br',
        'msn.com',
        'bol.com.br', 'uol.com.br',
        'terra.com.br', 'ig.com.br',
        'r7.com', 'zipmail.com.br',
    ];

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

        return $this->resolveFallback($now, $restrictBusinessHours, $contact->getId(), $contact);
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

    private function resolveFallback(\DateTime $now, bool $restrictBusinessHours, int $contactId, Lead $contact): \DateTime
    {
        return $restrictBusinessHours
            ? $this->fallbackBusinessHours($now, $contactId)
            : $this->fallbackByProfile($now, $contactId, $contact);
    }

    /**
     * Fallback sem dados WA: segmenta por perfil inferido do contato.
     *
     * B2B (empresa preenchida ou domínio corporativo) → 9h–11h seg–sex
     * B2C (domínio de email gratuito)                 → 17h–19h seg–sex (pós-trabalho)
     * Desconhecido                                     → 10h–11h seg–sex
     *
     * Todos os perfis usam apenas seg–sex: HSM é intrusivo, evitar fim de semana.
     * Distribuição por lead_id dentro da janela para evitar thundering herd.
     */
    private function fallbackByProfile(\DateTime $now, int $contactId, Lead $contact): \DateTime
    {
        [$hourStart, $hourEnd] = match ($this->inferContactType($contact)) {
            'b2b'   => [self::FALLBACK_B2B_HOUR_START, self::FALLBACK_B2B_HOUR_END],
            'b2c'   => [self::FALLBACK_B2C_HOUR_START, self::FALLBACK_B2C_HOUR_END],
            default => [self::FALLBACK_UNKNOWN_HOUR_START, self::FALLBACK_UNKNOWN_HOUR_END],
        };

        $windowMinutes = ($hourEnd - $hourStart) * 60;
        $slotMinutes   = $contactId % $windowMinutes;
        $targetHour    = $hourStart + intdiv($slotMinutes, 60);
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

    /**
     * Infere B2B, B2C ou desconhecido a partir dos campos do contato.
     *
     * Empresa preenchida → B2B (sinal mais forte).
     * Sem empresa: domínio do email decide — gratuito = B2C, corporativo = B2B.
     * Sem empresa e sem email → desconhecido.
     */
    private function inferContactType(Lead $contact): string
    {
        if (trim((string) ($contact->getCompany() ?? '')) !== '') {
            return 'b2b';
        }

        $email = trim((string) ($contact->getEmail() ?? ''));
        if ($email !== '') {
            $atPos = strrpos($email, '@');
            if ($atPos !== false) {
                $domain = strtolower(substr($email, $atPos + 1));
                if (in_array($domain, self::FREE_EMAIL_DOMAINS, true)) {
                    return 'b2c';
                }
                if ($domain !== '') {
                    return 'b2b';
                }
            }
        }

        return 'unknown';
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

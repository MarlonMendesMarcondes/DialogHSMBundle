<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Pacing;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\DTO\BusinessHoursCalendar;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;

/**
 * Configuração dos lotes programados ("Batch — lotes programados"), lida da tela de
 * configuração do plugin.
 */
class PacedSettings
{
    /** Valor de queue_override da ação de campanha para os lotes programados. */
    public const QUEUE_MODE = 'batch_paced';

    public const MIN_INTERVAL_MINUTES = 5;

    public const DEFAULT_RATE_PER_MINUTE = 60;
    public const DEFAULT_HOURS_START     = '08:00';
    public const DEFAULT_HOURS_END       = '18:00';
    public const DEFAULT_DAYS            = [1, 2, 3, 4, 5];

    private const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    public function __construct(
        private IntegrationsHelper $integrationsHelper,
        private CoreParametersHelper $coreParametersHelper,
    ) {
    }

    /**
     * Chave geral. Desligada, a opção não aparece na ação e ações já salvas com ela caem no
     * batch comum. O que já estiver agendado continua saindo.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->getApiKeys()['paced_enabled'] ?? false);
    }

    /**
     * Mensagens por minuto por número na trilha dos lotes programados (0 = sem limite).
     */
    public function getRatePerMinute(): int
    {
        return max(0, (int) ($this->getApiKeys()['paced_rate_per_minute'] ?? self::DEFAULT_RATE_PER_MINUTE));
    }

    public function calendar(): BusinessHoursCalendar
    {
        $apiKeys = $this->getApiKeys();
        $days    = $apiKeys['business_hours_days'] ?? self::DEFAULT_DAYS;

        return new BusinessHoursCalendar(
            BusinessHoursCalendar::parseTime($apiKeys['business_hours_start'] ?? self::DEFAULT_HOURS_START, 480),
            BusinessHoursCalendar::parseTime($apiKeys['business_hours_end'] ?? self::DEFAULT_HOURS_END, 1080),
            is_array($days) ? $days : self::DEFAULT_DAYS,
            $this->timezone(),
        );
    }

    private function timezone(): \DateTimeZone
    {
        $name = (string) ($this->coreParametersHelper->get('default_timezone') ?: self::DEFAULT_TIMEZONE);

        try {
            return new \DateTimeZone($name);
        } catch (\Throwable) {
            return new \DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getApiKeys(): array
    {
        try {
            $integration = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);

            return $integration->getIntegrationConfiguration()->getApiKeys() ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}

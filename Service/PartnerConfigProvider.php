<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;

/**
 * Lê partner_id e partner_api_key configurados na tela de integração DialogHSM
 * (Mautic Integrations > 360dialog WhatsApp > Config), armazenados criptografados
 * junto com as demais chaves da integração (getApiKeys()).
 *
 * Credenciais de conta (partner), compartilhadas entre todos os WhatsAppNumber —
 * diferente de client_id/channel_id, que são por número.
 */
class PartnerConfigProvider
{
    public function __construct(
        private IntegrationsHelper $integrationsHelper,
    ) {
    }

    public function getPartnerId(): ?string
    {
        return $this->readFromPlugin('partner_id');
    }

    public function getPartnerApiKey(): ?string
    {
        return $this->readFromPlugin('partner_api_key');
    }

    /**
     * Limiar de saldo (na moeda da conta) abaixo do qual um alerta é disparado.
     * Null se não configurado (alerta desabilitado).
     */
    public function getBalanceAlertThreshold(): ?float
    {
        $value = $this->readFromPlugin('balance_alert_threshold');

        return (null !== $value && '' !== $value) ? (float) $value : null;
    }

    /**
     * IDs dos usuários Mautic escolhidos para receber o alerta de saldo.
     * Vazio = usa o fallback (todos os admins), resolvido pelo chamador.
     *
     * @return int[]
     */
    public function getBalanceAlertRecipientIds(): array
    {
        $ids = $this->getApiKeys()['balance_alert_recipients'] ?? [];

        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * Se true, o alerta de saldo também é enviado por e-mail (além da
     * notificação in-app), usando o MailHelper/SMTP já configurado no Mautic.
     */
    public function getBalanceAlertSendEmail(): bool
    {
        return (bool) ($this->getApiKeys()['balance_alert_send_email'] ?? false);
    }

    public const PREFLIGHT_OFF     = 'off';
    public const PREFLIGHT_LOG     = 'log';
    public const PREFLIGHT_ENFORCE = 'enforce';

    public const PAYMENT_PAUSE_DEFAULT_MINUTES = 30;
    private const PAYMENT_PAUSE_MIN_MINUTES    = 5;
    private const PAYMENT_PAUSE_MAX_MINUTES    = 1440;

    /**
     * Modo da pré-validação do template antes do envio (feature flag de rollout):
     *   off     — não valida (comportamento anterior);
     *   log     — valida e só registra no log o que reprovaria, sem mudar o envio (padrão);
     *   enforce — normaliza os parâmetros e não envia o que reprovar (status invalid).
     * O padrão é log para o deploy não mudar nenhum envio antes de o resultado ser conferido.
     */
    public function getPreflightMode(): string
    {
        $mode = $this->readFromPlugin('preflight_mode');

        return in_array($mode, [self::PREFLIGHT_OFF, self::PREFLIGHT_LOG, self::PREFLIGHT_ENFORCE], true)
            ? $mode
            : self::PREFLIGHT_LOG;
    }

    /**
     * Pausa do número quando a 360dialog o bloqueia por falta de pagamento. Ligada por
     * padrão: desligada, as mensagens voltam a virar falha (comportamento anterior).
     */
    public function isPaymentPauseEnabled(): bool
    {
        return (bool) ($this->getApiKeys()['payment_pause_enabled'] ?? true);
    }

    /**
     * Intervalo entre as tentativas de envio de um número pausado por falta de pagamento.
     */
    public function getPaymentPauseMinutes(): int
    {
        $value = (int) ($this->readFromPlugin('payment_pause_minutes') ?? self::PAYMENT_PAUSE_DEFAULT_MINUTES);

        return max(self::PAYMENT_PAUSE_MIN_MINUTES, min(self::PAYMENT_PAUSE_MAX_MINUTES, $value));
    }

    private function readFromPlugin(string $field): ?string
    {
        $value = $this->getApiKeys()[$field] ?? null;

        return ('' !== $value && null !== $value) ? (string) $value : null;
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

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle;

final class DialogHSMEvents
{
    public const ON_CAMPAIGN_TRIGGER_ACTION         = 'mautic.dialoghsm.on_campaign_trigger_action';
    public const ON_CAMPAIGN_TRIGGER_ACTION_QUEUE   = 'mautic.dialoghsm.on_campaign_trigger_action_queue';
    public const ON_MARKETING_MESSAGE_SEND          = 'mautic.dialoghsm.on_marketing_message_send';
    public const ON_CAMPAIGN_TRIGGER_DECISION       = 'mautic.dialoghsm.on_campaign_trigger_decision';

    // Tipos de evento da Webhook nativa do Mautic (WebhookBundle) — mesma chave usada
    // para registrar no checklist "Eventos da Webhook" e para disparar a fila via
    // WebhookModel::queueWebhooksByType(), igual ao padrão do SmsBundle/EmailBundle core.
    public const WEBHOOK_MESSAGE_SENT           = 'mautic.dialoghsm.webhook.message_sent';
    public const WEBHOOK_MESSAGE_DELIVERED      = 'mautic.dialoghsm.webhook.message_delivered';
    public const WEBHOOK_MESSAGE_READ           = 'mautic.dialoghsm.webhook.message_read';
    public const WEBHOOK_MESSAGE_REPLIED        = 'mautic.dialoghsm.webhook.message_replied';
    public const WEBHOOK_MESSAGE_FAILED         = 'mautic.dialoghsm.webhook.message_failed';
    public const WEBHOOK_MESSAGE_BUTTON_CLICKED = 'mautic.dialoghsm.webhook.message_button_clicked';
}

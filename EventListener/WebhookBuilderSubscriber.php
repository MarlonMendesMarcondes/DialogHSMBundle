<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\WebhookBundle\Event\WebhookBuilderEvent;
use Mautic\WebhookBundle\WebhookEvents;
use MauticPlugin\DialogHSMBundle\DialogHSMEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Registra os eventos de WhatsApp no checklist "Eventos da Webhook" da Webhook nativa
 * do Mautic (WebhookBundle) — o mesmo painel usado por "Evento de abertura de email" etc.
 * O disparo em si acontece em WebhookProcessor via WebhookModel::queueWebhooksByType(),
 * usando as mesmas chaves registradas aqui (padrão do SmsBundle/EmailBundle core).
 */
class WebhookBuilderSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WebhookEvents::WEBHOOK_ON_BUILD => 'onWebhookBuild',
        ];
    }

    public function onWebhookBuild(WebhookBuilderEvent $event): void
    {
        $event->addEvent(DialogHSMEvents::WEBHOOK_MESSAGE_SENT, [
            'label'       => 'dialoghsm.webhook.event.message_sent',
            'description' => 'dialoghsm.webhook.event.message_sent.tooltip',
        ]);

        $event->addEvent(DialogHSMEvents::WEBHOOK_MESSAGE_DELIVERED, [
            'label'       => 'dialoghsm.webhook.event.message_delivered',
            'description' => 'dialoghsm.webhook.event.message_delivered.tooltip',
        ]);

        $event->addEvent(DialogHSMEvents::WEBHOOK_MESSAGE_READ, [
            'label'       => 'dialoghsm.webhook.event.message_read',
            'description' => 'dialoghsm.webhook.event.message_read.tooltip',
        ]);

        $event->addEvent(DialogHSMEvents::WEBHOOK_MESSAGE_REPLIED, [
            'label'       => 'dialoghsm.webhook.event.message_replied',
            'description' => 'dialoghsm.webhook.event.message_replied.tooltip',
        ]);

        $event->addEvent(DialogHSMEvents::WEBHOOK_MESSAGE_FAILED, [
            'label'       => 'dialoghsm.webhook.event.message_failed',
            'description' => 'dialoghsm.webhook.event.message_failed.tooltip',
        ]);

        $event->addEvent(DialogHSMEvents::WEBHOOK_MESSAGE_BUTTON_CLICKED, [
            'label'       => 'dialoghsm.webhook.event.message_button_clicked',
            'description' => 'dialoghsm.webhook.event.message_button_clicked.tooltip',
        ]);
    }
}

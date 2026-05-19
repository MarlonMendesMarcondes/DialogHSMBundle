<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\ChannelBundle\ChannelEvents;
use Mautic\ChannelBundle\Event\ChannelEvent;
use Mautic\ChannelBundle\Model\MessageModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\ReportBundle\Model\ReportModel;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Form\Type\WhatsAppMessageListType;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ChannelSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly IntegrationHelper $integrationHelper,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelEvents::ADD_CHANNEL => ['onAddChannel', 90],
        ];
    }

    public function onAddChannel(ChannelEvent $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject(DialogHSMIntegration::NAME);
        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return;
        }

        $event->addChannel(
            'whatsapp',
            [
                MessageModel::CHANNEL_FEATURE => [
                    'campaignAction'  => 'dialoghsm.send_whatsapp',
                    'lookupFormType'  => WhatsAppMessageListType::class,
                    'repository'      => WhatsAppMessage::class,
                ],
                LeadModel::CHANNEL_FEATURE   => [],
                ReportModel::CHANNEL_FEATURE => [
                    'table' => 'dialog_hsm_whatsapp_messages',
                ],
            ]
        );
    }
}

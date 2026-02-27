<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Helper\TokenHelper;
use MauticPlugin\DialogHSMBundle\DialogHSMEvents;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppType;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;

class CampaignSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private IntegrationsHelper $integrationsHelper,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private WhatsAppNumberModel $whatsAppNumberModel,
        private SendWhatsAppMessageHandler $handler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD                 => ['onCampaignBuild', 0],
            DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION       => ['onCampaignTriggerAction', 0],
            DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION_QUEUE => ['onCampaignTriggerActionQueue', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(
            'dialoghsm.send_whatsapp',
            [
                'label'          => 'dialoghsm.campaign.send_whatsapp',
                'description'    => 'dialoghsm.campaign.send_whatsapp.tooltip',
                'batchEventName' => DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION,
                'formType'       => SendWhatsAppType::class,
                'channel'        => 'whatsapp',
            ]
        );

        $event->addAction(
            'dialoghsm.send_whatsapp_queue',
            [
                'label'          => 'dialoghsm.campaign.send_whatsapp_queue',
                'description'    => 'dialoghsm.campaign.send_whatsapp_queue.tooltip',
                'batchEventName' => DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION_QUEUE,
                'formType'       => SendWhatsAppType::class,
                'channel'        => 'whatsapp',
            ]
        );
    }

    public function onCampaignTriggerAction(PendingEvent $event): void
    {
        if (!$event->checkContext('dialoghsm.send_whatsapp')) {
            return;
        }

        $this->processContacts($event, function (SendWhatsAppMessage $message, WhatsAppNumber $number): bool {
            $result = ($this->handler)($message);

            return $result['success'] ?? false;
        });
    }

    public function onCampaignTriggerActionQueue(PendingEvent $event): void
    {
        if (!$event->checkContext('dialoghsm.send_whatsapp_queue')) {
            return;
        }

        $config = $event->getEvent()->getProperties();
        $mode   = trim($config['queue_override'] ?? '');

        $this->processContacts($event, function (SendWhatsAppMessage $message, WhatsAppNumber $number) use ($mode): bool {
            $queueName = match ($mode) {
                'batch' => $number->getBatchQueueName() ?: $number->getQueueName(),
                'queue' => $number->getQueueName(),
                default => $number->getQueueName(),
            };

            $stamps = $queueName ? [new AmqpStamp($queueName)] : [];
            $this->bus->dispatch($message, $stamps);

            return true;
        }, applyBatchSleep: false);
    }

    private function processContacts(PendingEvent $event, callable $sender, bool $applyBatchSleep = true): void
    {
        if (!$this->isIntegrationEnabled()) {
            $event->failAll('dialoghsm.campaign.error.integration_disabled');

            return;
        }

        $config = $event->getEvent()->getProperties();

        $numberId       = (int) ($config['whatsapp_number'] ?? 0);
        $whatsAppNumber = $this->getWhatsAppNumber($numberId);

        if (null === $whatsAppNumber) {
            $event->failAll('dialoghsm.campaign.error.missing_number');

            return;
        }

        $apiKey  = $whatsAppNumber->getApiKey();
        $baseUrl = $this->getBaseUrl($whatsAppNumber);

        if (empty($apiKey)) {
            $event->failAll('dialoghsm.campaign.error.missing_api_key');

            return;
        }

        $contacts   = $event->getContacts();
        $sendDelay  = (int) ($config['send_delay'] ?? 0);
        $batchLimit = (int) ($config['batch_limit'] ?? 0);

        // batch_limit=N: envia N mensagens, pausa send_delay ms, repete para todos os contatos.
        // batch_limit=0: aplica send_delay entre cada mensagem individualmente.
        $effectiveBatch = $batchLimit > 0 ? $batchLimit : 1;
        $sentCount      = 0;

        foreach ($contacts as $logId => $contact) {
            $phone = $contact->getLeadPhoneNumber();

            if (empty($phone)) {
                $event->passWithError(
                    $event->getPending()->get($logId),
                    'dialoghsm.campaign.error.missing_phone'
                );
                continue;
            }

            $profileFields = $contact->getProfileFields();
            $payloadData   = $this->buildPayloadFromConfig($config, $profileFields);
            $templateName  = $payloadData['content'] ?? $payloadData['template'] ?? 'unknown';

            try {
                $success = $sender(new SendWhatsAppMessage(
                    leadId:       $contact->getId(),
                    phone:        $phone,
                    apiKey:       $apiKey,
                    baseUrl:      $baseUrl,
                    payloadData:  $payloadData,
                    templateName: $templateName,
                ), $whatsAppNumber);
            } catch (\Throwable $e) {
                $this->logger->error('DialogHSM: Exceção durante envio', [
                    'lead_id' => $contact->getId(),
                    'error'   => $e->getMessage(),
                ]);
                $success = false;
            }

            if ($success) {
                $event->pass($event->getPending()->get($logId));
            } else {
                $event->passWithError(
                    $event->getPending()->get($logId),
                    'dialoghsm.campaign.error.send_failed'
                );
            }

            ++$sentCount;

            if ($applyBatchSleep && $sendDelay > 0 && $sentCount % $effectiveBatch === 0) {
                usleep($sendDelay * 1000);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildPayloadFromConfig(array $config, array $profileFields): array
    {
        $payloadData = $config['payload_data'] ?? [];

        if (empty($payloadData)) {
            return [];
        }

        $list   = $payloadData['list'] ?? $payloadData;
        $result = [];

        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['label'], $item['value'])) {
                continue;
            }

            $key   = trim($item['label']);
            $value = trim($item['value']);

            if ('' === $key) {
                continue;
            }

            $value = TokenHelper::findLeadTokens($value, $profileFields, true);

            $result[$key] = $value;
        }

        return $result;
    }

    private function isIntegrationEnabled(): bool
    {
        try {
            $integration = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);

            return $integration->getIntegrationConfiguration()->getIsPublished();
        } catch (\Exception) {
            return false;
        }
    }

    private function getWhatsAppNumber(int $id): ?WhatsAppNumber
    {
        if ($id <= 0) {
            return null;
        }

        $number = $this->whatsAppNumberModel->getEntity($id);

        if (null === $number || !$number->getIsPublished()) {
            return null;
        }

        return $number;
    }

    private function getBaseUrl(WhatsAppNumber $number): string
    {
        $numberUrl = $number->getBaseUrl();
        if (!empty($numberUrl)) {
            return rtrim($numberUrl, '/');
        }

        try {
            $integration = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);
            $apiKeys     = $integration->getIntegrationConfiguration()->getApiKeys() ?? [];
            $pluginUrl   = $apiKeys['base_url'] ?? '';

            return !empty($pluginUrl) ? rtrim($pluginUrl, '/') : 'https://waba-v2.360dialog.io/messages';
        } catch (\Exception) {
            return 'https://waba-v2.360dialog.io/messages';
        }
    }
}

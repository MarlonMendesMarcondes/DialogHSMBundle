<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Helper\TokenHelper;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use MauticPlugin\DialogHSMBundle\DialogHSMEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppType;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CampaignSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private IntegrationsHelper $integrationsHelper,
        private DialogHSMApi $api,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private WhatsAppNumberModel $whatsAppNumberModel,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD           => ['onCampaignBuild', 0],
            DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION => ['onCampaignTriggerAction', 0],
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
    }

    public function onCampaignTriggerAction(PendingEvent $event): void
    {
        if (!$event->checkContext('dialoghsm.send_whatsapp')) {
            return;
        }

        if (!$this->isIntegrationEnabled()) {
            $event->failAll('dialoghsm.campaign.error.integration_disabled');

            return;
        }

        $config = $event->getEvent()->getProperties();

        // Buscar número WhatsApp selecionado (contém a API key)
        $numberId       = (int) ($config['whatsapp_number'] ?? 0);
        $whatsAppNumber = $this->getWhatsAppNumber($numberId);

        if (null === $whatsAppNumber) {
            $event->failAll('dialoghsm.campaign.error.missing_number');

            return;
        }

        $apiKey  = $whatsAppNumber->getApiKey();
        $baseUrl = $this->getBaseUrl();

        $contacts = $event->getContacts();

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

            // Construir payload a partir dos key-value pairs, resolvendo tokens
            $payloadData = $this->buildPayloadFromConfig($config, $profileFields);

            $templateName = $payloadData['content'] ?? $payloadData['template'] ?? 'unknown';

            $result = $this->api->sendMessage($apiKey, $baseUrl, $phone, $payloadData);

            $this->logMessage($contact->getId(), $templateName, $phone, $result);
            $this->updateContactFields($contact, $result);

            $pendingLog = $event->getPending()->get($logId);

            if ($result['success']) {
                $event->pass($pendingLog);
            } else {
                $event->fail($pendingLog, $result['error'] ?? 'dialoghsm.campaign.error.send_failed');
            }
        }
    }

    /**
     * Constrói o payload a partir dos dados configurados na campanha (SortableListType).
     *
     * @return array<string, string>
     */
    private function buildPayloadFromConfig(array $config, array $profileFields): array
    {
        $payloadData = $config['payload_data'] ?? [];

        if (empty($payloadData)) {
            return [];
        }

        // SortableListType format: ['list' => [['label' => 'key', 'value' => 'val'], ...]]
        $list = $payloadData['list'] ?? $payloadData;
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

            // Resolver tokens do contato nos valores
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

    private function getBaseUrl(): string
    {
        try {
            $integration = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);
            $apiKeys     = $integration->getIntegrationConfiguration()->getApiKeys() ?? [];

            return $apiKeys['base_url'] ?? 'https://waba.360dialog.io/v1';
        } catch (\Exception) {
            return 'https://waba.360dialog.io/v1';
        }
    }

    private function logMessage(int $leadId, string $templateName, string $phone, array $result): void
    {
        $log = new MessageLog();
        $log->setLeadId($leadId);
        $log->setTemplateName($templateName);
        $log->setPhoneNumber($phone);
        $log->setStatus($result['success'] ? 'sent' : 'failed');
        $log->setHttpStatusCode($result['http_status'] ?? null);
        $log->setApiResponse(!empty($result['response']) ? json_encode($result['response']) : null);
        $log->setErrorMessage($result['error'] ?? null);
        $log->setDateSent(new \DateTime());

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function updateContactFields($contact, array $result): void
    {
        try {
            $httpStatus   = $result['http_status'] ?? 'N/A';
            $statusText   = $result['success'] ? "sent (HTTP {$httpStatus})" : "failed (HTTP {$httpStatus})";
            $lastResponse = $result['success'] ? 'OK' : mb_substr($result['error'] ?? '', 0, 255);
            $lastSent     = (new \DateTime())->format('Y-m-d H:i:s');

            $this->entityManager->getConnection()->executeStatement(
                'UPDATE leads SET dialoghsm_status = ?, dialoghsm_last_response = ?, dialoghsm_last_sent = ? WHERE id = ?',
                [$statusText, $lastResponse, $lastSent, $contact->getId()]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Failed to update contact custom fields', [
                'lead_id' => $contact->getId(),
                'error'   => $e->getMessage(),
            ]);
        }
    }
}

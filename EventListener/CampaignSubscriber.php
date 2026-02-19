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

        // Use only the API key stored in the selected WhatsApp number model.
        // Do not fall back to any integration-level api_key to avoid exposing the key in the integration UI.
        $apiKey  = $whatsAppNumber->getApiKey();
        $baseUrl = $this->getBaseUrl($whatsAppNumber);

        if (empty($apiKey)) {
            $event->failAll('dialoghsm.campaign.error.missing_api_key');
            return;
        }

        $contacts   = $event->getContacts();
        $sendDelay  = (int) ($config['send_delay'] ?? 0);   // ms entre envios
        $batchLimit = (int) ($config['batch_limit'] ?? 0);  // 0 = sem limite

        $sentCount = 0;

        foreach ($contacts as $logId => $contact) {
            // Respeitar batch_limit: se atingiu o limite, deixar os demais como pending
            if ($batchLimit > 0 && $sentCount >= $batchLimit) {
                // Não processa: ficam pendentes para o próximo cron
                break;
            }

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

            // Delay antes de cada envio (exceto o primeiro)
            if ($sendDelay > 0 && $sentCount > 0) {
                usleep($sendDelay * 1000); // converter ms para µs
            }

            $result = $this->api->sendMessage($apiKey, $baseUrl, $phone, $payloadData);

            $this->logMessage($contact->getId(), $templateName, $phone, $result);
            $this->updateContactFields($contact, $result);

            $pendingLog = $event->getPending()->get($logId);

            if ($result['success']) {
                $event->pass($pendingLog);
            } else {
                $event->fail($pendingLog, $result['error'] ?? 'dialoghsm.campaign.error.send_failed');
            }

            ++$sentCount;
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

    private function getBaseUrl(WhatsAppNumber $number): string
    {
        // URL configurada no próprio número tem prioridade (MMlite vs convencional)
        $numberUrl = $number->getBaseUrl();
        if (!empty($numberUrl)) {
            return rtrim($numberUrl, '/');
        }

        // Fallback: URL configurada nas configurações do plugin
        try {
            $integration = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);
            $apiKeys     = $integration->getIntegrationConfiguration()->getApiKeys() ?? [];
            $pluginUrl   = $apiKeys['base_url'] ?? '';

            return !empty($pluginUrl) ? rtrim($pluginUrl, '/') : 'https://waba-v2.360dialog.io/messages';
        } catch (\Exception) {
            return 'https://waba-v2.360dialog.io/messages';
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

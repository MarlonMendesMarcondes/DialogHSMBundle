<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Helper\TokenHelper;
use MauticPlugin\DialogHSMBundle\DialogHSMEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppQueueType;
use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppType;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
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
        private EntityManagerInterface $entityManager,
        private SendWhatsAppDirectBatchMessageHandler $directBatchHandler,
        private string $directTransportDsn = 'null://null',
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
                'formType'       => SendWhatsAppQueueType::class,
                'channel'        => 'whatsapp',
            ]
        );
    }

    public function onCampaignTriggerAction(PendingEvent $event): void
    {
        if (!$event->checkContext('dialoghsm.send_whatsapp')) {
            return;
        }

        // Se Redis configurado: coleta todos os itens e publica UM único entry no stream.
        // O worker consome o lote inteiro em sequência com batch/delay, sem interferência
        // de outros workers (cada execução de campanha = um worker dedicado).
        if ($this->isRedisTransport($this->directTransportDsn)) {
            $config     = $event->getEvent()->getProperties();
            $sendDelay  = (int) ($config['send_delay'] ?? 0);
            $batchLimit = (int) ($config['batch_limit'] ?? 0);

            $items = [];
            $this->processContacts(
                $event,
                function (SendWhatsAppMessage $message, WhatsAppNumber $number) use (&$items): bool {
                    $items[] = $message;

                    return true;
                },
                applyBatchSleep: false
            );

            if (!empty($items)) {
                $this->bus->dispatch(new SendWhatsAppDirectBatchMessage(
                    items:      $items,
                    batchLimit: $batchLimit,
                    sendDelay:  $sendDelay,
                ));
            }

            return;
        }

        // Direto (null://null): coleta todos os itens e processa como lote síncrono.
        // GUARD: send_delay é ignorado no modo inline — usleep() bloquearia o processo
        // mautic:campaigns:trigger, impedindo segmentos e outras campanhas de executar.
        // Para throttle real configure MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT=redis://...
        // ou use a action send_whatsapp_queue (RabbitMQ) para envios em grande volume.
        $config     = $event->getEvent()->getProperties();
        $sendDelay  = (int) ($config['send_delay'] ?? 0);
        $batchLimit = (int) ($config['batch_limit'] ?? 0);

        if ($sendDelay > 0) {
            $this->logger->warning('DialogHSM: send_delay ignorado no modo inline (null://null). '
                .'Configure MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT=redis://... para throttle real '
                .'ou use a action send_whatsapp_queue para envios em grande volume.', [
                'send_delay'  => $sendDelay,
                'batch_limit' => $batchLimit,
            ]);
            $sendDelay = 0;
        }

        $items = [];
        $this->processContacts(
            $event,
            function (SendWhatsAppMessage $message, WhatsAppNumber $number) use (&$items): bool {
                $items[] = $message;

                return true;
            },
            applyBatchSleep: false
        );

        if (!empty($items)) {
            ($this->directBatchHandler)(new SendWhatsAppDirectBatchMessage(
                items:      $items,
                batchLimit: $batchLimit,
                sendDelay:  $sendDelay,
            ));
        }
    }

    public function onCampaignTriggerActionQueue(PendingEvent $event): void
    {
        if (!$event->checkContext('dialoghsm.send_whatsapp_queue')) {
            return;
        }

        $config = $event->getEvent()->getProperties();
        $mode   = trim($config['queue_override'] ?? '');

        $this->processContacts($event, function (SendWhatsAppMessage $message, WhatsAppNumber $number) use ($mode): bool {
            $uuid = bin2hex(random_bytes(16));

            $queueName = match ($mode) {
                'batch' => $number->getBatchQueueName(),
                'bulk'  => $number->getQueueName(),
                default => $number->getQueueName(),
            };

            if ($queueName === null || $queueName === '') {
                $this->logger->warning('DialogHSM: fila não configurada para o modo, mensagem ignorada', [
                    'mode'      => $mode ?: 'default',
                    'number_id' => $number->getId(),
                    'lead_id'   => $message->leadId,
                ]);

                return false;
            }

            $stamps  = [new AmqpStamp($queueName)];
            $payload = $message->withQueueLogId($uuid);
            if ($mode === 'batch') {
                $payload = $payload->withBatchMode();
            }
            $this->bus->dispatch($payload, $stamps);

            // Só persiste o log após confirmação do dispatch.
            // Se o dispatch lançar, o try/catch de processContacts captura
            // e chama persistFailureLog — nenhum log queued órfão é criado.
            $this->persistQueuedLog($message, $uuid);

            return true;
        }, applyBatchSleep: false);
    }

    private function processContacts(PendingEvent $event, callable $sender, bool $applyBatchSleep = true): void
    {
        $integration = $this->fetchEnabledIntegration();

        $campaignEvent   = $event->getEvent();
        $campaignId      = $campaignEvent->getCampaign()?->getId();
        $campaignEventId = $campaignEvent->getId();
        $config          = $campaignEvent->getProperties();
        $numberId        = (int) ($config['whatsapp_number'] ?? 0);

        if (null === $integration) {
            $this->failAllWithLog($event, $campaignId, $campaignEventId, null, 'dialoghsm.campaign.error.integration_disabled', 'integration_disabled');

            return;
        }

        $whatsAppNumber = $this->getWhatsAppNumber($numberId);

        if (null === $whatsAppNumber) {
            $this->failAllWithLog($event, $campaignId, $campaignEventId, null, 'dialoghsm.campaign.error.missing_number', 'number_not_found');

            return;
        }

        $apiKey  = $whatsAppNumber->getApiKey();
        $baseUrl = $this->resolveBaseUrl($whatsAppNumber, $integration);

        if (empty($apiKey)) {
            $this->failAllWithLog($event, $campaignId, $campaignEventId, $whatsAppNumber, 'dialoghsm.campaign.error.missing_api_key', 'missing_api_key');

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
            $phone = $this->normalizePhone($contact->getLeadPhoneNumber() ?? '');

            // Payload construído aqui (antes da validação) para ter templateName disponível nos logs de erro.
            $profileFields = $contact->getProfileFields();
            $payloadData   = $this->buildPayloadFromConfig($config, $profileFields);
            $templateName  = $payloadData['content'] ?? $payloadData['template'] ?? 'unknown';

            if (!$this->isValidE164($phone)) {
                $this->persistFailureLog(
                    $contact->getId(),
                    $phone ?: 'unknown',
                    $templateName,
                    $whatsAppNumber->getName() ?? '',
                    'invalid_phone: '.($phone ?: '(empty)'),
                    $campaignId,
                    $campaignEventId,
                );
                $event->fail(
                    $event->getPending()->get($logId),
                    'dialoghsm.campaign.error.invalid_phone'
                );
                continue;
            }

            try {
                $success = $sender(new SendWhatsAppMessage(
                    leadId:             $contact->getId(),
                    phone:              $phone,
                    apiKey:             $apiKey,
                    baseUrl:            $baseUrl,
                    payloadData:        $payloadData,
                    templateName:       $templateName,
                    whatsAppNumberName: $whatsAppNumber->getName() ?? '',
                    campaignId:         $campaignId,
                    campaignEventId:    $campaignEventId,
                ), $whatsAppNumber);
            } catch (\Throwable $e) {
                $this->logger->error('DialogHSM: Exceção durante envio', [
                    'lead_id' => $contact->getId(),
                    'error'   => $e->getMessage(),
                ]);
                $this->persistFailureLog(
                    $contact->getId(),
                    $phone,
                    $templateName,
                    $whatsAppNumber->getName() ?? '',
                    $e->getMessage(),
                    $campaignId,
                    $campaignEventId,
                );
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
                usleep($sendDelay * 1_000_000);
            }
        }
    }

    /**
     * Chama failAll e cria um log de falha por contato.
     * Garante que nenhum envio seja silenciado sem registro.
     */
    private function failAllWithLog(
        PendingEvent $event,
        ?int $campaignId,
        ?int $campaignEventId,
        ?WhatsAppNumber $number,
        string $failMessage,
        string $errorReason,
    ): void {
        foreach ($event->getContacts() as $contact) {
            $phone = $contact->getLeadPhoneNumber() ?: 'unknown';
            $this->persistFailureLog(
                $contact->getId(),
                $phone,
                'unknown',
                $number?->getName() ?? '',
                $errorReason,
                $campaignId,
                $campaignEventId,
            );
        }

        $event->failAll($failMessage);
    }

    /**
     * Persiste um MessageLog com status 'queued' no momento do dispatch para a fila.
     * O wamid recebe o UUID temporário para que o handler possa localizar e atualizar este registro.
     */
    private function persistQueuedLog(SendWhatsAppMessage $message, string $uuid): void
    {
        try {
            $log = new MessageLog();
            $log->setLeadId($message->leadId);
            $log->setCampaignId($message->campaignId);
            $log->setCampaignEventId($message->campaignEventId);
            $log->setSenderName($message->whatsAppNumberName ?: null);
            $log->setTemplateName($message->templateName);
            $log->setPhoneNumber($message->phone);
            $log->setWamid($uuid);
            $log->setStatus(MessageLog::STATUS_QUEUED);
            $log->setDateSent(new \DateTime());

            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Falha ao registrar log queued', [
                'lead_id' => $message->leadId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persiste um MessageLog de falha sem depender do handler.
     * Usado quando o envio falha antes de chegar à API (validação, exceção, etc.).
     */
    private function persistFailureLog(
        int $leadId,
        string $phone,
        string $templateName,
        string $senderName,
        string $errorMessage,
        ?int $campaignId,
        ?int $campaignEventId,
    ): void {
        try {
            $log = new MessageLog();
            $log->setLeadId($leadId);
            $log->setCampaignId($campaignId);
            $log->setCampaignEventId($campaignEventId);
            $log->setSenderName($senderName ?: null);
            $log->setTemplateName($templateName);
            $log->setPhoneNumber($phone);
            $log->setWamid(null);
            $log->setStatus(MessageLog::STATUS_FAILED);
            $log->setHttpStatusCode(null);
            $log->setApiResponse(null);
            $log->setErrorMessage(mb_substr($errorMessage, 0, 500));
            $log->setDateSent(new \DateTime());

            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Falha ao registrar log de erro de campanha', [
                'lead_id' => $leadId,
                'error'   => $e->getMessage(),
            ]);
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

    /**
     * Normaliza o telefone removendo apenas caracteres de formatação: espaços, hífens, parênteses e pontos.
     * Letras são preservadas (para que a validação E.164 as rejeite corretamente).
     * Exemplos:
     *   "+55 44 999067833"     → "+5544999067833"
     *   "+55 (11) 9.8765-4321" → "+5511987654321"
     *   "+5511abc9999"         → "+5511abc9999"   (mantido, será rejeitado pelo E.164)
     */
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[ \-().]/u', '', trim($phone)) ?? $phone;
    }

    /**
     * Valida formato E.164: começa com +, seguido de 7 a 15 dígitos.
     * Números vazios, sem prefixo + ou com quantidade errada de dígitos são rejeitados.
     */
    private function isValidE164(string $phone): bool
    {
        return (bool) preg_match('/^\+[1-9]\d{6,14}$/', $phone);
    }

    /**
     * Returns the integration object if it is enabled, or null if disabled/not found.
     * Centralises the single getIntegration() call for the entire request.
     */
    private function fetchEnabledIntegration(): ?object
    {
        try {
            $integration = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);

            return $integration->getIntegrationConfiguration()->getIsPublished() ? $integration : null;
        } catch (\Exception) {
            return null;
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

    private function isRedisTransport(string $dsn): bool
    {
        return str_starts_with($dsn, 'redis://') || str_starts_with($dsn, 'rediss://');
    }

    private function resolveBaseUrl(WhatsAppNumber $number, object $integration): string
    {
        $numberUrl = $number->getBaseUrl();
        if (!empty($numberUrl)) {
            return rtrim($numberUrl, '/');
        }

        $apiKeys   = $integration->getIntegrationConfiguration()->getApiKeys() ?? [];
        $pluginUrl = $apiKeys['base_url'] ?? '';

        return !empty($pluginUrl) ? rtrim($pluginUrl, '/') : 'https://waba-v2.360dialog.io/messages';
    }
}

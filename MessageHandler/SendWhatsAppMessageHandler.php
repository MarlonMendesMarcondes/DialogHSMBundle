<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class SendWhatsAppMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private DialogHSMApi $api,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private LeadModel $leadModel,
        private MessageLogRepository $messageLogRepository,
    ) {
    }

    /**
     * @return array{success: bool, response: array|null, error: string|null, http_status: int|null}
     */
    public function __invoke(SendWhatsAppMessage $message): array
    {
        $result = $this->api->sendMessage(
            $message->apiKey,
            $message->baseUrl,
            $message->phone,
            $message->payloadData
        );

        try {
            $this->logMessage($message->leadId, $message->templateName, $message->phone, $message->whatsAppNumberName, $result, $message->campaignId, $message->campaignEventId);
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Falha ao registrar log da mensagem', [
                'lead_id' => $message->leadId,
                'error'   => $e->getMessage(),
            ]);
        }

        $this->updateContactFields($message->leadId, $result);

        return $result;
    }

    private function logMessage(int $leadId, string $templateName, string $phone, string $senderName, array $result, ?int $campaignId = null, ?int $campaignEventId = null): void
    {
        $log = new MessageLog();
        $log->setLeadId($leadId);
        $log->setCampaignId($campaignId);
        $log->setCampaignEventId($campaignEventId);
        $log->setSenderName($senderName ?: null);
        $log->setTemplateName($templateName);
        $log->setPhoneNumber($phone);
        $log->setWamid($result['wamid'] ?? null);
        $log->setStatus($result['success'] ? MessageLog::STATUS_SENT : MessageLog::STATUS_FAILED);
        $log->setHttpStatusCode($result['http_status'] ?? null);
        $log->setApiResponse(!empty($result['response']) ? json_encode($result['response']) : null);
        $log->setErrorMessage($result['error'] ?? null);
        $log->setDateSent(new \DateTime());

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->messageLogRepository->prune();
    }

    private function updateContactFields(int $leadId, array $result): void
    {
        try {
            $lead = $this->leadModel->getEntity($leadId);
            if (null === $lead) {
                return;
            }

            $httpStatus   = $result['http_status'] ?? 'N/A';
            $statusText   = $result['success'] ? "sent (HTTP {$httpStatus})" : "failed (HTTP {$httpStatus})";
            $lastResponse = $result['success'] ? 'OK' : mb_substr($result['error'] ?? '', 0, 255);

            $this->leadModel->setFieldValues($lead, [
                'dialoghsm_status'        => $statusText,
                'dialoghsm_last_response' => $lastResponse,
                'dialoghsm_last_sent'     => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
            $this->leadModel->saveEntity($lead);
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Failed to update contact custom fields', [
                'lead_id' => $leadId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}

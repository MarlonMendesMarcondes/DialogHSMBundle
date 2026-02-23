<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class SendWhatsAppMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private DialogHSMApi $api,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private CoreParametersHelper $coreParametersHelper,
    ) {
    }

    public function __invoke(SendWhatsAppMessage $message): void
    {
        if ($message->sendDelay > 0) {
            usleep($message->sendDelay * 1000);
        }

        $result = $this->api->sendMessage(
            $message->apiKey,
            $message->baseUrl,
            $message->phone,
            $message->payloadData
        );

        $this->logMessage($message->leadId, $message->templateName, $message->phone, $result);
        $this->updateContactFieldsById($message->leadId, $result);
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

    private function updateContactFieldsById(int $leadId, array $result): void
    {
        try {
            $httpStatus   = $result['http_status'] ?? 'N/A';
            $statusText   = $result['success'] ? "sent (HTTP {$httpStatus})" : "failed (HTTP {$httpStatus})";
            $lastResponse = $result['success'] ? 'OK' : mb_substr($result['error'] ?? '', 0, 255);
            $timezone     = $this->coreParametersHelper->get('default_timezone') ?: 'UTC';
            $lastSent     = (new \DateTime('now', new \DateTimeZone($timezone)))->format('Y-m-d H:i:s');

            $this->entityManager->getConnection()->executeStatement(
                'UPDATE leads SET dialoghsm_status = ?, dialoghsm_last_response = ?, dialoghsm_last_sent = ? WHERE id = ?',
                [$statusText, $lastResponse, $lastSent, $leadId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Failed to update contact custom fields', [
                'lead_id' => $leadId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}

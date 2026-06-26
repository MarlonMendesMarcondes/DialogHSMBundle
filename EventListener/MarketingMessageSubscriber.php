<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\ChannelBundle\ChannelEvents;
use Mautic\ChannelBundle\Event\MessageQueueBatchProcessEvent;
use Mautic\LeadBundle\Helper\TokenHelper;
use MauticPlugin\DialogHSMBundle\DialogHSMEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppMessageModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;

class MarketingMessageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WhatsAppMessageModel $messageModel,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DialogHSMEvents::ON_MARKETING_MESSAGE_SEND        => ['onMarketingMessageSend', 0],
            ChannelEvents::PROCESS_MESSAGE_QUEUE_BATCH        => ['onProcessMessageQueueBatch', 0],
        ];
    }

    public function onMarketingMessageSend(PendingEvent $pendingEvent): void
    {
        if (!$pendingEvent->checkContext('dialoghsm.send_whatsapp_message')) {
            return;
        }

        $properties = $pendingEvent->getEvent()->getProperties();
        $messageId  = (int) ($properties['whatsAppMessage'] ?? 0);

        if (!$messageId) {
            $pendingEvent->failRemaining('dialoghsm.campaign.error.missing_number');
            return;
        }

        $message = $this->messageModel->getEntity($messageId);
        if (!$message || !$message->isPublished()) {
            $pendingEvent->failRemaining('dialoghsm.campaign.error.missing_number');
            return;
        }

        $number = $message->getWhatsAppNumber();
        if (!$number) {
            $pendingEvent->failRemaining('dialoghsm.campaign.error.missing_number');
            return;
        }

        $apiKey    = $number->getApiKey();
        $baseUrl   = $number->getBaseUrl() ?? 'https://waba.360dialog.io';
        $queueName = $number->getQueueName() ?? $number->getBatchQueueName();

        if (empty($apiKey)) {
            $pendingEvent->failRemaining('dialoghsm.campaign.error.missing_api_key');
            return;
        }

        $stamps          = $queueName ? [new AmqpStamp($queueName)] : [];
        $campaignEvent   = $pendingEvent->getEvent();
        $campaignId      = $campaignEvent->getCampaign()?->getId();
        $campaignEventId = $campaignEvent->getId();
        $contacts        = $pendingEvent->getContacts();

        foreach ($contacts as $logId => $contact) {
            $log   = $pendingEvent->getPending()->get($logId);
            $phone = trim((string) ($contact->getLeadPhoneNumber() ?? ''));

            if ('' === $phone) {
                $pendingEvent->fail($log, 'dialoghsm.campaign.error.missing_phone');
                continue;
            }

            $profileFields = $contact->getProfileFields();
            $payloadData   = $this->resolveTokens($message->getPayloadData(), $profileFields);

            $msgLog = new MessageLog();
            $msgLog->setLeadId((int) $contact->getId());
            $msgLog->setPhoneNumber($phone);
            $msgLog->setTemplateName($message->getTemplateName());
            $msgLog->setSenderName($number->getName() ?? '');
            $msgLog->setStatus(MessageLog::STATUS_QUEUED);
            $msgLog->setDateSent(new \DateTime());
            $msgLog->setWhatsappMessageId($messageId);
            $msgLog->setCampaignId($campaignId);
            $msgLog->setCampaignEventId($campaignEventId);
            $this->em->persist($msgLog);
            $this->em->flush();

            try {
                $this->bus->dispatch(new SendWhatsAppMessage(
                    leadId:             (int) $contact->getId(),
                    phone:              $phone,
                    apiKey:             $apiKey,
                    baseUrl:            $baseUrl,
                    payloadData:        $payloadData,
                    templateName:       $message->getTemplateName(),
                    whatsAppNumberName: $number->getName() ?? '',
                    queueLogId:         (string) $msgLog->getId(),
                    isBatch:            true,
                ), $stamps);
                $pendingEvent->pass($log);
            } catch (\Throwable $e) {
                $this->logger->error('DialogHSM MarketingMessage: dispatch failed', [
                    'lead_id'   => $contact->getId(),
                    'message'   => $e->getMessage(),
                ]);
                $msgLog->setStatus(MessageLog::STATUS_FAILED);
                $this->em->persist($msgLog);
                $this->em->flush();
                $pendingEvent->fail($log, 'dialoghsm.campaign.error.send_failed');
            }
        }

        $this->em->clear(MessageLog::class);
    }

    public function onProcessMessageQueueBatch(MessageQueueBatchProcessEvent $event): void
    {
        if (!$event->checkContext('whatsapp')) {
            return;
        }

        $messageId = (int) $event->getChannelId();
        $message   = $this->messageModel->getEntity($messageId);

        if (!$message || !$message->isPublished()) {
            return;
        }

        $number = $message->getWhatsAppNumber();
        if (!$number) {
            return;
        }

        $apiKey    = $number->getApiKey();
        $baseUrl   = $number->getBaseUrl() ?? 'https://waba.360dialog.io';
        $queueName = $number->getQueueName() ?? $number->getBatchQueueName();

        if (empty($apiKey)) {
            return;
        }

        $stamps = $queueName ? [new AmqpStamp($queueName)] : [];

        foreach ($event->getMessages() as $queuedMessage) {
            $contact = $queuedMessage->getLead();
            if (!$contact) {
                $queuedMessage->setFailed();
                continue;
            }

            $phone = trim((string) ($contact->getLeadPhoneNumber() ?? ''));
            if ('' === $phone) {
                $queuedMessage->setProcessed();
                $queuedMessage->setFailed();
                continue;
            }

            $profileFields = $contact->getProfileFields();
            $payloadData   = $this->resolveTokens($message->getPayloadData(), $profileFields);

            $msgLog = new MessageLog();
            $msgLog->setLeadId((int) $contact->getId());
            $msgLog->setPhoneNumber($phone);
            $msgLog->setTemplateName($message->getTemplateName());
            $msgLog->setSenderName($number->getName() ?? '');
            $msgLog->setStatus(MessageLog::STATUS_QUEUED);
            $msgLog->setDateSent(new \DateTime());
            $msgLog->setWhatsappMessageId($messageId);
            $this->em->persist($msgLog);
            $this->em->flush();

            try {
                $this->bus->dispatch(new SendWhatsAppMessage(
                    leadId:             (int) $contact->getId(),
                    phone:              $phone,
                    apiKey:             $apiKey,
                    baseUrl:            $baseUrl,
                    payloadData:        $payloadData,
                    templateName:       $message->getTemplateName(),
                    whatsAppNumberName: $number->getName() ?? '',
                    queueLogId:         (string) $msgLog->getId(),
                    isBatch:            true,
                ), $stamps);
                $queuedMessage->setProcessed();
                $queuedMessage->setSuccess();
            } catch (\Throwable $e) {
                $this->logger->error('DialogHSM MessageQueue: dispatch failed', [
                    'lead_id' => $contact->getId(),
                    'message' => $e->getMessage(),
                ]);
                $msgLog->setStatus(MessageLog::STATUS_FAILED);
                $this->em->persist($msgLog);
                $this->em->flush();
                $queuedMessage->setProcessed();
                $queuedMessage->setFailed();
            }
        }

        $this->em->clear(MessageLog::class);
        $event->stopPropagation();
    }

    /**
     * @param array<mixed>         $payloadData
     * @param array<string, mixed> $profileFields
     *
     * @return array<mixed>
     */
    private function resolveTokens(array $payloadData, array $profileFields): array
    {
        $list   = $payloadData['list'] ?? $payloadData;
        $result = [];

        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['label'], $item['value'])) {
                continue;
            }
            $key = trim((string) $item['label']);
            if ('' === $key) {
                continue;
            }
            $result[$key] = TokenHelper::findLeadTokens((string) $item['value'], $profileFields, true);
        }

        if (!empty($result) && !isset($result['vars'])) {
            $controlKeys    = ['content', 'url_arquivo', 'buttons', 'buttons_vars', 'limited_time_offer', 'language'];
            $varKeys        = array_diff(array_keys($result), $controlKeys);
            $result['vars'] = implode(',', $varKeys);
        }

        return $result;
    }
}

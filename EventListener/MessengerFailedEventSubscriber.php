<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

class MessengerFailedEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => 'onMessageFailed'];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof SendWhatsAppMessage) {
            return;
        }

        try {
            $log = new MessageLog();
            $log->setLeadId($message->leadId);
            $log->setCampaignId($message->campaignId);
            $log->setCampaignEventId($message->campaignEventId);
            $log->setSenderName($message->whatsAppNumberName ?: null);
            $log->setTemplateName($message->templateName);
            $log->setPhoneNumber($message->phone);
            $log->setStatus(MessageLog::STATUS_DLQ);
            $log->setErrorMessage(mb_substr($event->getThrowable()->getMessage(), 0, 255));
            $log->setDateSent(new \DateTime());

            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM: falha ao registrar mensagem DLQ no log', [
                'lead_id' => $message->leadId,
                'phone'   => $message->phone,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}

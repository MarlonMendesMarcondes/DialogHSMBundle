<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

class MessengerFailedEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private MessageLogRepository $messageLogRepository,
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

        if ($message instanceof SendWhatsAppDirectBatchMessage) {
            foreach ($message->items as $item) {
                $this->persistDlqLog($item, $event->getThrowable());
            }

            return;
        }

        if ($message instanceof SendWhatsAppMessage) {
            $this->persistDlqLog($message, $event->getThrowable());
        }
    }

    private function persistDlqLog(SendWhatsAppMessage $message, \Throwable $throwable): void
    {
        try {
            // Reutiliza log queued criado no dispatch (identificado pelo UUID no wamid)
            $log = $message->queueLogId !== null
                ? $this->messageLogRepository->findByWamid($message->queueLogId)
                : null;

            if ($log === null) {
                $log = new MessageLog();
                $log->setLeadId($message->leadId);
                $log->setCampaignId($message->campaignId);
                $log->setCampaignEventId($message->campaignEventId);
                $log->setSenderName($message->whatsAppNumberName ?: null);
                $log->setTemplateName($message->templateName);
                $log->setPhoneNumber($message->phone);
            }

            $log->setStatus(MessageLog::STATUS_DLQ);
            $log->setWamid(null);
            $log->setErrorMessage(mb_substr($throwable->getMessage(), 0, 255));
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

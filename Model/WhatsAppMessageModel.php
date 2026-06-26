<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Model;

use Mautic\ChannelBundle\Event\ChannelBroadcastEvent;
use Mautic\CoreBundle\Model\AjaxLookupModelInterface;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\LeadBundle\Helper\TokenHelper;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessageRepository;
use MauticPlugin\DialogHSMBundle\Form\Type\WhatsAppMessageType;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends FormModel<WhatsAppMessage>
 *
 * @implements AjaxLookupModelInterface<WhatsAppMessage>
 */
class WhatsAppMessageModel extends FormModel implements AjaxLookupModelInterface
{
    private const BATCH_SIZE = 100;

    private LeadModel $leadModel;
    private MessageBusInterface $bus;
    private BulkRateLimiter $rateLimiter;

    #[Required]
    public function setLeadModel(LeadModel $leadModel): void
    {
        $this->leadModel = $leadModel;
    }

    #[Required]
    public function setBus(MessageBusInterface $bus): void
    {
        $this->bus = $bus;
    }

    #[Required]
    public function setRateLimiter(BulkRateLimiter $rateLimiter): void
    {
        $this->rateLimiter = $rateLimiter;
    }

    public function getRepository(): WhatsAppMessageRepository
    {
        return $this->em->getRepository(WhatsAppMessage::class);
    }

    public function getPermissionBase(): string
    {
        return 'dialoghsm:whatsappmessages';
    }

    public function createForm($entity, FormFactoryInterface $formFactory, $action = null, $options = []): FormInterface
    {
        if (!$entity instanceof WhatsAppMessage) {
            throw new MethodNotAllowedHttpException(['WhatsAppMessage']);
        }

        if (!empty($action)) {
            $options['action'] = $action;
        }

        return $formFactory->create(WhatsAppMessageType::class, $entity, $options);
    }

    public function getEntity($id = null): ?WhatsAppMessage
    {
        if (null === $id) {
            return new WhatsAppMessage();
        }

        return parent::getEntity($id);
    }

    /**
     * @param string $filter
     * @param int    $limit
     * @param int    $start
     * @param array  $options
     */
    public function getLookupResults($type, $filter = '', $limit = 10, $start = 0, $options = []): array
    {
        $results     = [];
        $queryParams = [
            'limit'      => $limit,
            'start'      => $start,
            'orderBy'    => 'wm.name',
            'orderByDir' => 'ASC',
        ];

        if (is_array($filter) && !empty($filter)) {
            $queryParams['filter'] = ['force' => [['column' => 'wm.id', 'expr' => 'in', 'value' => $filter]]];
        } elseif (is_string($filter) && '' !== $filter) {
            $queryParams['filter'] = ['string' => $filter];
        }

        $entities = $this->getRepository()->getEntities($queryParams);

        foreach ($entities as $entity) {
            $results[$entity->getId()] = $entity->getName();
        }

        return $results;
    }

    /**
     * @return array{int, int} [sent, failed]
     */
    public function sendToLists(WhatsAppMessage $message, ChannelBroadcastEvent $event): array
    {
        $number = $message->getWhatsAppNumber();
        if (!$number) {
            return [0, 0];
        }

        $apiKey    = $number->getApiKey();
        $baseUrl   = $number->getBaseUrl() ?? 'https://waba.360dialog.io';
        $sent      = 0;
        $failed    = 0;
        $batchMin  = 0;
        $repo      = $this->getRepository();
        $sendDelay = $this->rateLimiter->getBulkSendDelay();

        do {
            $contacts = $repo->getPendingContacts($message->getId(), $batchMin, self::BATCH_SIZE);
            $items    = [];

            foreach ($contacts as $contact) {
                $leadId = (int) $contact['id'];
                $phone  = trim((string) ($contact['phone'] ?? ''));

                if ('' === $phone) {
                    ++$failed;
                    $batchMin = $leadId + 1;
                    continue;
                }

                $lead = $this->leadModel->getEntity($leadId);
                if (!$lead) {
                    ++$failed;
                    $batchMin = $leadId + 1;
                    continue;
                }

                $profileFields = $lead->getProfileFields();
                $payloadData   = $this->resolveTokens($message->getPayloadData(), $profileFields);
                $templateName  = $message->getTemplateName();

                $log = new MessageLog();
                $log->setLeadId($leadId);
                $log->setPhoneNumber($phone);
                $log->setTemplateName($templateName);
                $log->setSenderName($number->getName() ?? '');
                $log->setStatus(MessageLog::STATUS_QUEUED);
                $log->setDateSent(new \DateTime());
                $log->setWhatsappMessageId($message->getId());
                $this->em->persist($log);
                $this->em->flush();

                $items[] = new SendWhatsAppMessage(
                    leadId:             $leadId,
                    phone:              $phone,
                    apiKey:             $apiKey,
                    baseUrl:            $baseUrl,
                    payloadData:        $payloadData,
                    templateName:       $templateName,
                    whatsAppNumberName: $number->getName() ?? '',
                    queueLogId:         (string) $log->getId(),
                    isBatch:            true,
                );

                ++$sent;
                $batchMin = $leadId + 1;
            }

            if (!empty($items)) {
                try {
                    $this->bus->dispatch(new SendWhatsAppDirectBatchMessage(
                        items:      $items,
                        batchLimit: 1,
                        sendDelay:  $sendDelay,
                    ));
                } catch (\Throwable) {
                    $sent -= count($items);
                    $failed += count($items);
                }
            }

            $this->em->clear(MessageLog::class);
        } while (count($contacts) === self::BATCH_SIZE);

        $this->em->createQueryBuilder()
            ->update(WhatsAppMessage::class, 'wm')
            ->set('wm.sentCount', 'wm.sentCount + :sent')
            ->set('wm.failedCount', 'wm.failedCount + :failed')
            ->where('wm.id = :id')
            ->setParameter('sent', $sent)
            ->setParameter('failed', $failed)
            ->setParameter('id', $message->getId())
            ->getQuery()
            ->execute();

        return [$sent, $failed];
    }

    /**
     * Resolves {{lead.*}} tokens in all payload_data values for the given contact profile.
     *
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

        // buildPayload() usa 'vars' como CSV dos nomes dos body parameters.
        // Campanhas preenchem 'vars' via form; MM não tem esse campo.
        // Geramos aqui excluindo as chaves de controle da API (url_arquivo, buttons, etc.)
        // para que elas não virem body parameters erroneamente.
        if (!empty($result) && !isset($result['vars'])) {
            $controlKeys    = ['content', 'url_arquivo', 'buttons', 'buttons_vars', 'limited_time_offer', 'language'];
            $varKeys        = array_diff(array_keys($result), $controlKeys);
            $result['vars'] = implode(',', $varKeys);
        }

        return $result;
    }
}

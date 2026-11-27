<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper;
use MauticPlugin\DialogHSMBundle\DialogHSMEvents;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Exception\CampaignActionBuildException;
use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppQueueType;
use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppTemplateQueueType;
use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppTemplateType;
use MauticPlugin\DialogHSMBundle\Form\Type\SendWhatsAppType;
use MauticPlugin\DialogHSMBundle\Form\Type\WhatsAppMessageSendType;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Pacing\PacedScheduler;
use MauticPlugin\DialogHSMBundle\Pacing\PacedSettings;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use MauticPlugin\DialogHSMBundle\Service\PaymentBlockPauseService;
use MauticPlugin\DialogHSMBundle\Service\TemplatePayload;
use MauticPlugin\DialogHSMBundle\Service\Variable\TemplateVariableResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;

class CampaignSubscriber implements EventSubscriberInterface
{
    // 120s era menor que a latência real dos webhooks de status da Meta/360dialog,
    // fazendo o timeout disparar antes do código de erro real chegar.
    private const WEBHOOK_TIMEOUT_SECONDS = 600;

    /**
     * Intervalo até o core tentar de novo um contato que falhou por erro de montagem da
     * ação (ver failForBuildError). Curto para que, corrigida a causa e republicada a
     * campanha, o envio saia logo.
     */
    private const BUILD_ERROR_RETRY_INTERVAL = 'PT15M';

    /**
     * Ações de envio. As chaves originais viraram as versões "(legado)": as campanhas já
     * criadas guardam a chave no evento e continuam com o comportamento anterior, sem
     * pré-validação do template e sem pausa por bloqueio de pagamento. As versões novas (_v2)
     * herdam os nomes de antes e trazem as duas proteções.
     */
    public const ACTION_SEND_LEGACY       = 'dialoghsm.send_whatsapp';
    public const ACTION_SEND_QUEUE_LEGACY = 'dialoghsm.send_whatsapp_queue';
    public const ACTION_SEND              = 'dialoghsm.send_whatsapp_v2';
    public const ACTION_SEND_QUEUE        = 'dialoghsm.send_whatsapp_queue_v2';
    public const ACTION_SEND_MESSAGE      = 'dialoghsm.send_whatsapp_message';

    private const TEMPLATE_FORM_THEME = '@DialogHSM/FormTheme/template_action.html.twig';

    /**
     * Política de falhas deste subscriber — dois tipos, tratamentos opostos:
     *
     * 1. Erro de montagem da ação (integração, número, API key, fila, payload ou qualquer
     *    exceção inesperada antes do envio): fail() com intervalo de nova tentativa, sem
     *    enviar nada. O contato NÃO é consumido — o core o reagenda (is_scheduled=1) e
     *    mostra o motivo na timeline e na notificação. Se as falhas passarem de 10% dos
     *    contatos, o core despublica a campanha (CampaignEventSubscriber::onEventFailed):
     *    é o freio desejado para configuração quebrada. Ao republicar, o core zera o
     *    contador e os contatos reagendados saem no próximo trigger.
     *
     * 2. Erro do contato ou do envio (telefone inválido, falha no dispatch, falha/restrição
     *    reportada pelo webhook): passWithError(). Repetir não resolve, então o contato é
     *    encerrado com metadata failed=1 na timeline, sem contar no threshold de 10%
     *    (ver project_campaign_auto_disable_threshold). O status real (STATUS_FAILED)
     *    fica em dialog_hsm_message_log via persistFailureLog().
     *
     * Códigos de erro da Meta que indicam restrição de qualidade/entrega ou opt-out do
     * contato, não uma falha técnica do nosso lado. Usados só para escolher a mensagem
     * de razão mostrada na timeline ('meta_restricted' vs 'webhook_failed').
     *
     * IMPORTANTE: esses códigos chegam via webhook (MessageLog::getWebhookErrorCode()),
     * não via HTTP status do envio síncrono. A 360dialog aceita a mensagem no envio
     * (http_status_code=200) e só depois a Meta recusa a entrega, reportada
     * assincronamente pelo webhook. Por isso a checagem abaixo usa getWebhookErrorCode()
     * e nunca getHttpStatusCode() — falhas técnicas reais (payload malformado, permissão,
     * template inexistente) têm http_status_code 400/403/404/401 com webhook_error_code
     * nulo (falham no próprio envio, nunca chegam a gerar callback de webhook), então
     * nunca colidem com estes códigos.
     */
    private const META_RESTRICTION_CODES = [
        131049, // Not delivered to maintain healthy ecosystem engagement
        130472, // User's number is part of an experiment
        131050, // Recipient chose to stop receiving marketing messages (opt-out)
        131026, // Message undeliverable (recipient phone off/unreachable/not on WhatsApp/blocked number)
    ];

    public function __construct(
        private IntegrationsHelper $integrationsHelper,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private WhatsAppNumberModel $whatsAppNumberModel,
        private SendWhatsAppMessageHandler $handler,
        private EntityManagerInterface $entityManager,
        private SendWhatsAppDirectBatchMessageHandler $directBatchHandler,
        private MessageLogRepository $messageLogRepository,
        private LeadEventLogWriter $leadEventLogWriter,
        private string $directTransportDsn = 'null://null',
        private ?PaymentBlockPauseService $paymentPause = null,
        private ?TemplateVariableResolver $variableResolver = null,
        private ?PacedScheduler $pacedScheduler = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD                 => ['onCampaignBuild', 0],
            DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION       => ['onCampaignTriggerAction', 0],
            DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION_QUEUE => ['onCampaignTriggerActionQueue', 0],
            DialogHSMEvents::ON_CAMPAIGN_TRIGGER_DECISION     => ['onCampaignTriggerDecision', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        // Mesmo formulário e mesmo evento de disparo nas duas versões: o subscriber distingue
        // pela chave da ação (checkContext).
        // As versões novas usam o formulário guiado (template escolhido da lista do número,
        // um campo por variável); as legadas, o Payload Data livre de antes.
        $sendActions = [
            self::ACTION_SEND              => ['dialoghsm.campaign.send_whatsapp', DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION, SendWhatsAppTemplateType::class, self::TEMPLATE_FORM_THEME],
            self::ACTION_SEND_QUEUE        => ['dialoghsm.campaign.send_whatsapp_queue', DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION_QUEUE, SendWhatsAppTemplateQueueType::class, self::TEMPLATE_FORM_THEME],
            self::ACTION_SEND_LEGACY       => ['dialoghsm.campaign.send_whatsapp_legacy', DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION, SendWhatsAppType::class, null],
            self::ACTION_SEND_QUEUE_LEGACY => ['dialoghsm.campaign.send_whatsapp_queue_legacy', DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION_QUEUE, SendWhatsAppQueueType::class, null],
        ];

        foreach ($sendActions as $key => [$label, $batchEventName, $formType, $formTheme]) {
            $event->addAction(
                $key,
                array_filter([
                    'label'            => $label,
                    'description'      => $label.'.tooltip',
                    'batchEventName'   => $batchEventName,
                    'formType'         => $formType,
                    'formTheme'        => $formTheme,
                    'channel'          => 'whatsapp',
                    'timelineTemplate' => '@DialogHSM/Timeline/campaign_action.html.twig',
                ], static fn ($value): bool => null !== $value)
            );
        }

        $event->addAction(
            self::ACTION_SEND_MESSAGE,
            [
                'label'            => 'dialoghsm.campaign.send_whatsapp_message',
                'description'      => 'dialoghsm.campaign.send_whatsapp_message.tooltip',
                'batchEventName'   => DialogHSMEvents::ON_MARKETING_MESSAGE_SEND,
                'formType'         => WhatsAppMessageSendType::class,
                'channel'          => 'whatsapp',
                'channelIdField'   => 'whatsAppMessage',
                'timelineTemplate' => '@DialogHSM/Timeline/campaign_action.html.twig',
            ]
        );

        $sendActionTypes = [
            self::ACTION_SEND_LEGACY,
            self::ACTION_SEND_QUEUE_LEGACY,
            self::ACTION_SEND_MESSAGE,
            self::ACTION_SEND,
            self::ACTION_SEND_QUEUE,
        ];

        $event->addDecision(
            'dialoghsm.decision_delivered',
            [
                'label'                  => 'dialoghsm.campaign.decision_delivered',
                'description'            => 'dialoghsm.campaign.decision_delivered.tooltip',
                'eventName'              => DialogHSMEvents::ON_CAMPAIGN_TRIGGER_DECISION,
                'channel'                => 'whatsapp',
                'connectionRestrictions' => [
                    'source' => ['action' => $sendActionTypes],
                ],
            ]
        );

        $event->addDecision(
            'dialoghsm.decision_read',
            [
                'label'                  => 'dialoghsm.campaign.decision_read',
                'description'            => 'dialoghsm.campaign.decision_read.tooltip',
                'eventName'              => DialogHSMEvents::ON_CAMPAIGN_TRIGGER_DECISION,
                'channel'                => 'whatsapp',
                'connectionRestrictions' => [
                    'source' => ['action' => $sendActionTypes],
                ],
            ]
        );

        $event->addDecision(
            'dialoghsm.decision_replied',
            [
                'label'                  => 'dialoghsm.campaign.decision_replied',
                'description'            => 'dialoghsm.campaign.decision_replied.tooltip',
                'eventName'              => DialogHSMEvents::ON_CAMPAIGN_TRIGGER_DECISION,
                'channel'                => 'whatsapp',
                'connectionRestrictions' => [
                    'source' => ['action' => $sendActionTypes],
                ],
            ]
        );

        $event->addDecision(
            'dialoghsm.decision_button_clicked',
            [
                'label'                  => 'dialoghsm.campaign.decision_button_clicked',
                'description'            => 'dialoghsm.campaign.decision_button_clicked.tooltip',
                'eventName'              => DialogHSMEvents::ON_CAMPAIGN_TRIGGER_DECISION,
                'channel'                => 'whatsapp',
                'connectionRestrictions' => [
                    'source' => ['action' => $sendActionTypes],
                ],
            ]
        );
    }

    private const DECISION_KEYS = [
        'dialoghsm.decision_delivered',
        'dialoghsm.decision_read',
        'dialoghsm.decision_replied',
        'dialoghsm.decision_button_clicked',
    ];

    public function onCampaignTriggerDecision(CampaignExecutionEvent $event): CampaignExecutionEvent
    {
        $matchesContext = false;
        foreach (self::DECISION_KEYS as $decisionKey) {
            if ($event->checkContext($decisionKey)) {
                $matchesContext = true;
                break;
            }
        }

        if (!$matchesContext) {
            return $event->setResult(false);
        }

        $log = $event->getEventDetails();
        if (!$log instanceof MessageLog) {
            return $event->setResult(false);
        }

        $eventParent = $event->getEvent()['parent'] ?? null;
        if (empty($eventParent) || (int) $eventParent['id'] !== (int) $log->getCampaignEventId()) {
            return $event->setResult(false);
        }

        return $event->setResult(true);
    }

    public function onCampaignTriggerAction(PendingEvent $event): void
    {
        $legacy = $event->checkContext(self::ACTION_SEND_LEGACY);

        if (!$legacy && !$event->checkContext(self::ACTION_SEND)) {
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
                function (SendWhatsAppMessage $message) use (&$items): bool {
                    $items[] = $message;

                    return true;
                },
                applyBatchSleep: false,
                legacy: $legacy
            );

            if (!empty($items)) {
                $this->dispatchDirectBatch($items, $batchLimit, $sendDelay, fn (SendWhatsAppDirectBatchMessage $batch) => $this->bus->dispatch($batch));
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

        // Envio inline não tem fila para segurar a mensagem enquanto o número está pausado por
        // falta de pagamento: o contato fica com nova tentativa em 15 min (erro de montagem),
        // em vez de o envio sair e virar falha. Fila e Redis seguram a mensagem no consumo.
        $checkNumber = function (WhatsAppNumber $number) use ($legacy): void {
            if (!$legacy && null !== $this->paymentPause && $this->paymentPause->isPaused((string) $number->getApiKey())) {
                throw new CampaignActionBuildException(sprintf(
                    'O número WhatsApp "%s" está pausado: a 360dialog o bloqueou por falta de pagamento. O envio é tentado de novo automaticamente.',
                    $number->getName() ?? ''
                ));
            }
        };

        $items = [];
        $this->processContacts(
            $event,
            function (SendWhatsAppMessage $message) use (&$items): bool {
                $items[] = $message;

                return true;
            },
            applyBatchSleep: false,
            checkNumber: $checkNumber,
            legacy: $legacy
        );

        if (!empty($items)) {
            $results = $this->dispatchDirectBatch($items, $batchLimit, $sendDelay, fn (SendWhatsAppDirectBatchMessage $batch) => $this->directBatchHandler->__invoke($batch, inline: true));
            $this->markInlineSendFailures($event, $items, $results);
        }
    }

    /**
     * No modo inline o envio acontece neste mesmo processo, depois de o contato ser concluído
     * com pass(): o resultado da API já está disponível, então o envio recusado (template
     * inexistente, telefone recusado, parâmetro vazio) é marcado na ação como falha — antes
     * ficava como sucesso na campanha. passWithError sobre um log já aprovado só acrescenta
     * failed=1 e o motivo; não conta no limite de 10% do core.
     *
     * @param SendWhatsAppMessage[] $items
     */
    private function markInlineSendFailures(PendingEvent $event, array $items, mixed $results): void
    {
        if (!is_array($results)) {
            return;
        }

        $logIdByLead = [];
        foreach ($event->getContacts() as $logId => $contact) {
            $logIdByLead[(int) $contact->getId()] = $logId;
        }

        foreach ($items as $i => $item) {
            if (true === ($results[$i]['success'] ?? false) || !isset($logIdByLead[$item->leadId])) {
                continue;
            }

            $log = $event->getPending()->get($logIdByLead[$item->leadId]);
            if (null !== $log) {
                $event->passWithError($log, 'dialoghsm.campaign.error.send_failed');
            }
        }
    }

    /**
     * Entrega o lote do envio direto ao handler/stream. Exceções não podem escapar daqui:
     * o TriggerCampaignCommand em prod só captura \Exception, então um \Error derrubaria
     * o mautic:campaigns:trigger inteiro — todas as campanhas, não só esta.
     *
     * @param SendWhatsAppMessage[] $items
     */
    private function dispatchDirectBatch(array $items, int $batchLimit, int $sendDelay, callable $deliver): mixed
    {
        try {
            return $deliver(new SendWhatsAppDirectBatchMessage(
                items:      $items,
                batchLimit: $batchLimit,
                sendDelay:  $sendDelay,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('DialogHSM: Falha ao entregar lote do envio direto', [
                'items' => count($items),
                'error' => $e->getMessage(),
            ]);

            // Os contatos já foram concluídos na campanha: sem isto, sumiam sem nenhum registro.
            foreach ($items as $item) {
                $this->persistFailureLog(
                    $item->leadId,
                    $item->phone,
                    $item->templateName,
                    $item->whatsAppNumberName,
                    'Falha ao entregar lote do envio direto: '.$e->getMessage(),
                    $item->campaignId,
                    $item->campaignEventId,
                );
            }

            return null;
        }
    }

    public function onCampaignTriggerActionQueue(PendingEvent $event): void
    {
        $legacy = $event->checkContext(self::ACTION_SEND_QUEUE_LEGACY);

        if (!$legacy && !$event->checkContext(self::ACTION_SEND_QUEUE)) {
            return;
        }

        $config = $event->getEvent()->getProperties();
        $mode   = trim((string) ($config['queue_override'] ?? ''));

        // "Batch — lotes programados": com a chave geral desligada, a ação cai no batch comum
        if (PacedSettings::QUEUE_MODE === $mode && !$this->isPacedEnabled()) {
            $mode = 'batch';
        }

        if (PacedSettings::QUEUE_MODE === $mode) {
            $this->onCampaignTriggerActionPaced($event, $config, $legacy);

            return;
        }

        $checkNumber = function (WhatsAppNumber $number) use ($mode): void {
            if (null === $this->resolveQueueName($number, $mode)) {
                throw new CampaignActionBuildException(sprintf(
                    'O número WhatsApp "%s" não tem a fila %s configurada. Configure-a no cadastro do número ou troque o Tipo de Fila da ação.',
                    $number->getName() ?? '',
                    'batch' === $mode ? 'Batch (horário comercial)' : 'Bulk',
                ));
            }
        };

        $this->processContacts($event, function (SendWhatsAppMessage $message, WhatsAppNumber $number) use ($mode): bool {
            $uuid      = bin2hex(random_bytes(16));
            $queueName = $this->resolveQueueName($number, $mode);

            if (null === $queueName) {
                return false;
            }

            // O log 'queued' é gravado ANTES do dispatch: com o log depois, um worker rápido
            // consumia a mensagem antes do flush, criava outro log e o 'queued' ficava órfão.
            // Sem log gravado a mensagem não sai — o consumidor trata log ausente como envio
            // cancelado (ex.: "Limpar mensagens pendentes").
            $queuedLog = $this->persistQueuedLog($message, $uuid);
            if (null === $queuedLog) {
                return false;
            }

            $stamps  = [new AmqpStamp($queueName)];
            $payload = $message->withQueueLogId($uuid);
            if ($mode === 'batch') {
                $payload = $payload->withBatchMode();
            }

            try {
                $this->bus->dispatch($payload, $stamps);
            } catch (\Throwable $e) {
                $this->logger->error('DialogHSM: Falha ao publicar mensagem na fila', [
                    'lead_id' => $message->leadId,
                    'error'   => $e->getMessage(),
                ]);
                $this->markQueuedLogFailed($queuedLog, 'Falha ao enfileirar envio: '.$e->getMessage());

                return false;
            }

            return true;
        }, applyBatchSleep: false, checkNumber: $checkNumber, legacy: $legacy);
    }

    private function isPacedEnabled(): bool
    {
        try {
            return null !== $this->pacedScheduler && $this->pacedScheduler->isEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * "Batch — lotes programados": em vez de publicar no RabbitMQ, grava o log 'queued' e agenda a
     * mensagem no lote sorteado (dialog_hsm_paced_message). O envio é do PacedWorker. Não usa as
     * filas do número: o lote sai no horário mesmo com o bulk/batch do número ocupado.
     *
     * @param array<string, mixed> $config
     */
    private function onCampaignTriggerActionPaced(PendingEvent $event, array $config, bool $legacy): void
    {
        $lotSize  = (int) ($config['paced_lot_size'] ?? 0);
        $interval = (int) ($config['paced_lot_interval'] ?? 0);

        $checkConfig = function (WhatsAppNumber $number) use ($lotSize, $interval): void {
            if ($lotSize < 1 || $interval < PacedSettings::MIN_INTERVAL_MINUTES) {
                throw new CampaignActionBuildException(sprintf(
                    'Lotes programados: informe o tamanho do lote (mínimo 1) e o intervalo entre lotes (mínimo %d minutos).',
                    PacedSettings::MIN_INTERVAL_MINUTES
                ));
            }
        };

        $this->processContacts($event, function (SendWhatsAppMessage $message, WhatsAppNumber $number) use ($lotSize, $interval): bool {
            $uuid      = bin2hex(random_bytes(16));
            $queuedLog = $this->persistQueuedLog($message, $uuid);

            if (null === $queuedLog) {
                return false;
            }

            try {
                $this->pacedScheduler->schedule($message->withQueueLogId($uuid), (int) $number->getId(), $lotSize, $interval);
            } catch (\Throwable $e) {
                $this->logger->error('DialogHSM: Falha ao agendar mensagem em lote programado', [
                    'lead_id' => $message->leadId,
                    'error'   => $e->getMessage(),
                ]);
                $this->markQueuedLogFailed($queuedLog, 'Falha ao agendar envio: '.$e->getMessage());

                return false;
            }

            return true;
        }, applyBatchSleep: false, checkNumber: $checkConfig, legacy: $legacy);
    }

    private function resolveQueueName(WhatsAppNumber $number, string $mode): ?string
    {
        $queueName = 'batch' === $mode ? $number->getBatchQueueName() : $number->getQueueName();

        return (null === $queueName || '' === $queueName) ? null : $queueName;
    }

    /**
     * Garante que nenhuma exceção escape para o core. Uma exceção solta durante o kickoff
     * consome o contato em silêncio — o core já gravou o log como executado
     * (is_scheduled=0) antes de chamar a ação — e um \Error derruba o
     * mautic:campaigns:trigger inteiro, porque o comando só captura \Exception.
     *
     * @param (\Closure(WhatsAppNumber): void)|null $checkNumber validação extra do número
     *                                                          (lança CampaignActionBuildException)
     */
    private function processContacts(PendingEvent $event, callable $sender, bool $applyBatchSleep = true, ?\Closure $checkNumber = null, bool $legacy = false): void
    {
        // logIds já decididos neste lote (pass/passWithError) — não podem receber fail()
        $decided = [];

        try {
            $this->executeContacts($event, $sender, $applyBatchSleep, $checkNumber, $decided, $legacy);
        } catch (CampaignActionBuildException $e) {
            $this->failForBuildError($event, $decided, $e->getMessage());
        } catch (\Throwable $e) {
            $this->failForBuildError($event, $decided, 'Erro inesperado ao montar o envio: '.$e->getMessage(), $e);
        }
    }

    /**
     * Três etapas, nesta ordem, para que um erro de montagem nunca resulte em envio parcial:
     *   1. checagem prévia da configuração (integração, número, API key, fila, payload);
     *   2. montagem da mensagem de todos os contatos, sem enviar nada;
     *   3. envio.
     * Erros de montagem (etapas 1 e 2) lançam CampaignActionBuildException.
     *
     * @param array<int|string, true> $decided
     */
    private function executeContacts(PendingEvent $event, callable $sender, bool $applyBatchSleep, ?\Closure $checkNumber, array &$decided, bool $legacy = false): void
    {
        $campaignEvent   = $event->getEvent();
        $campaignId      = $campaignEvent->getCampaign()?->getId();
        $campaignEventId = $campaignEvent->getId();
        $config          = $campaignEvent->getProperties();
        $numberId        = (int) ($config['whatsapp_number'] ?? 0);

        // 1. Checagem prévia
        $integration = $this->fetchEnabledIntegration();

        if (null === $integration) {
            throw new CampaignActionBuildException('A integração 360dialog está desabilitada ou não configurada.');
        }

        $whatsAppNumber = $this->getWhatsAppNumber($numberId);

        if (null === $whatsAppNumber) {
            throw new CampaignActionBuildException(sprintf('O número WhatsApp selecionado na ação (ID %d) não existe ou está despublicado.', $numberId));
        }

        $apiKey = $whatsAppNumber->getApiKey();

        if (empty($apiKey)) {
            throw new CampaignActionBuildException(sprintf('O número WhatsApp "%s" não tem chave de API configurada.', $whatsAppNumber->getName() ?? ''));
        }

        if (null !== $checkNumber) {
            $checkNumber($whatsAppNumber);
        }

        $this->assertPayloadConfig($config);

        $baseUrl    = $this->resolveBaseUrl($whatsAppNumber, $integration);
        $sendDelay  = (int) ($config['send_delay'] ?? 0);
        $batchLimit = (int) ($config['batch_limit'] ?? 0);

        // 2. Montagem — nada é enviado até todos os contatos do lote estarem montados
        $messages = [];

        foreach ($event->getContacts() as $logId => $contact) {
            // Re-execução: já existe envio desta execução — decide com base no status real
            $existingLog = $this->messageLogRepository->findByCampaignEventAndLead(
                (int) $campaignEventId,
                (int) $contact->getId()
            );

            if ($existingLog !== null && $this->isFromCurrentExecution($existingLog, $event->getPending()->get($logId))) {
                $this->resolveFromWebhookLog($event, $logId, $existingLog);
                $decided[$logId] = true;
                continue;
            }

            $phone        = $this->normalizePhone((string) ($contact->getLeadPhoneNumber() ?? ''));
            $payloadData  = $this->buildPayloadFromConfig($config, $contact->getProfileFields(), $contact, TemplateVariableResolver::segmentsOf($campaignEvent->getCampaign()));
            $templateName = $this->templateNameFrom($payloadData);

            if ('' === $templateName) {
                throw new CampaignActionBuildException(sprintf(
                    'O nome do template (item "content" dos Dados do Payload) ficou vazio para o contato #%d após substituir os tokens.',
                    $contact->getId()
                ));
            }

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
                $event->passWithError(
                    $event->getPending()->get($logId),
                    'dialoghsm.campaign.error.invalid_phone'
                );
                $decided[$logId] = true;
                continue;
            }

            $messages[$logId] = new SendWhatsAppMessage(
                leadId:             $contact->getId(),
                phone:              $phone,
                apiKey:             $apiKey,
                baseUrl:            $baseUrl,
                payloadData:        $payloadData,
                templateName:       $templateName,
                whatsAppNumberName: $whatsAppNumber->getName() ?? '',
                campaignId:         $campaignId,
                campaignEventId:    $campaignEventId,
                legacy:             $legacy,
            );
        }

        // 3. Envio — falhas aqui são do envio (não da montagem): encerram o contato
        // batch_limit=N: envia N mensagens, pausa send_delay ms, repete para todos os contatos.
        // batch_limit=0: aplica send_delay entre cada mensagem individualmente.
        $effectiveBatch = $batchLimit > 0 ? $batchLimit : 1;
        $sentCount      = 0;

        foreach ($messages as $logId => $message) {
            try {
                $success = $sender($message, $whatsAppNumber);
            } catch (\Throwable $e) {
                $this->logger->error('DialogHSM: Exceção durante envio', [
                    'lead_id' => $message->leadId,
                    'error'   => $e->getMessage(),
                ]);
                $this->persistFailureLog(
                    $message->leadId,
                    $message->phone,
                    $message->templateName,
                    $message->whatsAppNumberName,
                    $e->getMessage(),
                    $campaignId,
                    $campaignEventId,
                );
                $event->passWithError(
                    $event->getPending()->get($logId),
                    'dialoghsm.campaign.error.send_failed'
                );
                $decided[$logId] = true;
                continue;
            }

            if (!$success) {
                $event->passWithError(
                    $event->getPending()->get($logId),
                    'dialoghsm.campaign.error.send_failed'
                );
                $decided[$logId] = true;
                continue;
            }

            // Mensagem entregue à fila/envio direto: o contato é decidido agora. O core exige
            // pass()/fail() para todo log do lote — sem isso lança LogNotProcessedException,
            // o mautic:campaigns:trigger abandona a campanha até o próximo cron e o disparo
            // sai picado (um lote por execução). O status real da Meta chega depois pelo
            // webhook: metadata da ação (WebhookProcessor/WebhookCampaignSubscriber) e nós
            // de decisão entregue/lido/respondeu.
            $event->pass($event->getPending()->get($logId));
            $decided[$logId] = true;

            ++$sentCount;

            if ($applyBatchSleep && $sendDelay > 0 && $sentCount % $effectiveBatch === 0) {
                usleep($sendDelay * 1_000_000);
            }
        }
    }

    /**
     * Erro de montagem: fail() com nova tentativa para todo contato ainda não decidido do
     * lote. Todos precisam ser decididos — o ActionDispatcher do core rejeita o lote
     * inteiro (LogNotProcessedException) se sobrar algum sem pass()/fail(), e aí nem o
     * reagendamento nem o motivo seriam gravados.
     *
     * Não grava dialog_hsm_message_log: na nova tentativa, findByCampaignEventAndLead()
     * acharia esse registro e resolveFromWebhookLog() encerraria o contato como falha de
     * envio — exatamente o consumo que este caminho existe para evitar.
     *
     * @param array<int|string, true> $decided
     */
    private function failForBuildError(PendingEvent $event, array $decided, string $reason, ?\Throwable $exception = null): void
    {
        $campaignEvent = $event->getEvent();
        $retryInterval = new \DateInterval(self::BUILD_ERROR_RETRY_INTERVAL);
        $failedCount   = 0;

        foreach ($event->getContacts() as $logId => $contact) {
            if (isset($decided[$logId])) {
                continue;
            }

            $log = $event->getPending()->get($logId);
            if (null === $log) {
                continue;
            }

            $event->fail($log, 'Envio não realizado — nova tentativa automática em 15 min. Motivo: '.$reason, $retryInterval);
            ++$failedCount;
        }

        $this->logger->error('DialogHSM: Envio não realizado por erro na montagem da ação de campanha — contatos mantidos para nova tentativa', [
            'campaign_id'       => $campaignEvent->getCampaign()?->getId(),
            'campaign_event_id' => $campaignEvent->getId(),
            'reason'            => $reason,
            'contacts'          => $failedCount,
            'exception'         => $exception,
        ]);
    }

    /**
     * Valida, uma vez por lote e antes de qualquer envio, o que depende só da configuração
     * da ação: o payload precisa ter o nome do template, e cada variável listada em "vars"
     * precisa existir como item do payload (senão o parâmetro iria vazio para a Meta).
     */
    private function assertPayloadConfig(array $config): void
    {
        $payload = $this->buildPayloadFromConfig($config, null);

        if ('' === $this->templateNameFrom($payload)) {
            throw new CampaignActionBuildException('Os Dados do Payload não têm o item "content" com o nome do template.');
        }

        $vars = trim((string) ($payload['vars'] ?? ''));
        if ('' === $vars) {
            return;
        }

        foreach (array_map('trim', explode(',', $vars)) as $varName) {
            if ('' !== $varName && !array_key_exists($varName, $payload)) {
                throw new CampaignActionBuildException(sprintf(
                    'O item "vars" dos Dados do Payload referencia "%s", mas não existe item com esse rótulo.',
                    $varName
                ));
            }
        }
    }

    /**
     * @param array<int|string, string> $payloadData
     */
    private function templateNameFrom(array $payloadData): string
    {
        return trim((string) ($payloadData['content'] ?? $payloadData['template'] ?? ''));
    }

    /**
     * O MessageLog é desta execução da ação ou de uma rotação anterior do contato?
     *
     * Quando o contato reentra na campanha (allow_restart, removido e readicionado), o core cria
     * um LeadEventLog novo com date_triggered = agora; o envio da rotação anterior é mais antigo
     * que isso e não pode impedir o novo envio. Já o envio desta execução nasce depois do log de
     * campanha. MessageLog não guarda a rotação, por isso a comparação é por data.
     */
    private function isFromCurrentExecution(MessageLog $messageLog, ?LeadEventLog $campaignLog): bool
    {
        $triggeredAt = $campaignLog?->getDateTriggered();
        $sentAt      = $messageLog->getDateSent();

        if (null === $triggeredAt || null === $sentAt) {
            return true;
        }

        return $sentAt >= $triggeredAt;
    }

    /**
     * Re-execução de um contato que já tem MessageLog: decide sempre (pass/passWithError),
     * nunca reenvia.
     */
    private function resolveFromWebhookLog(PendingEvent $event, mixed $logId, MessageLog $log): void
    {
        $status      = $log->getStatus();
        $campaignLog = $event->getPending()->get($logId);

        if ($campaignLog !== null) {
            $campaignLog->appendToMetadata(['whatsapp' => $this->buildWhatsAppMetadata($log)]);
        }

        $successStatuses = [MessageLog::STATUS_SENT, MessageLog::STATUS_DELIVERED, MessageLog::STATUS_READ];
        if (in_array($status, $successStatuses, true)) {
            $event->pass($campaignLog);

            return;
        }

        // Barrada na pré-validação: a mensagem nunca saiu. Encerra como erro do envio, nunca
        // como sucesso — senão a campanha seguiria como se o contato tivesse recebido.
        if (MessageLog::STATUS_INVALID === $status) {
            $event->passWithError($campaignLog, 'dialoghsm.campaign.error.preflight_invalid');

            return;
        }

        if (MessageLog::STATUS_FAILED === $status || MessageLog::STATUS_DLQ === $status) {
            if (in_array($log->getWebhookErrorCode(), self::META_RESTRICTION_CODES, true)) {
                $event->passWithError($campaignLog, 'dialoghsm.campaign.error.meta_restricted');

                return;
            }

            $event->passWithError($campaignLog, 'dialoghsm.campaign.error.webhook_failed');

            return;
        }

        if (MessageLog::STATUS_PENDING_WEBHOOK === $status) {
            $elapsed = (new \DateTime())->getTimestamp() - ($log->getDateSent()?->getTimestamp() ?? 0);
            if ($elapsed > self::WEBHOOK_TIMEOUT_SECONDS) {
                $event->passWithError($campaignLog, 'dialoghsm.campaign.error.webhook_timeout');

                return;
            }
        }

        // Mensagem ainda em trânsito (queued ou aguardando webhook): já foi enviada, então
        // não reenvia — decide agora para não deixar o log do lote sem pass()/fail().
        $event->pass($campaignLog);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWhatsAppMetadata(MessageLog $log): array
    {
        $fmtUtc = static fn (?\DateTimeInterface $dt): ?string => $dt === null ? null :
            \DateTime::createFromInterface($dt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return array_filter([
            'template_name' => $log->getTemplateName(),
            'sender_name'   => $log->getSenderName(),
            'phone_number'  => $log->getPhoneNumber(),
            'wamid'         => $log->getWamid(),
            'status'        => $log->getStatus(),
            'date_sent'     => $fmtUtc($log->getDateSent()),
            'error_message' => $log->getErrorMessage(),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Persiste um MessageLog com status 'queued' no momento do dispatch para a fila.
     * O wamid recebe o UUID temporário para que o handler possa localizar e atualizar este registro.
     */
    private function persistQueuedLog(SendWhatsAppMessage $message, string $uuid): ?MessageLog
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

            return $log;
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Falha ao registrar log queued', [
                'lead_id' => $message->leadId,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function markQueuedLogFailed(MessageLog $log, string $errorMessage): void
    {
        try {
            $log->setStatus(MessageLog::STATUS_FAILED);
            $log->setWamid(null);
            $log->setErrorMessage(mb_substr($errorMessage, 0, 500));
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Falha ao marcar log como falha', [
                'lead_id' => $log->getLeadId(),
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

            try {
                $this->leadEventLogWriter->write($log, MessageLog::STATUS_FAILED, $log->getDateSent() ?? new \DateTime());
            } catch (\Throwable) {}
        } catch (\Throwable $e) {
            $this->logger->warning('DialogHSM: Falha ao registrar log de erro de campanha', [
                'lead_id' => $leadId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * label/value são convertidos para string porque o SortableListTransformer do core
     * grava o valor como chave de array: um valor só com dígitos ("1", "2026") volta
     * do banco como int.
     *
     * @param array<string, mixed>|null $profileFields null = não substitui tokens
     *                                                 (usado na checagem prévia)
     * @param iterable<mixed>           $segments      segmentos de origem da campanha: dizem quais
     *                                                 itens de Custom Object contam
     *
     * @return array<int|string, mixed> valores string; DialogHSMApi::TOKENS_KEY guarda os originais com token
     */
    private function buildPayloadFromConfig(array $config, ?array $profileFields, ?Lead $contact = null, iterable $segments = []): array
    {
        $payloadData = $config['payload_data'] ?? [];
        $language    = (string) ($config['hsm_language'] ?? '');
        $params      = is_scalar($config['hsm_params'] ?? null) ? (string) $config['hsm_params'] : null;

        // Formulário guiado (ações novas): campos hsm_* nas propriedades do evento. Variável com
        // origem (campo do contato, Custom Object) vira texto aqui, com o contato e os segmentos.
        $resolveVariable = null === $this->variableResolver ? null
            : fn (mixed $variable, string $locale): string => $this->variableResolver->resolve($variable, $contact, $segments, $locale);
        $structured  = TemplatePayload::toList((string) ($config['hsm_template'] ?? ''), $language, $params, $resolveVariable);

        if (empty($payloadData) && [] === $structured) {
            return [];
        }

        $advanced = is_array($payloadData) ? ($payloadData['list'] ?? $payloadData) : [];
        $list     = TemplatePayload::merge($structured, is_array($advanced) ? $advanced : []);

        $result = [];
        $tokens = [];

        foreach ($list as $item) {
            if (!is_array($item) || !isset($item['label'], $item['value'])
                || !is_scalar($item['label']) || !is_scalar($item['value'])) {
                continue;
            }

            $key   = trim((string) $item['label']);
            $value = trim((string) $item['value']);

            if ('' === $key) {
                continue;
            }

            if (null !== $profileFields) {
                $resolved = TokenHelper::findLeadTokens($value, $profileFields, true);

                // Guarda o valor original de quem tinha token, só para a pré-validação dizer
                // qual token ficou vazio ("token {contactfield=firstname}").
                if ($resolved !== $value) {
                    $tokens[$key] = $value;
                }

                $value = $resolved;
            }

            $result[$key] = $value;
        }

        // Variável de campo/Custom Object já chega resolvida: a origem entra como "token" só para a
        // pré-validação dizer qual campo ficou vazio.
        $tokens += TemplatePayload::variableHints($params);

        if ([] !== $tokens && null !== $profileFields) {
            $result[DialogHSMApi::TOKENS_KEY] = $tokens;
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
     *
     * Números brasileiros sem código de país são completados automaticamente com "+55"
     * — cadastro/import de lead raramente inclui o "+55", e esse plugin serve só IES
     * brasileiras. Só se aplica a strings 100% numéricas (sem "+", sem letra), com
     * quantidade de dígitos plausível para DDD+número (10-11) ou 55+DDD+número (12-13):
     *   "44988291870"    (11 dígitos, sem código de país) → "+5544988291870"
     *   "4433221100"     (10 dígitos, fixo/celular antigo) → "+554433221100"
     *   "5544988291870"  (13 dígitos, "55" sem o "+")       → "+5544988291870"
     * Números fora desses tamanhos (ou com "+"/letras) passam intocados — a validação
     * E.164 em isValidE164() decide se rejeita.
     *
     * TODO(2026-07-09): assume Brasil-only de propósito (qualquer 10-11 dígitos é tratado
     * como BR, sem validar DDD real). Revisar antes de atender clientes fora do Brasil —
     * precisa virar configurável (código de país por WhatsAppNumber/integração, ou
     * libphonenumber).
     */
    private function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[ \-().]/u', '', trim($phone)) ?? $phone;

        if ('' === $cleaned || str_starts_with($cleaned, '+') || !ctype_digit($cleaned)) {
            return $cleaned;
        }

        if (preg_match('/^55\d{10,11}$/', $cleaned)) {
            return '+'.$cleaned;
        }

        if (preg_match('/^\d{10,11}$/', $cleaned)) {
            return '+55'.$cleaned;
        }

        return $cleaned;
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

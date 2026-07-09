<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Executioner\RealTimeExecutioner;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\PointBundle\Model\PointModel;
use Mautic\WebhookBundle\Model\WebhookModel;
use MauticPlugin\DialogHSMBundle\DialogHSMEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use MauticPlugin\DialogHSMBundle\Service\RedisContactCache;
use MauticPlugin\DialogHSMBundle\Service\WebhookProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Cobre a feature de webhook nativo do Mautic (WebhookBundle): WebhookProcessor deve
 * enfileirar o evento certo (WebhookModel::queueWebhooksByType) para cada status/ação de
 * mensagem WhatsApp — sent/delivered/read/failed/replied/button_clicked — com o payload
 * esperado, sem quebrar o fluxo do webhook quando o WebhookModel lança exceção.
 */
class WebhookProcessorNativeWebhookTest extends TestCase
{
    private WhatsAppNumberRepository&MockObject $numberRepository;
    private MessageLogRepository&MockObject $logRepository;
    private EntityManagerInterface&MockObject $em;
    private EventDispatcherInterface&MockObject $dispatcher;
    private LeadModel&MockObject $leadModel;
    private LeadEventLogWriter&MockObject $eventLogWriter;
    private PointModel&MockObject $pointModel;
    private RedisContactCache&MockObject $contactCache;
    private RealTimeExecutioner&MockObject $realTimeExecutioner;
    private ContactTracker&MockObject $contactTracker;
    private LoggerInterface&MockObject $logger;
    private WebhookModel&MockObject $webhookModel;
    private WebhookProcessor $processor;

    protected function setUp(): void
    {
        $this->numberRepository = $this->getMockBuilder(WhatsAppNumberRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByPhoneNumber'])
            ->getMock();
        $this->numberRepository->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());

        $this->logRepository       = $this->createMock(MessageLogRepository::class);
        $this->em                  = $this->createMock(EntityManagerInterface::class);
        $this->dispatcher          = $this->createMock(EventDispatcherInterface::class);
        $this->leadModel           = $this->createMock(LeadModel::class);
        $this->eventLogWriter      = $this->createMock(LeadEventLogWriter::class);
        $this->pointModel          = $this->createMock(PointModel::class);
        $this->contactCache        = $this->createMock(RedisContactCache::class);
        $this->realTimeExecutioner = $this->createMock(RealTimeExecutioner::class);
        $this->contactTracker      = $this->createMock(ContactTracker::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->webhookModel        = $this->createMock(WebhookModel::class);
        $this->processor           = new WebhookProcessor(
            $this->numberRepository,
            $this->logRepository,
            $this->em,
            $this->dispatcher,
            $this->leadModel,
            $this->eventLogWriter,
            $this->pointModel,
            $this->contactCache,
            $this->realTimeExecutioner,
            $this->contactTracker,
            $this->logger,
            $this->webhookModel,
        );
    }

    private function makeLog(string $status): MessageLog
    {
        $log = new MessageLog();
        $log->setStatus($status);
        $log->setWamid('wamid.abc');
        $log->setPhoneNumber('5511999999999');
        $log->setTemplateName('template_teste');

        return $log;
    }

    private function makeStatusPayload(string $wamid, string $status, array $errors = []): array
    {
        $entry = ['id' => $wamid, 'status' => $status, 'timestamp' => '1700000000', 'recipient_id' => '5511999999999'];
        if (!empty($errors)) {
            $entry['errors'] = $errors;
        }

        return ['entry' => [['changes' => [['value' => ['statuses' => [$entry]]]]]]];
    }

    // =========================================================================
    // HAPPY PATH — processStatus: sent / delivered / read / failed
    // =========================================================================

    public function testSentStatusQueuesMessageSentWebhook(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_PENDING_WEBHOOK);

        $this->logRepository->method('findByWamid')->willReturn($log);

        $this->webhookModel->expects($this->once())
            ->method('queueWebhooksByType')
            ->with(
                DialogHSMEvents::WEBHOOK_MESSAGE_SENT,
                $this->callback(function (array $payload) {
                    $this->assertSame('sent', $payload['status']);
                    $this->assertSame('template_teste', $payload['templateName']);
                    $this->assertArrayNotHasKey('errorMessage', $payload);

                    return true;
                })
            );

        $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', 'sent'));
    }

    public function testDeliveredStatusQueuesMessageDeliveredWebhook(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->logRepository->method('findByWamid')->willReturn($log);

        $this->webhookModel->expects($this->once())
            ->method('queueWebhooksByType')
            ->with(DialogHSMEvents::WEBHOOK_MESSAGE_DELIVERED, $this->isType('array'));

        $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', 'delivered'));
    }

    public function testDeliveredStatusWebhookIncludesEmailAndCustomFields(): void
    {
        // Plataforma de CS precisa de email + custom fields do lead, não do wamid interno.
        $log = $this->makeLog(MessageLog::STATUS_SENT);
        $log->setLeadId(321);

        $lead = $this->createMock(Lead::class);
        $lead->method('getEmail')->willReturn('contato@exemplo.com');
        $lead->method('getProfileFields')->willReturn([
            'id'          => 321,
            'firstname'   => 'Marlon',
            'curso'       => 'Tecnologo em Marketing',
        ]);

        $leadRepo = $this->createMock(LeadRepository::class);
        $leadRepo->method('getFieldValues')->with(321)->willReturn(['core' => []]);

        $this->leadModel->method('getEntity')->with(321)->willReturn($lead);
        $this->leadModel->method('getRepository')->willReturn($leadRepo);
        $this->logRepository->method('findByWamid')->willReturn($log);

        $this->webhookModel->expects($this->once())
            ->method('queueWebhooksByType')
            ->with(
                DialogHSMEvents::WEBHOOK_MESSAGE_DELIVERED,
                $this->callback(function (array $payload) {
                    $this->assertSame('contato@exemplo.com', $payload['email']);
                    $this->assertSame([
                        'firstname' => 'Marlon',
                        'curso'     => 'Tecnologo em Marketing',
                    ], $payload['customFields'], 'o campo "id" deve ser removido — já vai em leadId');

                    return true;
                })
            );

        $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', 'delivered'));
    }

    public function testReadStatusQueuesMessageReadWebhook(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_DELIVERED);

        $this->logRepository->method('findByWamid')->willReturn($log);

        $this->webhookModel->expects($this->once())
            ->method('queueWebhooksByType')
            ->with(DialogHSMEvents::WEBHOOK_MESSAGE_READ, $this->isType('array'));

        $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', 'read'));
    }

    public function testFailedStatusQueuesMessageFailedWebhookWithErrorDetails(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->logRepository->method('findByWamid')->willReturn($log);

        $this->webhookModel->expects($this->once())
            ->method('queueWebhooksByType')
            ->with(
                DialogHSMEvents::WEBHOOK_MESSAGE_FAILED,
                $this->callback(function (array $payload) {
                    $this->assertSame('failed', $payload['status']);
                    $this->assertSame('Re-engagement message', $payload['errorMessage']);
                    $this->assertSame(131047, $payload['webhookErrorCode']);

                    return true;
                })
            );

        $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', 'failed', [
            ['code' => 131047, 'title' => 'Re-engagement message'],
        ]));
    }

    public function testFailedStatusWithoutErrorsArrayQueuesWebhookWithoutErrorFields(): void
    {
        // Meta às vezes manda "failed" sem o array "errors" (ex.: falha genérica).
        // O payload não deve conter chaves vazias (array_filter remove null/'').
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->logRepository->method('findByWamid')->willReturn($log);

        $this->webhookModel->expects($this->once())
            ->method('queueWebhooksByType')
            ->with(
                DialogHSMEvents::WEBHOOK_MESSAGE_FAILED,
                $this->callback(function (array $payload) {
                    $this->assertSame('failed', $payload['status']);
                    $this->assertArrayNotHasKey('errorMessage', $payload);
                    $this->assertArrayNotHasKey('webhookErrorCode', $payload);

                    return true;
                })
            );

        $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', 'failed'));
    }

    // =========================================================================
    // BAD PATH — processStatus
    // =========================================================================

    public function testInvalidTransitionDoesNotQueueWebhook(): void
    {
        // read -> delivered não é uma transição válida (não pode retroceder)
        $log = $this->makeLog(MessageLog::STATUS_READ);

        $this->logRepository->method('findByWamid')->willReturn($log);

        $this->webhookModel->expects($this->never())->method('queueWebhooksByType');

        $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', 'delivered'));
    }

    public function testUnknownWamidDoesNotQueueWebhook(): void
    {
        $this->logRepository->method('findByWamid')->willReturn(null);

        $this->webhookModel->expects($this->never())->method('queueWebhooksByType');

        $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.unknown', 'sent'));
    }

    public function testUnknownPhoneNumberNeverReachesWebhookModel(): void
    {
        // 404 antes de qualquer lookup de log — número não cadastrado no plugin.
        $numberRepo = $this->getMockBuilder(WhatsAppNumberRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByPhoneNumber'])
            ->getMock();
        $numberRepo->method('findByPhoneNumber')->willReturn(null);

        $processor = new WebhookProcessor(
            $numberRepo,
            $this->logRepository,
            $this->em,
            $this->dispatcher,
            $this->leadModel,
            $this->eventLogWriter,
            $this->pointModel,
            $this->contactCache,
            $this->realTimeExecutioner,
            $this->contactTracker,
            $this->logger,
            $this->webhookModel,
        );

        $this->webhookModel->expects($this->never())->method('queueWebhooksByType');

        $result = $processor->process('+5511000000000', $this->makeStatusPayload('wamid.abc', 'sent'));

        $this->assertSame(404, $result);
    }

    public function testUnsupportedStatusValueDoesNotQueueWebhook(): void
    {
        // status fora da lista permitida (ex.: "deleted") deve ser ignorado silenciosamente
        $this->logRepository->expects($this->never())->method('findByWamid');

        $this->webhookModel->expects($this->never())->method('queueWebhooksByType');

        $result = $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', 'deleted'));

        $this->assertSame(200, $result);
    }

    public function testWebhookModelExceptionDoesNotBreakStatusFlow(): void
    {
        $log = $this->makeLog(MessageLog::STATUS_SENT);

        $this->logRepository->method('findByWamid')->willReturn($log);

        $this->webhookModel->method('queueWebhooksByType')
            ->willThrowException(new \RuntimeException('endpoint indisponível'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', 'delivered'));

        $this->assertSame(200, $result, 'Exceção do WebhookModel não deve derrubar o processamento do webhook');
        $this->assertSame(MessageLog::STATUS_DELIVERED, $log->getStatus(), 'O status do log já deve ter sido persistido antes da falha do webhook');
    }

    public function testLeadEnrichmentFailureDoesNotBreakStatusWebhook(): void
    {
        // getRepository()->getFieldValues() lança — o webhook ainda deve disparar com os
        // campos básicos (email/customFields simplesmente ficam de fora).
        $log = $this->makeLog(MessageLog::STATUS_SENT);
        $log->setLeadId(321);

        $leadRepo = $this->createMock(LeadRepository::class);
        $leadRepo->method('getFieldValues')->willThrowException(new \RuntimeException('DB indisponível'));

        $lead = $this->createMock(Lead::class);
        $this->leadModel->method('getEntity')->with(321)->willReturn($lead);
        $this->leadModel->method('getRepository')->willReturn($leadRepo);
        $this->logRepository->method('findByWamid')->willReturn($log);

        $this->webhookModel->expects($this->once())
            ->method('queueWebhooksByType')
            ->with(
                DialogHSMEvents::WEBHOOK_MESSAGE_DELIVERED,
                $this->callback(function (array $payload) {
                    $this->assertArrayNotHasKey('email', $payload);
                    $this->assertArrayNotHasKey('customFields', $payload);
                    $this->assertSame('delivered', $payload['status']);

                    return true;
                })
            );

        $result = $this->processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', 'delivered'));

        $this->assertSame(200, $result);
    }

    // =========================================================================
    // HAPPY PATH — processInbound: resposta e clique de botão
    // =========================================================================

    private function makeInboundPayloadWithContext(string $from, string $contextWamid, string $type = 'text'): array
    {
        $message = [
            'from'      => $from,
            'id'        => 'wamid.inbound.reply',
            'type'      => $type,
            'timestamp' => '1700000001',
            'context'   => ['id' => $contextWamid],
        ];

        if ('button' === $type) {
            $message['button'] = ['payload' => 'confirm-payload', 'text' => 'Confirmar'];
        }

        return [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [$message]],
                ]],
            ]],
        ];
    }

    private function makeHsmLog(int $leadId): MessageLog
    {
        $log = new MessageLog();
        $log->setLeadId($leadId);
        $log->setStatus(MessageLog::STATUS_READ);
        $log->setWamid('wamid.abc');

        return $log;
    }

    public function testReplyQueuesMessageRepliedWebhook(): void
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn(77);
        $log = $this->makeHsmLog(77);

        $this->leadModel->method('getEntity')->with(77)->willReturn($lead);
        $this->logRepository->method('findByWamid')->with('wamid.abc')->willReturn($log);

        $this->webhookModel->expects($this->once())
            ->method('queueWebhooksByType')
            ->with(
                DialogHSMEvents::WEBHOOK_MESSAGE_REPLIED,
                $this->callback(function (array $payload) {
                    $this->assertSame(77, $payload['leadId']);
                    $this->assertArrayHasKey('dateReplied', $payload);

                    return true;
                })
            );

        $this->processor->process('+5511999999999', $this->makeInboundPayloadWithContext('5511888888888', 'wamid.abc'));
    }

    public function testButtonClickQueuesBothRepliedAndButtonClickedWebhooks(): void
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn(77);
        $log = $this->makeHsmLog(77);

        $this->leadModel->method('getEntity')->with(77)->willReturn($lead);
        $this->logRepository->method('findByWamid')->with('wamid.abc')->willReturn($log);

        $queuedTypes = [];
        $this->webhookModel->expects($this->exactly(2))
            ->method('queueWebhooksByType')
            ->willReturnCallback(function (string $type) use (&$queuedTypes): void {
                $queuedTypes[] = $type;
            });

        $this->processor->process('+5511999999999', $this->makeInboundPayloadWithContext('5511888888888', 'wamid.abc', 'button'));

        // A Meta contabiliza clique de botão separado de resposta genérica — ambos disparam.
        $this->assertContains(DialogHSMEvents::WEBHOOK_MESSAGE_REPLIED, $queuedTypes);
        $this->assertContains(DialogHSMEvents::WEBHOOK_MESSAGE_BUTTON_CLICKED, $queuedTypes);
    }

    public function testButtonClickWebhookPayloadIncludesButtonPayload(): void
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn(77);
        $log = $this->makeHsmLog(77);

        $this->leadModel->method('getEntity')->with(77)->willReturn($lead);
        $this->logRepository->method('findByWamid')->with('wamid.abc')->willReturn($log);

        $this->webhookModel->expects($this->exactly(2))
            ->method('queueWebhooksByType')
            ->willReturnCallback(function (string $type, array $payload) {
                if (DialogHSMEvents::WEBHOOK_MESSAGE_BUTTON_CLICKED === $type) {
                    $this->assertSame('confirm-payload', $payload['buttonPayload']);
                }
            });

        $this->processor->process('+5511999999999', $this->makeInboundPayloadWithContext('5511888888888', 'wamid.abc', 'button'));
    }

    // =========================================================================
    // BAD PATH — processInbound: resposta e clique de botão
    // =========================================================================

    public function testReplyDoesNotQueueWhenContextWamidNotFound(): void
    {
        $this->logRepository->method('findByWamid')->with('wamid.unknown')->willReturn(null);

        $this->webhookModel->expects($this->never())->method('queueWebhooksByType');

        $this->processor->process('+5511999999999', $this->makeInboundPayloadWithContext('5511888888888', 'wamid.unknown'));
    }

    public function testReplyDoesNotQueueWhenLogHasNoLeadId(): void
    {
        $log = new MessageLog();
        $log->setWamid('wamid.abc');
        // sem setLeadId() — log órfão, sem contato associado

        $this->logRepository->method('findByWamid')->with('wamid.abc')->willReturn($log);

        $this->webhookModel->expects($this->never())->method('queueWebhooksByType');

        $this->processor->process('+5511999999999', $this->makeInboundPayloadWithContext('5511888888888', 'wamid.abc'));
    }

    public function testReplyDoesNotQueueWhenLeadNotFound(): void
    {
        $log = $this->makeHsmLog(77);

        $this->logRepository->method('findByWamid')->with('wamid.abc')->willReturn($log);
        $this->leadModel->method('getEntity')->with(77)->willReturn(null);

        $this->webhookModel->expects($this->never())->method('queueWebhooksByType');

        $this->processor->process('+5511999999999', $this->makeInboundPayloadWithContext('5511888888888', 'wamid.abc'));
    }

    public function testReplyDoesNotQueueTwiceWhenAlreadyReplied(): void
    {
        // idempotência: date_replied já preenchido — retry do webhook não deve reenfileirar
        $log = $this->makeHsmLog(77);
        $log->setDateReplied(new \DateTime('-10 minutes'));

        $this->logRepository->method('findByWamid')->with('wamid.abc')->willReturn($log);

        $this->webhookModel->expects($this->never())->method('queueWebhooksByType');

        $this->processor->process('+5511999999999', $this->makeInboundPayloadWithContext('5511888888888', 'wamid.abc'));
    }

    public function testButtonClickWithoutPayloadDoesNotQueueButtonClickedWebhook(): void
    {
        // webhook malformado: type=button sem button.payload nem button.text
        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn(77);
        $log = $this->makeHsmLog(77);

        $this->leadModel->method('getEntity')->with(77)->willReturn($lead);
        $this->logRepository->method('findByWamid')->with('wamid.abc')->willReturn($log);

        $payload = $this->makeInboundPayloadWithContext('5511888888888', 'wamid.abc', 'button');
        unset($payload['entry'][0]['changes'][0]['value']['messages'][0]['button']);

        $queuedTypes = [];
        $this->webhookModel->method('queueWebhooksByType')
            ->willReturnCallback(function (string $type) use (&$queuedTypes): void {
                $queuedTypes[] = $type;
            });

        $this->processor->process('+5511999999999', $payload);

        // resposta genérica ainda dispara — só o clique de botão (sem payload) é descartado
        $this->assertContains(DialogHSMEvents::WEBHOOK_MESSAGE_REPLIED, $queuedTypes);
        $this->assertNotContains(DialogHSMEvents::WEBHOOK_MESSAGE_BUTTON_CLICKED, $queuedTypes);
    }

    public function testButtonClickAlreadyRegisteredIsIdempotentAndDoesNotQueueAgain(): void
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn(77);
        $log = $this->makeHsmLog(77);
        $log->setDateReplied(new \DateTime('-10 minutes'));
        $log->setButtonPayload('confirm-payload');
        $log->setDateButtonClicked(new \DateTime('-10 minutes'));

        $this->leadModel->method('getEntity')->with(77)->willReturn($lead);
        $this->logRepository->method('findByWamid')->with('wamid.abc')->willReturn($log);

        $this->webhookModel->expects($this->never())->method('queueWebhooksByType');

        $this->processor->process('+5511999999999', $this->makeInboundPayloadWithContext('5511888888888', 'wamid.abc', 'button'));
    }

    public function testWebhookModelExceptionOnReplyDoesNotBreakInboundFlow(): void
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn(77);
        $log = $this->makeHsmLog(77);

        $this->leadModel->method('getEntity')->with(77)->willReturn($lead);
        $this->logRepository->method('findByWamid')->with('wamid.abc')->willReturn($log);

        $this->webhookModel->method('queueWebhooksByType')
            ->willThrowException(new \RuntimeException('endpoint indisponível'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $result = $this->processor->process('+5511999999999', $this->makeInboundPayloadWithContext('5511888888888', 'wamid.abc'));

        $this->assertSame(200, $result, 'Exceção do WebhookModel não deve derrubar o processamento do webhook de resposta');
        $this->assertNotNull($log->getDateReplied(), 'A resposta em si deve ter sido persistida mesmo com falha no webhook nativo');
    }
}

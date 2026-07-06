<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CampaignBundle\Executioner\RealTimeExecutioner;
use Mautic\CampaignBundle\Executioner\Scheduler\EventScheduler;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use Mautic\PointBundle\Model\PointModel;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use MauticPlugin\DialogHSMBundle\Service\OptimalTimeResolver;
use MauticPlugin\DialogHSMBundle\Service\RedisContactCache;
use MauticPlugin\DialogHSMBundle\Service\WebhookProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Testa o "contrato" entre WebhookProcessor (produtor) e CampaignSubscriber (consumidor)
 * das decisões de campanha "entregue"/"leu"/"respondeu".
 *
 * As duas classes concordam por convenção — mesma string de chave de decisão, mesmo
 * MessageLog como eventDetails, mesma correlação via campaignEventId — mas nada garantia
 * que essa convenção realmente batia nos dois lados. WebhookProcessorTest verifica apenas
 * o que o WebhookProcessor produz; CampaignSubscriberTest verifica apenas o que o
 * CampaignSubscriber consome. Este teste liga os dois pontos: captura a chamada real
 * feita ao RealTimeExecutioner::execute() e alimenta esse resultado de volta no
 * CampaignSubscriber::onCampaignTriggerDecision(), como o Mautic faria em produção.
 */
class WebhookDecisionFlowTest extends TestCase
{
    private function makeSubscriber(): CampaignSubscriber
    {
        return new CampaignSubscriber(
            $this->createMock(IntegrationsHelper::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(WhatsAppNumberModel::class),
            $this->createMock(SendWhatsAppMessageHandler::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(SendWhatsAppDirectBatchMessageHandler::class),
            $this->createMock(MessageLogRepository::class),
            $this->createMock(OptimalTimeResolver::class),
            $this->createMock(EventScheduler::class),
            $this->createMock(LeadEventLogWriter::class),
        );
    }

    /**
     * @return array{0: WebhookProcessor, 1: RealTimeExecutioner&MockObject, 2: MessageLogRepository&MockObject, 3: LeadModel&MockObject}
     */
    private function makeWebhookProcessor(): array
    {
        $numberRepo = $this->getMockBuilder(WhatsAppNumberRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByPhoneNumber'])
            ->getMock();
        $numberRepo->method('findByPhoneNumber')->willReturn(new WhatsAppNumber());

        $realTimeExecutioner = $this->createMock(RealTimeExecutioner::class);
        $logRepository       = $this->createMock(MessageLogRepository::class);
        $leadModel           = $this->createMock(LeadModel::class);

        $processor = new WebhookProcessor(
            $numberRepo,
            $logRepository,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(EventDispatcherInterface::class),
            $leadModel,
            $this->createMock(LeadEventLogWriter::class),
            $this->createMock(PointModel::class),
            $this->createMock(RedisContactCache::class),
            $realTimeExecutioner,
            $this->createMock(ContactTracker::class),
            $this->createMock(LoggerInterface::class),
        );

        return [$processor, $realTimeExecutioner, $logRepository, $leadModel];
    }

    private function makeStatusPayload(string $wamid, string $status): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => ['statuses' => [[
                        'id'           => $wamid,
                        'status'       => $status,
                        'timestamp'    => '1700000000',
                        'recipient_id' => '5511999999999',
                    ]]],
                ]],
            ]],
        ];
    }

    private function makeReplyPayload(string $from, string $contextWamid): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from'      => $from,
                            'id'        => 'wamid.inbound.reply',
                            'type'      => 'text',
                            'timestamp' => '1700000001',
                            'context'   => ['id' => $contextWamid],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * Captura os argumentos passados a RealTimeExecutioner::execute() e os devolve
     * como um CampaignExecutionEvent, exatamente como o RealTimeExecutioner real
     * repassaria para CampaignSubscriber::onCampaignTriggerDecision().
     *
     * @return array{0: string, 1: mixed} [decisionKey, passthrough]
     */
    private function captureExecutedDecision(RealTimeExecutioner&MockObject $rte, callable $trigger): array
    {
        $captured = null;
        $rte->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (string $type, $passthrough, ?string $channel, ?int $channelId) use (&$captured) {
                $captured = [$type, $passthrough, $channel, $channelId];

                return null;
            });

        $trigger();

        $this->assertNotNull($captured, 'RealTimeExecutioner::execute() não foi chamado');

        return $captured;
    }

    private function buildDecisionEvent(string $decisionKey, mixed $eventDetails, int $parentEventId): CampaignExecutionEvent
    {
        return new CampaignExecutionEvent(
            [
                'lead'            => null,
                'event'           => [
                    'type'   => $decisionKey,
                    'parent' => ['id' => $parentEventId],
                ],
                'eventDetails'    => $eventDetails,
                'systemTriggered' => false,
                'eventSettings'   => [],
            ],
            null
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function statusDecisionProvider(): iterable
    {
        yield 'delivered' => ['delivered', 'dialoghsm.decision_delivered'];
        yield 'read'      => ['read', 'dialoghsm.decision_read'];
    }

    /**
     * @dataProvider statusDecisionProvider
     */
    public function testWebhookStatusDecisionIsAcceptedByCampaignSubscriberForMatchingNode(
        string $webhookStatus,
        string $expectedDecisionKey,
    ): void {
        $log = new MessageLog();
        $log->setLeadId(1);
        $log->setCampaignEventId(20);
        $log->setStatus($webhookStatus === 'read' ? MessageLog::STATUS_DELIVERED : MessageLog::STATUS_SENT);

        [$processor, $rte, $logRepo, $leadModel] = $this->makeWebhookProcessor();
        $logRepo->method('findByWamid')->willReturn($log);
        $leadModel->method('getEntity')->with(1)->willReturn($this->createMock(Lead::class));

        [$decisionKey, $passthrough] = $this->captureExecutedDecision(
            $rte,
            fn () => $processor->process('+5511999999999', $this->makeStatusPayload('wamid.abc', $webhookStatus))
        );

        $this->assertSame($expectedDecisionKey, $decisionKey);
        $this->assertSame($log, $passthrough, 'O MessageLog repassado ao decision engine deve ser o mesmo log atualizado pelo webhook');

        $subscriber = $this->makeSubscriber();

        // Mesmo nó de envio que originou o log (campaignEventId=20) → decisão deve passar.
        $matchingEvent = $this->buildDecisionEvent($decisionKey, $passthrough, 20);
        $this->assertTrue(
            $subscriber->onCampaignTriggerDecision($matchingEvent)->getResult(),
            'CampaignSubscriber deveria aceitar a decisão produzida pelo WebhookProcessor para o nó correto'
        );

        // Nó de envio de outro fluxo na mesma campanha → decisão deve falhar.
        $mismatchedEvent = $this->buildDecisionEvent($decisionKey, $passthrough, 999);
        $this->assertFalse(
            $subscriber->onCampaignTriggerDecision($mismatchedEvent)->getResult(),
            'CampaignSubscriber não deveria avançar contatos de um nó de envio diferente'
        );
    }

    /**
     * A Meta às vezes agrupa "delivered" e "read" do MESMO log no mesmo payload de webhook
     * (ex.: contato abriu o WhatsApp e leu a mensagem antes do webhook de entrega ser processado).
     * As duas decisões devem disparar de forma independente, uma vez cada, sem se atropelar.
     */
    public function testDeliveredAndReadForSameLogInOnePayloadTriggerBothDecisionsIndependently(): void
    {
        $log = new MessageLog();
        $log->setLeadId(1);
        $log->setCampaignEventId(20);
        $log->setStatus(MessageLog::STATUS_SENT);

        [$processor, $rte, $logRepo, $leadModel] = $this->makeWebhookProcessor();
        $logRepo->method('findByWamid')->willReturn($log);
        $leadModel->method('getEntity')->with(1)->willReturn($this->createMock(Lead::class));

        $captured = [];
        $rte->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $type, $passthrough, ?string $channel, ?int $channelId) use (&$captured) {
                $captured[] = [$type, $passthrough, $channel, $channelId];

                return null;
            });

        // Meta entrega "delivered" e "read" do mesmo wamid no mesmo payload, nessa ordem.
        $processor->process('+5511999999999', [
            'entry' => [[
                'changes' => [[
                    'value' => ['statuses' => [
                        [
                            'id'           => 'wamid.abc',
                            'status'       => 'delivered',
                            'timestamp'    => '1700000000',
                            'recipient_id' => '5511999999999',
                        ],
                        [
                            'id'           => 'wamid.abc',
                            'status'       => 'read',
                            'timestamp'    => '1700000005',
                            'recipient_id' => '5511999999999',
                        ],
                    ]],
                ]],
            ]],
        ]);

        $this->assertCount(2, $captured);
        $this->assertSame('dialoghsm.decision_delivered', $captured[0][0]);
        $this->assertSame('dialoghsm.decision_read', $captured[1][0]);
        $this->assertSame($log, $captured[0][1]);
        $this->assertSame($log, $captured[1][1]);

        $subscriber = $this->makeSubscriber();

        // Ambas as decisões, alimentadas de volta no consumidor real, devem passar para o nó correto.
        foreach ($captured as [$decisionKey, $passthrough]) {
            $event = $this->buildDecisionEvent($decisionKey, $passthrough, 20);
            $this->assertTrue(
                $subscriber->onCampaignTriggerDecision($event)->getResult(),
                "Decisão '$decisionKey' deveria passar para o nó correto mesmo vindo do mesmo payload"
            );
        }
    }

    public function testWebhookReplyDecisionIsAcceptedByCampaignSubscriberForMatchingNode(): void
    {
        $log = new MessageLog();
        $log->setLeadId(1);
        $log->setCampaignEventId(20);
        $log->setWamid('wamid.original.hsm');
        $log->setStatus(MessageLog::STATUS_DELIVERED);

        [$processor, $rte, $logRepo, $leadModel] = $this->makeWebhookProcessor();
        $logRepo->method('findByWamid')->with('wamid.original.hsm')->willReturn($log);

        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn(1);
        $leadModel->method('getEntity')->with(1)->willReturn($lead);

        [$decisionKey, $passthrough] = $this->captureExecutedDecision(
            $rte,
            fn () => $processor->process('+5511999999999', $this->makeReplyPayload('5511888888888', 'wamid.original.hsm'))
        );

        $this->assertSame('dialoghsm.decision_replied', $decisionKey);
        $this->assertSame($log, $passthrough);

        $subscriber = $this->makeSubscriber();

        $matchingEvent = $this->buildDecisionEvent($decisionKey, $passthrough, 20);
        $this->assertTrue($subscriber->onCampaignTriggerDecision($matchingEvent)->getResult());

        $mismatchedEvent = $this->buildDecisionEvent($decisionKey, $passthrough, 999);
        $this->assertFalse($subscriber->onCampaignTriggerDecision($mismatchedEvent)->getResult());
    }
}

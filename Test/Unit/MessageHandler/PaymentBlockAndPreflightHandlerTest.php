<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumberRepository;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Service\BalanceAlertService;
use MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter;
use MauticPlugin\DialogHSMBundle\Service\CampaignActivityChecker;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use MauticPlugin\DialogHSMBundle\Service\PaymentBlockPauseService;
use MauticPlugin\DialogHSMBundle\Service\RedisContactCache;
use MauticPlugin\DialogHSMBundle\Service\WorkerQueueContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Handlers de envio com a pausa por falta de pagamento e o status "invalid" da pré-validação.
 * O PaymentBlockPauseService é o real (cache em memória): o teste cobre o ciclo inteiro —
 * bloqueio, mensagens seguras na fila, envio de teste e liberação.
 */
class PaymentBlockAndPreflightHandlerTest extends TestCase
{
    private const PAYMENT_BLOCKED = [
        'success'     => false,
        'response'    => ['meta' => ['developer_message' => 'This number is blocked due to lack of payment on client side.']],
        'error'       => 'HTTP 403: This number is blocked due to lack of payment on client side.',
        'http_status' => 403,
        'wamid'       => null,
        'retryable'   => false,
    ];

    private const SENT = ['success' => true, 'response' => ['messages' => [['id' => 'wamid.ok']]], 'error' => null, 'http_status' => 200, 'wamid' => 'wamid.ok', 'retryable' => false];

    private DialogHSMApi&MockObject $api;
    private EntityManagerInterface&MockObject $em;
    private MessageBusInterface&MockObject $bus;
    private LeadEventLogWriter&MockObject $eventLogWriter;
    private BalanceAlertService&MockObject $alerts;
    private WorkerQueueContext $queueContext;
    private PaymentBlockPauseService $pause;
    private SendWhatsAppMessageHandler $handler;

    /** @var MessageLog[] */
    private array $persisted = [];

    /** @var list<array{0: object, 1: array<int, object>}> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->api            = $this->createMock(DialogHSMApi::class);
        $this->em             = $this->createMock(EntityManagerInterface::class);
        $this->bus            = $this->createMock(MessageBusInterface::class);
        $this->eventLogWriter = $this->createMock(LeadEventLogWriter::class);
        $this->alerts         = $this->createMock(BalanceAlertService::class);
        $this->queueContext   = new WorkerQueueContext();

        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof MessageLog) {
                $this->persisted[] = $entity;
            }
        });
        $this->em->method('isOpen')->willReturn(true);

        $this->bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $this->dispatched[] = [$message, $stamps];

            return new Envelope($message);
        });

        $config = $this->createMock(PartnerConfigProvider::class);
        $config->method('isPaymentPauseEnabled')->willReturn(true);
        $config->method('getPaymentPauseMinutes')->willReturn(30);
        $this->pause = new PaymentBlockPauseService(new ArrayAdapter(), $config, $this->alerts, new NullLogger());

        $number = $this->createMock(WhatsAppNumber::class);
        $number->method('getIsPublished')->willReturn(true);
        $number->method('getName')->willReturn('Vestibular');
        $numbers = $this->createMock(WhatsAppNumberRepository::class);
        $numbers->method('findOneBy')->willReturn($number);

        $integrations = $this->createMock(IntegrationsHelper::class);
        $integrations->method('getIntegration')->willThrowException(new \RuntimeException('not configured'));

        $campaigns = $this->createMock(CampaignActivityChecker::class);
        $campaigns->method('getStatus')->willReturn(CampaignActivityChecker::ACTIVE);

        $this->handler = new SendWhatsAppMessageHandler(
            $this->api,
            $this->em,
            new NullLogger(),
            $this->createMock(LeadModel::class),
            $this->createMock(MessageLogRepository::class),
            $this->createMock(BulkRateLimiter::class),
            $integrations,
            $this->createMock(RedisContactCache::class),
            $numbers,
            $this->eventLogWriter,
            $campaigns,
            $this->queueContext,
            $this->bus,
            $this->pause,
        );
    }

    private function message(int $leadId = 1, bool $legacy = false): SendWhatsAppMessage
    {
        return new SendWhatsAppMessage(
            leadId:             $leadId,
            phone:              '+5511999990000',
            apiKey:             'API_KEY',
            baseUrl:            'https://waba-v2.360dialog.io/messages',
            payloadData:        ['content' => 'promo', 'language' => 'pt_BR'],
            templateName:       'promo',
            whatsAppNumberName: 'Vestibular',
            legacy:             $legacy,
        );
    }

    private function batchHandler(): SendWhatsAppDirectBatchMessageHandler
    {
        return new SendWhatsAppDirectBatchMessageHandler(
            $this->handler,
            new NullLogger(),
            $this->createMock(MessageLogRepository::class),
            static function (): void {},
            $this->em,
            $this->bus,
            $this->pause,
        );
    }

    // ── pausa por 403 de pagamento (fila RabbitMQ) ───────────────────────────

    public function testPaymentBlockPausesTheNumberAndRequeuesInsteadOfFailing(): void
    {
        $this->queueContext->setCurrentQueueName('wpp_vestibular');
        $this->api->expects($this->once())->method('sendMessage')->willReturn(self::PAYMENT_BLOCKED);
        $this->alerts->expects($this->once())->method('notifyPaymentBlock')->with($this->anything(), true, 30);

        $message = $this->message();
        $result  = ($this->handler)($message);

        $this->assertTrue($result['payment_blocked']);
        $this->assertSame([], $this->persisted, 'Nenhum log "failed": o log queued continua como está.');
        $this->assertTrue($this->pause->isPaused('API_KEY'));

        [$requeued, $stamps] = $this->dispatched[0];
        $this->assertSame($message, $requeued);
        $delay = array_values(array_filter($stamps, static fn ($s) => $s instanceof DelayStamp));
        $amqp  = array_values(array_filter($stamps, static fn ($s) => $s instanceof AmqpStamp));
        $this->assertSame(1_800_000, $delay[0]->getDelay());
        $this->assertSame('wpp_vestibular', $amqp[0]->getRoutingKey());
    }

    public function testMessagesForAPausedNumberAreNotSentAndNotFailed(): void
    {
        $this->queueContext->setCurrentQueueName('wpp_vestibular');
        $this->pause->pause('API_KEY', null, 'blocked');

        $this->api->expects($this->never())->method('sendMessage');

        for ($lead = 1; $lead <= 3; ++$lead) {
            $this->assertTrue(($this->handler)($this->message($lead))['paused']);
        }

        $this->assertSame([], $this->persisted);
        $this->assertCount(3, $this->dispatched);
    }

    public function testFirstSendAfterThePauseIsATestThatReleasesTheNumber(): void
    {
        $alerts = [];
        $this->alerts->method('notifyPaymentBlock')->willReturnCallback(function ($n, bool $blocked) use (&$alerts): void {
            $alerts[] = $blocked;
        });

        $this->queueContext->setCurrentQueueName('wpp_vestibular');
        $this->api->method('sendMessage')->willReturnOnConsecutiveCalls(self::PAYMENT_BLOCKED, self::SENT, self::SENT);

        ($this->handler)($this->message(1));
        $this->assertTrue($this->pause->isPaused('API_KEY'));

        // TTL da pausa vencido (simulado encerrando a pausa): a próxima mensagem é o teste.
        $this->pause->allowRecheck('API_KEY', 'test');

        $this->assertTrue(($this->handler)($this->message(2))['success']);
        $this->assertTrue(($this->handler)($this->message(3))['success']);

        $this->assertFalse($this->pause->isPaused('API_KEY'));
        $this->assertSame([true, false], $alerts, 'Um aviso de bloqueio e um de liberação.');
        $this->assertCount(2, $this->persisted);
        $this->assertSame(MessageLog::STATUS_PENDING_WEBHOOK, $this->persisted[0]->getStatus());
    }

    public function testStillBlockedOnTheTestSendRenewsThePauseWithoutFailing(): void
    {
        $this->queueContext->setCurrentQueueName('wpp_vestibular');
        $this->api->method('sendMessage')->willReturn(self::PAYMENT_BLOCKED);
        $this->alerts->expects($this->once())->method('notifyPaymentBlock');

        ($this->handler)($this->message(1));
        $this->pause->allowRecheck('API_KEY', 'test');
        ($this->handler)($this->message(2));

        $this->assertTrue($this->pause->isPaused('API_KEY'));
        $this->assertSame([], $this->persisted);
    }

    // ── lote (Redis / envio direto) ──────────────────────────────────────────

    public function testAsyncBatchRepublishesTheRestOfTheBatchWithDelay(): void
    {
        $this->api->method('sendMessage')->willReturnOnConsecutiveCalls(self::SENT, self::PAYMENT_BLOCKED);
        $items = [$this->message(1), $this->message(2), $this->message(3), $this->message(4)];

        $results = $this->batchHandler()(new SendWhatsAppDirectBatchMessage($items, 0, 0.0));

        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[1]['paused']);
        $this->assertCount(1, $this->persisted, 'Só o envio que passou é registrado; o resto não vira falha.');

        [$batch, $stamps] = $this->dispatched[0];
        $this->assertInstanceOf(SendWhatsAppDirectBatchMessage::class, $batch);
        $this->assertSame([2, 3, 4], array_map(static fn (SendWhatsAppMessage $m): int => $m->leadId, $batch->items));
        $this->assertSame(1_800_000, $stamps[0]->getDelay());
    }

    public function testInlineBatchWithoutQueueRecordsTheRestWithTheReason(): void
    {
        $this->api->method('sendMessage')->willReturn(self::PAYMENT_BLOCKED);

        $results = $this->batchHandler()->__invoke(new SendWhatsAppDirectBatchMessage([$this->message(1), $this->message(2)], 0, 0.0), inline: true);

        $this->assertFalse($results[0]['success']);
        $this->assertSame([], $this->dispatched, 'Envio inline não tem fila: nada é republicado.');
        $this->assertCount(2, $this->persisted);
        $this->assertSame(SendWhatsAppMessageHandler::PAYMENT_BLOCK_MESSAGE, substr((string) $this->persisted[0]->getErrorMessage(), strlen('[API 360dialog] ')));
    }

    // ── ações "(legado)": comportamento anterior ─────────────────────────────

    public function testLegacyMessageSkipsPreflightAndIsNotHeldByThePause(): void
    {
        $this->pause->pause('API_KEY', null, 'blocked');

        $this->api->expects($this->once())->method('sendMessage')
            ->with('API_KEY', $this->anything(), $this->anything(), $this->anything(), false)
            ->willReturn(self::SENT);

        $result = ($this->handler)($this->message(1, legacy: true));

        $this->assertTrue($result['success']);
        $this->assertTrue($this->pause->isPaused('API_KEY'), 'Envio legado não libera nem mexe na pausa.');
    }

    public function testLegacyMessageWithPaymentBlockFailsAsBefore(): void
    {
        $this->queueContext->setCurrentQueueName('wpp_vestibular');
        $this->api->method('sendMessage')->willReturn(self::PAYMENT_BLOCKED);
        $this->alerts->expects($this->never())->method('notifyPaymentBlock');

        ($this->handler)($this->message(1, legacy: true));

        $this->assertSame([], $this->dispatched);
        $this->assertSame(MessageLog::STATUS_FAILED, $this->persisted[0]->getStatus());
        $this->assertFalse($this->pause->isPaused('API_KEY'));
    }

    public function testNewMessageAsksForPreflight(): void
    {
        $this->api->expects($this->once())->method('sendMessage')
            ->with('API_KEY', $this->anything(), $this->anything(), $this->anything(), true)
            ->willReturn(self::SENT);

        ($this->handler)($this->message());
    }

    public function testMessageQueuedBeforeTheDeployIsTreatedAsLegacy(): void
    {
        // Serializada sem a propriedade legacy (formato anterior).
        $old = $this->message();
        $data = (fn () => get_object_vars($this))->call($old);
        unset($data['legacy']);

        $restored = (new \ReflectionClass(SendWhatsAppMessage::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($data);

        $this->assertTrue($restored->legacy);
    }

    // ── pré-validação: status invalid ────────────────────────────────────────

    public function testMessageRejectedByPreflightIsLoggedAsInvalid(): void
    {
        $this->api->method('sendMessage')->willReturn([
            'success'     => false,
            'response'    => null,
            'error'       => 'Pré-validação: parâmetro 1 do BODY vazio (variável "nome", token {contactfield=firstname})',
            'http_status' => null,
            'wamid'       => null,
            'retryable'   => false,
            'invalid'     => true,
        ]);
        $this->eventLogWriter->expects($this->once())->method('write')
            ->with($this->isInstanceOf(MessageLog::class), MessageLog::STATUS_INVALID, $this->anything());

        $result = ($this->handler)($this->message());

        $this->assertFalse($result['success']);
        $this->assertSame(MessageLog::STATUS_INVALID, $this->persisted[0]->getStatus());
        $this->assertSame('Pré-validação: parâmetro 1 do BODY vazio (variável "nome", token {contactfield=firstname})', $this->persisted[0]->getErrorMessage());
        $this->assertNull($this->persisted[0]->getWamid());
        $this->assertNull($this->persisted[0]->getHttpStatusCode());
    }
}

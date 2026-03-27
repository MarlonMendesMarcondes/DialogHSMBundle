<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Testes de performance do CampaignSubscriber.
 *
 * Medem o throughput (contatos/s) e overhead de memória do loop de envio
 * nos dois modos: inline (sem Redis) e dispatch (com Redis).
 *
 * Nenhum teste dorme — send_delay=0 em todos os casos.
 */
class CampaignSubscriberPerformanceTest extends TestCase
{
    private const VOLUMES      = [100, 500, 1_000];
    private const MAX_MS_PER_CONTACT_INLINE   = 1.0;  // 1 ms por contato inline
    private const MAX_MS_PER_CONTACT_REDIS    = 0.5;  // 0.5 ms por contato via Redis
    private const MAX_MEMORY_GROWTH_MB        = 5.0;  // crescimento máximo de memória por rodada

    private IntegrationsHelper&MockObject    $mockIntegrationsHelper;
    private MessageBusInterface&MockObject   $mockBus;
    private LoggerInterface&MockObject       $mockLogger;
    private WhatsAppNumberModel&MockObject   $mockNumberModel;
    private SendWhatsAppMessageHandler&MockObject $mockHandler;
    private EntityManagerInterface&MockObject $mockEntityManager;

    protected function setUp(): void
    {
        $this->mockIntegrationsHelper = $this->createMock(IntegrationsHelper::class);
        $this->mockBus                = $this->createMock(MessageBusInterface::class);
        $this->mockLogger             = $this->createMock(LoggerInterface::class);
        $this->mockNumberModel        = $this->createMock(WhatsAppNumberModel::class);
        $this->mockHandler            = $this->createMock(SendWhatsAppMessageHandler::class);
        $this->mockEntityManager      = $this->createMock(EntityManagerInterface::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeSubscriber(string $directTransportDsn = 'null://null'): CampaignSubscriber
    {
        return new CampaignSubscriber(
            $this->mockIntegrationsHelper,
            $this->mockBus,
            $this->mockLogger,
            $this->mockNumberModel,
            $this->mockHandler,
            $this->mockEntityManager,
            $directTransportDsn,
        );
    }

    private function makeIntegration(): object
    {
        $mockConfig = new class {
            public function getIsPublished(): bool { return true; }
            public function getApiKeys(): array    { return ['base_url' => '']; }
        };

        return new class($mockConfig) {
            public function __construct(private $config) {}
            public function getIntegrationConfiguration() { return $this->config; }
        };
    }

    private function makeNumber(): WhatsAppNumber&MockObject
    {
        $mock = $this->createMock(WhatsAppNumber::class);
        $mock->method('getApiKey')->willReturn('VALID_API_KEY_12345');
        $mock->method('getBaseUrl')->willReturn('https://api.360dialog.com/v1/messages');
        $mock->method('getIsPublished')->willReturn(true);

        return $mock;
    }

    /**
     * @return array<int, Lead&MockObject>
     */
    private function buildContacts(int $count): array
    {
        $contacts = [];
        for ($i = 1; $i <= $count; ++$i) {
            $mock = $this->createMock(Lead::class);
            $mock->method('getLeadPhoneNumber')->willReturn("+5511900{$i}");
            $mock->method('getId')->willReturn($i);
            $mock->method('getProfileFields')->willReturn([]);
            $contacts[$i] = $mock;
        }

        return $contacts;
    }

    // -------------------------------------------------------------------------
    // Performance: envio inline (sem Redis)
    // -------------------------------------------------------------------------

    /**
     * @dataProvider volumeProvider
     */
    public function testInlineSendThroughput(int $volume): void
    {
        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegration());

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->makeNumber());

        $this->mockHandler
            ->method('__invoke')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        $contacts = $this->buildContacts($volume);
        $event    = $this->buildPendingEvent('dialoghsm.send_whatsapp', $contacts);

        $subscriber = $this->makeSubscriber('null://null');

        $memBefore = memory_get_usage(true);
        $start     = microtime(true);

        $subscriber->onCampaignTriggerAction($event);

        $elapsed   = (microtime(true) - $start) * 1000; // ms
        $memGrowth = (memory_get_usage(true) - $memBefore) / 1024 / 1024; // MB

        $msPerContact = $elapsed / $volume;

        $this->assertLessThan(
            self::MAX_MS_PER_CONTACT_INLINE,
            $msPerContact,
            sprintf(
                'Inline: %d contatos em %.2f ms (%.4f ms/contato) — limite: %.1f ms/contato',
                $volume,
                $elapsed,
                $msPerContact,
                self::MAX_MS_PER_CONTACT_INLINE
            )
        );

        $this->assertLessThan(
            self::MAX_MEMORY_GROWTH_MB,
            $memGrowth,
            sprintf('Memória cresceu %.2f MB para %d contatos — limite: %.1f MB', $memGrowth, $volume, self::MAX_MEMORY_GROWTH_MB)
        );
    }

    // -------------------------------------------------------------------------
    // Performance: envio via Redis (dispatch)
    // -------------------------------------------------------------------------

    /**
     * @dataProvider volumeProvider
     */
    public function testRedisSendThroughput(int $volume): void
    {
        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegration());

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->makeNumber());

        $this->mockBus
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $contacts = $this->buildContacts($volume);
        $event    = $this->buildPendingEvent('dialoghsm.send_whatsapp', $contacts);

        $subscriber = $this->makeSubscriber('redis://localhost:6379');

        $memBefore = memory_get_usage(true);
        $start     = microtime(true);

        $subscriber->onCampaignTriggerAction($event);

        $elapsed   = (microtime(true) - $start) * 1000;
        $memGrowth = (memory_get_usage(true) - $memBefore) / 1024 / 1024;

        $msPerContact = $elapsed / $volume;

        $this->assertLessThan(
            self::MAX_MS_PER_CONTACT_REDIS,
            $msPerContact,
            sprintf(
                'Redis: %d contatos em %.2f ms (%.4f ms/contato) — limite: %.1f ms/contato',
                $volume,
                $elapsed,
                $msPerContact,
                self::MAX_MS_PER_CONTACT_REDIS
            )
        );

        $this->assertLessThan(
            self::MAX_MEMORY_GROWTH_MB,
            $memGrowth,
            sprintf('Memória cresceu %.2f MB para %d contatos — limite: %.1f MB', $memGrowth, $volume, self::MAX_MEMORY_GROWTH_MB)
        );
    }

    // -------------------------------------------------------------------------
    // Performance: Redis não chama handler (I/O diferida para o worker)
    // -------------------------------------------------------------------------

    public function testRedisNeverCallsHandlerFor1000Contacts(): void
    {
        // No modo Redis a chamada à API é diferida para o worker — o handler
        // nunca deve ser invocado no processo do Mautic, independentemente do volume.
        $volume = 1_000;

        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegration());

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->makeNumber());

        $this->mockBus
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        // Handler NÃO deve ser chamado nenhuma vez
        $this->mockHandler->expects($this->never())->method('__invoke');

        $contacts   = $this->buildContacts($volume);
        $event      = $this->buildPendingEvent('dialoghsm.send_whatsapp', $contacts);
        $subscriber = $this->makeSubscriber('redis://localhost:6379');

        $start = microtime(true);
        $subscriber->onCampaignTriggerAction($event);
        $elapsed = (microtime(true) - $start) * 1000;

        // O loop de dispatch de 1000 contatos deve terminar em menos de 500 ms
        $this->assertLessThan(
            500.0,
            $elapsed,
            sprintf('Dispatch de %d contatos levou %.2f ms — esperado < 500 ms', $volume, $elapsed)
        );
    }

    // -------------------------------------------------------------------------
    // Performance: dispatch não cria objetos desnecessários
    // -------------------------------------------------------------------------

    public function testRedisDispatchMessageTypeIsSendWhatsAppDirectMessage(): void
    {
        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegration());

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->makeNumber());

        $volume      = 50;
        $dispatched  = [];

        $this->mockBus
            ->method('dispatch')
            ->willReturnCallback(function ($msg) use (&$dispatched): Envelope {
                $dispatched[] = $msg;

                return new Envelope($msg);
            });

        $contacts   = $this->buildContacts($volume);
        $event      = $this->buildPendingEvent('dialoghsm.send_whatsapp', $contacts);
        $subscriber = $this->makeSubscriber('redis://localhost:6379');

        $subscriber->onCampaignTriggerAction($event);

        $this->assertCount($volume, $dispatched);

        foreach ($dispatched as $msg) {
            $this->assertInstanceOf(
                SendWhatsAppDirectMessage::class,
                $msg,
                'Todos os objetos despachados devem ser SendWhatsAppDirectMessage'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Rate limiting: batch_limit=10, send_delay=2s (configuração 360dialog)
    // -------------------------------------------------------------------------

    /**
     * Valida o tempo de execução esperado com throttle de 2s a cada 10 mensagens.
     *
     * Fórmula: floor(contacts / batch_limit) × send_delay
     *   20 contatos / 10 por lote = 2 sleeps × 1s (usamos 1s aqui para não travar o CI)
     *
     * Em produção com send_delay=2: 1000 contatos → 100 × 2s = 200s (~3,3 min)
     */
    public function testInlineBatchDelayTimingIsCorrect(): void
    {
        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegration());

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->makeNumber());

        $this->mockHandler
            ->method('__invoke')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        // 20 contatos, lote=10, delay=1s → 2 sleeps = ~2s
        $contacts = $this->buildContacts(20);
        $event    = $this->buildPendingEvent('dialoghsm.send_whatsapp', $contacts, [
            'batch_limit' => 10,
            'send_delay'  => 1,
        ]);

        $subscriber = $this->makeSubscriber('null://null');

        $start = microtime(true);
        $subscriber->onCampaignTriggerAction($event);
        $elapsed = microtime(true) - $start;

        // 2 sleeps de 1s = pelo menos 1.9s (margem de 5% para variação do scheduler)
        $this->assertGreaterThanOrEqual(1.9, $elapsed, 'Esperados 2 sleeps de 1s (lote 10, 20 contatos)');
        // Não deve ultrapassar 3s (margem)
        $this->assertLessThan(3.0, $elapsed, 'Não deve haver mais de 2 sleeps');
    }

    public function testRedisIgnoresBatchDelayEvenWith360dialogConfig(): void
    {
        // No modo Redis applyBatchSleep=false → nenhum sleep, mesmo com batch_limit=10 e send_delay=2
        $this->mockIntegrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegration());

        $this->mockNumberModel
            ->method('getEntity')
            ->willReturn($this->makeNumber());

        $this->mockBus
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        // Mesma configuração de produção: batch_limit=10, send_delay=2
        $contacts = $this->buildContacts(100);
        $event    = $this->buildPendingEvent('dialoghsm.send_whatsapp', $contacts, [
            'batch_limit' => 10,
            'send_delay'  => 2,
        ]);

        $subscriber = $this->makeSubscriber('redis://localhost:6379');

        $start = microtime(true);
        $subscriber->onCampaignTriggerAction($event);
        $elapsed = microtime(true) - $start;

        // 100 contatos com batch=10 e delay=2 → inline seria 20s; Redis deve terminar em < 1s
        $this->assertLessThan(
            1.0,
            $elapsed,
            sprintf('Redis com send_delay=2 não deve dormir — levou %.3f s para 100 contatos', $elapsed)
        );
    }

    // -------------------------------------------------------------------------
    // Data provider
    // -------------------------------------------------------------------------

    public static function volumeProvider(): array
    {
        return array_map(fn (int $v) => [$v], self::VOLUMES);
    }

    private function buildPendingEvent(string $context, array $contacts, array $extraConfig = []): PendingEvent&MockObject
    {
        $config = array_merge([
            'whatsapp_number' => 1,
            'payload_data'    => ['list' => [['label' => 'content', 'value' => 'perf_template']]],
            'send_delay'      => 0,
            'batch_limit'     => 0,
        ], $extraConfig);

        $mockCampaignEvent = $this->createMock(CampaignEvent::class);
        $mockCampaignEvent->method('getProperties')->willReturn($config);

        $mockLog     = $this->createMock(LeadEventLog::class);
        $mockPending = $this->createMock(ArrayCollection::class);
        $mockPending->method('get')->willReturn($mockLog);

        $mockPendingEvent = $this->createMock(PendingEvent::class);
        $mockPendingEvent->method('checkContext')->with($context)->willReturn(true);
        $mockPendingEvent->method('getEvent')->willReturn($mockCampaignEvent);
        $mockPendingEvent->method('getContacts')->willReturn($contacts);
        $mockPendingEvent->method('getPending')->willReturn($mockPending);

        return $mockPendingEvent;
    }
}

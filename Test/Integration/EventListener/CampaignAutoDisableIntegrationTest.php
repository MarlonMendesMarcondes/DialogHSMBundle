<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\CampaignRepository;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\CampaignBundle\EventListener\CampaignEventSubscriber;
use Mautic\CampaignBundle\Executioner\Dispatcher\ActionDispatcher;
use Mautic\CampaignBundle\Executioner\Dispatcher\LegacyEventDispatcher;
use Mautic\CampaignBundle\Executioner\Helper\NotificationHelper;
use Mautic\CampaignBundle\Executioner\Scheduler\EventScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\DialogHSMBundle\DialogHSMEvents;
use MauticPlugin\DialogHSMBundle\Entity\MessageLog;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\CampaignSubscriber;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppNumberModel;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Teste de integração real entre o plugin (CampaignSubscriber) e o core do
 * Mautic (PendingEvent, ActionDispatcher, CampaignEventSubscriber).
 *
 * Diferente dos testes unitários em Test/Unit/EventListener/CampaignSubscriberActionTest.php,
 * aqui NENHUMA classe de negócio é mockada: PendingEvent, ActionDispatcher e
 * CampaignEventSubscriber (que contém o limite de 10% que desativa a campanha —
 * Mautic\CampaignBundle\EventListener\CampaignEventSubscriber::$disableCampaignThreshold)
 * são as classes REAIS do core, ligadas por um EventDispatcher real, exatamente
 * como em produção. Só dependências de persistência/notificação (EventRepository,
 * CampaignRepository, NotificationHelper) são mockadas, porque não há kernel
 * Symfony/banco de teste neste projeto (ver project_integration_test_infra_gap).
 *
 * Isso fecha a lacuna dos testes unitários, que só verificam que passWithError()/
 * fail() foram chamados — nunca o que o core do Mautic faz de fato com essa chamada.
 */
class CampaignAutoDisableIntegrationTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private ActionDispatcher $actionDispatcher;
    private EventRepository&MockObject $mockEventRepository;
    private CampaignRepository&MockObject $mockCampaignRepository;

    /** @var array<int, int> id do Event => contador de falhas simulado */
    private array $failedCounts = [];

    protected function setUp(): void
    {
        // EventRepository real faria UPDATE no banco (incrementFailedCount) — aqui
        // simulamos o contador em memória, mas a DECISÃO de desativar (>= 10%) é
        // feita pelo CampaignEventSubscriber::onEventFailed() real, não por nós.
        $this->mockEventRepository = $this->createMock(EventRepository::class);
        $this->mockEventRepository->method('incrementFailedCount')
            ->willReturnCallback(function (CampaignEvent $event) {
                $id = spl_object_id($event);
                $this->failedCounts[$id] = ($this->failedCounts[$id] ?? 0) + 1;

                return $this->failedCounts[$id];
            });

        $this->mockCampaignRepository = $this->createMock(CampaignRepository::class);

        $realCampaignEventSubscriber = new CampaignEventSubscriber(
            $this->mockEventRepository,
            $this->createMock(NotificationHelper::class),
            $this->mockCampaignRepository,
        );

        // EventDispatcher REAL do Symfony — não um mock. É ele quem efetivamente
        // liga o nosso CampaignSubscriber (plugin) ao CampaignEventSubscriber (core).
        // O subscriber do plugin é registrado dentro de dispatch(), específico de
        // cada teste (cada MessageLogRepository simula um cenário diferente).
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber($realCampaignEventSubscriber);

        $this->actionDispatcher = new ActionDispatcher(
            $this->dispatcher,
            new NullLogger(),
            $this->createMock(EventScheduler::class),
            $this->createMock(LegacyEventDispatcher::class),
        );
    }

    /**
     * Monta o CampaignSubscriber do plugin com MessageLogRepository configurado
     * para devolver, por lead, o MessageLog com o status/webhook_error_code que o
     * teste quer simular — assim resolveFromWebhookLog() roda de verdade.
     *
     * @param array<int, MessageLog> $logsByLeadId
     */
    private function makePluginSubscriberWithLogs(array $logsByLeadId): CampaignSubscriber
    {
        $mockMessageLogRepository = $this->createMock(MessageLogRepository::class);
        $mockMessageLogRepository->method('findByCampaignEventAndLead')
            ->willReturnCallback(fn (int $eventId, int $leadId) => $logsByLeadId[$leadId] ?? null);

        $mockIntegrationsHelper = $this->createMock(IntegrationsHelper::class);
        $mockIntegrationsHelper->method('getIntegration')->willReturn($this->makeIntegrationMock());

        $mockNumberModel = $this->createMock(WhatsAppNumberModel::class);
        $mockNumberModel->method('getEntity')->willReturn($this->buildWhatsAppNumber());

        return new CampaignSubscriber(
            $mockIntegrationsHelper,
            $this->createMock(MessageBusInterface::class),
            $this->createMock(LoggerInterface::class),
            $mockNumberModel,
            $this->createMock(SendWhatsAppMessageHandler::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(SendWhatsAppDirectBatchMessageHandler::class),
            $mockMessageLogRepository,
            $this->createMock(LeadEventLogWriter::class),
        );
    }

    /**
     * Réplica mínima do helper equivalente em CampaignSubscriberActionTest — só o
     * necessário para o plugin considerar a integração ativada e o número válido,
     * o que não é o que este teste está verificando.
     */
    private function makeIntegrationMock(): object
    {
        $mockConfig = new class {
            public function getIsPublished(): bool
            {
                return true;
            }

            public function getApiKeys(): array
            {
                return ['base_url' => ''];
            }
        };

        return new class($mockConfig) {
            public function __construct(private $config)
            {
            }

            public function getIntegrationConfiguration()
            {
                return $this->config;
            }
        };
    }

    private function buildWhatsAppNumber(): WhatsAppNumber&MockObject
    {
        $mock = $this->createMock(WhatsAppNumber::class);
        $mock->method('getApiKey')->willReturn('VALID_API_KEY_12345');
        $mock->method('getBaseUrl')->willReturn('https://api.360dialog.com/v1/messages');
        $mock->method('getIsPublished')->willReturn(true);
        $mock->method('getQueueName')->willReturn('whatsapp_bulk');
        $mock->method('getBatchQueueName')->willReturn('whatsapp_batch');

        return $mock;
    }

    /**
     * @return array{campaign: Campaign, event: CampaignEvent, logs: ArrayCollection<int, LeadEventLog>, leadIds: int[]}
     */
    private function buildCampaignWithContacts(int $totalContacts): array
    {
        $campaign = new Campaign();
        $campaign->setIsPublished(true);

        $event = new CampaignEvent();
        $event->setCampaign($campaign);
        $event->setType('dialoghsm.send_whatsapp_queue');
        // Sem isso, getWhatsAppNumber() do plugin recebe id=0 (whatsapp_number ausente
        // das properties) e falha TODOS os contatos por "número não encontrado" antes
        // de sequer chegar em resolveFromWebhookLog() — mascarando o teste (qualquer
        // cenário viraria "campanha desativada", pelo motivo errado).
        $event->setProperties([
            'whatsapp_number' => 1,
            'payload_data'    => ['list' => [['label' => 'content', 'value' => 'meu_template']]],
            'send_delay'      => 0,
            'batch_limit'     => 0,
        ]);

        $logs        = new ArrayCollection();
        $leadIds     = [];
        $campaignLeads = new ArrayCollection();

        for ($i = 1; $i <= $totalContacts; ++$i) {
            $lead = new Lead();
            $lead->setId($i);
            // Campaign::addLead() espera Mautic\CampaignBundle\Entity\Lead (a relação
            // campaign_leads), não o contato em si. Aqui só precisamos que
            // getLeads()->count() reflita o total de contatos — inserir direto na
            // coleção via reflection evita montar a relação completa (campaign/lead/
            // dateAdded), que não pertence à decisão real do core sendo testada.
            $campaignLeads->set($i, new \stdClass());
            $leadIds[] = $i;

            $log = new LeadEventLog();
            $log->setEvent($event);
            $log->setLead($lead);
            // LeadEventLog::getId() só é preenchido pelo Doctrine após persistir no
            // banco (não há aqui). PendingEvent::extractContacts() usa getId() como
            // chave da coleção de contatos — sem setar isso via reflection, todas as
            // entidades ficariam com id=null e colapsariam numa única chave, perdendo
            // 9 dos 10 contatos do teste. $i garante IDs únicos e consistentes com a
            // chave usada em $logs->set($i, $log) logo abaixo.
            $this->setEntityId($log, $i);
            $logs->set($i, $log);
        }

        $leadsProperty = new \ReflectionProperty(Campaign::class, 'leads');
        $leadsProperty->setAccessible(true);
        $leadsProperty->setValue($campaign, $campaignLeads);

        return ['campaign' => $campaign, 'event' => $event, 'logs' => $logs, 'leadIds' => $leadIds];
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }

    private function buildMessageLog(string $status, ?int $webhookErrorCode = null): MessageLog
    {
        $log = new MessageLog();
        $log->setStatus($status);
        $log->setDateSent(new \DateTime());
        $log->setWebhookErrorCode($webhookErrorCode);

        return $log;
    }

    /**
     * Dispara o dispatchEvent real do ActionDispatcher, religando o plugin
     * (CampaignSubscriber com os logs desejados) + core (CampaignEventSubscriber)
     * já montados no setUp, mas com o subscriber do plugin específico deste teste.
     */
    private function dispatch(CampaignEvent $event, ArrayCollection $logs, CampaignSubscriber $pluginSubscriber): \Mautic\CampaignBundle\Event\PendingEvent
    {
        // Registra o subscriber do plugin específico deste teste. setUp() já deixou
        // o CampaignEventSubscriber real do core registrado; cada teste roda com um
        // EventDispatcher próprio (setUp roda de novo a cada método), então não há
        // subscriber de teste anterior para remover.
        $this->dispatcher->addSubscriber($pluginSubscriber);

        $config = new ActionAccessor([
            'batchEventName' => DialogHSMEvents::ON_CAMPAIGN_TRIGGER_ACTION_QUEUE,
        ]);

        return $this->actionDispatcher->dispatchEvent($config, $event, $logs);
    }

    /**
     * 10 contatos, 1 falha técnica real (10% exato) → o CampaignEventSubscriber
     * REAL do core desativa a campanha (setIsPublished(false) via saveEntity real
     * do fluxo, verificado pela mudança de estado da entidade Campaign real).
     */
    public function testOneRealTechnicalFailureOutOfTenContactsDisablesCampaign(): void
    {
        ['campaign' => $campaign, 'event' => $event, 'logs' => $logs] = $this->buildCampaignWithContacts(10);

        // Lead 1: erro técnico real (sem webhook_error_code) → deve contar como falha.
        $logsByLead = [1 => $this->buildMessageLog(MessageLog::STATUS_FAILED, null)];
        // Leads 2-10: sucesso.
        for ($i = 2; $i <= 10; ++$i) {
            $logsByLead[$i] = $this->buildMessageLog(MessageLog::STATUS_DELIVERED);
        }

        $pluginSubscriber = $this->makePluginSubscriberWithLogs($logsByLead);

        self::assertTrue($campaign->isPublished(), 'Pré-condição: campanha deve começar publicada.');

        $this->dispatch($event, $logs, $pluginSubscriber);

        self::assertFalse(
            $campaign->isPublished(),
            'Com 1 falha técnica real em 10 contatos (10%), o CampaignEventSubscriber real do core deve desativar a campanha.'
        );
    }

    /**
     * 10 contatos, 1 "falha" que é na verdade restrição Meta (131026, mesmo código
     * que adicionamos em META_RESTRICTION_CODES) → o plugin chama passWithError(),
     * o log nunca entra em PendingEvent::getFailures(), o ActionDispatcher REAL
     * nunca dispara ON_EVENT_FAILED, e o CampaignEventSubscriber REAL do core
     * nunca roda onEventFailed() → campanha continua publicada.
     */
    public function testOneMetaRestrictionOutOfTenContactsDoesNotDisableCampaign(): void
    {
        ['campaign' => $campaign, 'event' => $event, 'logs' => $logs] = $this->buildCampaignWithContacts(10);

        // Lead 1: restrição Meta (131026, "Message undeliverable") → NÃO deve contar.
        $logsByLead = [1 => $this->buildMessageLog(MessageLog::STATUS_FAILED, 131026)];
        for ($i = 2; $i <= 10; ++$i) {
            $logsByLead[$i] = $this->buildMessageLog(MessageLog::STATUS_DELIVERED);
        }

        $pluginSubscriber = $this->makePluginSubscriberWithLogs($logsByLead);

        self::assertTrue($campaign->isPublished(), 'Pré-condição: campanha deve começar publicada.');

        $pendingEvent = $this->dispatch($event, $logs, $pluginSubscriber);

        self::assertCount(
            0,
            $pendingEvent->getFailures(),
            'PendingEvent::getFailures() real deve ficar vazio — a restrição Meta (131026) precisa cair em getSuccessful(), nunca em getFailures().'
        );
        self::assertCount(10, $pendingEvent->getSuccessful());

        self::assertTrue(
            $campaign->isPublished(),
            'Restrição Meta (131026) não deve disparar ON_EVENT_FAILED nem desativar a campanha, mesmo estando em 10% de "failed".'
        );
    }

    /**
     * Mistura: de 10 contatos, 1 é falha técnica real (10%) e 3 são restrição Meta
     * (131049/130472/131050) — comprova que só a falha técnica conta para o
     * threshold real do core; as 3 restrições Meta são ignoradas pelo contador,
     * mesmo representando 40% do total (se contassem, desativaria muito antes).
     */
    public function testMixOfTechnicalFailureAndMetaRestrictionsOnlyCountsTechnicalFailure(): void
    {
        ['campaign' => $campaign, 'event' => $event, 'logs' => $logs] = $this->buildCampaignWithContacts(10);

        $logsByLead = [
            1 => $this->buildMessageLog(MessageLog::STATUS_FAILED, null),    // técnico real: conta
            2 => $this->buildMessageLog(MessageLog::STATUS_FAILED, 131049), // restrição Meta: não conta
            3 => $this->buildMessageLog(MessageLog::STATUS_DLQ, 130472),    // restrição Meta: não conta
            4 => $this->buildMessageLog(MessageLog::STATUS_FAILED, 131050), // restrição Meta: não conta
        ];
        for ($i = 5; $i <= 10; ++$i) {
            $logsByLead[$i] = $this->buildMessageLog(MessageLog::STATUS_DELIVERED);
        }

        $pluginSubscriber = $this->makePluginSubscriberWithLogs($logsByLead);

        $this->dispatch($event, $logs, $pluginSubscriber);

        self::assertFalse(
            $campaign->isPublished(),
            '1 falha técnica real em 10 contatos (10%) deve desativar a campanha, mesmo com 3 restrições Meta adicionais que não contam.'
        );
        self::assertSame(
            1,
            $this->failedCounts[spl_object_id($event)] ?? 0,
            'O contador real de falhas do core (EventRepository::incrementFailedCount) deve ter sido incrementado exatamente 1 vez — só pela falha técnica, nunca pelas 3 restrições Meta.'
        );
    }
}

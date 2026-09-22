<?php

declare(strict_types=1);

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\CampaignRepository;
use Mautic\CampaignBundle\Entity\Event as CampaignEvent;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLogRepository;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\CampaignBundle\EventListener\CampaignEventSubscriber;
use Mautic\CampaignBundle\Executioner\Dispatcher\ActionDispatcher;
use Mautic\CampaignBundle\Executioner\Dispatcher\LegacyEventDispatcher;
use Mautic\CampaignBundle\Executioner\Helper\NotificationHelper;
use Mautic\CampaignBundle\Executioner\Scheduler\EventScheduler;
use Mautic\CampaignBundle\Model\CampaignModel;
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
 * passAllWithError() foram chamados — nunca o que o core do Mautic faz de fato com
 * essa chamada.
 *
 * IMPORTANTE — CampaignSubscriber não chama mais fail()/failAll() (ver o próprio
 * arquivo do plugin): todo caminho de falha usa passWithError()/passAllWithError(),
 * que nunca alimenta PendingEvent::getFailures() nem dispara ON_EVENT_FAILED. Ou
 * seja, o comportamento validado aqui NÃO é mais "quantas falhas técnicas atingem o
 * threshold e desativam a campanha" — é a garantia oposta: nenhuma falha do
 * DialogHSM, técnica ou de restrição Meta, jamais desativa a campanha, porque o
 * plugin nunca aciona o caminho fail()/failAll() do core que leva a isso.
 *
 * IMPORTANTE — divergência real de comportamento entre Mautic 5 e 7:
 * o core reescreveu completamente a lógica de auto-disable entre as duas versões.
 *   Mautic 5: threshold fixo de 10%, sem mínimo de contatos, conta toda falha.
 *   Mautic 7: threshold de 35%, mínimo de 100 contatos, e só conta a falha depois
 *             que o MESMO contato falhou LOOPS_TO_FAIL (100) vezes seguidas no
 *             mesmo evento (mecanismo anti-flapping que não existia no Mautic 5).
 * Este teste lê threshold/mínimo via reflection na classe real do core (nunca
 * hardcoded), então continua válido e significativo em ambas as versões — em vez
 * de só "consertar a assinatura do mock e fingir que a mecânica é a mesma".
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
        // simulamos o contador em memória, mas a DECISÃO de desativar (threshold real)
        // é feita pelo CampaignEventSubscriber::onEventFailed() real, não por nós.
        $this->mockEventRepository = $this->createMock(EventRepository::class);
        $this->mockEventRepository->method('incrementFailedCount')
            ->willReturnCallback(function (CampaignEvent $event) {
                $id = spl_object_id($event);
                $this->failedCounts[$id] = ($this->failedCounts[$id] ?? 0) + 1;

                return $this->failedCounts[$id];
            });

        // Mautic 7: getFailedCountLeadEvent() precisa retornar exatamente LOOPS_TO_FAIL
        // pra passar do guard anti-flapping e a falha ser contada (ver onEventFailed()).
        // Mautic 5 não tem esse método no EventRepository real — createMock() nem
        // deixa configurar um método inexistente, por isso o reflection abaixo.
        if (method_exists(EventRepository::class, 'getFailedCountLeadEvent')) {
            $loopsToFail = $this->coreConstant('LOOPS_TO_FAIL', 100);
            $this->mockEventRepository->method('getFailedCountLeadEvent')->willReturn($loopsToFail);
        }

        $this->mockCampaignRepository = $this->createMock(CampaignRepository::class);

        $realCampaignEventSubscriber = $this->buildCoreCampaignEventSubscriber();

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
     * Lê uma constante da classe REAL do core, sem hardcode — funciona em
     * qualquer versão do Mautic que a declare (ex.: LOOPS_TO_FAIL,
     * MINIMUM_CONTACTS_FOR_DISABLE, DISABLE_CAMPAIGN_THRESHOLD). Se a versão
     * instalada não tiver essa constante (Mautic 5, que usa uma property em
     * vez de const), cai no $default.
     */
    private function coreConstant(string $name, int|float $default): int|float
    {
        $ref = new \ReflectionClass(CampaignEventSubscriber::class);

        return $ref->hasConstant($name) ? $ref->getConstant($name) : $default;
    }

    /**
     * Threshold real de falha que desativa a campanha, na versão do core
     * instalada. Mautic 7 expõe DISABLE_CAMPAIGN_THRESHOLD como constante;
     * Mautic 5 usa a property privada $disableCampaignThreshold (default 0.1),
     * lida via valor default da property (não precisa de instância).
     */
    private function getDisableThreshold(): float
    {
        $ref = new \ReflectionClass(CampaignEventSubscriber::class);

        if ($ref->hasConstant('DISABLE_CAMPAIGN_THRESHOLD')) {
            return (float) $ref->getConstant('DISABLE_CAMPAIGN_THRESHOLD');
        }

        return (float) $ref->getProperty('disableCampaignThreshold')->getDefaultValue();
    }

    /**
     * Quantidade mínima de contatos exigida pelo core antes de considerar
     * desativar a campanha. Mautic 5 não tem esse conceito (retorna 1).
     */
    private function getMinimumContactsForDisable(): int
    {
        return (int) $this->coreConstant('MINIMUM_CONTACTS_FOR_DISABLE', 1);
    }

    /**
     * Monta o CampaignEventSubscriber REAL do core com os mocks certos pra
     * cada assinatura de construtor — Mautic 5 (EventRepository,
     * NotificationHelper, CampaignRepository) ou Mautic 7+ (EventRepository,
     * CampaignModel, LeadEventLogRepository, EventDispatcherInterface).
     * Detecta pela contagem de parâmetros do construtor real, nunca hardcoded
     * por versão — se o core mudar de novo, este teste avisa via TypeError
     * em vez de mascarar silenciosamente.
     */
    private function buildCoreCampaignEventSubscriber(): CampaignEventSubscriber
    {
        $params = (new \ReflectionClass(CampaignEventSubscriber::class))->getConstructor()->getParameters();

        if (3 === count($params)) {
            return new CampaignEventSubscriber(
                $this->mockEventRepository,
                $this->createMock(NotificationHelper::class),
                $this->mockCampaignRepository,
            );
        }

        if (4 === count($params)) {
            $mockCampaignModel = $this->createMock(CampaignModel::class);
            // transactionalCampaignUnPublish() é o que de fato desativa a campanha
            // no Mautic 7 — como CampaignModel está mockado, replicamos aqui o
            // único efeito que o teste observa (campaign->isPublished() === false).
            $mockCampaignModel->method('transactionalCampaignUnPublish')
                ->willReturnCallback(static function (Campaign $campaign): void {
                    $campaign->setIsPublished(false);
                });

            $mockLeadEventLogRepository = $this->createMock(LeadEventLogRepository::class);
            $mockLeadEventLogRepository->method('isLastFailed')->willReturn(true);

            return new CampaignEventSubscriber(
                $this->mockEventRepository,
                $mockCampaignModel,
                $mockLeadEventLogRepository,
                new EventDispatcher(), // dispatcher próprio só p/ hasListeners()/dispatch() internos, sem listeners
            );
        }

        throw new \RuntimeException(sprintf(
            'CampaignEventSubscriber::__construct() tem %d parâmetros — assinatura desconhecida, este teste precisa ser atualizado para a versão do Mautic instalada.',
            count($params)
        ));
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
        // Mautic 7: onEventFailed() chama $failedEvent->getId() logo no início
        // (getFailedCountLeadEvent) — sem id setado (só ocorre via Doctrine em
        // produção), o TypeError acontece antes de qualquer lógica de negócio ser
        // exercida. Mautic 5 não precisa disso, mas setar não tem efeito colateral.
        $this->setEntityId($event, 999);
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
     * N contatos com falhas técnicas reais, em quantidade suficiente para atingir
     * (e superar) o threshold REAL do core instalado (10% no Mautic 5, 35% +
     * mínimo de 100 contatos no Mautic 7) — SE o plugin ainda chamasse fail(), isso
     * desativaria a campanha. Desde que CampaignSubscriber passou a usar
     * passWithError() em todo caminho de falha (nunca mais fail()/failAll()), o log
     * cai em PendingEvent::getSuccessful(), ON_EVENT_FAILED nunca é disparado, e a
     * campanha permanece publicada mesmo com 100% de falhas técnicas.
     */
    public function testTechnicalFailuresAtOrAboveThresholdNeverDisableCampaign(): void
    {
        $totalContacts     = max($this->getMinimumContactsForDisable(), 20);
        $technicalFailures = max(1, (int) ceil($this->getDisableThreshold() * $totalContacts));

        ['campaign' => $campaign, 'event' => $event, 'logs' => $logs] = $this->buildCampaignWithContacts($totalContacts);

        $logsByLead = [];
        for ($i = 1; $i <= $technicalFailures; ++$i) {
            $logsByLead[$i] = $this->buildMessageLog(MessageLog::STATUS_FAILED, null);
        }
        for ($i = $technicalFailures + 1; $i <= $totalContacts; ++$i) {
            $logsByLead[$i] = $this->buildMessageLog(MessageLog::STATUS_DELIVERED);
        }

        $pluginSubscriber = $this->makePluginSubscriberWithLogs($logsByLead);

        self::assertTrue($campaign->isPublished(), 'Pré-condição: campanha deve começar publicada.');

        $pendingEvent = $this->dispatch($event, $logs, $pluginSubscriber);

        self::assertCount(
            0,
            $pendingEvent->getFailures(),
            'PendingEvent::getFailures() real deve ficar vazio — desde que fail() foi removido do CampaignSubscriber, falhas técnicas caem em getSuccessful() via passWithError().'
        );

        self::assertTrue(
            $campaign->isPublished(),
            sprintf(
                'Com %d falhas técnicas reais em %d contatos (threshold real do core: %.0f%%), a campanha deve permanecer publicada — o plugin não chama mais fail()/failAll(), então o CampaignEventSubscriber do core nunca roda onEventFailed().',
                $technicalFailures,
                $totalContacts,
                $this->getDisableThreshold() * 100
            )
        );
        self::assertSame(
            0,
            $this->failedCounts[spl_object_id($event)] ?? 0,
            'EventRepository::incrementFailedCount() nunca deve ser chamado — ON_EVENT_FAILED só é disparado por PendingEvent::fail()/failAll(), que o plugin não usa mais.'
        );
    }

    /**
     * 10 contatos, 1 "falha" que é na verdade restrição Meta (131026, mesmo código
     * que adicionamos em META_RESTRICTION_CODES) → o plugin chama passWithError(),
     * o log nunca entra em PendingEvent::getFailures(), o ActionDispatcher REAL
     * nunca dispara ON_EVENT_FAILED, e o CampaignEventSubscriber REAL do core
     * nunca roda onEventFailed() → campanha continua publicada. Esta é uma instância
     * específica da garantia geral (ver testTechnicalFailuresAtOrAboveThresholdNeverDisableCampaign):
     * restrição Meta é só mais um dos caminhos de falha do plugin, todos via passWithError().
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
     * Mistura: falhas técnicas reais em quantidade suficiente para, no comportamento
     * antigo (fail()), atingir o threshold real do core + 3 contatos extras com
     * restrição Meta (131049/130472/131050). Comprova a garantia geral: nem as
     * falhas técnicas nem as restrições Meta desativam a campanha ou incrementam o
     * contador de falhas do core — nenhum dos dois caminhos usa mais fail()/failAll().
     */
    public function testMixOfTechnicalFailureAndMetaRestrictionsNeverDisablesCampaign(): void
    {
        $baseContacts      = max($this->getMinimumContactsForDisable(), 20);
        $totalContacts     = $baseContacts + 3; // +3 leads de restrição Meta, somados ao denominador
        $technicalFailures = max(1, (int) ceil($this->getDisableThreshold() * $totalContacts));

        ['campaign' => $campaign, 'event' => $event, 'logs' => $logs] = $this->buildCampaignWithContacts($totalContacts);

        $logsByLead = [
            $totalContacts - 2 => $this->buildMessageLog(MessageLog::STATUS_FAILED, 131049), // restrição Meta: não conta
            $totalContacts - 1 => $this->buildMessageLog(MessageLog::STATUS_DLQ, 130472),     // restrição Meta: não conta
            $totalContacts     => $this->buildMessageLog(MessageLog::STATUS_FAILED, 131050),  // restrição Meta: não conta
        ];
        for ($i = 1; $i <= $technicalFailures; ++$i) {
            $logsByLead[$i] = $this->buildMessageLog(MessageLog::STATUS_FAILED, null); // técnico real: também não conta mais
        }
        for ($i = $technicalFailures + 1; $i <= $totalContacts - 3; ++$i) {
            $logsByLead[$i] = $this->buildMessageLog(MessageLog::STATUS_DELIVERED);
        }

        $pluginSubscriber = $this->makePluginSubscriberWithLogs($logsByLead);

        self::assertTrue($campaign->isPublished(), 'Pré-condição: campanha deve começar publicada.');

        $this->dispatch($event, $logs, $pluginSubscriber);

        self::assertTrue(
            $campaign->isPublished(),
            sprintf(
                '%d falhas técnicas reais + 3 restrições Meta em %d contatos (threshold real: %.0f%%) NÃO devem desativar a campanha — o plugin não chama mais fail()/failAll() em nenhum dos dois caminhos.',
                $technicalFailures,
                $totalContacts,
                $this->getDisableThreshold() * 100
            )
        );
        self::assertSame(
            0,
            $this->failedCounts[spl_object_id($event)] ?? 0,
            'O contador real de falhas do core (EventRepository::incrementFailedCount) nunca deve ser incrementado — nem pelas falhas técnicas, nem pelas restrições Meta, já que nenhuma delas passa mais por fail()/failAll().'
        );
    }
}

<?php

declare(strict_types=1);

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessageRepository;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppMessageModel;
use MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter;
use Mautic\ChannelBundle\Event\ChannelBroadcastEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Testa a geração de 'vars' em resolveTokens() do WhatsAppMessageModel.
 *
 * Contexto: campanhas preenchem 'vars' via form; Marketing Messages não têm
 * esse campo, então resolveTokens() precisa gerar 'vars' automaticamente a
 * partir dos labels, excluindo chaves de controle da API (url_arquivo, buttons, etc.).
 */
class WhatsAppMessageModelResolveTokensTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeModel(
        LeadModel $leadModel,
        MessageBusInterface $bus,
        EntityManagerInterface $em,
    ): WhatsAppMessageModel {
        $ref   = new \ReflectionClass(WhatsAppMessageModel::class);
        $model = $ref->newInstanceWithoutConstructor();

        $emProp = new \ReflectionProperty(\Mautic\CoreBundle\Model\AbstractCommonModel::class, 'em');
        $emProp->setAccessible(true);
        $emProp->setValue($model, $em);

        $model->setLeadModel($leadModel);
        $model->setBus($bus);

        $rateLimiter = $this->createMock(BulkRateLimiter::class);
        $rateLimiter->method('getBulkSendDelay')->willReturn(0.0);
        $model->setRateLimiter($rateLimiter);

        return $model;
    }

    private function makeEmWithRepo(array $contacts = []): array
    {
        $mockQuery = $this->createMock(AbstractQuery::class);
        $mockQuery->method('execute')->willReturn(null);

        $mockQb = $this->createMock(QueryBuilder::class);
        $mockQb->method('update')->willReturnSelf();
        $mockQb->method('set')->willReturnSelf();
        $mockQb->method('where')->willReturnSelf();
        $mockQb->method('setParameter')->willReturnSelf();
        $mockQb->method('getQuery')->willReturn($mockQuery);

        $mockRepo = $this->createMock(WhatsAppMessageRepository::class);
        $mockRepo->method('getPendingContacts')
            ->willReturnOnConsecutiveCalls($contacts, []);

        $mockEm = $this->createMock(EntityManagerInterface::class);
        $mockEm->method('createQueryBuilder')->willReturn($mockQb);
        $mockEm->method('getRepository')
            ->with(WhatsAppMessage::class)
            ->willReturn($mockRepo);
        $mockEm->method('persist');
        $mockEm->method('flush');
        $mockEm->method('clear');

        return [$mockEm, $mockRepo];
    }

    private function makeNumber(): WhatsAppNumber
    {
        $number = $this->createMock(WhatsAppNumber::class);
        $number->method('getApiKey')->willReturn('test-api-key');
        $number->method('getBaseUrl')->willReturn('https://waba.360dialog.io');
        $number->method('getName')->willReturn('Test Number');

        return $number;
    }

    private function makeMessage(array $payloadData): WhatsAppMessage
    {
        $message = $this->createMock(WhatsAppMessage::class);
        $message->method('getId')->willReturn(42);
        $message->method('getWhatsAppNumber')->willReturn($this->makeNumber());
        $message->method('getTemplateName')->willReturn('regua_teste');
        $message->method('getPayloadData')->willReturn($payloadData);

        return $message;
    }

    private function makeLead(array $profileFields = []): Lead
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('getProfileFields')->willReturn($profileFields);

        return $lead;
    }

    private function captureDispatchedPayload(array $payloadData, array $profileFields = []): array
    {
        [$em] = $this->makeEmWithRepo([['id' => 1, 'phone' => '5511999999999']]);

        $lead = $this->makeLead($profileFields);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturn($lead);

        $captured = null;
        $bus      = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $msg) use (&$captured): Envelope {
            $captured = $msg;

            return new Envelope($msg);
        });

        $model = $this->makeModel($leadModel, $bus, $em);
        $model->sendToLists($this->makeMessage($payloadData), $this->createMock(ChannelBroadcastEvent::class));

        $this->assertInstanceOf(SendWhatsAppDirectBatchMessage::class, $captured);

        return $captured->items[0]->payloadData;
    }

    // -------------------------------------------------------------------------
    // vars: geração automática a partir dos labels
    // -------------------------------------------------------------------------

    public function testVarsBuiltFromLabelsInOriginalOrder(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'nome',   'value' => 'João'],
                ['label' => 'codigo', 'value' => '12345'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertArrayHasKey('vars', $result);
        $this->assertSame('nome,codigo', $result['vars']);
    }

    public function testVarsSingleLabelProducesCorrectCsv(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'body', 'value' => 'Olá'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertSame('body', $result['vars']);
    }

    public function testVarsEmptyWhenNoLabels(): void
    {
        $payloadData = ['list' => []];

        [$em] = $this->makeEmWithRepo([['id' => 1, 'phone' => '5511999999999']]);
        $lead = $this->makeLead();

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturn($lead);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $model  = $this->makeModel($leadModel, $bus, $em);
        $result = $model->sendToLists($this->makeMessage($payloadData), $this->createMock(ChannelBroadcastEvent::class));

        // Sem labels, dispatch não é chamado (nenhum item no batch) — apenas verificamos que não lança
        $this->assertSame([1, 0], $result);
    }

    // -------------------------------------------------------------------------
    // vars: chaves de controle excluídas
    // -------------------------------------------------------------------------

    public function testVarsExcludesUrlArquivo(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'nome',        'value' => 'João'],
                ['label' => 'url_arquivo', 'value' => 'https://cdn.example.com/doc.pdf'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertSame('nome', $result['vars'], 'url_arquivo não deve entrar em vars');
        $this->assertArrayHasKey('url_arquivo', $result, 'url_arquivo deve permanecer no payload');
    }

    public function testVarsExcludesButtons(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'nome',    'value' => 'João'],
                ['label' => 'buttons', 'value' => 'url,quick_reply'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertSame('nome', $result['vars']);
        $this->assertArrayHasKey('buttons', $result);
    }

    public function testVarsExcludesButtonsVars(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'nome',         'value' => 'João'],
                ['label' => 'buttons_vars', 'value' => 'https://link.com,Sim'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertSame('nome', $result['vars']);
        $this->assertArrayHasKey('buttons_vars', $result);
    }

    public function testVarsExcludesLanguage(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'nome',     'value' => 'João'],
                ['label' => 'language', 'value' => 'en_US'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertSame('nome', $result['vars']);
        $this->assertSame('en_US', $result['language']);
    }

    public function testVarsExcludesContent(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'content', 'value' => 'regua_teste'],
                ['label' => 'nome',    'value' => 'João'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertStringNotContainsString('content', $result['vars']);
        $this->assertSame('nome', $result['vars']);
    }

    public function testVarsExcludesLimitedTimeOffer(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'nome',               'value' => 'João'],
                ['label' => 'limited_time_offer', 'value' => '2026-12-31 23:59:59'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertSame('nome', $result['vars']);
        $this->assertArrayHasKey('limited_time_offer', $result);
    }

    public function testVarsExcludesAllControlKeysAtOnce(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'nome',               'value' => 'João'],
                ['label' => 'url_arquivo',         'value' => 'https://cdn.example.com/img.jpg'],
                ['label' => 'buttons',             'value' => 'url'],
                ['label' => 'buttons_vars',        'value' => 'https://link.com'],
                ['label' => 'language',            'value' => 'en_US'],
                ['label' => 'limited_time_offer',  'value' => '2026-12-31 23:59:59'],
                ['label' => 'content',             'value' => 'regua_teste'],
                ['label' => 'codigo',              'value' => '99'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertSame('nome,codigo', $result['vars'], 'Apenas labels não-controle devem entrar em vars');
    }

    // -------------------------------------------------------------------------
    // vars: preserva valor existente no payload
    // -------------------------------------------------------------------------

    public function testVarsNotOverwrittenWhenAlreadyPresent(): void
    {
        // Usuário explicitamente definiu 'vars' como label — deve ser respeitado
        $payloadData = [
            'list' => [
                ['label' => 'vars', 'value' => 'nome'],
                ['label' => 'nome', 'value' => 'João'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertSame('nome', $result['vars'], 'vars explícito no payload não deve ser sobrescrito');
    }

    // -------------------------------------------------------------------------
    // Chaves de controle preservadas no payload para buildPayload()
    // -------------------------------------------------------------------------

    public function testControlKeysPreservedInPayloadForBuildPayload(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'nome',        'value' => 'João'],
                ['label' => 'url_arquivo', 'value' => 'https://cdn.example.com/doc.pdf'],
                ['label' => 'buttons',     'value' => 'url'],
                ['label' => 'buttons_vars','value' => 'https://link.com'],
                ['label' => 'language',    'value' => 'en_US'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertArrayHasKey('url_arquivo',  $result);
        $this->assertArrayHasKey('buttons',      $result);
        $this->assertArrayHasKey('buttons_vars', $result);
        $this->assertArrayHasKey('language',     $result);
        $this->assertSame('https://cdn.example.com/doc.pdf', $result['url_arquivo']);
        $this->assertSame('url',                             $result['buttons']);
        $this->assertSame('en_US',                           $result['language']);
    }

    // -------------------------------------------------------------------------
    // Resolução de tokens por contato
    // -------------------------------------------------------------------------

    public function testVarsBuiltAfterTokenResolution(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'nome',   'value' => '{contactfield=firstname}'],
                ['label' => 'cidade', 'value' => '{contactfield=city}'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData, ['firstname' => 'Maria', 'city' => 'Curitiba']);

        $this->assertSame('nome,cidade', $result['vars']);
        $this->assertSame('Maria',    $result['nome']);
        $this->assertSame('Curitiba', $result['cidade']);
    }
}

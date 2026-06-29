<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Mautic\ChannelBundle\Event\MessageQueueBatchProcessEvent;
use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\EventListener\MarketingMessageSubscriber;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Model\WhatsAppMessageModel;
use MauticPlugin\DialogHSMBundle\Service\LeadEventLogWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Testa MarketingMessageSubscriber.onProcessMessageQueueBatch():
 * foco na geração correta de 'vars' e tratamento de chaves de controle no payload.
 */
class MarketingMessageSubscriberTest extends TestCase
{
    private WhatsAppMessageModel&MockObject $mockModel;
    private MessageBusInterface&MockObject  $mockBus;
    private EntityManagerInterface&MockObject $mockEm;
    private LoggerInterface&MockObject $mockLogger;
    private LeadEventLogWriter&MockObject $mockEventLogWriter;

    protected function setUp(): void
    {
        $this->mockModel          = $this->createMock(WhatsAppMessageModel::class);
        $this->mockBus            = $this->createMock(MessageBusInterface::class);
        $this->mockEm             = $this->createMock(EntityManagerInterface::class);
        $this->mockLogger         = $this->createMock(LoggerInterface::class);
        $this->mockEventLogWriter = $this->createMock(LeadEventLogWriter::class);
    }

    private function makeSubscriber(): MarketingMessageSubscriber
    {
        return new MarketingMessageSubscriber(
            $this->mockModel,
            $this->mockBus,
            $this->mockEm,
            $this->mockLogger,
            $this->mockEventLogWriter,
        );
    }

    private function makeNumber(string $apiKey = 'api-key-123', string $queueName = ''): WhatsAppNumber&MockObject
    {
        $number = $this->createMock(WhatsAppNumber::class);
        $number->method('getApiKey')->willReturn($apiKey);
        $number->method('getBaseUrl')->willReturn('https://waba.360dialog.io');
        $number->method('getName')->willReturn('Test Number');
        $number->method('getQueueName')->willReturn($queueName ?: null);
        $number->method('getBatchQueueName')->willReturn(null);

        return $number;
    }

    private function makeWhatsAppMessage(array $payloadData, string $templateName = 'regua_teste'): WhatsAppMessage&MockObject
    {
        $message = $this->createMock(WhatsAppMessage::class);
        $message->method('getId')->willReturn(1);
        $message->method('isPublished')->willReturn(true);
        $message->method('getWhatsAppNumber')->willReturn($this->makeNumber());
        $message->method('getTemplateName')->willReturn($templateName);
        $message->method('getPayloadData')->willReturn($payloadData);

        return $message;
    }

    private function makeLead(string $phone = '+5511999999999', array $profileFields = []): Lead&MockObject
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn(1);
        $lead->method('getLeadPhoneNumber')->willReturn($phone);
        $lead->method('getProfileFields')->willReturn($profileFields);

        return $lead;
    }

    private function makeQueuedMessage(Lead $lead): MessageQueue&MockObject
    {
        $qm = $this->createMock(MessageQueue::class);
        $qm->method('getLead')->willReturn($lead);

        return $qm;
    }

    private function makeBatchEvent(int $messageId, array $queuedMessages): MessageQueueBatchProcessEvent
    {
        return new MessageQueueBatchProcessEvent($queuedMessages, 'whatsapp', $messageId);
    }

    private function captureDispatchedPayload(array $payloadData, array $profileFields = []): array
    {
        $this->mockModel->method('getEntity')->willReturn($this->makeWhatsAppMessage($payloadData));

        $lead    = $this->makeLead('+5511999999999', $profileFields);
        $qm      = $this->makeQueuedMessage($lead);
        $qm->method('setProcessed');
        $qm->method('setSuccess');

        $this->mockEm->method('persist');
        $this->mockEm->method('flush');
        $this->mockEm->method('clear');

        $captured = null;
        $this->mockBus->method('dispatch')
            ->willReturnCallback(function (SendWhatsAppMessage $msg) use (&$captured): Envelope {
                $captured = $msg;

                return new Envelope($msg);
            });

        $event = $this->makeBatchEvent(1, [$qm]);
        $this->makeSubscriber()->onProcessMessageQueueBatch($event);

        $this->assertNotNull($captured, 'Bus deve ter sido chamado');

        return $captured->payloadData;
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

    public function testVarsSingleLabel(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'body', 'value' => 'Texto do template'],
            ],
        ];

        $result = $this->captureDispatchedPayload($payloadData);

        $this->assertSame('body', $result['vars']);
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

        $this->assertSame('nome', $result['vars']);
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

        $this->assertSame('nome,codigo', $result['vars']);
    }

    // -------------------------------------------------------------------------
    // vars: preserva valor existente
    // -------------------------------------------------------------------------

    public function testVarsNotOverwrittenWhenAlreadyPresent(): void
    {
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
    // Chaves de controle preservadas no payload
    // -------------------------------------------------------------------------

    public function testControlKeysPreservedInPayloadForBuildPayload(): void
    {
        $payloadData = [
            'list' => [
                ['label' => 'nome',         'value' => 'João'],
                ['label' => 'url_arquivo',  'value' => 'https://cdn.example.com/doc.pdf'],
                ['label' => 'buttons',      'value' => 'url'],
                ['label' => 'buttons_vars', 'value' => 'https://link.com'],
                ['label' => 'language',     'value' => 'en_US'],
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

    // -------------------------------------------------------------------------
    // Guard cases: contexto errado, mensagem não publicada, sem número
    // -------------------------------------------------------------------------

    public function testDoesNothingWhenContextIsNotWhatsapp(): void
    {
        $event = new MessageQueueBatchProcessEvent([], 'email', 1);

        $this->mockModel->expects($this->never())->method('getEntity');
        $this->mockBus->expects($this->never())->method('dispatch');

        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }

    public function testDoesNothingWhenMessageEntityNotFound(): void
    {
        $this->mockModel->method('getEntity')->willReturn(null);

        $event = $this->makeBatchEvent(99, []);

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }

    public function testDoesNothingWhenMessageNotPublished(): void
    {
        $message = $this->createMock(WhatsAppMessage::class);
        $message->method('isPublished')->willReturn(false);

        $this->mockModel->method('getEntity')->willReturn($message);

        $event = $this->makeBatchEvent(1, []);

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }

    public function testDoesNothingWhenWhatsAppNumberIsNull(): void
    {
        $message = $this->createMock(WhatsAppMessage::class);
        $message->method('isPublished')->willReturn(true);
        $message->method('getWhatsAppNumber')->willReturn(null);

        $this->mockModel->method('getEntity')->willReturn($message);

        $event = $this->makeBatchEvent(1, []);

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }

    public function testDoesNothingWhenApiKeyIsEmpty(): void
    {
        $number = $this->makeNumber(apiKey: '');
        $message = $this->createMock(WhatsAppMessage::class);
        $message->method('isPublished')->willReturn(true);
        $message->method('getWhatsAppNumber')->willReturn($number);

        $this->mockModel->method('getEntity')->willReturn($message);

        $event = $this->makeBatchEvent(1, []);

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }

    public function testQueuedMessageWithNoLeadIsSetFailed(): void
    {
        $this->mockModel->method('getEntity')->willReturn($this->makeWhatsAppMessage([]));

        $qm = $this->createMock(MessageQueue::class);
        $qm->method('getLead')->willReturn(null);
        $qm->expects($this->once())->method('setFailed');

        $this->mockEm->method('persist');
        $this->mockEm->method('flush');
        $this->mockEm->method('clear');

        $event = $this->makeBatchEvent(1, [$qm]);
        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }

    public function testQueuedMessageWithEmptyPhoneIsSetFailed(): void
    {
        $this->mockModel->method('getEntity')->willReturn($this->makeWhatsAppMessage([]));

        $lead = $this->makeLead(phone: '');
        $qm   = $this->makeQueuedMessage($lead);
        $qm->expects($this->once())->method('setFailed');
        $qm->expects($this->once())->method('setProcessed');

        $this->mockEm->method('persist');
        $this->mockEm->method('flush');
        $this->mockEm->method('clear');

        $event = $this->makeBatchEvent(1, [$qm]);

        $this->mockBus->expects($this->never())->method('dispatch');

        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }

    public function testDispatchFailureSetsQueuedMessageFailed(): void
    {
        $this->mockModel->method('getEntity')->willReturn($this->makeWhatsAppMessage([]));

        $lead = $this->makeLead();
        $qm   = $this->makeQueuedMessage($lead);
        $qm->expects($this->once())->method('setFailed');
        $qm->expects($this->once())->method('setProcessed');

        $this->mockEm->method('persist');
        $this->mockEm->method('flush');
        $this->mockEm->method('clear');

        $this->mockBus->method('dispatch')->willThrowException(new \RuntimeException('bus failure'));
        $this->mockLogger->expects($this->once())->method('error');

        $event = $this->makeBatchEvent(1, [$qm]);
        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }

    public function testSuccessfulDispatchSetsQueuedMessageProcessedAndSuccess(): void
    {
        $this->mockModel->method('getEntity')->willReturn($this->makeWhatsAppMessage([]));

        $lead = $this->makeLead();
        $qm   = $this->makeQueuedMessage($lead);
        $qm->expects($this->once())->method('setProcessed');
        $qm->expects($this->once())->method('setSuccess');

        $this->mockEm->method('persist');
        $this->mockEm->method('flush');
        $this->mockEm->method('clear');

        $this->mockBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $event = $this->makeBatchEvent(1, [$qm]);
        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }

    // -------------------------------------------------------------------------
    // EventLogWriter: chamada de write() com ACTION_DISPATCHED após dispatch bem-sucedido
    // -------------------------------------------------------------------------

    public function testWritesDispatchedEventOnSuccessfulDispatch(): void
    {
        $this->mockModel->method('getEntity')->willReturn($this->makeWhatsAppMessage([]));

        $lead = $this->makeLead();
        $qm   = $this->makeQueuedMessage($lead);
        $qm->method('setProcessed');
        $qm->method('setSuccess');

        $this->mockEm->method('persist');
        $this->mockEm->method('flush');
        $this->mockEm->method('clear');

        $this->mockBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->mockEventLogWriter->expects($this->once())
            ->method('write')
            ->with(
                $this->anything(),
                LeadEventLogWriter::ACTION_DISPATCHED,
                $this->isInstanceOf(\DateTime::class)
            );

        $event = $this->makeBatchEvent(1, [$qm]);
        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }

    public function testDoesNotWriteDispatchedEventOnDispatchFailure(): void
    {
        $this->mockModel->method('getEntity')->willReturn($this->makeWhatsAppMessage([]));

        $lead = $this->makeLead();
        $qm   = $this->makeQueuedMessage($lead);
        $qm->method('setProcessed');
        $qm->method('setFailed');

        $this->mockEm->method('persist');
        $this->mockEm->method('flush');
        $this->mockEm->method('clear');

        $this->mockBus->method('dispatch')->willThrowException(new \RuntimeException('bus failure'));
        $this->mockLogger->method('error');

        $this->mockEventLogWriter->expects($this->never())->method('write');

        $event = $this->makeBatchEvent(1, [$qm]);
        $this->makeSubscriber()->onProcessMessageQueueBatch($event);
    }
}

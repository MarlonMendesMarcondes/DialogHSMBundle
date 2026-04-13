<?php
declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Exception\TransientApiException;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendWhatsAppMessageHandlerTest extends TestCase
{
    private DialogHSMApi&MockObject $mockApi;
    private EntityManagerInterface&MockObject $mockEntityManager;
    private LoggerInterface&MockObject $mockLogger;
    private LeadModel&MockObject $mockLeadModel;
    private Lead&MockObject $mockLead;
    private MessageLogRepository&MockObject $mockMessageLogRepository;
    private BulkRateLimiter&MockObject $mockRateLimiter;
    private IntegrationsHelper&MockObject $mockIntegrationsHelper;
    private SendWhatsAppMessageHandler $handler;

    protected function setUp(): void
    {
        $this->mockApi                  = $this->createMock(DialogHSMApi::class);
        $this->mockEntityManager        = $this->createMock(EntityManagerInterface::class);
        $this->mockLogger               = $this->createMock(LoggerInterface::class);
        $this->mockLeadModel            = $this->createMock(LeadModel::class);
        $this->mockLead                 = $this->createMock(Lead::class);
        $this->mockMessageLogRepository = $this->createMock(MessageLogRepository::class);
        $this->mockRateLimiter          = $this->createMock(BulkRateLimiter::class);
        $this->mockIntegrationsHelper   = $this->createMock(IntegrationsHelper::class);

        // Default: getIntegration throws (fail-open → DEFAULT_MAX_RECORDS=10000)
        $this->mockIntegrationsHelper->method('getIntegration')->willThrowException(new \RuntimeException('not configured'));

        $this->handler = new SendWhatsAppMessageHandler(
            $this->mockApi,
            $this->mockEntityManager,
            $this->mockLogger,
            $this->mockLeadModel,
            $this->mockMessageLogRepository,
            $this->mockRateLimiter,
            $this->mockIntegrationsHelper,
        );
    }

    private function makeMessage(int $leadId = 1): SendWhatsAppMessage
    {
        return new SendWhatsAppMessage(
            leadId:       $leadId,
            phone:        '11999999999',
            apiKey:       'API_KEY',
            baseUrl:      'https://api.360dialog.com/v1/messages',
            payloadData:  ['content' => 'nome_template', 'language' => 'pt_BR'],
            templateName: 'nome_template',
        );
    }

    // -------------------------------------------------------------------------
    // Testes: fluxo principal (log + atualização de campos)
    // -------------------------------------------------------------------------

    public function testHandleSuccess(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => ['id' => 'abc'], 'error' => null, 'http_status' => 200, 'retryable' => false]);

        $this->mockEntityManager->expects($this->once())->method('persist');
        $this->mockEntityManager->expects($this->once())->method('flush');
        $this->mockLeadModel->expects($this->once())->method('setFieldValues');
        $this->mockLeadModel->expects($this->once())->method('saveEntity');
        $this->mockMessageLogRepository->expects($this->once())->method('prune');

        ($this->handler)($this->makeMessage());
    }

    public function testHandleHttpError(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => 'HTTP 400: Bad Request', 'http_status' => 400, 'retryable' => false]);

        $this->mockEntityManager->expects($this->once())->method('persist');
        $this->mockEntityManager->expects($this->once())->method('flush');
        $this->mockLeadModel->expects($this->once())->method('setFieldValues');
        $this->mockLeadModel->expects($this->once())->method('saveEntity');
        $this->mockMessageLogRepository->expects($this->once())->method('prune');

        ($this->handler)($this->makeMessage());
    }

    public function testHandleRequestExceptionTransientThrows(): void
    {
        $this->mockApi
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => 'Network error', 'http_status' => null, 'retryable' => true]);

        // Erro transitório: não deve persistir log nem atualizar contato — o Messenger vai fazer retry
        $this->mockEntityManager->expects($this->never())->method('persist');
        $this->mockLeadModel->expects($this->never())->method('setFieldValues');

        $this->expectException(TransientApiException::class);

        ($this->handler)($this->makeMessage());
    }

    // -------------------------------------------------------------------------
    // Testes: valores enviados ao LeadModel
    // -------------------------------------------------------------------------

    public function testSuccessUpdatesContactFieldWithSentStatus(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => ['id' => 'abc'], 'error' => null, 'http_status' => 200, 'retryable' => false]);

        $capturedFields = null;
        $this->mockLeadModel
            ->method('setFieldValues')
            ->willReturnCallback(function (Lead $lead, array $fields) use (&$capturedFields): void {
                $capturedFields = $fields;
            });

        ($this->handler)($this->makeMessage(leadId: 42));

        $this->assertEquals('sent (HTTP 200)', $capturedFields['dialoghsm_status']);
        $this->assertEquals('OK', $capturedFields['dialoghsm_last_response']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $capturedFields['dialoghsm_last_sent'],
            'dialoghsm_last_sent deve estar no formato Y-m-d H:i:s'
        );
    }

    public function testFailureUpdatesContactFieldWithFailedStatus(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => 'HTTP 400: Bad Request', 'http_status' => 400, 'retryable' => false]);

        $capturedFields = null;
        $this->mockLeadModel
            ->method('setFieldValues')
            ->willReturnCallback(function (Lead $lead, array $fields) use (&$capturedFields): void {
                $capturedFields = $fields;
            });

        ($this->handler)($this->makeMessage(leadId: 7));

        $this->assertEquals('failed (HTTP 400)', $capturedFields['dialoghsm_status']);
        $this->assertEquals('HTTP 400: Bad Request', $capturedFields['dialoghsm_last_response']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $capturedFields['dialoghsm_last_sent'],
            'dialoghsm_last_sent deve estar no formato Y-m-d H:i:s'
        );
    }

    public function testNullHttpStatusFallsBackToNA(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => null, 'retryable' => false]);

        $capturedFields = null;
        $this->mockLeadModel
            ->method('setFieldValues')
            ->willReturnCallback(function (Lead $lead, array $fields) use (&$capturedFields): void {
                $capturedFields = $fields;
            });

        ($this->handler)($this->makeMessage());

        $this->assertEquals('sent (HTTP N/A)', $capturedFields['dialoghsm_status']);
    }

    public function testSetFieldValuesReceivesLeadReturnedByGetEntity(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200, 'retryable' => false]);

        $capturedLead = null;
        $this->mockLeadModel
            ->method('setFieldValues')
            ->willReturnCallback(function (Lead $lead, array $fields) use (&$capturedLead): void {
                $capturedLead = $lead;
            });

        ($this->handler)($this->makeMessage());

        $this->assertSame($this->mockLead, $capturedLead);
    }

    public function testLeadModelExceptionIsHandledGracefully(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200, 'retryable' => false]);

        $this->mockLeadModel
            ->method('setFieldValues')
            ->willThrowException(new \RuntimeException('LeadModel failure'));

        $this->mockLogger
            ->expects($this->atLeastOnce())
            ->method('warning');

        // Não deve lançar exceção
        ($this->handler)($this->makeMessage());
    }

    public function testLongErrorMessageIsTruncatedTo255CharsInContactField(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $longError = str_repeat('x', 300);

        // Usa HTTP 400 (permanente) para que o erro chegue ao updateContactFields sem lançar exceção
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => $longError, 'http_status' => 400, 'retryable' => false]);

        $capturedFields = null;
        $this->mockLeadModel
            ->method('setFieldValues')
            ->willReturnCallback(function (Lead $lead, array $fields) use (&$capturedFields): void {
                $capturedFields = $fields;
            });

        ($this->handler)($this->makeMessage());

        $this->assertEquals(255, mb_strlen($capturedFields['dialoghsm_last_response']));
    }

    // -------------------------------------------------------------------------
    // Testes: skipHousekeeping — prune omitido no modo lote
    // -------------------------------------------------------------------------

    public function testPruneNotCalledWhenSkipHousekeepingIsTrue(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200, 'retryable' => false]);

        $this->mockMessageLogRepository->expects($this->never())->method('prune');

        ($this->handler)($this->makeMessage(), skipHousekeeping: true);
    }

    public function testPruneCalledByDefaultWithoutFlag(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200, 'retryable' => false]);

        $this->mockMessageLogRepository->expects($this->once())->method('prune');

        ($this->handler)($this->makeMessage());
    }

    // -------------------------------------------------------------------------
    // Testes: skipRateLimit — BulkRateLimiter omitido no modo batch
    // -------------------------------------------------------------------------

    public function testRateLimiterNotCalledWhenSkipRateLimitIsTrue(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200, 'retryable' => false]);

        $this->mockRateLimiter->expects($this->never())->method('throttle');

        ($this->handler)($this->makeMessage(), skipHousekeeping: true, skipRateLimit: true);
    }

    public function testRateLimiterCalledByDefault(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200, 'retryable' => false]);

        $this->mockRateLimiter->expects($this->once())->method('throttle');

        ($this->handler)($this->makeMessage());
    }

    // -------------------------------------------------------------------------
    // Testes: getLogMaxRecords / getLogMaxDays — leitura de configuração
    // -------------------------------------------------------------------------

    public function testGetLogMaxRecordsReturnsDefaultWhenIntegrationThrows(): void
    {
        // setUp já configura getIntegration() para lançar exceção
        // DEFAULT_MAX_RECORDS = 0 significa sem limite por contagem
        $this->assertSame(0, $this->handler->getLogMaxRecords());
    }

    public function testGetLogMaxDaysReturnsDefaultWhenIntegrationThrows(): void
    {
        $this->assertSame(30, $this->handler->getLogMaxDays());
    }

    // -------------------------------------------------------------------------
    // Testes: resiliência — falha no log não impede atualização do contato
    // -------------------------------------------------------------------------

    public function testLogFailureDoesNotPreventContactFieldUpdate(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200, 'retryable' => false]);

        // Simula falha no persist (log falha)
        $this->mockEntityManager
            ->method('persist')
            ->willThrowException(new \RuntimeException('DB error'));

        // setFieldValues e saveEntity devem ser chamados mesmo assim
        $this->mockLeadModel->expects($this->once())->method('setFieldValues');
        $this->mockLeadModel->expects($this->once())->method('saveEntity');

        ($this->handler)($this->makeMessage());
    }

    // -------------------------------------------------------------------------
    // Testes: contato não encontrado
    // -------------------------------------------------------------------------

    public function testLeadNotFoundDoesNotUpdateFields(): void
    {
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200, 'retryable' => false]);

        $this->mockLeadModel->method('getEntity')->willReturn(null);

        $this->mockLeadModel->expects($this->never())->method('setFieldValues');
        $this->mockLeadModel->expects($this->never())->method('saveEntity');

        ($this->handler)($this->makeMessage());
    }

    // -------------------------------------------------------------------------
    // Testes: retry — erros transitórios x permanentes
    // -------------------------------------------------------------------------

    /**
     * @dataProvider transientErrorProvider
     */
    public function testTransientErrorThrowsExceptionToTriggerRetry(int|null $httpStatus): void
    {
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => 'Erro transitório', 'http_status' => $httpStatus, 'retryable' => true]);

        // Erro transitório: não persiste log nem atualiza contato — Messenger vai retry
        $this->mockEntityManager->expects($this->never())->method('persist');
        $this->mockLeadModel->expects($this->never())->method('setFieldValues');

        $this->expectException(TransientApiException::class);

        ($this->handler)($this->makeMessage());
    }

    /**
     * @return array<string, array{int|null}>
     */
    public static function transientErrorProvider(): array
    {
        return [
            'rate limit (429)'        => [429],
            'internal server (500)'   => [500],
            'bad gateway (502)'       => [502],
            'service unavailable (503)' => [503],
            'sem resposta HTTP (rede)' => [null],
        ];
    }

    /**
     * @dataProvider permanentErrorProvider
     */
    public function testPermanentErrorLogsAsFailedWithoutRetry(int $httpStatus): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => "HTTP {$httpStatus}: erro", 'http_status' => $httpStatus, 'retryable' => false]);

        // Erro permanente: persiste log como 'failed', não lança exceção
        $this->mockEntityManager->expects($this->once())->method('persist');
        $this->mockEntityManager->expects($this->once())->method('flush');
        $this->mockLeadModel->expects($this->once())->method('setFieldValues');

        ($this->handler)($this->makeMessage());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function permanentErrorProvider(): array
    {
        return [
            'bad request (400)'   => [400],
            'unauthorized (401)'  => [401],
            'forbidden (403)'     => [403],
            'not found (404)'     => [404],
        ];
    }

    public function testSkipRetryPreventsExceptionForTransientError(): void
    {
        $this->mockLeadModel->method('getEntity')->willReturn($this->mockLead);
        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => 'HTTP 503: unavailable', 'http_status' => 503, 'retryable' => true]);

        // Com skipRetry=true (contexto de lote), deve registrar como 'failed' sem lançar
        $this->mockEntityManager->expects($this->once())->method('persist');
        $this->mockEntityManager->expects($this->once())->method('flush');
        $this->mockLeadModel->expects($this->once())->method('setFieldValues');

        // Não deve lançar exceção
        ($this->handler)($this->makeMessage(), skipRetry: true);
    }
}

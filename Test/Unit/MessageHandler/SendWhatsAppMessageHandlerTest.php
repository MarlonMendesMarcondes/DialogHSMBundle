<?php
declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\DialogHSMBundle\Api\DialogHSMApi;
use MauticPlugin\DialogHSMBundle\Entity\MessageLogRepository;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendWhatsAppMessageHandlerTest extends TestCase
{
    private DialogHSMApi&MockObject $mockApi;
    private EntityManagerInterface&MockObject $mockEntityManager;
    private LoggerInterface&MockObject $mockLogger;
    private CoreParametersHelper&MockObject $mockCoreParameters;
    private Connection&MockObject $mockConnection;
    private MessageLogRepository&MockObject $mockMessageLogRepository;
    private SendWhatsAppMessageHandler $handler;

    protected function setUp(): void
    {
        $this->mockApi                  = $this->createMock(DialogHSMApi::class);
        $this->mockEntityManager        = $this->createMock(EntityManagerInterface::class);
        $this->mockLogger               = $this->createMock(LoggerInterface::class);
        $this->mockCoreParameters       = $this->createMock(CoreParametersHelper::class);
        $this->mockConnection           = $this->createMock(Connection::class);
        $this->mockMessageLogRepository = $this->createMock(MessageLogRepository::class);

        $this->mockEntityManager->method('getConnection')->willReturn($this->mockConnection);
        $this->mockCoreParameters->method('get')->with('default_timezone')->willReturn('UTC');

        $this->handler = new SendWhatsAppMessageHandler(
            $this->mockApi,
            $this->mockEntityManager,
            $this->mockLogger,
            $this->mockCoreParameters,
            $this->mockMessageLogRepository,
        );
    }

    public function testHandleSuccess(): void
    {
        $message = new SendWhatsAppMessage(
            leadId:       1,
            phone:        '11999999999',
            apiKey:       'API_KEY',
            baseUrl:      'https://api.360dialog.com/v1/messages',
            payloadData:  ['content' => 'nome_template', 'language' => 'pt_BR'],
            templateName: 'nome_template',
        );

        $this->mockApi
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => ['id' => 'abc'], 'error' => null, 'http_status' => 200]);

        $this->mockEntityManager->expects($this->once())->method('persist');
        $this->mockEntityManager->expects($this->once())->method('flush');
        $this->mockConnection->expects($this->once())->method('executeStatement');
        $this->mockMessageLogRepository->expects($this->once())->method('prune');

        ($this->handler)($message);
    }

    public function testHandleHttpError(): void
    {
        $message = new SendWhatsAppMessage(
            leadId:       1,
            phone:        '11999999999',
            apiKey:       'API_KEY',
            baseUrl:      'https://api.360dialog.com/v1/messages',
            payloadData:  ['content' => 'nome_template', 'language' => 'pt_BR'],
            templateName: 'nome_template',
        );

        $this->mockApi
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => 'HTTP 400: Bad Request', 'http_status' => 400]);

        $this->mockEntityManager->expects($this->once())->method('persist');
        $this->mockEntityManager->expects($this->once())->method('flush');
        $this->mockConnection->expects($this->once())->method('executeStatement');
        $this->mockMessageLogRepository->expects($this->once())->method('prune');

        ($this->handler)($message);
    }

    public function testHandleRequestException(): void
    {
        $message = new SendWhatsAppMessage(
            leadId:       1,
            phone:        '11999999999',
            apiKey:       'API_KEY',
            baseUrl:      'https://api.360dialog.com/v1/messages',
            payloadData:  ['content' => 'nome_template', 'language' => 'pt_BR'],
            templateName: 'nome_template',
        );

        $this->mockApi
            ->expects($this->once())
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => 'Network error', 'http_status' => null]);

        $this->mockEntityManager->expects($this->once())->method('persist');
        $this->mockEntityManager->expects($this->once())->method('flush');
        $this->mockConnection->expects($this->once())->method('executeStatement');
        $this->mockMessageLogRepository->expects($this->once())->method('prune');

        ($this->handler)($message);
    }

    public function testSuccessUpdatesContactFieldWithSentStatus(): void
    {
        $message = new SendWhatsAppMessage(
            leadId:       42,
            phone:        '11999999999',
            apiKey:       'API_KEY',
            baseUrl:      'https://api.360dialog.com/v1/messages',
            payloadData:  ['content' => 'nome_template', 'language' => 'pt_BR'],
            templateName: 'nome_template',
        );

        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => ['id' => 'abc'], 'error' => null, 'http_status' => 200]);

        $capturedParams = null;
        $this->mockConnection
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams): int {
                $capturedParams = $params;

                return 1;
            });

        ($this->handler)($message);

        // $capturedParams[0] = dialoghsm_status, [1] = dialoghsm_last_response, [3] = lead_id
        $this->assertStringContainsString('sent', $capturedParams[0]);
        $this->assertEquals('OK', $capturedParams[1]);
        $this->assertEquals(42, $capturedParams[3]);
    }

    public function testFailureUpdatesContactFieldWithFailedStatus(): void
    {
        $message = new SendWhatsAppMessage(
            leadId:       7,
            phone:        '11999999999',
            apiKey:       'API_KEY',
            baseUrl:      'https://api.360dialog.com/v1/messages',
            payloadData:  ['content' => 'nome_template', 'language' => 'pt_BR'],
            templateName: 'nome_template',
        );

        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => 'HTTP 400: Bad Request', 'http_status' => 400]);

        $capturedParams = null;
        $this->mockConnection
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams): int {
                $capturedParams = $params;

                return 1;
            });

        ($this->handler)($message);

        $this->assertStringContainsString('failed', $capturedParams[0]);
        $this->assertEquals('HTTP 400: Bad Request', $capturedParams[1]);
        $this->assertEquals(7, $capturedParams[3]);
    }

    public function testLongErrorMessageIsTruncatedTo255CharsInContactField(): void
    {
        $longError = str_repeat('x', 300); // 300 caracteres

        $message = new SendWhatsAppMessage(
            leadId:       1,
            phone:        '11999999999',
            apiKey:       'API_KEY',
            baseUrl:      'https://api.360dialog.com/v1/messages',
            payloadData:  ['content' => 'nome_template', 'language' => 'pt_BR'],
            templateName: 'nome_template',
        );

        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => false, 'response' => null, 'error' => $longError, 'http_status' => 500]);

        $capturedParams = null;
        $this->mockConnection
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams): int {
                $capturedParams = $params;

                return 1;
            });

        ($this->handler)($message);

        // dialoghsm_last_response deve ser truncado em 255 chars
        $this->assertEquals(255, mb_strlen($capturedParams[1]));
    }

    public function testLogFailureDoesNotPreventContactFieldUpdate(): void
    {
        $message = new SendWhatsAppMessage(
            leadId:       1,
            phone:        '11999999999',
            apiKey:       'API_KEY',
            baseUrl:      'https://api.360dialog.com/v1/messages',
            payloadData:  ['content' => 'nome_template', 'language' => 'pt_BR'],
            templateName: 'nome_template',
        );

        $this->mockApi
            ->method('sendMessage')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        // Simula falha no persist (log falha)
        $this->mockEntityManager
            ->method('persist')
            ->willThrowException(new \RuntimeException('DB error'));

        // executeStatement deve ser chamado mesmo assim
        $this->mockConnection->expects($this->once())->method('executeStatement');

        ($this->handler)($message);
    }
}
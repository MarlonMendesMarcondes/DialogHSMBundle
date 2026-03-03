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
}
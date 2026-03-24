<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SendWhatsAppDirectMessageHandlerTest extends TestCase
{
    private SendWhatsAppMessageHandler&MockObject $mockBaseHandler;
    private SendWhatsAppDirectMessageHandler $handler;

    protected function setUp(): void
    {
        $this->mockBaseHandler = $this->createMock(SendWhatsAppMessageHandler::class);
        $this->handler         = new SendWhatsAppDirectMessageHandler($this->mockBaseHandler);
    }

    private function makeMessage(): SendWhatsAppDirectMessage
    {
        return new SendWhatsAppDirectMessage(
            leadId:       1,
            phone:        '11999999999',
            apiKey:       'API_KEY_12345678901',
            baseUrl:      'https://api.360dialog.com/v1/messages',
            payloadData:  ['content' => 'template_direct'],
            templateName: 'template_direct',
        );
    }

    public function testDelegatesToBaseHandler(): void
    {
        $expected = ['success' => true, 'response' => ['id' => 'abc'], 'error' => null, 'http_status' => 200];

        $this->mockBaseHandler
            ->expects($this->once())
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppDirectMessage::class))
            ->willReturn($expected);

        $result = ($this->handler)($this->makeMessage());

        $this->assertSame($expected, $result);
    }

    public function testReturnsBaseHandlerResult(): void
    {
        $expected = ['success' => false, 'response' => null, 'error' => 'HTTP 400', 'http_status' => 400];

        $this->mockBaseHandler
            ->method('__invoke')
            ->willReturn($expected);

        $result = ($this->handler)($this->makeMessage());

        $this->assertFalse($result['success']);
        $this->assertEquals('HTTP 400', $result['error']);
    }

    public function testMessageIsDirectMessageSubtype(): void
    {
        $message = $this->makeMessage();

        $this->assertInstanceOf(\MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage::class, $message);
        $this->assertInstanceOf(SendWhatsAppDirectMessage::class, $message);
    }

    public function testZeroRateDelayDoesNotSleep(): void
    {
        $this->mockBaseHandler
            ->method('__invoke')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        $message = new SendWhatsAppDirectMessage(
            leadId: 1, phone: '11999999999', apiKey: 'KEY', baseUrl: 'https://x.com',
            payloadData: [], templateName: 'tpl', rateDelaySeconds: 0.0,
        );

        $start = microtime(true);
        ($this->handler)($message);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.05, $elapsed, 'rateDelaySeconds=0 não deve gerar sleep');
    }

    public function testRateDelaySleepsAfterEachMessage(): void
    {
        // 0.2s delay = equivalente a batch_limit=10, send_delay=2s no modo inline
        $this->mockBaseHandler
            ->method('__invoke')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        $message = new SendWhatsAppDirectMessage(
            leadId: 1, phone: '11999999999', apiKey: 'KEY', baseUrl: 'https://x.com',
            payloadData: [], templateName: 'tpl', rateDelaySeconds: 0.2,
        );

        $start = microtime(true);
        ($this->handler)($message);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.19, $elapsed, 'Esperado sleep de 0.2s após a mensagem');
        $this->assertLessThan(0.4, $elapsed, 'Sleep não deve ultrapassar 0.4s');
    }

    public function testResultIsReturnedEvenWithRateDelay(): void
    {
        $expected = ['success' => true, 'response' => ['id' => 'xyz'], 'error' => null, 'http_status' => 200];

        $this->mockBaseHandler
            ->method('__invoke')
            ->willReturn($expected);

        $message = new SendWhatsAppDirectMessage(
            leadId: 1, phone: '11999999999', apiKey: 'KEY', baseUrl: 'https://x.com',
            payloadData: [], templateName: 'tpl', rateDelaySeconds: 0.05,
        );
        $result = ($this->handler)($message);

        $this->assertSame($expected, $result);
    }

    public function testRateDelaySecondsIsCarriedInMessage(): void
    {
        // send_delay=2, batch_limit=10 → rateDelaySeconds=0.2
        $message = new SendWhatsAppDirectMessage(
            leadId: 1, phone: '11999999999', apiKey: 'KEY', baseUrl: 'https://x.com',
            payloadData: [], templateName: 'tpl', rateDelaySeconds: 0.2,
        );

        $this->assertEquals(0.2, $message->rateDelaySeconds);
    }
}

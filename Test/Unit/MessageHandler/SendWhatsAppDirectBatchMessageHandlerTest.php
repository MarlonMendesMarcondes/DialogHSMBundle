<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppDirectBatchMessage;
use MauticPlugin\DialogHSMBundle\Message\SendWhatsAppMessage;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppDirectBatchMessageHandler;
use MauticPlugin\DialogHSMBundle\MessageHandler\SendWhatsAppMessageHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SendWhatsAppDirectBatchMessageHandlerTest extends TestCase
{
    private SendWhatsAppMessageHandler&MockObject $mockBaseHandler;
    private SendWhatsAppDirectBatchMessageHandler $handler;

    protected function setUp(): void
    {
        $this->mockBaseHandler = $this->createMock(SendWhatsAppMessageHandler::class);
        $this->handler         = new SendWhatsAppDirectBatchMessageHandler($this->mockBaseHandler, new NullLogger());
    }

    private function makeMessage(int $leadId = 1): SendWhatsAppMessage
    {
        return new SendWhatsAppMessage(
            leadId:             $leadId,
            phone:              '+551199999' . str_pad((string) $leadId, 4, '0', STR_PAD_LEFT),
            apiKey:             'API_KEY',
            baseUrl:            'https://api.example.com',
            payloadData:        ['content' => 'tpl'],
            templateName:       'tpl',
            whatsAppNumberName: 'Número Teste',
            campaignId:         1,
            campaignEventId:    1,
        );
    }

    private function makeBatch(int $count, int $batchLimit = 0, int $sendDelay = 0): SendWhatsAppDirectBatchMessage
    {
        $items = [];
        for ($i = 1; $i <= $count; ++$i) {
            $items[] = $this->makeMessage($i);
        }

        return new SendWhatsAppDirectBatchMessage($items, $batchLimit, $sendDelay);
    }

    // -------------------------------------------------------------------------
    // Delegação ao handler base
    // -------------------------------------------------------------------------

    public function testCallsBaseHandlerForEachItem(): void
    {
        $batch = $this->makeBatch(3);

        $this->mockBaseHandler
            ->expects($this->exactly(3))
            ->method('__invoke')
            ->with($this->isInstanceOf(SendWhatsAppMessage::class))
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        ($this->handler)($batch);
    }

    public function testEmptyBatchCallsBaseHandlerNever(): void
    {
        $batch = new SendWhatsAppDirectBatchMessage([], 0, 0);

        $this->mockBaseHandler->expects($this->never())->method('__invoke');

        ($this->handler)($batch);
    }

    // -------------------------------------------------------------------------
    // Throttle: batchLimit=0 (ou 1), sendDelay=0 → sem sleep
    // -------------------------------------------------------------------------

    public function testNoBatchLimitAndNoDelayRunsWithoutSleep(): void
    {
        $batch = $this->makeBatch(5, batchLimit: 0, sendDelay: 0);

        $this->mockBaseHandler
            ->method('__invoke')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        $start   = microtime(true);
        ($this->handler)($batch);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.1, $elapsed, 'Sem delay configurado não deve dormir');
    }

    public function testNoDelayRunsWithoutSleepEvenWithBatchLimit(): void
    {
        $batch = $this->makeBatch(10, batchLimit: 5, sendDelay: 0);

        $this->mockBaseHandler
            ->method('__invoke')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        $start   = microtime(true);
        ($this->handler)($batch);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.1, $elapsed, 'sendDelay=0 não deve gerar nenhum sleep');
    }

    // -------------------------------------------------------------------------
    // Throttle: batchLimit=5, sendDelay=1 → sleep após cada grupo de 5
    // -------------------------------------------------------------------------

    public function testBatchGroupSleepAfterEachGroup(): void
    {
        // 10 mensagens, grupos de 5, delay de 1s → 2 sleeps = ~2s
        $batch = $this->makeBatch(10, batchLimit: 5, sendDelay: 1);

        $this->mockBaseHandler
            ->method('__invoke')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        $start   = microtime(true);
        ($this->handler)($batch);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(1.9, $elapsed, 'Esperados 2 sleeps de 1s (≥1.9s)');
        $this->assertLessThan(3.5, $elapsed, 'Não deve demorar mais que 3.5s');
    }

    public function testPartialGroupDoesNotSleep(): void
    {
        // 7 mensagens, grupos de 5, delay de 1s → 1 sleep após 5, nada após o grupo de 2
        $batch = $this->makeBatch(7, batchLimit: 5, sendDelay: 1);

        $this->mockBaseHandler
            ->method('__invoke')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        $start   = microtime(true);
        ($this->handler)($batch);
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'Esperado 1 sleep de 1s (≥0.9s)');
        $this->assertLessThan(2.5, $elapsed, 'Grupo parcial não gera sleep extra');
    }

    // -------------------------------------------------------------------------
    // Throttle: batchLimit=0, sendDelay=0 → sem sleep
    // -------------------------------------------------------------------------

    public function testZeroBatchLimitAndZeroDelayRunsWithoutSleep(): void
    {
        $batch = new SendWhatsAppDirectBatchMessage(
            [
                $this->makeMessage(1),
                $this->makeMessage(2),
                $this->makeMessage(3),
            ],
            batchLimit: 0,
            sendDelay:  0,
        );

        $this->mockBaseHandler
            ->method('__invoke')
            ->willReturn(['success' => true, 'response' => null, 'error' => null, 'http_status' => 200]);

        $start   = microtime(true);
        ($this->handler)($batch);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.1, $elapsed, 'sendDelay=0 não dorme mesmo com batchLimit=0');
    }

    // -------------------------------------------------------------------------
    // Resiliência: exceção em um item não para o lote
    // -------------------------------------------------------------------------

    public function testExceptionInOneItemDoesNotAbortBatch(): void
    {
        $batch = $this->makeBatch(3, batchLimit: 0, sendDelay: 0);

        $this->mockBaseHandler
            ->method('__invoke')
            ->willReturnOnConsecutiveCalls(
                ['success' => true, 'response' => null, 'error' => null, 'http_status' => 200],
                $this->throwException(new \RuntimeException('API timeout')),
                ['success' => true, 'response' => null, 'error' => null, 'http_status' => 200],
            );

        ($this->handler)($batch);

        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // Semântica: campos carregados corretamente
    // -------------------------------------------------------------------------

    public function testDirectBatchMessageCarriesParams(): void
    {
        $items = [$this->makeMessage(1)];
        $batch = new SendWhatsAppDirectBatchMessage($items, batchLimit: 10, sendDelay: 2);

        $this->assertCount(1, $batch->items);
        $this->assertSame(10, $batch->batchLimit);
        $this->assertSame(2, $batch->sendDelay);
    }
}

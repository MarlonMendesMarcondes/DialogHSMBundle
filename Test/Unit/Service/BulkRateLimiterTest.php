<?php

declare(strict_types=1);

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\DialogHSMBundle\Service\BulkRateLimiter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BulkRateLimiterTest extends TestCase
{
    private IntegrationsHelper&MockObject $integrationsHelper;
    private \Redis&MockObject $redis;

    protected function setUp(): void
    {
        $this->integrationsHelper = $this->createMock(IntegrationsHelper::class);
        $this->redis              = $this->createMock(\Redis::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeIntegrationStub(int $ratePerMinute): object
    {
        $config = new class($ratePerMinute) {
            public function __construct(private int $rate) {}
            public function getApiKeys(): array { return ['bulk_rate_per_minute' => $this->rate]; }
        };

        return new class($config) {
            public function __construct(private $config) {}
            public function getIntegrationConfiguration(): object { return $this->config; }
        };
    }

    private function makeLimiter(int $ratePerMinute): BulkRateLimiter
    {
        $this->integrationsHelper
            ->method('getIntegration')
            ->with(DialogHSMIntegration::NAME)
            ->willReturn($this->makeIntegrationStub($ratePerMinute));

        return new BulkRateLimiter($this->integrationsHelper, '', $this->redis);
    }

    // -------------------------------------------------------------------------
    // rate = 0: nenhuma interação com Redis
    // -------------------------------------------------------------------------

    public function testThrottleDoesNothingWhenRateIsZero(): void
    {
        $this->integrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegrationStub(0));

        $this->redis->expects($this->never())->method('incr');

        $limiter = new BulkRateLimiter($this->integrationsHelper, '', $this->redis);
        $limiter->throttle();
    }

    // -------------------------------------------------------------------------
    // Slot disponível: INCR retorna ≤ ratePerSecond → retorna sem sleep
    // -------------------------------------------------------------------------

    public function testThrottleAcquiresSlotImmediatelyWhenWithinRate(): void
    {
        $limiter = $this->makeLimiter(120); // ratePerSecond = 2

        $this->redis->expects($this->once())->method('incr')->willReturn(1);
        $this->redis->expects($this->once())->method('expire');
        $this->redis->expects($this->never())->method('decr');

        $limiter->throttle();
    }

    public function testThrottleAcquiresSlotAtExactBoundary(): void
    {
        $limiter = $this->makeLimiter(120); // ratePerSecond = 2

        $this->redis->method('incr')->willReturn(2); // exatamente no limite
        $this->redis->expects($this->never())->method('decr');

        $limiter->throttle();
    }

    // -------------------------------------------------------------------------
    // Segundo saturado: INCR > ratePerSecond → DECR + retry
    // -------------------------------------------------------------------------

    public function testThrottleDecrementsAndRetriesWhenSecondIsFull(): void
    {
        $limiter = $this->makeLimiter(60); // ratePerSecond = 1

        // 1ª tentativa saturada (count=2 > 1) → DECR; 2ª disponível (count=1)
        $this->redis->method('incr')->willReturnOnConsecutiveCalls(2, 1);
        $this->redis->expects($this->once())->method('decr');

        $limiter->throttle();
    }

    public function testThrottleCallsDecrForEachFailedAttempt(): void
    {
        $limiter = $this->makeLimiter(60); // ratePerSecond = 1

        // 3 tentativas saturadas, depois slot disponível
        $this->redis->method('incr')->willReturnOnConsecutiveCalls(5, 5, 5, 1);
        $this->redis->expects($this->exactly(3))->method('decr');

        $limiter->throttle();
    }

    // -------------------------------------------------------------------------
    // MAX_RETRIES: fail-open após 5 tentativas saturadas
    // -------------------------------------------------------------------------

    public function testThrottleFailsOpenAfterMaxRetries(): void
    {
        $limiter = $this->makeLimiter(60); // ratePerSecond = 1

        // Sempre saturado — deve parar após 5 tentativas sem lançar exceção
        $this->redis->method('incr')->willReturn(99);
        $this->redis->expects($this->exactly(5))->method('decr');

        $limiter->throttle();
        $this->assertTrue(true); // chegou aqui = fail-open funcionou
    }

    // -------------------------------------------------------------------------
    // Redis indisponível: fail-open silencioso
    // -------------------------------------------------------------------------

    public function testThrottleFailsOpenWhenRedisThrows(): void
    {
        $limiter = $this->makeLimiter(120);

        $this->redis->method('incr')->willThrowException(new \RedisException('Connection refused'));

        $limiter->throttle(); // não deve lançar
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Sem DSN e sem override: throttle desativado silenciosamente
    // -------------------------------------------------------------------------

    public function testThrottleDoesNothingWhenNoDsnAndNoOverride(): void
    {
        $this->integrationsHelper
            ->method('getIntegration')
            ->willReturn($this->makeIntegrationStub(120));

        // Sem redisOverride e sem DSN válido → getRedis() retorna null → fail-open
        $limiter = new BulkRateLimiter($this->integrationsHelper, '');
        $limiter->throttle(); // não deve lançar
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Cache do rate: IntegrationsHelper chamado apenas 1x em múltiplos throttles
    // -------------------------------------------------------------------------

    public function testRateIsCachedAcrossMultipleCalls(): void
    {
        // getIntegration deve ser chamado apenas 1x para múltiplos throttle()
        $this->integrationsHelper
            ->expects($this->once())
            ->method('getIntegration')
            ->willReturn($this->makeIntegrationStub(120));

        $this->redis->method('incr')->willReturn(1);

        $limiter = new BulkRateLimiter($this->integrationsHelper, '', $this->redis);
        $limiter->throttle();
        $limiter->throttle();
        $limiter->throttle();
    }

    // -------------------------------------------------------------------------
    // IntegrationsHelper lança exceção: fail-open (rate = 0)
    // -------------------------------------------------------------------------

    public function testThrottleFailsOpenWhenIntegrationsHelperThrows(): void
    {
        $this->integrationsHelper
            ->method('getIntegration')
            ->willThrowException(new \RuntimeException('Integration not found'));

        $this->redis->expects($this->never())->method('incr');

        $limiter = new BulkRateLimiter($this->integrationsHelper, '', $this->redis);
        $limiter->throttle(); // rate = 0 por fallback → sem Redis
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // expire só é chamado quando count === 1 (slot criado pela primeira vez)
    // -------------------------------------------------------------------------

    public function testExpireIsCalledOnlyWhenCountIsOne(): void
    {
        $limiter = $this->makeLimiter(120); // ratePerSecond = 2

        $this->redis->method('incr')->willReturn(2); // count=2 ≤ ratePerSecond=2 → slot ok, mas expire não
        $this->redis->expects($this->never())->method('expire');
        $this->redis->expects($this->never())->method('decr');

        $limiter->throttle();
    }

    public function testExpireIsCalledWhenCountIsOne(): void
    {
        $limiter = $this->makeLimiter(120);

        $this->redis->method('incr')->willReturn(1);
        $this->redis->expects($this->once())->method('expire');

        $limiter->throttle();
    }
}

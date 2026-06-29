<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Service\RedisContactCache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RedisContactCacheTest extends TestCase
{
    private \Redis&MockObject $redis;
    private RedisContactCache $cache;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(\Redis::class);
        $this->cache = new RedisContactCache('', $this->redis);
    }

    // =========================================================================
    // setLastSent
    // =========================================================================

    public function testSetLastSentWritesHashAndExpiry(): void
    {
        $this->redis->expects($this->once())
            ->method('hMSet')
            ->with('dialoghsm:contact:5511999999999', ['wamid' => 'wamid.HSM001', 'replied' => '0']);
        $this->redis->expects($this->once())
            ->method('expire')
            ->with('dialoghsm:contact:5511999999999', RedisContactCache::TTL);

        $this->cache->setLastSent('5511999999999', 'wamid.HSM001');
    }

    public function testSetLastSentResetsRepliedFieldToZero(): void
    {
        $captured = [];
        $this->redis->method('hMSet')->willReturnCallback(
            function (string $key, array $fields) use (&$captured): void {
                $captured = $fields;
            }
        );

        $this->cache->setLastSent('5511999999999', 'wamid.HSM002');

        $this->assertSame('0', $captured['replied'],
            'Novo envio deve resetar replied para 0');
    }

    public function testSetLastSentSilentlyIgnoresRedisException(): void
    {
        $this->redis->method('hMSet')->willThrowException(new \RedisException('connection refused'));

        // deve completar sem lançar exceção
        $this->cache->setLastSent('5511999999999', 'wamid.HSM003');
        $this->assertTrue(true);
    }

    public function testSetLastSentNoOpWhenRedisUnavailable(): void
    {
        $cache = new RedisContactCache(''); // sem override, sem DSN válido

        $this->redis->expects($this->never())->method('hMSet');

        $cache->setLastSent('5511999999999', 'wamid.HSM004');
    }

    // =========================================================================
    // getLastWamid
    // =========================================================================

    public function testGetLastWamidReturnsWamidWhenPresent(): void
    {
        $this->redis->method('hGet')
            ->with('dialoghsm:contact:5511999999999', 'wamid')
            ->willReturn('wamid.HSM001');

        $this->assertSame('wamid.HSM001', $this->cache->getLastWamid('5511999999999'));
    }

    public function testGetLastWamidReturnsNullWhenFieldMissing(): void
    {
        $this->redis->method('hGet')->willReturn(false);

        $this->assertNull($this->cache->getLastWamid('5511999999999'));
    }

    public function testGetLastWamidReturnsNullWhenFieldEmpty(): void
    {
        $this->redis->method('hGet')->willReturn('');

        $this->assertNull($this->cache->getLastWamid('5511999999999'));
    }

    public function testGetLastWamidReturnsNullOnRedisException(): void
    {
        $this->redis->method('hGet')->willThrowException(new \RedisException('connection refused'));

        $this->assertNull($this->cache->getLastWamid('5511999999999'));
    }

    public function testGetLastWamidReturnsNullWhenRedisUnavailable(): void
    {
        $cache = new RedisContactCache('');

        $this->assertNull($cache->getLastWamid('5511999999999'));
    }

    // =========================================================================
    // isReplied
    // =========================================================================

    public function testIsRepliedReturnsTrueWhenFieldIsOne(): void
    {
        $this->redis->method('hGet')
            ->with('dialoghsm:contact:5511999999999', 'replied')
            ->willReturn('1');

        $this->assertTrue($this->cache->isReplied('5511999999999'));
    }

    public function testIsRepliedReturnsFalseWhenFieldIsZero(): void
    {
        $this->redis->method('hGet')
            ->with('dialoghsm:contact:5511999999999', 'replied')
            ->willReturn('0');

        $this->assertFalse($this->cache->isReplied('5511999999999'));
    }

    public function testIsRepliedReturnsFalseWhenFieldMissing(): void
    {
        $this->redis->method('hGet')->willReturn(false);

        $this->assertFalse($this->cache->isReplied('5511999999999'));
    }

    public function testIsRepliedReturnsFalseOnRedisException(): void
    {
        $this->redis->method('hGet')->willThrowException(new \RedisException('connection refused'));

        $this->assertFalse($this->cache->isReplied('5511999999999'));
    }

    public function testIsRepliedReturnsFalseWhenRedisUnavailable(): void
    {
        $cache = new RedisContactCache('');

        $this->assertFalse($cache->isReplied('5511999999999'));
    }

    // =========================================================================
    // markReplied
    // =========================================================================

    public function testMarkRepliedSetsFieldToOne(): void
    {
        $this->redis->expects($this->once())
            ->method('hSet')
            ->with('dialoghsm:contact:5511999999999', 'replied', '1');

        $this->cache->markReplied('5511999999999');
    }

    public function testMarkRepliedSilentlyIgnoresRedisException(): void
    {
        $this->redis->method('hSet')->willThrowException(new \RedisException('connection refused'));

        $this->cache->markReplied('5511999999999');
        $this->assertTrue(true);
    }

    public function testMarkRepliedNoOpWhenRedisUnavailable(): void
    {
        $cache = new RedisContactCache('');

        $this->redis->expects($this->never())->method('hSet');

        $cache->markReplied('5511999999999');
    }

    // =========================================================================
    // Key prefix
    // =========================================================================

    public function testKeyPrefixConstant(): void
    {
        $this->assertSame('dialoghsm:contact:', RedisContactCache::KEY_PREFIX);
    }

    public function testTtlConstantIs24Hours(): void
    {
        $this->assertSame(86_400, RedisContactCache::TTL);
    }

    // =========================================================================
    // Normalização de telefone (+ removido para chave uniforme)
    // =========================================================================

    public function testSetLastSentStripsLeadingPlusFromKey(): void
    {
        $this->redis->expects($this->once())
            ->method('hMSet')
            ->with('dialoghsm:contact:5511999999999', $this->anything());

        // E.164 com + (formato Mautic) → mesma chave que sem +
        $this->cache->setLastSent('+5511999999999', 'wamid.HSM001');
    }

    public function testGetLastWamidStripsLeadingPlusFromKey(): void
    {
        $this->redis->method('hGet')
            ->with('dialoghsm:contact:5511999999999', 'wamid')
            ->willReturn('wamid.HSM001');

        $this->assertSame('wamid.HSM001', $this->cache->getLastWamid('+5511999999999'));
    }

    public function testSetWithPlusAndGetWithoutPlusShareSameKey(): void
    {
        // Envio usa E.164 (+55...), webhook 360dialog chega sem + (55...)
        // Ambos devem acessar a mesma chave Redis
        $this->redis->expects($this->once())
            ->method('hMSet')
            ->with('dialoghsm:contact:5511999999999', $this->anything());

        $this->redis->method('hGet')
            ->with('dialoghsm:contact:5511999999999', 'wamid')
            ->willReturn('wamid.HSM001');

        $this->cache->setLastSent('+5511999999999', 'wamid.HSM001');
        $wamid = $this->cache->getLastWamid('5511999999999');

        $this->assertSame('wamid.HSM001', $wamid);
    }

    public function testMarkRepliedStripsLeadingPlusFromKey(): void
    {
        $this->redis->expects($this->once())
            ->method('hSet')
            ->with('dialoghsm:contact:5511999999999', 'replied', '1');

        $this->cache->markReplied('+5511999999999');
    }

    public function testIsRepliedStripsLeadingPlusFromKey(): void
    {
        $this->redis->method('hGet')
            ->with('dialoghsm:contact:5511999999999', 'replied')
            ->willReturn('1');

        $this->assertTrue($this->cache->isReplied('+5511999999999'));
    }

    // =========================================================================
    // Sequência de uso: setLastSent → isReplied → markReplied
    // =========================================================================

    public function testFullFlowSetLastSentThenMarkReplied(): void
    {
        // Simula: envio HSM → resposta do contato
        $this->redis->method('hGet')
            ->willReturnMap([
                ['dialoghsm:contact:5511999999999', 'wamid', 'wamid.HSM001'],
                ['dialoghsm:contact:5511999999999', 'replied', '1'],
            ]);

        $wamid = $this->cache->getLastWamid('5511999999999');
        $this->assertSame('wamid.HSM001', $wamid);

        $this->assertTrue($this->cache->isReplied('5511999999999'));
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;

/**
 * Rate limiter para envio bulk via Redis sliding window (por segundo).
 *
 * Algoritmo:
 *   1. INCR na chave do segundo atual
 *   2. Se count <= ratePerSecond → slot adquirido, prossegue sem bloqueio
 *   3. Se count > ratePerSecond  → devolve o slot (DECR), aguarda o início do próximo segundo
 *   4. Tenta novamente (máx. 5 tentativas — fail-open após isso)
 *
 * Propriedades:
 *   - Atômico: INCR/DECR do Redis são operações atômicas, sem mutex nem lock
 *   - Sem bloqueio quando dentro do rate (caso mais comum)
 *   - Sleep máximo de ~1s por tentativa, quando o segundo está saturado
 *   - Fail-open: se Redis indisponível ou rate=0, não aplica throttle
 */
class BulkRateLimiter
{
    private const CACHE_TTL_SECONDS = 30;
    private const MAX_RETRIES       = 5;

    private ?\Redis $redis   = null;
    private int $cachedRate  = -1;
    private float $cacheExp  = 0.0;

    public function __construct(
        private IntegrationsHelper $integrationsHelper,
        private string $redisDsn = '',
    ) {
    }

    /**
     * Aplica throttle antes de enviar uma mensagem.
     * Retorna imediatamente se dentro do rate; bloqueia no máximo até o início do próximo segundo.
     */
    public function throttle(): void
    {
        $ratePerMinute = $this->getRatePerMinute();
        if ($ratePerMinute <= 0) {
            return;
        }

        $redis = $this->getRedis();
        if ($redis === null) {
            return; // fail-open: Redis indisponível
        }

        $ratePerSecond = max(1, (int) ceil($ratePerMinute / 60));

        for ($attempt = 0; $attempt < self::MAX_RETRIES; ++$attempt) {
            $second = (int) microtime(true);
            $key    = 'dialoghsm:rate:bulk:' . $second;

            $count = $redis->incr($key);
            if (1 === $count) {
                $redis->expire($key, 2); // TTL = 2s (janela atual + folga)
            }

            if ($count <= $ratePerSecond) {
                return; // slot adquirido — sem bloqueio
            }

            // Devolve o slot e aguarda o próximo segundo
            $redis->decr($key);
            $now    = microtime(true);
            $waitUs = (int) (($second + 1 - $now) * 1_000_000);
            if ($waitUs > 0) {
                usleep($waitUs);
            }
        }
        // Após MAX_RETRIES: fail-open — prossegue sem throttle
    }

    private function getRatePerMinute(): int
    {
        $now = microtime(true);
        if ($this->cachedRate >= 0 && $now < $this->cacheExp) {
            return $this->cachedRate;
        }

        try {
            $integration     = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);
            $apiKeys         = $integration->getIntegrationConfiguration()->getApiKeys() ?? [];
            $this->cachedRate = max(0, (int) ($apiKeys['bulk_rate_per_minute'] ?? 0));
        } catch (\Throwable) {
            $this->cachedRate = 0;
        }

        $this->cacheExp = $now + self::CACHE_TTL_SECONDS;

        return $this->cachedRate;
    }

    private function getRedis(): ?\Redis
    {
        if ($this->redis !== null) {
            try {
                $this->redis->ping();

                return $this->redis;
            } catch (\Throwable) {
                $this->redis = null;
            }
        }

        if ('' === $this->redisDsn || !str_starts_with($this->redisDsn, 'redis')) {
            return null;
        }

        try {
            $parsed = parse_url($this->redisDsn);
            $host   = $parsed['host'] ?? 'localhost';
            $port   = (int) ($parsed['port'] ?? 6379);

            $r = new \Redis();
            $r->connect($host, $port, 1.0); // timeout 1s
            $this->redis = $r;
        } catch (\Throwable) {
            return null;
        }

        return $this->redis;
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;

/**
 * Rate limiter para envio bulk e batch via Redis sliding window (por segundo).
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
 *   - Bulk e batch usam o mesmo mecanismo; diferem apenas no namespace da chave Redis
 *     e no campo de configuração (bulk_rate_per_minute / batch_rate_per_minute)
 */
class BulkRateLimiter
{
    private const CACHE_TTL_SECONDS = 30;
    private const MAX_RETRIES       = 5;

    private ?\Redis $redis        = null;
    private int $cachedRate       = -1;
    private float $cacheExp       = 0.0;
    private int $cachedBatchRate  = -1;
    private float $batchCacheExp  = 0.0;

    public function __construct(
        private IntegrationsHelper $integrationsHelper,
        private string $redisDsn = '',
        private ?\Redis $redisOverride = null,
    ) {
    }

    /**
     * Aplica throttle antes de enviar uma mensagem.
     * Retorna imediatamente se dentro do rate; bloqueia no máximo até o início do próximo segundo.
     *
     * @param string $numberKey Identificador do WhatsApp Number (ex.: whatsAppNumberName).
     *                          Cada número recebe seu próprio contador de rate, garantindo
     *                          que N números entreguem N × ratePerSecond mensagens/s no total.
     *                          Vazio → usa namespace "global" (comportamento legado).
     */
    public function throttle(string $numberKey = ''): void
    {
        $this->applyThrottle('bulk', $this->getRatePerMinute(), $numberKey);
    }

    /**
     * Aplica throttle para envios batch via Redis sliding window (mesmo mecanismo do bulk).
     * Lê batch_rate_per_minute das configurações do plugin.
     * Namespace Redis: "batch" — chaves isoladas das do bulk.
     */
    public function throttleBatch(string $numberKey = ''): void
    {
        $this->applyThrottle('batch', $this->getBatchRatePerMinute(), $numberKey);
    }

    private function applyThrottle(string $namespace, int $ratePerMinute, string $numberKey): void
    {
        if ($ratePerMinute <= 0) {
            return;
        }

        $redis = $this->getRedis();
        if ($redis === null) {
            return; // fail-open: Redis indisponível
        }

        $ratePerSecond = max(1, (int) ceil($ratePerMinute / 60));
        $ns            = $this->sanitizeKey($numberKey);

        for ($attempt = 0; $attempt < self::MAX_RETRIES; ++$attempt) {
            try {
                $second = (int) microtime(true);
                $key    = 'dialoghsm:rate:' . $namespace . ':' . $ns . ':' . $second;

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
            } catch (\Throwable) {
                return; // fail-open: Redis indisponível durante o throttle
            }
        }
        // Após MAX_RETRIES: fail-open — prossegue sem throttle
    }

    /**
     * Normaliza o identificador do número para uso seguro como segmento de chave Redis.
     * Substitui qualquer caractere que não seja alfanumérico, hífen ou underscore por "_".
     */
    private function sanitizeKey(string $key): string
    {
        if ('' === $key) {
            return 'global';
        }

        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) ?: 'global';
    }

    private function getRatePerMinute(): int
    {
        $now = microtime(true);
        if ($this->cachedRate >= 0 && $now < $this->cacheExp) {
            return $this->cachedRate;
        }

        $this->cachedRate = $this->readRateFromPlugin('bulk_rate_per_minute');
        $this->cacheExp   = $now + self::CACHE_TTL_SECONDS;

        return $this->cachedRate;
    }

    private function getBatchRatePerMinute(): int
    {
        $now = microtime(true);
        if ($this->cachedBatchRate >= 0 && $now < $this->batchCacheExp) {
            return $this->cachedBatchRate;
        }

        $this->cachedBatchRate = $this->readRateFromPlugin('batch_rate_per_minute');
        $this->batchCacheExp   = $now + self::CACHE_TTL_SECONDS;

        return $this->cachedBatchRate;
    }

    private function readRateFromPlugin(string $field): int
    {
        try {
            $integration = $this->integrationsHelper->getIntegration(DialogHSMIntegration::NAME);
            $apiKeys     = $integration->getIntegrationConfiguration()->getApiKeys() ?? [];

            return max(0, (int) ($apiKeys[$field] ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getRedis(): ?\Redis
    {
        if ($this->redisOverride !== null) {
            return $this->redisOverride;
        }

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
            $db     = isset($parsed['path']) ? (int) ltrim($parsed['path'], '/') : 0;

            $r = new \Redis();
            $r->connect($host, $port, 1.0); // timeout 1s
            if ($db > 0) {
                $r->select($db);
            }
            $this->redis = $r;
        } catch (\Throwable) {
            return null;
        }

        return $this->redis;
    }
}

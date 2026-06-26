<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

/**
 * Cache Redis de último envio e status de resposta por número de telefone.
 *
 * Estrutura (Hash por contato):
 *   KEY:   dialoghsm:contact:{phone}
 *   FIELD  wamid    → wamid do último HSM enviado (gravado pelo handler de envio)
 *   FIELD  replied  → "0" pendente / "1" já respondeu
 *   TTL:   86 400 s (24h, renovado a cada novo envio)
 *
 * Quando Redis está indisponível, todos os métodos falham silenciosamente:
 * - setLastSent / markReplied são no-op
 * - getLastWamid retorna null → WebhookProcessor cai no fallback de DB
 * - isReplied retorna false → permite que o DB decida
 */
class RedisContactCache
{
    public const KEY_PREFIX = 'dialoghsm:contact:';
    public const TTL        = 86_400; // 24h

    private ?\Redis $redis = null;

    public function __construct(
        private readonly string $redisDsn = '',
        private readonly ?\Redis $redisOverride = null,
    ) {}

    /**
     * Grava o wamid do último HSM enviado e reseta o flag de resposta.
     * Chamado pelo handler de envio após confirmação 200 da API 360dialog.
     */
    public function setLastSent(string $phone, string $wamid): void
    {
        $redis = $this->getRedis();
        if ($redis === null) {
            return;
        }

        try {
            $key = self::KEY_PREFIX . $this->normalizePhone($phone);
            $redis->hMSet($key, ['wamid' => $wamid, 'replied' => '0']);
            $redis->expire($key, self::TTL);
        } catch (\Throwable) {
        }
    }

    /**
     * Retorna o wamid do último HSM enviado ao contato, ou null quando Redis está
     * indisponível / a chave expirou / nenhum HSM foi enviado na janela.
     */
    public function getLastWamid(string $phone): ?string
    {
        $redis = $this->getRedis();
        if ($redis === null) {
            return null;
        }

        try {
            $wamid = $redis->hGet(self::KEY_PREFIX . $this->normalizePhone($phone), 'wamid');

            return ($wamid !== false && $wamid !== '') ? (string) $wamid : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Retorna true se o contato já foi marcado como respondido na janela atual.
     * Falha silenciosa: retorna false quando Redis indisponível (o DB decide).
     */
    public function isReplied(string $phone): bool
    {
        $redis = $this->getRedis();
        if ($redis === null) {
            return false;
        }

        try {
            return $redis->hGet(self::KEY_PREFIX . $this->normalizePhone($phone), 'replied') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Marca o contato como respondido no Hash.
     * Chamado após persistir a resposta — tanto no Scenario A quanto no B.
     */
    public function markReplied(string $phone): void
    {
        $redis = $this->getRedis();
        if ($redis === null) {
            return;
        }

        try {
            $redis->hSet(self::KEY_PREFIX . $this->normalizePhone($phone), 'replied', '1');
        } catch (\Throwable) {
        }
    }

    /**
     * Remove o '+' inicial para garantir chave uniforme entre envio (E.164 com +)
     * e recebimento 360dialog (sem +).
     */
    private function normalizePhone(string $phone): string
    {
        return ltrim($phone, '+');
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
            $r->connect($host, $port, 1.0);
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

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Diz se a campanha de uma mensagem na fila ainda pode enviar.
 *
 * Usado pelo consumidor do RabbitMQ: mensagem de campanha desativada (ou fora do período
 * publish_up/publish_down) não é enviada — volta para a fila e espera a reativação.
 *
 * O status fica em cache por alguns segundos dentro do processo do worker: um lote de
 * centenas de mensagens da mesma campanha faz uma consulta, não centenas.
 */
class CampaignActivityChecker
{
    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';
    public const DELETED  = 'deleted';

    private const CACHE_TTL_SECONDS = 15;

    /** @var array<int, array{status: string, expires: float}> */
    private array $cache = [];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return string self::ACTIVE, self::INACTIVE (despublicada ou fora do período) ou
     *                self::DELETED (removida — nunca vai voltar)
     */
    public function getStatus(int $campaignId): string
    {
        $now = microtime(true);

        if (isset($this->cache[$campaignId]) && $this->cache[$campaignId]['expires'] > $now) {
            return $this->cache[$campaignId]['status'];
        }

        $prefix = defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '';

        // Datas do Mautic são gravadas em UTC — a comparação fica no banco com UTC_TIMESTAMP()
        // para não depender do fuso do PHP do worker.
        $row = $this->connection->fetchAssociative(
            "SELECT is_published, deleted,
                    (publish_up IS NULL OR publish_up <= UTC_TIMESTAMP())
                    AND (publish_down IS NULL OR publish_down > UTC_TIMESTAMP()) AS in_period
             FROM `{$prefix}campaigns` WHERE id = :id",
            ['id' => $campaignId]
        );

        if (false === $row || null !== $row['deleted']) {
            $status = self::DELETED;
        } elseif ((bool) $row['is_published'] && (bool) $row['in_period']) {
            $status = self::ACTIVE;
        } else {
            $status = self::INACTIVE;
        }

        $this->cache[$campaignId] = ['status' => $status, 'expires' => $now + self::CACHE_TTL_SECONDS];

        return $status;
    }
}

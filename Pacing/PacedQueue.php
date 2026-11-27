<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Pacing;

use Doctrine\DBAL\Connection;
use MauticPlugin\DialogHSMBundle\Entity\PacedMessage;

/**
 * A fila dos lotes programados, em dialog_hsm_paced_message.
 *
 * Reserva (claim) por UPDATE com o token do worker: duas execuções sobrepostas nunca pegam a
 * mesma linha. Reserva de um worker que morreu expira depois de CLAIM_TTL_MINUTES.
 * Datas em UTC, comparadas com UTC_TIMESTAMP() no banco.
 */
class PacedQueue
{
    public const CLAIM_TTL_MINUTES = 10;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $payload mensagem sem a chave de API
     */
    public function enqueue(int $lotId, \DateTimeImmutable $dueAt, int $whatsAppNumberId, string $queueLogId, array $payload): void
    {
        $this->connection->insert($this->table(), [
            'lot_id'             => $lotId,
            'due_at'             => $dueAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'whatsapp_number_id' => $whatsAppNumberId,
            'queue_log_id'       => $queueLogId,
            'payload'            => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'attempts'           => 0,
            'date_added'         => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Reserva até $limit mensagens vencidas para o token e devolve as linhas reservadas.
     *
     * @return array<int, array{id: int|string, lot_id: int|string, whatsapp_number_id: int|string, queue_log_id: string, payload: string, attempts: int|string}>
     */
    public function claimDue(string $token, int $limit): array
    {
        $this->connection->executeStatement(
            "UPDATE `{$this->table()}`
             SET claimed_by = :token, claimed_at = UTC_TIMESTAMP()
             WHERE due_at <= UTC_TIMESTAMP()
               AND (claimed_by IS NULL OR claimed_at < UTC_TIMESTAMP() - INTERVAL ".self::CLAIM_TTL_MINUTES.' MINUTE)
             ORDER BY due_at
             LIMIT '.max(1, $limit),
            ['token' => $token]
        );

        return $this->connection->fetchAllAssociative(
            "SELECT id, lot_id, whatsapp_number_id, queue_log_id, payload, attempts
             FROM `{$this->table()}` WHERE claimed_by = :token ORDER BY due_at",
            ['token' => $token]
        );
    }

    /**
     * Segundos até a próxima mensagem vencer (0 = já há vencida), ou null se a fila está vazia.
     */
    public function secondsUntilNextDue(): ?int
    {
        $seconds = $this->connection->fetchOne(
            "SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), MIN(due_at)) FROM `{$this->table()}`
             WHERE claimed_by IS NULL OR claimed_at < UTC_TIMESTAMP() - INTERVAL ".self::CLAIM_TTL_MINUTES.' MINUTE'
        );

        return null === $seconds || false === $seconds ? null : max(0, (int) $seconds);
    }

    public function hasPending(): bool
    {
        return false !== $this->connection->fetchOne("SELECT 1 FROM `{$this->table()}` LIMIT 1");
    }

    public function delete(int $id): void
    {
        $this->connection->delete($this->table(), ['id' => $id]);
    }

    /**
     * Devolve a mensagem à fila para um novo horário, soltando a reserva.
     */
    public function postpone(int $id, \DateTimeImmutable $dueAt, bool $countAttempt): void
    {
        $this->connection->executeStatement(
            "UPDATE `{$this->table()}`
             SET due_at = :due, claimed_by = NULL, claimed_at = NULL".($countAttempt ? ', attempts = attempts + 1' : '').'
             WHERE id = :id',
            ['due' => $dueAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'id' => $id]
        );
    }

    /**
     * Solta as reservas que sobraram do token (ex.: worker encerrou no time-limit no meio do lote).
     */
    public function release(string $token): void
    {
        $this->connection->executeStatement(
            "UPDATE `{$this->table()}` SET claimed_by = NULL, claimed_at = NULL WHERE claimed_by = :token",
            ['token' => $token]
        );
    }

    private function table(): string
    {
        return (defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '').PacedMessage::TABLE;
    }
}

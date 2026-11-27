<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Mensagem agendada num lote programado. A tabela é a própria fila: o worker
 * (dialoghsm:consume --mode=paced) reserva as linhas vencidas, envia e apaga.
 *
 * payload guarda a mensagem SEM a chave de API, que é lida do número na hora do envio.
 * queue_log_id é o UUID gravado no wamid do log 'queued' (mesmo contrato da fila RabbitMQ):
 * apagar o log ("Limpar mensagens pendentes") cancela o envio.
 *
 * Mapeada só para o Mautic criar a tabela na instalação; o acesso é por SQL direto
 * (Pacing\PacedQueue).
 */
class PacedMessage
{
    public const TABLE = 'dialog_hsm_paced_message';

    private ?int $id = null;
    private ?int $lotId = null;
    private ?\DateTimeInterface $dueAt = null;
    private ?int $whatsappNumberId = null;
    private ?string $queueLogId = null;
    private ?string $payload = null;
    private int $attempts = 0;
    private ?string $claimedBy = null;
    private ?\DateTimeInterface $claimedAt = null;
    private ?\DateTimeInterface $dateAdded = null;

    /**
     * @param ClassMetadata<self> $metadata
     */
    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder
            ->setTable(self::TABLE)
            ->addIndex(['due_at'], 'paced_message_due_idx')
            ->addIndex(['lot_id'], 'paced_message_lot_idx')
            ->addIndex(['claimed_by'], 'paced_message_claimed_idx');

        $builder->addId();

        $builder->createField('lotId', Types::INTEGER)->columnName('lot_id')->build();
        $builder->createField('dueAt', Types::DATETIME_MUTABLE)->columnName('due_at')->build();
        $builder->createField('whatsappNumberId', Types::INTEGER)->columnName('whatsapp_number_id')->build();
        $builder->createField('queueLogId', Types::STRING)->columnName('queue_log_id')->length(64)->build();
        $builder->createField('payload', Types::TEXT)->columnName('payload')->build();
        $builder->createField('attempts', Types::INTEGER)->columnName('attempts')->build();
        $builder->createField('claimedBy', Types::STRING)->columnName('claimed_by')->length(32)->nullable()->build();
        $builder->createField('claimedAt', Types::DATETIME_MUTABLE)->columnName('claimed_at')->nullable()->build();
        $builder->createField('dateAdded', Types::DATETIME_MUTABLE)->columnName('date_added')->build();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

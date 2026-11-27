<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Um lote de um disparo "Batch — lotes programados": horário de saída (due_at, em UTC) e quantos
 * contatos já foram sorteados para ele (allocated, nunca passa de lot_size).
 *
 * Mapeada só para o Mautic criar a tabela na instalação do plugin. Leitura e escrita são feitas
 * por SQL direto (Pacing\PacedLotAllocator), porque a reserva de vaga precisa ser um UPDATE
 * atômico.
 */
class PacedLot
{
    public const TABLE = 'dialog_hsm_paced_lot';

    private ?int $id = null;
    private ?int $campaignId = null;
    private ?int $campaignEventId = null;
    private ?int $lotIndex = null;
    private ?int $lotSize = null;
    private ?int $lotInterval = null;
    private ?\DateTimeInterface $dueAt = null;
    private int $allocated = 0;
    private ?\DateTimeInterface $dateAdded = null;

    /**
     * @param ClassMetadata<self> $metadata
     */
    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder
            ->setTable(self::TABLE)
            ->addIndex(['campaign_event_id', 'due_at'], 'paced_lot_event_due_idx');

        $builder->addId();

        $builder->createField('campaignId', Types::INTEGER)->columnName('campaign_id')->nullable()->build();
        $builder->createField('campaignEventId', Types::INTEGER)->columnName('campaign_event_id')->build();
        $builder->createField('lotIndex', Types::INTEGER)->columnName('lot_index')->build();
        $builder->createField('lotSize', Types::INTEGER)->columnName('lot_size')->build();
        $builder->createField('lotInterval', Types::INTEGER)->columnName('lot_interval')->build();
        $builder->createField('dueAt', Types::DATETIME_MUTABLE)->columnName('due_at')->build();
        $builder->createField('allocated', Types::INTEGER)->columnName('allocated')->build();
        $builder->createField('dateAdded', Types::DATETIME_MUTABLE)->columnName('date_added')->build();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

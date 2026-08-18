<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Histórico de recargas (cargas) de saldo por número, detectado via o campo
 * last_renewal da 360dialog Partner API (Get Channel Balance).
 *
 * Uma linha nova só é gravada quando a DATA de recarga muda em relação à
 * última registrada para o número — não é um snapshot periódico (o cron
 * roda de hora em hora, mas isso não gera 1 linha/hora), só quando o
 * cliente de fato recarrega o saldo na 360dialog.
 */
class WhatsAppNumberBalanceHistory
{
    private ?int $id = null;
    private ?int $whatsAppNumberId = null;
    private ?\DateTimeInterface $rechargeDate = null;
    private ?float $rechargeAmount = null;
    private ?float $balanceAtSync = null;
    private ?string $currency = null;
    private ?\DateTimeInterface $dateAdded = null;

    /**
     * @param ClassMetadata<self> $metadata
     */
    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder
            ->setTable('dialog_hsm_number_balance_history')
            ->setCustomRepositoryClass(WhatsAppNumberBalanceHistoryRepository::class)
            ->addIndex(['whatsapp_number_id'], 'whatsapp_number_id_idx');

        $builder->addId();

        $builder
            ->createField('whatsAppNumberId', Types::INTEGER)
            ->columnName('whatsapp_number_id')
            ->build();

        $builder
            ->createField('rechargeDate', Types::DATETIME_MUTABLE)
            ->columnName('recharge_date')
            ->build();

        $builder
            ->createField('rechargeAmount', Types::FLOAT)
            ->columnName('recharge_amount')
            ->nullable()
            ->build();

        $builder
            ->createField('balanceAtSync', Types::FLOAT)
            ->columnName('balance_at_sync')
            ->nullable()
            ->build();

        $builder
            ->createField('currency', Types::STRING)
            ->columnName('currency')
            ->length(10)
            ->nullable()
            ->build();

        $builder
            ->createField('dateAdded', Types::DATETIME_MUTABLE)
            ->columnName('date_added')
            ->build();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWhatsAppNumberId(): ?int
    {
        return $this->whatsAppNumberId;
    }

    public function setWhatsAppNumberId(int $whatsAppNumberId): self
    {
        $this->whatsAppNumberId = $whatsAppNumberId;

        return $this;
    }

    public function getRechargeDate(): ?\DateTimeInterface
    {
        return $this->rechargeDate;
    }

    public function setRechargeDate(\DateTimeInterface $rechargeDate): self
    {
        $this->rechargeDate = $rechargeDate;

        return $this;
    }

    public function getRechargeAmount(): ?float
    {
        return $this->rechargeAmount;
    }

    public function setRechargeAmount(?float $rechargeAmount): self
    {
        $this->rechargeAmount = $rechargeAmount;

        return $this;
    }

    public function getBalanceAtSync(): ?float
    {
        return $this->balanceAtSync;
    }

    public function setBalanceAtSync(?float $balanceAtSync): self
    {
        $this->balanceAtSync = $balanceAtSync;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getDateAdded(): ?\DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function setDateAdded(\DateTimeInterface $dateAdded): self
    {
        $this->dateAdded = $dateAdded;

        return $this;
    }
}

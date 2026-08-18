<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\FormEntity;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Mapping\ClassMetadata;

class WhatsAppNumber extends FormEntity
{
    private ?int $id = null;
    private ?string $name = null;
    private ?string $phoneNumber = null;
    private ?string $apiKey = null;
    private ?string $baseUrl = null;
    private ?string $queueName = null;
    private ?string $batchQueueName = null;
    private ?string $clientId = null;
    private ?string $channelId = null;
    private ?float $balance = null;
    private ?string $balanceCurrency = null;
    private ?\DateTimeInterface $balanceUpdatedAt = null;
    private ?string $balanceAlertState = null;

    /** @var array<int, array{period_date: string, total_price: float}>|null */
    private ?array $balanceUsageSnapshot = null;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder
            ->setTable('dialog_hsm_numbers')
            ->setCustomRepositoryClass(WhatsAppNumberRepository::class);

        $builder->addIdColumns('name', null);

        $builder
            ->createField('phoneNumber', 'string')
            ->columnName('phone_number')
            ->length(50)
            ->build();

        $builder
            ->createField('apiKey', 'text')
            ->columnName('api_key')
            ->build();

        $builder
            ->createField('baseUrl', 'string')
            ->columnName('base_url')
            ->length(500)
            ->nullable()
            ->build();

        $builder
            ->createField('queueName', 'string')
            ->columnName('queue_name')
            ->length(100)
            ->nullable()
            ->build();

        $builder
            ->createField('batchQueueName', 'string')
            ->columnName('batch_queue_name')
            ->length(100)
            ->nullable()
            ->build();

        $builder
            ->createField('clientId', 'string')
            ->columnName('client_id')
            ->length(100)
            ->nullable()
            ->build();

        $builder
            ->createField('channelId', 'string')
            ->columnName('channel_id')
            ->length(100)
            ->nullable()
            ->build();

        $builder
            ->createField('balance', 'float')
            ->columnName('balance')
            ->nullable()
            ->build();

        $builder
            ->createField('balanceCurrency', 'string')
            ->columnName('balance_currency')
            ->length(10)
            ->nullable()
            ->build();

        $builder
            ->createField('balanceUpdatedAt', 'datetime')
            ->columnName('balance_updated_at')
            ->nullable()
            ->build();

        $builder
            ->createField('balanceAlertState', 'string')
            ->columnName('balance_alert_state')
            ->length(20)
            ->nullable()
            ->build();

        $builder
            ->createField('balanceUsageSnapshot', 'json')
            ->columnName('balance_usage_snapshot')
            ->nullable()
            ->build();
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $queueNameRegex = new Regex([
            'pattern' => '/^[a-zA-Z0-9._\-]+$/',
            'message' => 'dialoghsm.number.queue_name.invalid',
        ]);

        $metadata->addPropertyConstraint('name', new NotBlank(['message' => 'mautic.core.name.required']));
        $metadata->addPropertyConstraint('phoneNumber', new NotBlank(['message' => 'dialoghsm.number.phone.required']));
        $metadata->addPropertyConstraint('apiKey', new NotBlank(['message' => 'API Key is required.']));
        $metadata->addPropertyConstraint('apiKey', new Length([
            'min'        => 20,
            'minMessage' => 'API Key is too short (minimum 20 characters). Please check and re-enter the key.',
        ]));
        $metadata->addPropertyConstraint('queueName', $queueNameRegex);
        $metadata->addPropertyConstraint('batchQueueName', $queueNameRegex);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->isChanged('name', $name);
        $this->name = $name;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): self
    {
        $this->isChanged('phoneNumber', $phoneNumber);
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): self
    {
        $this->isChanged('apiKey', $apiKey);
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Define a API key diretamente sem disparar rastreamento de alterações (isChanged).
     * Uso exclusivo da camada de criptografia — não usar em código de negócio.
     *
     * @internal
     */
    public function setApiKeyRaw(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(?string $baseUrl): self
    {
        $this->isChanged('baseUrl', $baseUrl);
        $this->baseUrl = $baseUrl ?: null;

        return $this;
    }

    public function getQueueName(): ?string
    {
        return $this->queueName;
    }

    public function setQueueName(?string $queueName): self
    {
        $this->isChanged('queueName', $queueName);
        $this->queueName = $queueName ?: null;

        return $this;
    }

    public function getBatchQueueName(): ?string
    {
        return $this->batchQueueName;
    }

    public function setBatchQueueName(?string $batchQueueName): self
    {
        $this->isChanged('batchQueueName', $batchQueueName);
        $this->batchQueueName = $batchQueueName ?: null;

        return $this;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): self
    {
        $this->isChanged('clientId', $clientId);
        $this->clientId = $clientId ?: null;

        return $this;
    }

    public function getChannelId(): ?string
    {
        return $this->channelId;
    }

    public function setChannelId(?string $channelId): self
    {
        $this->isChanged('channelId', $channelId);
        $this->channelId = $channelId ?: null;

        return $this;
    }

    public function getBalance(): ?float
    {
        return $this->balance;
    }

    public function getBalanceCurrency(): ?string
    {
        return $this->balanceCurrency;
    }

    public function getBalanceUpdatedAt(): ?\DateTimeInterface
    {
        return $this->balanceUpdatedAt;
    }

    /**
     * Atualiza o saldo consultado via 360dialog Partner API.
     * Sem isChanged(): não é uma edição de usuário via formulário, é estado
     * de sistema atualizado por consulta em segundo plano (Controller/Command).
     */
    public function setBalanceInfo(?float $balance, ?string $currency, \DateTimeInterface $updatedAt): self
    {
        $this->balance          = $balance;
        $this->balanceCurrency  = $currency;
        $this->balanceUpdatedAt = $updatedAt;

        return $this;
    }

    public function getBalanceAlertState(): ?string
    {
        return $this->balanceAlertState;
    }

    /**
     * Último estado de alerta conhecido ('ok'|'low'|'depleted'), usado para
     * detectar TRANSIÇÕES de estado (ex.: low -> ok = "saldo recarregado").
     * Sem isChanged(): estado de sistema, não edição de usuário via formulário.
     */
    public function setBalanceAlertState(?string $state): self
    {
        $this->balanceAlertState = $state;

        return $this;
    }

    /**
     * @return array<int, array{period_date: string, total_price: float}>|null
     */
    public function getBalanceUsageSnapshot(): ?array
    {
        return $this->balanceUsageSnapshot;
    }

    /**
     * Snapshot do array usage[] (custo mensal) já retornado pela mesma
     * chamada de saldo — sem consulta extra à Partner API.
     * Sem isChanged(): estado de sistema, não edição de usuário via formulário.
     *
     * @param array<int, array{period_date: string, total_price: float}>|null $snapshot
     */
    public function setBalanceUsageSnapshot(?array $snapshot): self
    {
        $this->balanceUsageSnapshot = $snapshot;

        return $this;
    }

}

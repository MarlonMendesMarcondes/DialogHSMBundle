<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\FormEntity;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
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
    private ?string $webhookToken = null;

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
            ->createField('webhookToken', 'string')
            ->columnName('webhook_token')
            ->length(64)
            ->nullable()
            ->unique()
            ->build();
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addPropertyConstraint('name', new NotBlank(['message' => 'mautic.core.name.required']));
        $metadata->addPropertyConstraint('phoneNumber', new NotBlank(['message' => 'dialoghsm.number.phone.required']));
        $metadata->addPropertyConstraint('apiKey', new NotBlank(['message' => 'API Key is required.']));
        $metadata->addPropertyConstraint('apiKey', new Length([
            'min'        => 20,
            'minMessage' => 'API Key is too short (minimum 20 characters). Please check and re-enter the key.',
        ]));
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

    public function getWebhookToken(): ?string
    {
        return $this->webhookToken;
    }

    public function setWebhookToken(string $webhookToken): self
    {
        $this->webhookToken = $webhookToken;

        return $this;
    }
}

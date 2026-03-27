<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

class MessageLog
{
    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DLQ    = 'dlq';

    private ?int $id = null;
    private ?int $leadId = null;
    private ?int $campaignId = null;
    private ?int $campaignEventId = null;
    private ?string $senderName = null;
    private ?string $templateName = null;
    private ?string $phoneNumber = null;
    private ?string $status = null;
    private ?int $httpStatusCode = null;
    private ?string $apiResponse = null;
    private ?string $errorMessage = null;
    private ?\DateTimeInterface $dateSent = null;

    /**
     * @param ClassMetadata<self> $metadata
     */
    public static function loadMetadata(ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder
            ->setTable('dialog_hsm_message_log')
            ->setCustomRepositoryClass(MessageLogRepository::class)
            ->addIndex(['lead_id'], 'lead_id_idx')
            ->addIndex(['status'], 'status_idx')
            ->addIndex(['date_sent'], 'date_sent_idx');

        $builder->addId();

        $builder
            ->createField('leadId', Types::INTEGER)
            ->columnName('lead_id')
            ->build();

        $builder
            ->createField('campaignId', Types::INTEGER)
            ->columnName('campaign_id')
            ->nullable()
            ->build();

        $builder
            ->createField('campaignEventId', Types::INTEGER)
            ->columnName('campaign_event_id')
            ->nullable()
            ->build();

        $builder
            ->createField('senderName', Types::STRING)
            ->columnName('sender_name')
            ->length(255)
            ->nullable()
            ->build();

        $builder
            ->createField('templateName', Types::STRING)
            ->columnName('template_name')
            ->length(255)
            ->build();

        $builder
            ->createField('phoneNumber', Types::STRING)
            ->columnName('phone_number')
            ->length(50)
            ->build();

        $builder
            ->createField('status', Types::STRING)
            ->length(20)
            ->build();

        $builder
            ->createField('httpStatusCode', Types::INTEGER)
            ->columnName('http_status_code')
            ->nullable()
            ->build();

        $builder
            ->createField('apiResponse', Types::TEXT)
            ->columnName('api_response')
            ->nullable()
            ->build();

        $builder
            ->createField('errorMessage', Types::TEXT)
            ->columnName('error_message')
            ->nullable()
            ->build();

        $builder
            ->createField('dateSent', Types::DATETIME_MUTABLE)
            ->columnName('date_sent')
            ->build();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLeadId(): ?int
    {
        return $this->leadId;
    }

    public function setLeadId(int $leadId): self
    {
        $this->leadId = $leadId;

        return $this;
    }

    public function getCampaignId(): ?int
    {
        return $this->campaignId;
    }

    public function setCampaignId(?int $campaignId): self
    {
        $this->campaignId = $campaignId;

        return $this;
    }

    public function getCampaignEventId(): ?int
    {
        return $this->campaignEventId;
    }

    public function setCampaignEventId(?int $campaignEventId): self
    {
        $this->campaignEventId = $campaignEventId;

        return $this;
    }

    public function getSenderName(): ?string
    {
        return $this->senderName;
    }

    public function setSenderName(?string $senderName): self
    {
        $this->senderName = $senderName;

        return $this;
    }

    public function getTemplateName(): ?string
    {
        return $this->templateName;
    }

    public function setTemplateName(string $templateName): self
    {
        $this->templateName = $templateName;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(string $phoneNumber): self
    {
        $this->phoneNumber = $phoneNumber;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    public function setHttpStatusCode(?int $httpStatusCode): self
    {
        $this->httpStatusCode = $httpStatusCode;

        return $this;
    }

    public function getApiResponse(): ?string
    {
        return $this->apiResponse;
    }

    public function setApiResponse(?string $apiResponse): self
    {
        $this->apiResponse = $apiResponse;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getDateSent(): ?\DateTimeInterface
    {
        return $this->dateSent;
    }

    public function setDateSent(\DateTimeInterface $dateSent): self
    {
        $this->dateSent = $dateSent;

        return $this;
    }
}

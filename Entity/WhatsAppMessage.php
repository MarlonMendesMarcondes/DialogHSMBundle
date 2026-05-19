<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\FormEntity;
use Mautic\LeadBundle\Entity\LeadList;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Mapping\ClassMetadata;

class WhatsAppMessage extends FormEntity
{
    private ?int $id = null;
    private ?string $name = null;
    private ?WhatsAppNumber $whatsAppNumber = null;
    private string $templateName = '';
    private array $payloadData = [];
    private ?\DateTimeInterface $publishUp = null;
    private ?\DateTimeInterface $publishDown = null;

    /** @var ArrayCollection<int, LeadList> */
    private $lists;

    private int $sentCount = 0;
    private int $failedCount = 0;

    public function __construct()
    {
        $this->lists = new ArrayCollection();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder
            ->setTable('dialog_hsm_whatsapp_messages')
            ->setCustomRepositoryClass(WhatsAppMessageRepository::class);

        $builder->addIdColumns('name', null);

        $builder
            ->createManyToOne('whatsAppNumber', WhatsAppNumber::class)
            ->addJoinColumn('whatsapp_number_id', 'id', false, false, 'RESTRICT')
            ->build();

        $builder
            ->createField('templateName', Types::STRING)
            ->columnName('template_name')
            ->length(255)
            ->build();

        $builder
            ->createField('payloadData', Types::JSON)
            ->columnName('payload_data')
            ->nullable()
            ->build();

        $builder
            ->createField('publishUp', Types::DATETIME_MUTABLE)
            ->columnName('publish_up')
            ->nullable()
            ->build();

        $builder
            ->createField('publishDown', Types::DATETIME_MUTABLE)
            ->columnName('publish_down')
            ->nullable()
            ->build();

        $builder
            ->createManyToMany('lists', LeadList::class)
            ->setJoinTable('dialog_hsm_wa_msg_list_xref')
            ->setIndexBy('id')
            ->addInverseJoinColumn('leadlist_id', 'id', false, false, 'CASCADE')
            ->addJoinColumn('whatsapp_message_id', 'id', false, false, 'CASCADE')
            ->fetchExtraLazy()
            ->build();

        $builder
            ->createField('sentCount', Types::INTEGER)
            ->columnName('sent_count')
            ->build();

        $builder
            ->createField('failedCount', Types::INTEGER)
            ->columnName('failed_count')
            ->build();
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addPropertyConstraint('name', new NotBlank(['message' => 'mautic.core.name.required']));
        $metadata->addPropertyConstraint('whatsAppNumber', new NotBlank(['message' => 'dialoghsm.whatsapp_message.number.required']));
        $metadata->addPropertyConstraint('templateName', new NotBlank(['message' => 'dialoghsm.whatsapp_message.template_name.required']));
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

    public function getWhatsAppNumber(): ?WhatsAppNumber
    {
        return $this->whatsAppNumber;
    }

    public function setWhatsAppNumber(?WhatsAppNumber $whatsAppNumber): self
    {
        $this->isChanged('whatsAppNumber', $whatsAppNumber);
        $this->whatsAppNumber = $whatsAppNumber;

        return $this;
    }

    public function getTemplateName(): string
    {
        return $this->templateName;
    }

    public function setTemplateName(string $templateName): self
    {
        $this->isChanged('templateName', $templateName);
        $this->templateName = $templateName;

        return $this;
    }

    public function getPayloadData(): array
    {
        return $this->payloadData;
    }

    public function setPayloadData(array $payloadData): self
    {
        $this->isChanged('payloadData', $payloadData);
        $this->payloadData = $payloadData;

        return $this;
    }

    public function getPublishUp(): ?\DateTimeInterface
    {
        return $this->publishUp;
    }

    public function setPublishUp(?\DateTimeInterface $publishUp): self
    {
        $this->isChanged('publishUp', $publishUp);
        $this->publishUp = $publishUp;

        return $this;
    }

    public function getPublishDown(): ?\DateTimeInterface
    {
        return $this->publishDown;
    }

    public function setPublishDown(?\DateTimeInterface $publishDown): self
    {
        $this->isChanged('publishDown', $publishDown);
        $this->publishDown = $publishDown;

        return $this;
    }

    /**
     * @return ArrayCollection<int, LeadList>
     */
    public function getLists()
    {
        return $this->lists;
    }

    public function addList(LeadList $list): self
    {
        $this->lists[] = $list;

        return $this;
    }

    public function removeList(LeadList $list): self
    {
        $this->lists->removeElement($list);

        return $this;
    }

    public function getSentCount(): int
    {
        return $this->sentCount;
    }

    public function setSentCount(int $sentCount): self
    {
        $this->sentCount = $sentCount;

        return $this;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function setFailedCount(int $failedCount): self
    {
        $this->failedCount = $failedCount;

        return $this;
    }
}

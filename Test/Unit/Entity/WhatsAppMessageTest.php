<?php

declare(strict_types=1);

use Doctrine\ORM\Mapping\ClassMetadata as ORMClassMetadata;
use Mautic\LeadBundle\Entity\LeadList;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Mapping\ClassMetadata as ValidatorClassMetadata;

class WhatsAppMessageTest extends TestCase
{
    private function makeMessage(): WhatsAppMessage
    {
        return new WhatsAppMessage();
    }

    // =========================================================================
    // Valores padrão
    // =========================================================================

    public function testGetIdReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeMessage()->getId());
    }

    public function testGetNameReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeMessage()->getName());
    }

    public function testGetWhatsAppNumberReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeMessage()->getWhatsAppNumber());
    }

    public function testGetTemplateNameReturnsEmptyStringByDefault(): void
    {
        $this->assertSame('', $this->makeMessage()->getTemplateName());
    }

    public function testGetPayloadDataReturnsEmptyArrayByDefault(): void
    {
        $this->assertSame([], $this->makeMessage()->getPayloadData());
    }

    public function testGetPublishUpReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeMessage()->getPublishUp());
    }

    public function testGetPublishDownReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeMessage()->getPublishDown());
    }

    public function testGetSentCountReturnsZeroByDefault(): void
    {
        $this->assertSame(0, $this->makeMessage()->getSentCount());
    }

    public function testGetFailedCountReturnsZeroByDefault(): void
    {
        $this->assertSame(0, $this->makeMessage()->getFailedCount());
    }

    // =========================================================================
    // name
    // =========================================================================

    public function testSetNameStoresValue(): void
    {
        $message = $this->makeMessage()->setName('Campanha Natal');
        $this->assertSame('Campanha Natal', $message->getName());
    }

    public function testSetNameReturnsSelf(): void
    {
        $message = $this->makeMessage();
        $this->assertSame($message, $message->setName('Campanha Natal'));
    }

    public function testSetNameTracksChange(): void
    {
        $message = $this->makeMessage();
        $message->setName('Campanha Natal');
        $this->assertArrayHasKey('name', $message->getChanges());
    }

    public function testSetNameAcceptsNull(): void
    {
        $message = $this->makeMessage();
        $message->setName('Campanha');
        $message->setName(null);
        $this->assertNull($message->getName());
    }

    // =========================================================================
    // whatsAppNumber
    // =========================================================================

    public function testSetWhatsAppNumberStoresValue(): void
    {
        $number = new WhatsAppNumber();
        $message = $this->makeMessage()->setWhatsAppNumber($number);
        $this->assertSame($number, $message->getWhatsAppNumber());
    }

    public function testSetWhatsAppNumberReturnsSelf(): void
    {
        $message = $this->makeMessage();
        $number  = new WhatsAppNumber();
        $this->assertSame($message, $message->setWhatsAppNumber($number));
    }

    public function testSetWhatsAppNumberTracksChange(): void
    {
        $message = $this->makeMessage();
        $message->setWhatsAppNumber(new WhatsAppNumber());
        $this->assertArrayHasKey('whatsAppNumber', $message->getChanges());
    }

    public function testSetWhatsAppNumberAcceptsNull(): void
    {
        $message = $this->makeMessage();
        $message->setWhatsAppNumber(new WhatsAppNumber());
        $message->setWhatsAppNumber(null);
        $this->assertNull($message->getWhatsAppNumber());
    }

    // =========================================================================
    // templateName
    // =========================================================================

    public function testSetTemplateNameStoresValue(): void
    {
        $message = $this->makeMessage()->setTemplateName('template_boas_vindas');
        $this->assertSame('template_boas_vindas', $message->getTemplateName());
    }

    public function testSetTemplateNameReturnsSelf(): void
    {
        $message = $this->makeMessage();
        $this->assertSame($message, $message->setTemplateName('template_boas_vindas'));
    }

    public function testSetTemplateNameTracksChange(): void
    {
        $message = $this->makeMessage();
        $message->setTemplateName('template_boas_vindas');
        $this->assertArrayHasKey('templateName', $message->getChanges());
    }

    // =========================================================================
    // payloadData
    // =========================================================================

    public function testSetPayloadDataStoresValue(): void
    {
        $payload = ['key' => 'value', 'count' => 3];
        $message = $this->makeMessage()->setPayloadData($payload);
        $this->assertSame($payload, $message->getPayloadData());
    }

    public function testSetPayloadDataReturnsSelf(): void
    {
        $message = $this->makeMessage();
        $this->assertSame($message, $message->setPayloadData(['a' => 1]));
    }

    public function testSetPayloadDataTracksChange(): void
    {
        $message = $this->makeMessage();
        $message->setPayloadData(['a' => 1]);
        $this->assertArrayHasKey('payloadData', $message->getChanges());
    }

    public function testSetPayloadDataAcceptsEmptyArray(): void
    {
        $message = $this->makeMessage()->setPayloadData([]);
        $this->assertSame([], $message->getPayloadData());
    }

    // =========================================================================
    // publishUp
    // =========================================================================

    public function testSetPublishUpStoresValue(): void
    {
        $date    = new \DateTime('2026-01-01 10:00:00');
        $message = $this->makeMessage()->setPublishUp($date);
        $this->assertSame($date, $message->getPublishUp());
    }

    public function testSetPublishUpReturnsSelf(): void
    {
        $message = $this->makeMessage();
        $this->assertSame($message, $message->setPublishUp(new \DateTime()));
    }

    public function testSetPublishUpTracksChange(): void
    {
        $message = $this->makeMessage();
        $message->setPublishUp(new \DateTime('2026-01-01'));
        $this->assertArrayHasKey('publishUp', $message->getChanges());
    }

    public function testSetPublishUpAcceptsNull(): void
    {
        $message = $this->makeMessage();
        $message->setPublishUp(new \DateTime());
        $message->setPublishUp(null);
        $this->assertNull($message->getPublishUp());
    }

    // =========================================================================
    // publishDown
    // =========================================================================

    public function testSetPublishDownStoresValue(): void
    {
        $date    = new \DateTime('2026-12-31 23:59:59');
        $message = $this->makeMessage()->setPublishDown($date);
        $this->assertSame($date, $message->getPublishDown());
    }

    public function testSetPublishDownReturnsSelf(): void
    {
        $message = $this->makeMessage();
        $this->assertSame($message, $message->setPublishDown(new \DateTime()));
    }

    public function testSetPublishDownTracksChange(): void
    {
        $message = $this->makeMessage();
        $message->setPublishDown(new \DateTime('2026-12-31'));
        $this->assertArrayHasKey('publishDown', $message->getChanges());
    }

    public function testSetPublishDownAcceptsNull(): void
    {
        $message = $this->makeMessage();
        $message->setPublishDown(new \DateTime());
        $message->setPublishDown(null);
        $this->assertNull($message->getPublishDown());
    }

    // =========================================================================
    // sentCount — setter não chama isChanged(); sem teste de tracking
    // =========================================================================

    public function testSetSentCountStoresValue(): void
    {
        $message = $this->makeMessage()->setSentCount(42);
        $this->assertSame(42, $message->getSentCount());
    }

    public function testSetSentCountReturnsSelf(): void
    {
        $message = $this->makeMessage();
        $this->assertSame($message, $message->setSentCount(10));
    }

    // =========================================================================
    // failedCount — setter não chama isChanged(); sem teste de tracking
    // =========================================================================

    public function testSetFailedCountStoresValue(): void
    {
        $message = $this->makeMessage()->setFailedCount(7);
        $this->assertSame(7, $message->getFailedCount());
    }

    public function testSetFailedCountReturnsSelf(): void
    {
        $message = $this->makeMessage();
        $this->assertSame($message, $message->setFailedCount(3));
    }

    // =========================================================================
    // lists — addList / removeList
    // =========================================================================

    public function testConstructorInitializesEmptyListsCollection(): void
    {
        $message = $this->makeMessage();
        $this->assertCount(0, $message->getLists());
    }

    public function testAddListAppendsToCollection(): void
    {
        $message = $this->makeMessage();
        $list    = $this->createMock(LeadList::class);

        $message->addList($list);

        $this->assertCount(1, $message->getLists());
    }

    public function testAddListReturnsSelf(): void
    {
        $message = $this->makeMessage();
        $list    = $this->createMock(LeadList::class);

        $this->assertSame($message, $message->addList($list));
    }

    public function testAddListMultipleItemsIncreasesCount(): void
    {
        $message = $this->makeMessage();

        $message->addList($this->createMock(LeadList::class));
        $message->addList($this->createMock(LeadList::class));

        $this->assertCount(2, $message->getLists());
    }

    public function testRemoveListRemovesElement(): void
    {
        $message = $this->makeMessage();
        $list    = $this->createMock(LeadList::class);

        $message->addList($list);
        $message->removeList($list);

        $this->assertCount(0, $message->getLists());
    }

    public function testRemoveListReturnsSelf(): void
    {
        $message = $this->makeMessage();
        $list    = $this->createMock(LeadList::class);

        $message->addList($list);

        $this->assertSame($message, $message->removeList($list));
    }

    public function testRemoveListDoesNotAffectOtherElements(): void
    {
        $message = $this->makeMessage();
        $list1   = $this->createMock(LeadList::class);
        $list2   = $this->createMock(LeadList::class);

        $message->addList($list1);
        $message->addList($list2);
        $message->removeList($list1);

        $this->assertCount(1, $message->getLists());
        $this->assertTrue($message->getLists()->contains($list2));
    }

    // =========================================================================
    // loadValidatorMetadata
    // =========================================================================

    public function testLoadValidatorMetadataAddsNotBlankToName(): void
    {
        $constraints = [];

        $metadata = $this->createMock(ValidatorClassMetadata::class);
        $metadata
            ->method('addPropertyConstraint')
            ->willReturnCallback(function (string $property, $constraint) use (&$constraints): void {
                $constraints[$property][] = $constraint;
            });

        WhatsAppMessage::loadValidatorMetadata($metadata);

        $this->assertArrayHasKey('name', $constraints);
        $this->assertInstanceOf(NotBlank::class, $constraints['name'][0]);
        $this->assertSame('mautic.core.name.required', $constraints['name'][0]->message);
    }

    public function testLoadValidatorMetadataAddsNotBlankToWhatsAppNumber(): void
    {
        $constraints = [];

        $metadata = $this->createMock(ValidatorClassMetadata::class);
        $metadata
            ->method('addPropertyConstraint')
            ->willReturnCallback(function (string $property, $constraint) use (&$constraints): void {
                $constraints[$property][] = $constraint;
            });

        WhatsAppMessage::loadValidatorMetadata($metadata);

        $this->assertArrayHasKey('whatsAppNumber', $constraints);
        $this->assertInstanceOf(NotBlank::class, $constraints['whatsAppNumber'][0]);
        $this->assertSame('dialoghsm.whatsapp_message.number.required', $constraints['whatsAppNumber'][0]->message);
    }

    public function testLoadValidatorMetadataAddsNotBlankToTemplateName(): void
    {
        $constraints = [];

        $metadata = $this->createMock(ValidatorClassMetadata::class);
        $metadata
            ->method('addPropertyConstraint')
            ->willReturnCallback(function (string $property, $constraint) use (&$constraints): void {
                $constraints[$property][] = $constraint;
            });

        WhatsAppMessage::loadValidatorMetadata($metadata);

        $this->assertArrayHasKey('templateName', $constraints);
        $this->assertInstanceOf(NotBlank::class, $constraints['templateName'][0]);
        $this->assertSame('dialoghsm.whatsapp_message.template_name.required', $constraints['templateName'][0]->message);
    }

    public function testLoadValidatorMetadataRegistersExactlyThreeConstraints(): void
    {
        $count = 0;

        $metadata = $this->createMock(ValidatorClassMetadata::class);
        $metadata
            ->method('addPropertyConstraint')
            ->willReturnCallback(function () use (&$count): void {
                ++$count;
            });

        WhatsAppMessage::loadValidatorMetadata($metadata);

        $this->assertSame(3, $count);
    }

    // =========================================================================
    // loadMetadata
    // =========================================================================

    public function testLoadMetadataRunsWithoutException(): void
    {
        $classMetadata = new ORMClassMetadata(WhatsAppMessage::class);

        $this->expectNotToPerformAssertions();
        WhatsAppMessage::loadMetadata($classMetadata);
    }
}

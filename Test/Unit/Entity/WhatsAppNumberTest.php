<?php

declare(strict_types=1);

use Doctrine\ORM\Mapping\ClassMetadata as ORMClassMetadata;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Mapping\ClassMetadata as ValidatorClassMetadata;

class WhatsAppNumberTest extends TestCase
{
    private function makeNumber(): WhatsAppNumber
    {
        return new WhatsAppNumber();
    }

    // =========================================================================
    // Getters e Setters
    // =========================================================================

    public function testGetIdReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getId());
    }

    public function testGetNameReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getName());
    }

    public function testSetNameStoresValue(): void
    {
        $number = $this->makeNumber()->setName('Vendas');
        $this->assertSame('Vendas', $number->getName());
    }

    public function testSetNameTracksChange(): void
    {
        $number = $this->makeNumber();
        $number->setName('Vendas');
        $changes = $number->getChanges();
        $this->assertArrayHasKey('name', $changes);
    }

    public function testSetNameReturnsSelf(): void
    {
        $number = $this->makeNumber();
        $this->assertSame($number, $number->setName('x'));
    }

    public function testGetPhoneNumberReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getPhoneNumber());
    }

    public function testSetPhoneNumberStoresValue(): void
    {
        $number = $this->makeNumber()->setPhoneNumber('+5511999999999');
        $this->assertSame('+5511999999999', $number->getPhoneNumber());
    }

    public function testSetPhoneNumberTracksChange(): void
    {
        $number = $this->makeNumber();
        $number->setPhoneNumber('+5511999999999');
        $this->assertArrayHasKey('phoneNumber', $number->getChanges());
    }

    public function testSetPhoneNumberReturnsSelf(): void
    {
        $number = $this->makeNumber();
        $this->assertSame($number, $number->setPhoneNumber('+5511999999999'));
    }

    public function testGetApiKeyReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getApiKey());
    }

    public function testSetApiKeyStoresValue(): void
    {
        $key = str_repeat('a', 32);
        $number = $this->makeNumber()->setApiKey($key);
        $this->assertSame($key, $number->getApiKey());
    }

    public function testSetApiKeyTracksChange(): void
    {
        $number = $this->makeNumber();
        $number->setApiKey('api_key_123456789012345');
        $this->assertArrayHasKey('apiKey', $number->getChanges());
    }

    public function testSetApiKeyReturnsSelf(): void
    {
        $number = $this->makeNumber();
        $this->assertSame($number, $number->setApiKey('key'));
    }

    public function testSetApiKeyRawStoresValue(): void
    {
        $number = $this->makeNumber();
        $number->setApiKeyRaw('ENC:base64|iv');
        $this->assertSame('ENC:base64|iv', $number->getApiKey());
    }

    public function testSetApiKeyRawDoesNotTrackChange(): void
    {
        $number = $this->makeNumber();
        $number->setApiKeyRaw('ENC:base64|iv');
        $this->assertArrayNotHasKey('apiKey', $number->getChanges());
    }

    public function testSetApiKeyRawAcceptsNull(): void
    {
        $number = $this->makeNumber();
        $number->setApiKey('some-key');
        $number->setApiKeyRaw(null);
        $this->assertNull($number->getApiKey());
    }

    public function testGetBaseUrlReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getBaseUrl());
    }

    public function testSetBaseUrlStoresValue(): void
    {
        $number = $this->makeNumber()->setBaseUrl('https://api.example.com');
        $this->assertSame('https://api.example.com', $number->getBaseUrl());
    }

    public function testSetBaseUrlConvertsEmptyStringToNull(): void
    {
        $number = $this->makeNumber()->setBaseUrl('');
        $this->assertNull($number->getBaseUrl());
    }

    public function testSetBaseUrlConvertsNullToNull(): void
    {
        $number = $this->makeNumber()->setBaseUrl(null);
        $this->assertNull($number->getBaseUrl());
    }

    public function testSetBaseUrlTracksChange(): void
    {
        $number = $this->makeNumber();
        $number->setBaseUrl('https://api.example.com');
        $this->assertArrayHasKey('baseUrl', $number->getChanges());
    }

    public function testSetBaseUrlReturnsSelf(): void
    {
        $number = $this->makeNumber();
        $this->assertSame($number, $number->setBaseUrl('https://api.example.com'));
    }

    public function testGetQueueNameReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getQueueName());
    }

    public function testSetQueueNameStoresValue(): void
    {
        $number = $this->makeNumber()->setQueueName('queue.vendas');
        $this->assertSame('queue.vendas', $number->getQueueName());
    }

    public function testSetQueueNameConvertsEmptyStringToNull(): void
    {
        $number = $this->makeNumber()->setQueueName('');
        $this->assertNull($number->getQueueName());
    }

    public function testSetQueueNameTracksChange(): void
    {
        $number = $this->makeNumber();
        $number->setQueueName('queue.vendas');
        $this->assertArrayHasKey('queueName', $number->getChanges());
    }

    public function testSetQueueNameReturnsSelf(): void
    {
        $number = $this->makeNumber();
        $this->assertSame($number, $number->setQueueName('queue.vendas'));
    }

    public function testGetBatchQueueNameReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getBatchQueueName());
    }

    public function testSetBatchQueueNameStoresValue(): void
    {
        $number = $this->makeNumber()->setBatchQueueName('batch.vendas');
        $this->assertSame('batch.vendas', $number->getBatchQueueName());
    }

    public function testSetBatchQueueNameConvertsEmptyStringToNull(): void
    {
        $number = $this->makeNumber()->setBatchQueueName('');
        $this->assertNull($number->getBatchQueueName());
    }

    public function testSetBatchQueueNameTracksChange(): void
    {
        $number = $this->makeNumber();
        $number->setBatchQueueName('batch.vendas');
        $this->assertArrayHasKey('batchQueueName', $number->getChanges());
    }

    public function testSetBatchQueueNameReturnsSelf(): void
    {
        $number = $this->makeNumber();
        $this->assertSame($number, $number->setBatchQueueName('batch.vendas'));
    }

    // =========================================================================
    // loadValidatorMetadata
    // =========================================================================

    public function testLoadValidatorMetadataAddsConstraintsForAllRequiredFields(): void
    {
        $constraints = [];

        $metadata = $this->createMock(ValidatorClassMetadata::class);
        $metadata
            ->method('addPropertyConstraint')
            ->willReturnCallback(function (string $property, $constraint) use (&$constraints): void {
                $constraints[$property][] = $constraint;
            });

        WhatsAppNumber::loadValidatorMetadata($metadata);

        $this->assertArrayHasKey('name', $constraints);
        $this->assertArrayHasKey('phoneNumber', $constraints);
        $this->assertArrayHasKey('apiKey', $constraints);

        // name e phoneNumber devem ter NotBlank
        $this->assertInstanceOf(NotBlank::class, $constraints['name'][0]);
        $this->assertInstanceOf(NotBlank::class, $constraints['phoneNumber'][0]);

        // apiKey deve ter NotBlank + Length
        $apiKeyConstraintTypes = array_map('get_class', $constraints['apiKey']);
        $this->assertContains(NotBlank::class, $apiKeyConstraintTypes);
        $this->assertContains(Length::class, $apiKeyConstraintTypes);
    }

    public function testLoadValidatorMetadataApiKeyLengthMin(): void
    {
        $lengthConstraint = null;

        $metadata = $this->createMock(ValidatorClassMetadata::class);
        $metadata
            ->method('addPropertyConstraint')
            ->willReturnCallback(function (string $property, $constraint) use (&$lengthConstraint): void {
                if ($property === 'apiKey' && $constraint instanceof Length) {
                    $lengthConstraint = $constraint;
                }
            });

        WhatsAppNumber::loadValidatorMetadata($metadata);

        $this->assertNotNull($lengthConstraint);
        $this->assertSame(20, $lengthConstraint->min);
    }

    // =========================================================================
    // loadMetadata — verifica que é chamado sem exceções
    // =========================================================================
    // webhookToken
    // =========================================================================

    public function testGetWebhookTokenReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getWebhookToken());
    }

    public function testSetWebhookTokenStoresValue(): void
    {
        $token  = bin2hex(random_bytes(32));
        $number = $this->makeNumber()->setWebhookToken($token);
        $this->assertSame($token, $number->getWebhookToken());
    }

    public function testSetWebhookTokenReturnsSelf(): void
    {
        $number = $this->makeNumber();
        $this->assertSame($number, $number->setWebhookToken('abc123'));
    }

    // =========================================================================

    public function testLoadMetadataRunsWithoutException(): void
    {
        // Testa que o método não lança exceções com um ClassMetadata real do Doctrine.
        // A cobertura dos valores de length (50, 500, 100) é garantida pela execução.
        $classMetadata = new ORMClassMetadata(WhatsAppNumber::class);

        $this->expectNotToPerformAssertions();
        WhatsAppNumber::loadMetadata($classMetadata);
    }
}

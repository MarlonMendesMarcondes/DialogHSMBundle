<?php

declare(strict_types=1);

use Doctrine\ORM\Mapping\ClassMetadata as ORMClassMetadata;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
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

    public function testGetClientIdReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getClientId());
    }

    public function testSetClientIdStoresValue(): void
    {
        $number = $this->makeNumber()->setClientId('client-abc');
        $this->assertSame('client-abc', $number->getClientId());
    }

    public function testSetClientIdConvertsEmptyStringToNull(): void
    {
        $number = $this->makeNumber()->setClientId('');
        $this->assertNull($number->getClientId());
    }

    public function testSetClientIdTracksChange(): void
    {
        $number = $this->makeNumber();
        $number->setClientId('client-abc');
        $this->assertArrayHasKey('clientId', $number->getChanges());
    }

    public function testGetChannelIdReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getChannelId());
    }

    public function testSetChannelIdStoresValue(): void
    {
        $number = $this->makeNumber()->setChannelId('channel-abc');
        $this->assertSame('channel-abc', $number->getChannelId());
    }

    public function testSetChannelIdConvertsEmptyStringToNull(): void
    {
        $number = $this->makeNumber()->setChannelId('');
        $this->assertNull($number->getChannelId());
    }

    public function testSetChannelIdTracksChange(): void
    {
        $number = $this->makeNumber();
        $number->setChannelId('channel-abc');
        $this->assertArrayHasKey('channelId', $number->getChanges());
    }

    // =========================================================================
    // Saldo (balance, balanceCurrency, balanceUpdatedAt)
    // =========================================================================

    public function testGetBalanceReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getBalance());
        $this->assertNull($this->makeNumber()->getBalanceCurrency());
        $this->assertNull($this->makeNumber()->getBalanceUpdatedAt());
    }

    public function testSetBalanceInfoStoresAllFields(): void
    {
        $number    = $this->makeNumber();
        $updatedAt = new \DateTime('2026-08-18 12:00:00');

        $number->setBalanceInfo(48.15, 'usd', $updatedAt);

        $this->assertSame(48.15, $number->getBalance());
        $this->assertSame('usd', $number->getBalanceCurrency());
        $this->assertSame($updatedAt, $number->getBalanceUpdatedAt());
    }

    public function testSetBalanceInfoDoesNotTrackChange(): void
    {
        $number = $this->makeNumber();
        $number->setBalanceInfo(48.15, 'usd', new \DateTime());

        $this->assertArrayNotHasKey('balance', $number->getChanges());
    }

    public function testSetBalanceInfoReturnsSelf(): void
    {
        $number = $this->makeNumber();
        $this->assertSame($number, $number->setBalanceInfo(1.0, 'usd', new \DateTime()));
    }

    public function testSetBalanceInfoAcceptsNullBalance(): void
    {
        $number = $this->makeNumber();
        $number->setBalanceInfo(null, null, new \DateTime());

        $this->assertNull($number->getBalance());
        $this->assertNull($number->getBalanceCurrency());
    }

    // =========================================================================
    // balanceAlertState
    // =========================================================================

    public function testGetBalanceAlertStateReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getBalanceAlertState());
    }

    public function testSetBalanceAlertStateStoresValue(): void
    {
        $number = $this->makeNumber()->setBalanceAlertState('low');
        $this->assertSame('low', $number->getBalanceAlertState());
    }

    public function testSetBalanceAlertStateDoesNotTrackChange(): void
    {
        $number = $this->makeNumber();
        $number->setBalanceAlertState('depleted');
        $this->assertArrayNotHasKey('balanceAlertState', $number->getChanges());
    }

    public function testSetBalanceAlertStateReturnsSelf(): void
    {
        $number = $this->makeNumber();
        $this->assertSame($number, $number->setBalanceAlertState('ok'));
    }

    // =========================================================================
    // balanceUsageSnapshot
    // =========================================================================

    public function testGetBalanceUsageSnapshotReturnsNullByDefault(): void
    {
        $this->assertNull($this->makeNumber()->getBalanceUsageSnapshot());
    }

    public function testSetBalanceUsageSnapshotStoresValue(): void
    {
        $snapshot = [['period_date' => '2026-05-01T00:00:00Z', 'total_price' => 57.9375]];
        $number   = $this->makeNumber()->setBalanceUsageSnapshot($snapshot);

        $this->assertSame($snapshot, $number->getBalanceUsageSnapshot());
    }

    public function testSetBalanceUsageSnapshotDoesNotTrackChange(): void
    {
        $number = $this->makeNumber();
        $number->setBalanceUsageSnapshot([['period_date' => '2026-05-01T00:00:00Z', 'total_price' => 1.0]]);
        $this->assertArrayNotHasKey('balanceUsageSnapshot', $number->getChanges());
    }

    public function testSetBalanceUsageSnapshotReturnsSelf(): void
    {
        $number = $this->makeNumber();
        $this->assertSame($number, $number->setBalanceUsageSnapshot(null));
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
            ->willReturnCallback(function (string $property, $constraint) use (&$constraints, $metadata) {
                $constraints[$property][] = $constraint;
                return $metadata;
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

    public function testLoadValidatorMetadataAddsRegexToQueueNames(): void
    {
        $constraints = [];

        $metadata = $this->createMock(ValidatorClassMetadata::class);
        $metadata
            ->method('addPropertyConstraint')
            ->willReturnCallback(function (string $property, $constraint) use (&$constraints, $metadata) {
                $constraints[$property][] = $constraint;
                return $metadata;
            });

        WhatsAppNumber::loadValidatorMetadata($metadata);

        $this->assertArrayHasKey('queueName', $constraints);
        $this->assertArrayHasKey('batchQueueName', $constraints);
        $this->assertInstanceOf(Regex::class, $constraints['queueName'][0]);
        $this->assertInstanceOf(Regex::class, $constraints['batchQueueName'][0]);
    }

    /**
     * @dataProvider validQueueNameProvider
     */
    public function testValidQueueNamesPassRegex(string $name): void
    {
        $constraints = [];

        $metadata = $this->createMock(ValidatorClassMetadata::class);
        $metadata
            ->method('addPropertyConstraint')
            ->willReturnCallback(function (string $property, $constraint) use (&$constraints, $metadata) {
                $constraints[$property][] = $constraint;
                return $metadata;
            });

        WhatsAppNumber::loadValidatorMetadata($metadata);

        /** @var Regex $regex */
        $regex = $constraints['queueName'][0];
        $this->assertMatchesRegularExpression($regex->pattern, $name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validQueueNameProvider(): array
    {
        return [
            'simple'         => ['queue'],
            'with-dot'       => ['queue.bulk'],
            'with-hyphen'    => ['queue-bulk'],
            'with-underscore'=> ['queue_bulk'],
            'alphanumeric'   => ['queue123'],
            'mixed'          => ['whatsapp.bulk-2024_v1'],
        ];
    }

    /**
     * @dataProvider invalidQueueNameProvider
     */
    public function testInvalidQueueNamesFailRegex(string $name): void
    {
        $constraints = [];

        $metadata = $this->createMock(ValidatorClassMetadata::class);
        $metadata
            ->method('addPropertyConstraint')
            ->willReturnCallback(function (string $property, $constraint) use (&$constraints, $metadata) {
                $constraints[$property][] = $constraint;
                return $metadata;
            });

        WhatsAppNumber::loadValidatorMetadata($metadata);

        /** @var Regex $regex */
        $regex = $constraints['queueName'][0];
        $this->assertDoesNotMatchRegularExpression($regex->pattern, $name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidQueueNameProvider(): array
    {
        return [
            'space'          => ['queue bulk'],
            'semicolon'      => ['queue;bulk'],
            'ampersand'      => ['queue&bulk'],
            'pipe'           => ['queue|bulk'],
            'backtick'       => ['queue`bulk'],
            'dollar'         => ['queue$bulk'],
            'parenthesis'    => ['queue(bulk)'],
            'slash'          => ['queue/bulk'],
            'backslash'      => ['queue\\bulk'],
            'quote'          => ["queue'bulk"],
            'double-quote'   => ['queue"bulk'],
        ];
    }

    public function testLoadValidatorMetadataApiKeyLengthMin(): void
    {
        $lengthConstraint = null;

        $metadata = $this->createMock(ValidatorClassMetadata::class);
        $metadata
            ->method('addPropertyConstraint')
            ->willReturnCallback(function (string $property, $constraint) use (&$lengthConstraint, $metadata) {
                if ($property === 'apiKey' && $constraint instanceof Length) {
                    $lengthConstraint = $constraint;
                }
                return $metadata;
            });

        WhatsAppNumber::loadValidatorMetadata($metadata);

        $this->assertNotNull($lengthConstraint);
        $this->assertSame(20, $lengthConstraint->min);
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

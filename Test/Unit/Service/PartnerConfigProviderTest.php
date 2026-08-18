<?php

declare(strict_types=1);

use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\DialogHSMBundle\Service\PartnerConfigProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PartnerConfigProviderTest extends TestCase
{
    private IntegrationsHelper&MockObject $integrationsHelper;

    protected function setUp(): void
    {
        $this->integrationsHelper = $this->createMock(IntegrationsHelper::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeIntegrationStub(array $apiKeys): object
    {
        $config = new class($apiKeys) {
            public function __construct(private array $apiKeys)
            {
            }

            public function getApiKeys(): array
            {
                return $this->apiKeys;
            }
        };

        return new class($config) {
            public function __construct(private $config)
            {
            }

            public function getIntegrationConfiguration(): object
            {
                return $this->config;
            }
        };
    }

    private function makeProvider(array $apiKeys): PartnerConfigProvider
    {
        $this->integrationsHelper
            ->method('getIntegration')
            ->with(DialogHSMIntegration::NAME)
            ->willReturn($this->makeIntegrationStub($apiKeys));

        return new PartnerConfigProvider($this->integrationsHelper);
    }

    // =========================================================================
    // getPartnerId / getPartnerApiKey
    // =========================================================================

    public function testGetPartnerIdReturnsConfiguredValue(): void
    {
        $provider = $this->makeProvider(['partner_id' => 'PARTNER_123']);

        $this->assertSame('PARTNER_123', $provider->getPartnerId());
    }

    public function testGetPartnerApiKeyReturnsConfiguredValue(): void
    {
        $provider = $this->makeProvider(['partner_api_key' => 'secret-key']);

        $this->assertSame('secret-key', $provider->getPartnerApiKey());
    }

    public function testGetPartnerIdReturnsNullWhenAbsent(): void
    {
        $provider = $this->makeProvider([]);

        $this->assertNull($provider->getPartnerId());
    }

    public function testGetPartnerIdReturnsNullWhenEmptyString(): void
    {
        $provider = $this->makeProvider(['partner_id' => '']);

        $this->assertNull($provider->getPartnerId());
    }

    public function testGetPartnerIdReturnsNullWhenIntegrationThrows(): void
    {
        $this->integrationsHelper
            ->method('getIntegration')
            ->willThrowException(new \Exception('Integration not found'));

        $provider = new PartnerConfigProvider($this->integrationsHelper);

        $this->assertNull($provider->getPartnerId());
        $this->assertNull($provider->getPartnerApiKey());
    }

    public function testGetPartnerIdReturnsNullWhenApiKeysIsNull(): void
    {
        $config = new class {
            public function getApiKeys(): ?array
            {
                return null;
            }
        };
        $integration = new class($config) {
            public function __construct(private $config)
            {
            }

            public function getIntegrationConfiguration(): object
            {
                return $this->config;
            }
        };

        $this->integrationsHelper->method('getIntegration')->willReturn($integration);

        $provider = new PartnerConfigProvider($this->integrationsHelper);

        $this->assertNull($provider->getPartnerId());
    }

    // =========================================================================
    // getBalanceAlertThreshold
    // =========================================================================

    public function testGetBalanceAlertThresholdReturnsConfiguredFloat(): void
    {
        $provider = $this->makeProvider(['balance_alert_threshold' => '15.5']);

        $this->assertSame(15.5, $provider->getBalanceAlertThreshold());
    }

    public function testGetBalanceAlertThresholdReturnsNullWhenAbsent(): void
    {
        $provider = $this->makeProvider([]);

        $this->assertNull($provider->getBalanceAlertThreshold());
    }

    public function testGetBalanceAlertThresholdReturnsNullWhenEmptyString(): void
    {
        $provider = $this->makeProvider(['balance_alert_threshold' => '']);

        $this->assertNull($provider->getBalanceAlertThreshold());
    }

    public function testGetBalanceAlertThresholdHandlesZero(): void
    {
        $provider = $this->makeProvider(['balance_alert_threshold' => '0']);

        $this->assertSame(0.0, $provider->getBalanceAlertThreshold());
    }

    // =========================================================================
    // getBalanceAlertRecipientIds
    // =========================================================================

    public function testGetBalanceAlertRecipientIdsReturnsConfiguredIds(): void
    {
        $provider = $this->makeProvider(['balance_alert_recipients' => ['5', '9']]);

        $this->assertSame([5, 9], $provider->getBalanceAlertRecipientIds());
    }

    public function testGetBalanceAlertRecipientIdsReturnsEmptyArrayWhenAbsent(): void
    {
        $provider = $this->makeProvider([]);

        $this->assertSame([], $provider->getBalanceAlertRecipientIds());
    }

    public function testGetBalanceAlertRecipientIdsReturnsEmptyArrayWhenNotArray(): void
    {
        $provider = $this->makeProvider(['balance_alert_recipients' => 'not-an-array']);

        $this->assertSame([], $provider->getBalanceAlertRecipientIds());
    }

    // =========================================================================
    // getBalanceAlertSendEmail
    // =========================================================================

    public function testGetBalanceAlertSendEmailReturnsTrueWhenEnabled(): void
    {
        $provider = $this->makeProvider(['balance_alert_send_email' => true]);

        $this->assertTrue($provider->getBalanceAlertSendEmail());
    }

    public function testGetBalanceAlertSendEmailReturnsFalseByDefault(): void
    {
        $provider = $this->makeProvider([]);

        $this->assertFalse($provider->getBalanceAlertSendEmail());
    }
}

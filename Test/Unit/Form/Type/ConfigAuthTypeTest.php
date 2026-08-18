<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Form\Type\ConfigAuthType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfigAuthTypeTest extends TestCase
{
    private ConfigAuthType $type;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $this->type = new ConfigAuthType($translator);
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

    /**
     * @return array<string, array<string, mixed>> field name => options passed to $builder->add()
     */
    private function buildFormAndCaptureFields(array $apiKeys): array
    {
        $captured = [];

        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(
            function (mixed $field, mixed $type = null, array $options = []) use (&$captured, $builder): FormBuilderInterface {
                if (is_string($field)) {
                    $captured[$field] = $options;
                }

                return $builder;
            }
        );

        $this->type->buildForm($builder, ['integration' => $this->makeIntegrationStub($apiKeys)]);

        return $captured;
    }

    // =========================================================================
    // balance_alert_threshold
    // =========================================================================

    public function testBalanceAlertThresholdDefaultsToTenWhenNotConfigured(): void
    {
        $fields = $this->buildFormAndCaptureFields([]);

        $this->assertSame(10.0, $fields['balance_alert_threshold']['data']);
    }

    public function testBalanceAlertThresholdUsesConfiguredValue(): void
    {
        $fields = $this->buildFormAndCaptureFields(['balance_alert_threshold' => '25']);

        $this->assertSame(25.0, $fields['balance_alert_threshold']['data']);
    }

    // =========================================================================
    // partner_id / partner_api_key
    // =========================================================================

    public function testPartnerIdDefaultsToEmptyString(): void
    {
        $fields = $this->buildFormAndCaptureFields([]);

        $this->assertSame('', $fields['partner_id']['data']);
    }

    public function testPartnerIdUsesConfiguredValue(): void
    {
        $fields = $this->buildFormAndCaptureFields(['partner_id' => 'PARTNER_123']);

        $this->assertSame('PARTNER_123', $fields['partner_id']['data']);
    }

    public function testPartnerApiKeyHelpShowsWarningBadgeWhenNotConfigured(): void
    {
        $fields = $this->buildFormAndCaptureFields([]);

        $this->assertStringContainsString('label-warning', $fields['partner_api_key']['help']);
        $this->assertTrue($fields['partner_api_key']['help_html']);
    }

    public function testPartnerApiKeyHelpShowsSuccessBadgeWhenConfigured(): void
    {
        $fields = $this->buildFormAndCaptureFields(['partner_api_key' => 'secret']);

        $this->assertStringContainsString('label-success', $fields['partner_api_key']['help']);
    }

    public function testPartnerApiKeyAlwaysEmptyIsFalse(): void
    {
        $fields = $this->buildFormAndCaptureFields(['partner_api_key' => 'secret']);

        // always_empty=false é o que faz o PasswordType exibir o valor atual
        // (em vez de sempre renderizar em branco) — precisa disso pro badge fazer sentido.
        $this->assertFalse($fields['partner_api_key']['always_empty']);
    }

    // =========================================================================
    // balance_alert_recipients / balance_alert_send_email
    // =========================================================================

    public function testBalanceAlertRecipientsDefaultsToEmptyArray(): void
    {
        $fields = $this->buildFormAndCaptureFields([]);

        $this->assertSame([], $fields['balance_alert_recipients']['data']);
    }

    public function testBalanceAlertRecipientsUsesConfiguredValue(): void
    {
        $fields = $this->buildFormAndCaptureFields(['balance_alert_recipients' => [5, 9]]);

        $this->assertSame([5, 9], $fields['balance_alert_recipients']['data']);
    }

    public function testBalanceAlertSendEmailDefaultsToFalse(): void
    {
        $fields = $this->buildFormAndCaptureFields([]);

        $this->assertFalse($fields['balance_alert_send_email']['data']);
    }

    public function testBalanceAlertSendEmailUsesConfiguredValue(): void
    {
        $fields = $this->buildFormAndCaptureFields(['balance_alert_send_email' => true]);

        $this->assertTrue($fields['balance_alert_send_email']['data']);
    }

    // =========================================================================
    // Metadados do form
    // =========================================================================

    public function testGetBlockPrefixReturnsCorrectValue(): void
    {
        $this->assertSame('dialoghsm_config_auth', $this->type->getBlockPrefix());
    }

    public function testAllExpectedFieldsAreAdded(): void
    {
        $fields = $this->buildFormAndCaptureFields([]);

        foreach ([
            'base_url', 'consumer_limit', 'batch_consumer_limit',
            'bulk_rate_per_minute', 'batch_rate_per_minute',
            'log_max_records', 'log_max_days',
            'balance_alert_threshold', 'balance_alert_recipients', 'balance_alert_send_email',
            'partner_id', 'partner_api_key',
        ] as $field) {
            $this->assertArrayHasKey($field, $fields, "Campo '{$field}' não foi adicionado ao form");
        }
    }
}

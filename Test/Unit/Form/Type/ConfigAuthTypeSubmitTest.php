<?php

declare(strict_types=1);

use Mautic\CoreBundle\Form\Type\ButtonGroupType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\UserBundle\Entity\UserRepository;
use Mautic\UserBundle\Form\Type\UserListType;
use Mautic\UserBundle\Model\UserModel;
use MauticPlugin\DialogHSMBundle\Form\Type\ConfigAuthType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Testa o PRE_SUBMIT de verdade através de um pipeline real do Symfony Form
 * (submit() de ponta a ponta), diferente de ConfigAuthTypeTest.php (que só
 * captura as chamadas a $builder->add() com um builder mockado — não exercita
 * event listeners).
 */
class ConfigAuthTypeSubmitTest extends TestCase
{
    /**
     * @param array<string, mixed> $apiKeys
     */
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

    private function buildFormFactory(): FormFactoryInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key) => $key);

        $userModel = $this->createMock(UserModel::class);
        $userRepo  = $this->createMock(UserRepository::class);
        $userRepo->method('getEntities')->willReturn([]);
        $userModel->method('getRepository')->willReturn($userRepo);

        return Forms::createFormFactoryBuilder()
            ->addType(new ConfigAuthType($translator))
            ->addType(new YesNoButtonGroupType())
            ->addType(new ButtonGroupType())
            ->addType(new UserListType($userModel))
            ->getFormFactory();
    }

    /**
     * @return array<string, mixed>
     */
    private function baseSubmittedData(): array
    {
        return [
            'base_url'                 => 'https://waba-v2.360dialog.io/messages',
            'consumer_limit'            => '50',
            'batch_consumer_limit'      => '100',
            'bulk_rate_per_minute'      => '0',
            'batch_rate_per_minute'     => '0',
            'log_max_records'           => '0',
            'log_max_days'              => '30',
            'balance_alert_threshold'   => '10',
            'balance_alert_recipients'  => [],
            'balance_alert_send_email'  => '0',
            'partner_id'                => 'PARTNER1',
        ];
    }

    public function testSubmittingEmptyPartnerApiKeyKeepsExistingValue(): void
    {
        $integration = $this->makeIntegrationStub([
            'partner_id'      => 'PARTNER1',
            'partner_api_key' => 'super-secret-key',
        ]);

        $form = $this->buildFormFactory()->create(ConfigAuthType::class, null, ['integration' => $integration]);

        $submitted                    = $this->baseSubmittedData();
        $submitted['partner_api_key'] = '';

        $form->submit($submitted);

        $this->assertTrue($form->isSynchronized(), implode('; ', array_map(
            static fn ($e) => $e->getMessage(),
            iterator_to_array($form->getErrors(true))
        )));
        $this->assertSame('super-secret-key', $form->get('partner_api_key')->getData());
    }

    public function testSubmittingNonEmptyPartnerApiKeyOverridesExistingValue(): void
    {
        $integration = $this->makeIntegrationStub([
            'partner_id'      => 'PARTNER1',
            'partner_api_key' => 'old-key',
        ]);

        $form = $this->buildFormFactory()->create(ConfigAuthType::class, null, ['integration' => $integration]);

        $submitted                    = $this->baseSubmittedData();
        $submitted['partner_api_key'] = 'brand-new-key';

        $form->submit($submitted);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame('brand-new-key', $form->get('partner_api_key')->getData());
    }

    public function testSubmittingEmptyWhenNoPreviousKeyStaysEmpty(): void
    {
        $integration = $this->makeIntegrationStub([
            'partner_id' => 'PARTNER1',
        ]);

        $form = $this->buildFormFactory()->create(ConfigAuthType::class, null, ['integration' => $integration]);

        $submitted                    = $this->baseSubmittedData();
        $submitted['partner_api_key'] = '';

        $form->submit($submitted);

        $this->assertTrue($form->isSynchronized());
        // PasswordType sem valor submetido resolve para null (empty_data padrão
        // do Symfony), não string vazia — aqui só confirmamos que o listener
        // não inventa um valor do nada quando nunca houve chave configurada.
        $this->assertNull($form->get('partner_api_key')->getData());
    }
}

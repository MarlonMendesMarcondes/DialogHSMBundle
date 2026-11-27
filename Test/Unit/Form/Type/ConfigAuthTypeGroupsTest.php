<?php

declare(strict_types=1);

use MauticPlugin\DialogHSMBundle\Form\Type\ConfigAuthType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Abas da configuração do plugin: todo campo tem grupo e a tela recebe grupos e histórico.
 */
class ConfigAuthTypeGroupsTest extends TestCase
{
    private function type(): ConfigAuthType
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ConfigAuthType($translator);
    }

    /**
     * @return string[]
     */
    private function fieldNames(): array
    {
        $names   = [];
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (mixed $name) use (&$names, $builder): FormBuilderInterface {
            $names[] = $name;

            return $builder;
        });
        $builder->method('addEventListener')->willReturn($builder);

        $integration = new class {
            public function getIntegrationConfiguration(): object
            {
                return new class {
                    public function getApiKeys(): array
                    {
                        return [];
                    }
                };
            }
        };

        $this->type()->buildForm($builder, ['integration' => $integration]);

        return $names;
    }

    public function testEveryFieldBelongsToExactlyOneGroup(): void
    {
        $grouped = array_merge(...array_column(ConfigAuthType::GROUPS, 'fields'));

        $this->assertSame([], array_values(array_diff($this->fieldNames(), $grouped)), 'campo novo sem grupo em ConfigAuthType::GROUPS');
        $this->assertSame([], array_values(array_diff($grouped, $this->fieldNames())), 'grupo cita campo que não existe');
        $this->assertSame(count($grouped), count(array_unique($grouped)), 'campo em dois grupos');
    }

    public function testWideFieldsAreFieldsOfTheirGroup(): void
    {
        foreach (ConfigAuthType::GROUPS as $key => $group) {
            $this->assertSame([], array_values(array_diff($group['wide'], $group['fields'])), $key);
        }
    }

    public function testConnectionIsTheFirstGroupBecauseItStaysOnTheCoreTab(): void
    {
        $this->assertSame('connection', array_key_first(ConfigAuthType::GROUPS));
    }

    public function testViewReceivesTheGroupsAndTheChangelog(): void
    {
        $view = new FormView();
        $this->type()->buildView($view, $this->createMock(FormInterface::class), []);

        $this->assertSame(ConfigAuthType::GROUPS, $view->vars['dialoghsm_groups']);
        $this->assertNotEmpty($view->vars['dialoghsm_changelog'], 'CHANGELOG.md do plugin lido');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $view->vars['dialoghsm_changelog'][0]['date']);
    }
}

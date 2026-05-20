<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppMessage;
use MauticPlugin\DialogHSMBundle\Form\Type\WhatsAppMessageType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WhatsAppMessageTypeTest extends TestCase
{
    private WhatsAppMessageType $type;

    protected function setUp(): void
    {
        $this->type = new WhatsAppMessageType(
            $this->createMock(EntityManagerInterface::class)
        );
    }

    public function testGetBlockPrefixReturnsCorrectValue(): void
    {
        $this->assertSame('dialoghsm_whatsapp_message', $this->type->getBlockPrefix());
    }

    public function testConfigureOptionsBindsWhatsAppMessageDataClass(): void
    {
        $resolver = new OptionsResolver();
        $this->type->configureOptions($resolver);
        $options = $resolver->resolve([]);

        $this->assertSame(WhatsAppMessage::class, $options['data_class']);
    }

    public function testBuildFormAddsAllRequiredFields(): void
    {
        $addedFields  = [];
        $createdFields = [];

        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(
            function (mixed $field) use (&$addedFields, $builder): FormBuilderInterface {
                if (is_string($field)) {
                    $addedFields[] = $field;
                }

                return $builder;
            }
        );
        $builder->method('create')->willReturnCallback(
            function (string $field) use (&$createdFields, $builder): FormBuilderInterface {
                $createdFields[] = $field;

                return $builder;
            }
        );
        $builder->method('addModelTransformer')->willReturn($builder);
        $builder->method('addEventListener')->willReturn($builder);

        $this->type->buildForm($builder, ['data_class' => WhatsAppMessage::class]);

        $allFields = array_merge($addedFields, $createdFields);
        $expected  = ['name', 'whatsAppNumber', 'templateName', 'payloadData', 'lists', 'isPublished', 'publishUp', 'publishDown', 'buttons'];

        foreach ($expected as $field) {
            $this->assertContains($field, $allFields, "Field '{$field}' must be added to the form");
        }
    }

    public function testIsPublishedFieldIsPresent(): void
    {
        $addedFields = [];

        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(
            function (mixed $field) use (&$addedFields, $builder): FormBuilderInterface {
                if (is_string($field)) {
                    $addedFields[] = $field;
                }

                return $builder;
            }
        );
        $builder->method('create')->willReturn($builder);
        $builder->method('addEventListener')->willReturn($builder);
        $builder->method('addModelTransformer')->willReturn($builder);

        $this->type->buildForm($builder, ['data_class' => WhatsAppMessage::class]);

        $this->assertContains(
            'isPublished',
            $addedFields,
            'isPublished field must be present — required by the form template'
        );
    }

    public function testWhatsAppNumberFieldRegistersPostSetDataListener(): void
    {
        $listenerPriorities = [];

        $childBuilder = $this->createMock(FormBuilderInterface::class);
        $childBuilder->method('addEventListener')->willReturnCallback(
            function (string $event, callable $listener, int $priority = 0) use (&$listenerPriorities, $childBuilder): FormBuilderInterface {
                $listenerPriorities[$event][] = $priority;

                return $childBuilder;
            }
        );
        $childBuilder->method('addModelTransformer')->willReturn($childBuilder);

        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturn($builder);
        $builder->method('create')->willReturnCallback(
            function (string $field) use ($childBuilder, $builder): FormBuilderInterface {
                return 'whatsAppNumber' === $field ? $childBuilder : $builder;
            }
        );
        $builder->method('addModelTransformer')->willReturn($builder);
        $builder->method('addEventListener')->willReturn($builder);

        $this->type->buildForm($builder, ['data_class' => WhatsAppMessage::class]);

        $this->assertArrayHasKey(
            \Symfony\Component\Form\FormEvents::POST_SET_DATA,
            $listenerPriorities,
            'whatsAppNumber field must register a POST_SET_DATA listener'
        );
        $this->assertContains(
            100,
            $listenerPriorities[\Symfony\Component\Form\FormEvents::POST_SET_DATA],
            'The POST_SET_DATA listener must have priority 100 to run before EntityLookupChoiceLoader'
        );
    }

    public function testPublishUpDownFieldsUseCorrectTypes(): void
    {
        $fieldTypes = [];

        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(
            function (mixed $field, mixed $type = null) use (&$fieldTypes, $builder): FormBuilderInterface {
                if (is_string($field) && $type !== null) {
                    $fieldTypes[$field] = $type;
                }

                return $builder;
            }
        );
        $builder->method('create')->willReturn($builder);
        $builder->method('addEventListener')->willReturn($builder);

        $this->type->buildForm($builder, ['data_class' => WhatsAppMessage::class]);

        $this->assertSame(
            \Mautic\CoreBundle\Form\Type\PublishUpDateType::class,
            $fieldTypes['publishUp'] ?? null,
            'publishUp must use PublishUpDateType'
        );
        $this->assertSame(
            \Mautic\CoreBundle\Form\Type\PublishDownDateType::class,
            $fieldTypes['publishDown'] ?? null,
            'publishDown must use PublishDownDateType'
        );
    }
}

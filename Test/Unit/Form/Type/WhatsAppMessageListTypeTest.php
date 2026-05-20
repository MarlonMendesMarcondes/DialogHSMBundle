<?php

declare(strict_types=1);

use Mautic\CoreBundle\Form\Type\EntityLookupType;
use MauticPlugin\DialogHSMBundle\Form\Type\WhatsAppMessageListType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WhatsAppMessageListTypeTest extends TestCase
{
    private WhatsAppMessageListType $type;

    protected function setUp(): void
    {
        $this->type = new WhatsAppMessageListType();
    }

    public function testGetParentReturnsEntityLookupType(): void
    {
        $this->assertSame(EntityLookupType::class, $this->type->getParent());
    }

    public function testAjaxLookupActionUsesTraitMethod(): void
    {
        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'modal_route', 'modal_header', 'model', 'model_lookup_method',
            'lookup_arguments', 'ajax_lookup_action', 'multiple', 'required',
        ]);

        $this->type->configureOptions($resolver);
        $options = $resolver->resolve([]);

        $this->assertSame(
            'dialoghsm:getLookupChoiceList',
            $options['ajax_lookup_action'],
            'ajax_lookup_action must point to the AjaxLookupControllerTrait method'
        );
    }

    public function testModelKeyIsCorrect(): void
    {
        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'modal_route', 'modal_header', 'model', 'model_lookup_method',
            'lookup_arguments', 'ajax_lookup_action', 'multiple', 'required',
        ]);

        $this->type->configureOptions($resolver);
        $options = $resolver->resolve([]);

        $this->assertSame('dialoghsm.whatsappmessage', $options['model']);
    }

    public function testLookupArgumentsTypeIsStringKey(): void
    {
        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'modal_route', 'modal_header', 'model', 'model_lookup_method',
            'lookup_arguments', 'ajax_lookup_action', 'multiple', 'required',
        ]);

        $this->type->configureOptions($resolver);
        $options = $resolver->resolve([]);

        $this->assertIsString(
            $options['lookup_arguments']['type'],
            'lookup_arguments.type must be a string model key, not a class name'
        );
        $this->assertSame('dialoghsm.whatsappmessage', $options['lookup_arguments']['type']);
    }

    public function testModalRouteIsSet(): void
    {
        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'modal_route', 'modal_header', 'model', 'model_lookup_method',
            'lookup_arguments', 'ajax_lookup_action', 'multiple', 'required',
        ]);

        $this->type->configureOptions($resolver);
        $options = $resolver->resolve([]);

        $this->assertSame('mautic_dialoghsm_message_action', $options['modal_route']);
    }

    public function testMultipleIsFalseByDefault(): void
    {
        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'modal_route', 'modal_header', 'model', 'model_lookup_method',
            'lookup_arguments', 'ajax_lookup_action', 'multiple', 'required',
        ]);

        $this->type->configureOptions($resolver);
        $options = $resolver->resolve([]);

        $this->assertFalse($options['multiple']);
    }
}

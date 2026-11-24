<?php

declare(strict_types=1);

use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\DialogHSMBundle\Service\Variable\CustomObjectVariableSource;
use MauticPlugin\DialogHSMBundle\Service\Variable\TemplateVariableResolver;
use MauticPlugin\DialogHSMBundle\Twig\VariableSourcesExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class VariableSourcesExtensionTest extends TestCase
{
    private function extension(?CustomObjectVariableSource $customObjects = null): VariableSourcesExtension
    {
        $fields = $this->createMock(FieldModel::class);
        $fields->method('getFieldListWithProperties')->with('lead')->willReturn([
            'firstname' => ['alias' => 'firstname', 'label' => 'Primeiro nome', 'type' => 'text', 'isPublished' => true],
            'antigo'    => ['alias' => 'antigo', 'label' => 'Antigo', 'type' => 'text', 'isPublished' => false],
            'data_prova' => ['alias' => 'data_prova', 'label' => 'Data da prova', 'type' => 'date', 'isPublished' => true],
        ]);

        return new VariableSourcesExtension($fields, new TemplateVariableResolver($this->createMock(LeadModel::class), $customObjects), new NullLogger());
    }

    public function testListsPublishedContactFieldsSortedByLabel(): void
    {
        $sources = $this->extension()->sources();

        $this->assertSame([
            ['alias' => 'data_prova', 'label' => 'Data da prova', 'type' => 'date'],
            ['alias' => 'firstname', 'label' => 'Primeiro nome', 'type' => 'text'],
        ], $sources['contactFields']);
        $this->assertNull($sources['customObjects'], 'Sem o fork, a origem Custom Object não aparece.');
    }

    public function testListsCustomObjectsWhenTheForkIsInstalled(): void
    {
        if (!class_exists(\MauticPlugin\CustomObjectsBundle\Segment\SegmentItemResolver::class)) {
            $this->markTestSkipped('Fork do CustomObjectsBundle não instalado.');
        }

        $source = $this->createMock(CustomObjectVariableSource::class);
        $source->method('listObjects')->willReturn([['alias' => 'curso', 'label' => 'Cursos', 'fields' => []]]);

        $this->assertSame([['alias' => 'curso', 'label' => 'Cursos', 'fields' => []]], $this->extension($source)->sources()['customObjects']);
    }

    public function testFailureListingCustomObjectsKeepsTheForm(): void
    {
        if (!class_exists(\MauticPlugin\CustomObjectsBundle\Segment\SegmentItemResolver::class)) {
            $this->markTestSkipped('Fork do CustomObjectsBundle não instalado.');
        }

        $source = $this->createMock(CustomObjectVariableSource::class);
        $source->method('listObjects')->willThrowException(new \RuntimeException('banco fora'));

        $this->assertNull($this->extension($source)->sources()['customObjects']);
    }

    public function testRegistersTheTwigFunction(): void
    {
        $this->assertSame('dialoghsm_variable_sources', $this->extension()->getFunctions()[0]->getName());
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Twig;

use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\DialogHSMBundle\Service\Variable\TemplateVariableResolver;
use Psr\Log\LoggerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * dialoghsm_variable_sources(): o que o seletor de origem das variáveis do formulário guiado
 * oferece — os campos publicados do contato e, só com o fork do CustomObjectsBundle instalado, os
 * Custom Objects publicados com seus campos.
 *
 * Vai junto com o formulário (sem rota nova): a lista é pequena e já é necessária na abertura.
 */
class VariableSourcesExtension extends AbstractExtension
{
    public function __construct(
        private FieldModel $fieldModel,
        private TemplateVariableResolver $variableResolver,
        private LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('dialoghsm_variable_sources', [$this, 'sources'])];
    }

    /**
     * @return array{contactFields: list<array{alias: string, label: string, type: string}>, customObjects: list<array<string, mixed>>|null}
     */
    public function sources(): array
    {
        $contactFields = [];
        foreach ($this->fieldModel->getFieldListWithProperties('lead') as $field) {
            if (empty($field['isPublished'])) {
                continue;
            }
            $contactFields[] = ['alias' => (string) $field['alias'], 'label' => (string) $field['label'], 'type' => (string) $field['type']];
        }
        usort($contactFields, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        $customObjects = null;
        if (null !== ($source = $this->variableResolver->customObjects())) {
            try {
                $customObjects = $source->listObjects();
            } catch (\Throwable $e) {
                // Lista de Custom Objects é complementar: sem ela, o formulário segue sem a origem.
                $this->logger->warning('DialogHSM: falha ao listar Custom Objects para as variáveis', ['error' => $e->getMessage()]);
            }
        }

        return ['contactFields' => $contactFields, 'customObjects' => $customObjects];
    }
}

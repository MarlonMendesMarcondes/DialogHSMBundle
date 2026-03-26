<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Security\Permissions;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Security\Permissions\AbstractPermissions;
use Symfony\Component\Form\FormBuilderInterface;

class DialogHSMPermissions extends AbstractPermissions
{
    public function __construct(CoreParametersHelper $coreParametersHelper)
    {
        parent::__construct($coreParametersHelper->all());

        $this->addStandardPermissions('numbers');
    }

    public function getName(): string
    {
        return 'dialoghsm';
    }

    public function buildForm(FormBuilderInterface &$builder, array $options, array $data): void
    {
        $this->addStandardFormFields('dialoghsm', 'numbers', $builder, $data);
    }
}

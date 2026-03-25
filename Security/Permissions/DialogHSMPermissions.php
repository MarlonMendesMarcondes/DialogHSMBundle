<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Security\Permissions;

use Mautic\CoreBundle\Security\Permissions\AbstractPermissions;
use Symfony\Component\Form\FormBuilderInterface;

class DialogHSMPermissions extends AbstractPermissions
{
    public function __construct($params)
    {
        parent::__construct($params);

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

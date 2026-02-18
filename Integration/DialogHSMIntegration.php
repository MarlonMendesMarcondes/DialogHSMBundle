<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;

class DialogHSMIntegration extends BasicIntegration implements BasicInterface
{
    use ConfigurationTrait;

    public const NAME         = 'DialogHSM';
    public const DISPLAY_NAME = '360dialog WhatsApp';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return self::DISPLAY_NAME;
    }

    public function getIcon(): string
    {
        return 'plugins/DialogHSMBundle/Assets/img/dialoghsm.png';
    }
}

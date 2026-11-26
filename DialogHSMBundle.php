<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle;

use Mautic\IntegrationsBundle\Bundle\AbstractPluginBundle;
use MauticPlugin\DialogHSMBundle\DependencyInjection\Compiler\CampaignHourlyChartPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class DialogHSMBundle extends AbstractPluginBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // The hourly chart of the campaign page at the right hour, unless another plugin already replaced the model.
        $container->addCompilerPass(new CampaignHourlyChartPass());
    }
}

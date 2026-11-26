<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\DependencyInjection\Compiler;

use Mautic\CampaignBundle\Model\CampaignModel;
use MauticPlugin\DialogHSMBundle\Chart\CampaignHourlyChartModel;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Core's campaign model becomes CampaignHourlyChartModel (hourly chart at the right hour), but only while it is still
 * core's class: where another plugin replaced it (MauticPatchesBundle does, with the same chart fix), that one is
 * kept. Plugins' services.php run before compiler passes, so their replacement is already in the definition here.
 */
class CampaignHourlyChartPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CampaignModel::class)) {
            return;
        }

        $definition = $container->getDefinition(CampaignModel::class);

        if (CampaignModel::class !== ($definition->getClass() ?? CampaignModel::class)) {
            return;
        }

        $definition->setClass(CampaignHourlyChartModel::class);
    }
}

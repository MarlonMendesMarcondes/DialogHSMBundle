<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Test\Unit\DependencyInjection\Compiler;

use Mautic\CampaignBundle\Model\CampaignModel;
use MauticPlugin\DialogHSMBundle\Chart\CampaignHourlyChartModel;
use MauticPlugin\DialogHSMBundle\DependencyInjection\Compiler\CampaignHourlyChartPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class CampaignHourlyChartPassTest extends TestCase
{
    public function testCoresCampaignModelGetsTheHourlyChartFix(): void
    {
        $container = new ContainerBuilder();
        $container->register(CampaignModel::class);

        (new CampaignHourlyChartPass())->process($container);

        $this->assertSame(CampaignHourlyChartModel::class, $container->getDefinition(CampaignModel::class)->getClass());
    }

    public function testAModelReplacedByAnotherPluginIsKept(): void
    {
        $container = new ContainerBuilder();
        $container->register(CampaignModel::class, 'MauticPlugin\MauticPatchesBundle\Model\CampaignPrefixModel');

        (new CampaignHourlyChartPass())->process($container);

        $this->assertSame('MauticPlugin\MauticPatchesBundle\Model\CampaignPrefixModel', $container->getDefinition(CampaignModel::class)->getClass());
    }

    public function testWithoutTheCampaignModelNothingIsRegistered(): void
    {
        $container = new ContainerBuilder();

        (new CampaignHourlyChartPass())->process($container);

        $this->assertFalse($container->hasDefinition(CampaignModel::class));
    }

    public function testTheModelIsCoresCampaignModel(): void
    {
        $this->assertTrue(is_subclass_of(CampaignHourlyChartModel::class, CampaignModel::class));
    }
}

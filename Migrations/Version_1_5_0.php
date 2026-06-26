<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_5_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        return false;
    }

    protected function up(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_4_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->getTable($this->tablePrefix . 'dialog_hsm_numbers')->hasColumn('webhook_secret');
    }

    protected function up(): void
    {
        $this->addSql(
            "ALTER TABLE `{$this->tablePrefix}dialog_hsm_numbers`
             ADD COLUMN `webhook_secret` VARCHAR(64) NULL DEFAULT NULL AFTER `batch_queue_name`,
             ADD INDEX `idx_webhook_secret` (`webhook_secret`)"
        );
    }
}

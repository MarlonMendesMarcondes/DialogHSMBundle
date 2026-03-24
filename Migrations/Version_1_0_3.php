<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Retroactive migration for installations where Version_1_0_1 already ran
 * before `base_url` was added to that migration.
 */
class Version_1_0_3 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('dialog_hsm_numbers'));

            return !$table->hasColumn('base_url');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $tableName = $this->concatPrefix('dialog_hsm_numbers');

        $this->addSql("ALTER TABLE `{$tableName}` ADD COLUMN IF NOT EXISTS `base_url` VARCHAR(500) DEFAULT NULL AFTER `api_key`");
    }
}
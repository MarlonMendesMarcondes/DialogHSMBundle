<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Retroactive migration for installations where Version_1_0_0 already ran
 * before `http_status_code` was added to that migration.
 */
class Version_1_0_2 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('dialog_hsm_message_log'));

            return !$table->hasColumn('http_status_code');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $tableName = $this->concatPrefix('dialog_hsm_message_log');

        $this->addSql("ALTER TABLE `{$tableName}` ADD COLUMN IF NOT EXISTS `http_status_code` INT DEFAULT NULL AFTER `status`");
    }
}
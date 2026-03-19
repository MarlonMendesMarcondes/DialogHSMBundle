<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adds sender_name column to dialog_hsm_message_log.
 */
class Version_1_0_8 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('dialog_hsm_message_log'));

            return !$table->hasColumn('sender_name');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $tableName = $this->concatPrefix('dialog_hsm_message_log');

        $this->addSql("ALTER TABLE `{$tableName}`
            ADD COLUMN `sender_name` VARCHAR(255) DEFAULT NULL AFTER `lead_id`
        ");
    }
}

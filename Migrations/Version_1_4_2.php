<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_4_2 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('dialog_hsm_message_log'));

            return !$table->hasColumn('webhook_error_code');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $tableName = $this->concatPrefix('dialog_hsm_message_log');

        $this->addSql("ALTER TABLE `{$tableName}` ADD COLUMN IF NOT EXISTS `webhook_error_code` INT NULL DEFAULT NULL AFTER `error_message`");
    }
}

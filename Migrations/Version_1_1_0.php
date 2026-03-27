<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adiciona campaign_id e campaign_event_id em dialog_hsm_message_log.
 */
class Version_1_1_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('dialog_hsm_message_log'));

            return !$table->hasColumn('campaign_id');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $tableName = $this->concatPrefix('dialog_hsm_message_log');

        $this->addSql("ALTER TABLE `{$tableName}` ADD COLUMN IF NOT EXISTS `campaign_id` INT(11) NULL DEFAULT NULL AFTER `lead_id`");
        $this->addSql("ALTER TABLE `{$tableName}` ADD COLUMN IF NOT EXISTS `campaign_event_id` INT(11) NULL DEFAULT NULL AFTER `campaign_id`");
        $this->addSql("ALTER TABLE `{$tableName}` ADD INDEX IF NOT EXISTS `campaign_id_idx` (`campaign_id`)");
    }
}

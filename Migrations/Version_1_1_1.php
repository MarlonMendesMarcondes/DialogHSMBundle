<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adiciona wamid em dialog_hsm_message_log e webhook_token em dialog_hsm_numbers.
 */
class Version_1_1_1 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('dialog_hsm_message_log'));

            return !$table->hasColumn('wamid');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $logTable    = $this->concatPrefix('dialog_hsm_message_log');
        $numberTable = $this->concatPrefix('dialog_hsm_numbers');

        $this->addSql("ALTER TABLE `{$logTable}` ADD COLUMN IF NOT EXISTS `wamid` VARCHAR(255) NULL DEFAULT NULL AFTER `id`");
        $this->addSql("ALTER TABLE `{$logTable}` ADD INDEX IF NOT EXISTS `wamid_idx` (`wamid`)");
        $this->addSql("ALTER TABLE `{$numberTable}` ADD COLUMN IF NOT EXISTS `webhook_token` VARCHAR(64) NULL DEFAULT NULL AFTER `batch_queue_name`");
        $this->addSql("ALTER TABLE `{$numberTable}` ADD UNIQUE INDEX IF NOT EXISTS `webhook_token_uniq` (`webhook_token`)");
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adiciona date_delivered e date_read em dialog_hsm_message_log
 * para registrar o timestamp exato de cada transição de status.
 * Usado pela linha do tempo do contato para exibir eventos separados.
 */
class Version_1_4_1 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('dialog_hsm_message_log'));

            return !$table->hasColumn('date_delivered');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $tableName = $this->concatPrefix('dialog_hsm_message_log');

        $this->addSql("ALTER TABLE `{$tableName}` ADD COLUMN IF NOT EXISTS `date_delivered` DATETIME NULL DEFAULT NULL AFTER `date_sent`");
        $this->addSql("ALTER TABLE `{$tableName}` ADD COLUMN IF NOT EXISTS `date_read` DATETIME NULL DEFAULT NULL AFTER `date_delivered`");
    }
}

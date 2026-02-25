<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adds DialogHSM tracking columns to the leads table.
 *
 * These columns are written directly by SendWhatsAppMessageHandler after each
 * message dispatch and provide per-contact send status visibility.
 */
class Version_1_0_4 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('leads'));

            return !$table->hasColumn('dialoghsm_status');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $tableName = $this->concatPrefix('leads');

        $this->addSql("ALTER TABLE `{$tableName}`
            ADD COLUMN `dialoghsm_status` VARCHAR(50) DEFAULT NULL,
            ADD COLUMN `dialoghsm_last_response` VARCHAR(255) DEFAULT NULL,
            ADD COLUMN `dialoghsm_last_sent` DATETIME DEFAULT NULL
        ");
    }
}
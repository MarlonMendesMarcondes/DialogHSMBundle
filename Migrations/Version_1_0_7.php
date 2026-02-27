<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adds batch_queue_name column to dialog_hsm_numbers.
 *
 * Allows each WhatsApp number to configure a dedicated RabbitMQ queue
 * for batch/commercial-hours sending, separate from the mass queue.
 */
class Version_1_0_7 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('dialog_hsm_numbers'));

            return !$table->hasColumn('batch_queue_name');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $tableName = $this->concatPrefix('dialog_hsm_numbers');

        $this->addSql("ALTER TABLE `{$tableName}`
            ADD COLUMN `batch_queue_name` VARCHAR(100) DEFAULT NULL AFTER `queue_name`
        ");
    }
}

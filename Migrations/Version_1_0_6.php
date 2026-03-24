<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adds queue_name column to dialog_hsm_numbers.
 *
 * Allows each WhatsApp number to route messages to a dedicated
 * RabbitMQ queue via AMQP routing key, enabling per-number consumers.
 */
class Version_1_0_6 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('dialog_hsm_numbers'));

            return !$table->hasColumn('queue_name');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $tableName = $this->concatPrefix('dialog_hsm_numbers');

        $this->addSql("ALTER TABLE `{$tableName}`
            ADD COLUMN IF NOT EXISTS `queue_name` VARCHAR(100) DEFAULT NULL AFTER `base_url`
        ");
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_0_0 extends AbstractMigration
{
    private string $table = 'dialog_hsm_message_log';

    protected function isApplicable(Schema $schema): bool
    {
        try {
            $schema->getTable($this->concatPrefix($this->table));

            return false;
        } catch (SchemaException) {
            return true;
        }
    }

    protected function up(): void
    {
        $tableName = $this->concatPrefix($this->table);

        $this->addSql("
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `id` INT AUTO_INCREMENT NOT NULL,
                `lead_id` INT NOT NULL,
                `template_name` VARCHAR(255) NOT NULL,
                `phone_number` VARCHAR(50) NOT NULL,
                `status` VARCHAR(20) NOT NULL,
                `http_status_code` INT DEFAULT NULL,
                `api_response` LONGTEXT DEFAULT NULL,
                `error_message` LONGTEXT DEFAULT NULL,
                `date_sent` DATETIME NOT NULL,
                INDEX `{$this->concatPrefix('lead_id_idx')}` (`lead_id`),
                INDEX `{$this->concatPrefix('status_idx')}` (`status`),
                INDEX `{$this->concatPrefix('date_sent_idx')}` (`date_sent`),
                PRIMARY KEY(`id`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
    }
}

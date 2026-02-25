<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_0_1 extends AbstractMigration
{
    private string $table = 'dialog_hsm_numbers';

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
                `is_published` TINYINT(1) NOT NULL DEFAULT 1,
                `date_added` DATETIME DEFAULT NULL,
                `created_by` INT DEFAULT NULL,
                `created_by_user` VARCHAR(191) DEFAULT NULL,
                `date_modified` DATETIME DEFAULT NULL,
                `modified_by` INT DEFAULT NULL,
                `modified_by_user` VARCHAR(191) DEFAULT NULL,
                `checked_out` DATETIME DEFAULT NULL,
                `checked_out_by` INT DEFAULT NULL,
                `checked_out_by_user` VARCHAR(191) DEFAULT NULL,
                `name` VARCHAR(191) NOT NULL,
                `phone_number` VARCHAR(50) NOT NULL,
                `api_key` LONGTEXT NOT NULL,
                `base_url` VARCHAR(500) DEFAULT NULL,
                INDEX `{$this->concatPrefix('idx_hsm_number_name')}` (`name`),
                PRIMARY KEY(`id`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
    }
}

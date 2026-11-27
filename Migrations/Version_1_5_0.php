<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Lotes programados ("Batch — lotes programados"): só cria as duas tabelas novas.
 * Nenhuma tabela existente é alterada.
 */
class Version_1_5_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $connection = $this->entityManager->getConnection();

            foreach (['dialog_hsm_paced_lot', 'dialog_hsm_paced_message'] as $table) {
                if (false === $connection->fetchOne('SHOW TABLES LIKE ?', [$this->concatPrefix($table)])) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function up(): void
    {
        $lotTable     = $this->concatPrefix('dialog_hsm_paced_lot');
        $messageTable = $this->concatPrefix('dialog_hsm_paced_message');

        $this->addSql("
            CREATE TABLE IF NOT EXISTS `{$lotTable}` (
                `id` INT AUTO_INCREMENT NOT NULL,
                `campaign_id` INT DEFAULT NULL,
                `campaign_event_id` INT NOT NULL,
                `lot_index` INT NOT NULL,
                `lot_size` INT NOT NULL,
                `lot_interval` INT NOT NULL,
                `due_at` DATETIME NOT NULL,
                `allocated` INT NOT NULL DEFAULT 0,
                `date_added` DATETIME NOT NULL,
                INDEX `{$this->concatPrefix('paced_lot_event_due_idx')}` (`campaign_event_id`, `due_at`),
                PRIMARY KEY(`id`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");

        $this->addSql("
            CREATE TABLE IF NOT EXISTS `{$messageTable}` (
                `id` INT AUTO_INCREMENT NOT NULL,
                `lot_id` INT NOT NULL,
                `due_at` DATETIME NOT NULL,
                `whatsapp_number_id` INT NOT NULL,
                `queue_log_id` VARCHAR(64) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `attempts` INT NOT NULL DEFAULT 0,
                `claimed_by` VARCHAR(32) DEFAULT NULL,
                `claimed_at` DATETIME DEFAULT NULL,
                `date_added` DATETIME NOT NULL,
                INDEX `{$this->concatPrefix('paced_message_due_idx')}` (`due_at`),
                INDEX `{$this->concatPrefix('paced_message_lot_idx')}` (`lot_id`),
                INDEX `{$this->concatPrefix('paced_message_claimed_idx')}` (`claimed_by`),
                PRIMARY KEY(`id`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
    }
}

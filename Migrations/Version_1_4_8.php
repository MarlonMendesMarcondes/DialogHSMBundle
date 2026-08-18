<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Suporte completo à consulta de saldo por número via 360dialog Partner API:
 * - dialog_hsm_numbers: client_id, channel_id (identificadores da Partner API),
 *   balance/balance_currency/balance_updated_at (cache do saldo), balance_alert_state
 *   (transição ok/low/depleted) e balance_usage_snapshot (custo mensal, usage[]
 *   já retornado pela mesma chamada de saldo — sem consulta extra).
 * - dialog_hsm_number_balance_history: histórico de recargas (cargas) de saldo,
 *   detectado via last_renewal — uma linha por recarga real, não um snapshot
 *   periódico.
 *
 * partner_id e a Partner API Key ficam na config global da integração (não
 * aqui), por serem credenciais de conta compartilhadas entre todos os números.
 */
class Version_1_4_8 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table   = $this->concatPrefix('dialog_hsm_numbers');
            $columns = $this->entityManager->getConnection()->fetchAllAssociative(
                "SHOW COLUMNS FROM `{$table}` LIKE 'balance_usage_snapshot'"
            );

            return empty($columns);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function up(): void
    {
        $numbersTable = $this->concatPrefix('dialog_hsm_numbers');

        $this->addSql(
            "ALTER TABLE `{$numbersTable}` ADD COLUMN IF NOT EXISTS `client_id` VARCHAR(100) NULL DEFAULT NULL"
        );
        $this->addSql(
            "ALTER TABLE `{$numbersTable}` ADD COLUMN IF NOT EXISTS `channel_id` VARCHAR(100) NULL DEFAULT NULL"
        );
        $this->addSql(
            "ALTER TABLE `{$numbersTable}` ADD COLUMN IF NOT EXISTS `balance` DOUBLE PRECISION NULL DEFAULT NULL"
        );
        $this->addSql(
            "ALTER TABLE `{$numbersTable}` ADD COLUMN IF NOT EXISTS `balance_currency` VARCHAR(10) NULL DEFAULT NULL"
        );
        $this->addSql(
            "ALTER TABLE `{$numbersTable}` ADD COLUMN IF NOT EXISTS `balance_updated_at` DATETIME NULL DEFAULT NULL"
        );
        $this->addSql(
            "ALTER TABLE `{$numbersTable}` ADD COLUMN IF NOT EXISTS `balance_alert_state` VARCHAR(20) NULL DEFAULT NULL"
        );
        $this->addSql(
            "ALTER TABLE `{$numbersTable}` ADD COLUMN IF NOT EXISTS `balance_usage_snapshot` JSON NULL DEFAULT NULL"
        );

        $historyTable = $this->concatPrefix('dialog_hsm_number_balance_history');

        $this->addSql("
            CREATE TABLE IF NOT EXISTS `{$historyTable}` (
                `id` INT AUTO_INCREMENT NOT NULL,
                `whatsapp_number_id` INT NOT NULL,
                `recharge_date` DATETIME NOT NULL,
                `recharge_amount` DOUBLE PRECISION DEFAULT NULL,
                `balance_at_sync` DOUBLE PRECISION DEFAULT NULL,
                `currency` VARCHAR(10) DEFAULT NULL,
                `date_added` DATETIME NOT NULL,
                INDEX `{$this->concatPrefix('whatsapp_number_id_idx')}` (`whatsapp_number_id`),
                PRIMARY KEY(`id`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
    }
}

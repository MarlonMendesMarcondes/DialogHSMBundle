<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adiciona button_payload (varchar) e date_button_clicked (datetime) em dialog_hsm_message_log.
 * Captura cliques em quick-reply buttons de templates HSM (webhook type=button da 360dialog),
 * distinto do tracking genérico de "resposta" (date_replied).
 */
class Version_1_4_7 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table   = $this->concatPrefix('dialog_hsm_message_log');
            $columns = $this->entityManager->getConnection()->fetchAllAssociative(
                "SHOW COLUMNS FROM `{$table}` LIKE 'date_button_clicked'"
            );

            return empty($columns);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function up(): void
    {
        $table = $this->concatPrefix('dialog_hsm_message_log');

        $this->addSql(
            "ALTER TABLE `{$table}` ADD COLUMN IF NOT EXISTS `button_payload` VARCHAR(255) NULL DEFAULT NULL"
        );
        $this->addSql(
            "ALTER TABLE `{$table}` ADD COLUMN IF NOT EXISTS `date_button_clicked` DATETIME NULL DEFAULT NULL"
        );
    }
}

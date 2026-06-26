<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adiciona a coluna date_replied (datetime) em dialog_hsm_message_log.
 * Armazena quando o contato respondeu ao HSM específico (Scenario A via context.id
 * ou Scenario B por telefone + janela de 24h).
 */
class Version_1_4_6 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table   = $this->concatPrefix('dialog_hsm_message_log');
            $columns = $this->entityManager->getConnection()->fetchAllAssociative(
                "SHOW COLUMNS FROM `{$table}` LIKE 'date_replied'"
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
            "ALTER TABLE `{$table}` ADD COLUMN IF NOT EXISTS `date_replied` DATETIME NULL DEFAULT NULL"
        );
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Adiciona o campo dialoghsm_last_reply (datetime) ao lead.
 * Registra a data/hora em que o contato respondeu pela última vez a um HSM.
 */
class Version_1_4_5 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $exists = (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM `' . $this->concatPrefix('lead_fields') . '` WHERE `alias` = ?',
                ['dialoghsm_last_reply']
            );

            return $exists === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function up(): void
    {
        $conn        = $this->entityManager->getConnection();
        $leadsTable  = $this->concatPrefix('leads');
        $fieldsTable = $this->concatPrefix('lead_fields');

        $this->addSql(
            "ALTER TABLE `{$leadsTable}` ADD COLUMN IF NOT EXISTS `dialoghsm_last_reply` DATETIME NULL DEFAULT NULL"
        );

        $maxOrder = (int) $conn->fetchOne(
            "SELECT COALESCE(MAX(field_order), 0) FROM `{$fieldsTable}` WHERE object = 'lead'"
        );

        $now = date('Y-m-d H:i:s');
        $conn->executeStatement("
            INSERT INTO `{$fieldsTable}`
                (is_published, date_added, label, alias, type, field_group, object,
                 is_required, is_fixed, is_visible, is_short_visible, is_listable,
                 is_publicly_updatable, is_unique_identifer, is_index,
                 field_order, column_is_not_created, original_is_published_value,
                 properties)
            VALUES (1, '{$now}', 'WhatsApp Last Reply', 'dialoghsm_last_reply', 'datetime', 'core', 'lead',
                    0, 0, 1, 0, 1, 0, 0, 0, " . ($maxOrder + 1) . ", 0, 1, 'a:0:{}')
        ");
    }
}

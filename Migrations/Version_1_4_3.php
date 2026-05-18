<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * (1) Converte dialoghsm_status de text para select com valores fixos.
 * (2) Adiciona campo dialoghsm_meta_error_code (number, indexável) para
 *     filtros precisos por código de erro da Meta no builder de segmentos.
 */
class Version_1_4_3 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $type = $this->entityManager->getConnection()->fetchOne(
                'SELECT `type` FROM `' . $this->concatPrefix('lead_fields') . '` WHERE `alias` = ?',
                ['dialoghsm_status']
            );

            // Roda se status ainda é text OU se o novo campo ainda não existe
            if ($type === 'text') {
                return true;
            }

            $hasColumn = $this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM `' . $this->concatPrefix('lead_fields') . '` WHERE `alias` = ?',
                ['dialoghsm_meta_error_code']
            );

            return (int) $hasColumn === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function up(): void
    {
        $this->upgradeStatusField();
        $this->addMetaErrorCodeField();
    }

    private function upgradeStatusField(): void
    {
        $type = $this->entityManager->getConnection()->fetchOne(
            'SELECT `type` FROM `' . $this->concatPrefix('lead_fields') . '` WHERE `alias` = ?',
            ['dialoghsm_status']
        );

        if ($type !== 'text') {
            return;
        }

        $table = $this->concatPrefix('lead_fields');

        $properties = serialize([
            'list' => [
                ['label' => 'Aguardando confirmação', 'value' => 'pending_webhook'],
                ['label' => 'Enviado (Meta)',          'value' => 'sent'],
                ['label' => 'Entregue',               'value' => 'delivered'],
                ['label' => 'Lido',                   'value' => 'read'],
                ['label' => 'Falha API 360dialog',    'value' => 'failed_api'],
                ['label' => 'Rejeitado pela Meta',    'value' => 'failed_meta'],
            ],
        ]);

        $this->entityManager->getConnection()->executeStatement(
            "UPDATE `{$table}` SET `type` = 'select', `properties` = ? WHERE `alias` = 'dialoghsm_status'",
            [$properties]
        );
    }

    private function addMetaErrorCodeField(): void
    {
        $conn       = $this->entityManager->getConnection();
        $leadsTable = $this->concatPrefix('leads');
        $fieldsTable = $this->concatPrefix('lead_fields');

        $exists = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM `' . $fieldsTable . '` WHERE `alias` = ?',
            ['dialoghsm_meta_error_code']
        );

        if ($exists > 0) {
            return;
        }

        $colExists = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$leadsTable, 'dialoghsm_meta_error_code']
        );

        if ($colExists === 0) {
            $this->addSql("ALTER TABLE `{$leadsTable}` ADD COLUMN `dialoghsm_meta_error_code` INT NULL DEFAULT NULL");
        }

        $maxOrder = (int) $conn->fetchOne(
            "SELECT COALESCE(MAX(field_order), 0) FROM `{$fieldsTable}` WHERE object = 'lead'"
        );

        $now = date('Y-m-d H:i:s');
        $conn->executeStatement("
            INSERT INTO `{$fieldsTable}`
                (is_published, date_added, label, alias, type, field_group, object,
                 is_required, is_fixed, is_visible, is_short_visible, is_listable,
                 is_publicly_updatable, is_unique_identifer, is_index,
                 field_order, column_is_not_created, original_is_published_value)
            VALUES (1, '{$now}', 'WhatsApp Meta Error Code', 'dialoghsm_meta_error_code', 'number', 'core', 'lead',
                    0, 0, 1, 0, 1, 0, 0, 1, " . ($maxOrder + 1) . ", 0, 1)
        ");
    }
}

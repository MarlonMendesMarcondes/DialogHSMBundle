<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

class Version_1_4_4 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            return !$schema->hasTable($this->concatPrefix('dialog_hsm_whatsapp_messages'));
        } catch (\Throwable) {
            return false;
        }
    }

    protected function up(): void
    {
        $messagesTable = $this->concatPrefix('dialog_hsm_whatsapp_messages');
        $xrefTable     = $this->concatPrefix('dialog_hsm_wa_msg_list_xref');
        $logTable      = $this->concatPrefix('dialog_hsm_message_log');
        $numbersTable  = $this->concatPrefix('dialog_hsm_numbers');
        $listsTable    = $this->concatPrefix('lead_lists');

        $this->addSql("
            CREATE TABLE IF NOT EXISTS `{$messagesTable}` (
                `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `is_published`        TINYINT(1)   NOT NULL DEFAULT 1,
                `date_added`          DATETIME     NULL,
                `date_modified`       DATETIME     NULL,
                `created_by`          INT          NULL,
                `created_by_user`     VARCHAR(255) NULL,
                `modified_by`         INT          NULL,
                `modified_by_user`    VARCHAR(255) NULL,
                `checked_out`         DATETIME     NULL,
                `checked_out_by`      INT          NULL,
                `checked_out_by_user` VARCHAR(255) NULL,
                `name`                VARCHAR(255) NOT NULL,
                `whatsapp_number_id`  INT UNSIGNED NOT NULL,
                `template_name`       VARCHAR(255) NOT NULL,
                `payload_data`        LONGTEXT     NULL COMMENT '(DC2Type:json)',
                `publish_up`          DATETIME     NULL,
                `publish_down`        DATETIME     NULL,
                `sent_count`          INT          NOT NULL DEFAULT 0,
                `failed_count`        INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk_wa_msg_number`
                    FOREIGN KEY (`whatsapp_number_id`) REFERENCES `{$numbersTable}` (`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->addSql("
            CREATE TABLE IF NOT EXISTS `{$xrefTable}` (
                `whatsapp_message_id` INT UNSIGNED NOT NULL,
                `leadlist_id`         INT UNSIGNED NOT NULL,
                PRIMARY KEY (`whatsapp_message_id`, `leadlist_id`),
                CONSTRAINT `fk_wa_msg_xref_msg`
                    FOREIGN KEY (`whatsapp_message_id`) REFERENCES `{$messagesTable}` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_wa_msg_xref_list`
                    FOREIGN KEY (`leadlist_id`) REFERENCES `{$listsTable}` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->addSql("
            ALTER TABLE `{$logTable}`
                ADD COLUMN IF NOT EXISTS `whatsapp_message_id` INT NULL DEFAULT NULL
        ");
    }
}

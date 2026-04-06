<?php

declare(strict_types=1);

namespace MauticPlugin\DialogHSMBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Marcador de versão para a criptografia de API keys (segurança crítica).
 *
 * A partir desta versão o campo api_key em dialog_hsm_numbers armazena valores
 * criptografados com o prefixo "ENC:" usando o EncryptionHelper do Mautic
 * (AES via openssl, chave derivada do mautic.secret_key).
 *
 * Chaves antigas em texto plano continuam funcionando até a próxima gravação,
 * quando são criptografadas automaticamente pelo ApiKeyEncryptionSubscriber.
 *
 * Para criptografar todas as chaves existentes de uma vez, execute:
 *   php bin/console dialoghsm:encrypt-api-keys
 *
 * Não há alteração de schema nesta migração — o tipo TEXT já comporta o valor criptografado.
 */
class Version_1_2_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('dialog_hsm_numbers'));

            // Aplica apenas se a coluna api_key existir (sanidade) e não houver outra sentinela
            return $table->hasColumn('api_key') && !$table->hasColumn('api_key_enc_marker');
        } catch (SchemaException) {
            return false;
        }
    }

    protected function up(): void
    {
        $numberTable = $this->concatPrefix('dialog_hsm_numbers');

        // Coluna sentinela: registra que esta migration foi aplicada.
        // O campo em si não armazena dados relevantes.
        $this->addSql("ALTER TABLE `{$numberTable}` ADD COLUMN IF NOT EXISTS `api_key_enc_marker` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Sentinela: api_key usa criptografia ENC: desde Version_1_2_0'");
    }
}

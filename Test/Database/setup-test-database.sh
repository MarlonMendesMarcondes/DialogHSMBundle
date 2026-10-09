#!/usr/bin/env bash
#
# Cria (ou recria do zero) o banco de teste dos testes de banco do DialogHSM.
#
# Copia só a ESTRUTURA do banco do Mautic — nenhum contato, campanha ou log — e os
# registros da tabela plugins. A configuração da integração DialogHSM é criada vazia,
# sem chaves de API. Os testes rodam cada caso dentro de uma transação desfeita no fim.
#
# Uso (no host, com o docker compose do ambiente local no ar):
#   plugins/DialogHSMBundle/Test/Database/setup-test-database.sh
#
# Variáveis (padrões do docker-compose.yml local):
#   DB_CONTAINER=mautic_mysql  DB_USER=mautic
#   SOURCE_DB=mautic           TEST_DB=mautic_dialoghsm_test
#   DB_ROOT_PASSWORD           padrão: lida do próprio container (MYSQL_ROOT_PASSWORD
#                              ou MARIADB_ROOT_PASSWORD), nunca escrita aqui
#
# Rode de novo sempre que entrar migration nova no Mautic ou no plugin.

set -euo pipefail

DB_CONTAINER="${DB_CONTAINER:-mautic_mysql}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-$(docker exec "$DB_CONTAINER" sh -c 'printf %s "${MYSQL_ROOT_PASSWORD:-$MARIADB_ROOT_PASSWORD}"')}"
DB_USER="${DB_USER:-mautic}"
SOURCE_DB="${SOURCE_DB:-mautic}"
TEST_DB="${TEST_DB:-mautic_dialoghsm_test}"

if [[ -z "$DB_ROOT_PASSWORD" ]]; then
    echo "Senha root do MySQL não encontrada no container $DB_CONTAINER. Informe DB_ROOT_PASSWORD." >&2
    exit 1
fi

if [[ "$TEST_DB" == "$SOURCE_DB" ]]; then
    echo "TEST_DB não pode ser o mesmo banco de origem ($SOURCE_DB)." >&2
    exit 1
fi

mysql_root() {
    docker exec -i "$DB_CONTAINER" mysql -uroot -p"$DB_ROOT_PASSWORD" "$@"
}

echo "Recriando o banco $TEST_DB..."
mysql_root -e "DROP DATABASE IF EXISTS \`$TEST_DB\`;
CREATE DATABASE \`$TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`$TEST_DB\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;"

echo "Copiando a estrutura de $SOURCE_DB (sem dados)..."
docker exec "$DB_CONTAINER" mysqldump -uroot -p"$DB_ROOT_PASSWORD" \
    --no-data --skip-triggers --single-transaction "$SOURCE_DB" \
    | mysql_root "$TEST_DB"

echo "Copiando o registro dos plugins e criando a integração DialogHSM sem credenciais..."
mysql_root "$TEST_DB" -e "
INSERT INTO plugins SELECT * FROM \`$SOURCE_DB\`.plugins;
INSERT INTO plugin_integration_settings (plugin_id, name, is_published, supported_features, api_keys, feature_settings)
SELECT id, 'DialogHSM', 1, 'a:0:{}', 'a:0:{}', 'a:0:{}' FROM plugins WHERE bundle = 'DialogHSMBundle';"

tables=$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$TEST_DB'")
echo "Pronto: $TEST_DB com $tables tabelas."

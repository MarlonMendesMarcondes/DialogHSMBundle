# Testes de banco

Kernel real do Mautic com MySQL real, sem mocks: campanhas rodando pelo `mautic:campaigns:trigger`, webhooks gravando status, consumidor, broadcast e queries dos repositórios.

## Preparar o banco (uma vez)

No host, com o `docker compose` local no ar:

```bash
plugins/DialogHSMBundle/Test/Database/setup-test-database.sh
```

O script cria `mautic_dialoghsm_test` com a **estrutura** do banco do Mautic, sem nenhum dado, e a integração DialogHSM sem chaves de API. Rode de novo sempre que entrar migration nova no Mautic ou no plugin.

## Rodar

```bash
docker exec -w /var/www/html/docroot/plugins/DialogHSMBundle mautic_app \
  php /tmp/phpunit.phar --bootstrap /var/www/html/vendor/autoload.php --testsuite database
```

Os testes de banco também fazem parte da suíte `all`. Sem o banco de teste, eles são pulados.

## Garantias

- **Banco separado:** o kernel é compilado num cache próprio (`/tmp/dialoghsm-database-tests`) apontando para o banco de teste. Antes de cada teste o nome do banco conectado é conferido; se não for o de teste, a suíte para.
- **Nada fica gravado:** cada teste roda dentro de uma transação desfeita no final.
- **Nada é enviado:** RabbitMQ em `in-memory://` e transporte direto em `null://`. Os números de teste apontam para `.invalid` ou IP interno, e a proteção de SSRF fica ligada mesmo com o `DIALOGHSM_DISABLE_SSRF_CHECK=1` do ambiente local.

## Escrevendo um teste

Estenda `DatabaseTestCase` e use os helpers de fixture: `createLead()`, `createCampaign()`, `createWhatsAppNumber()`, `createQueueActionEvent()`, `addLeadToCampaign()`. Para buscar serviços use `getService()`, e para rodar comandos do console, `runCommand()`.

# DialogHSMBundle — WhatsApp (360dialog) para Mautic

Plugin que integra o Mautic com a API da 360dialog para enviar templates HSM do WhatsApp em campanhas e broadcasts, receber o status de entrega e leitura por webhook e usar esses eventos como decisões de campanha.

---

## Compatibilidade

| | Versão |
|---|---|
| Mautic | 5.x (validado também no 7.0) |
| PHP | 8.1 – 8.3 |
| Banco | MySQL 8 / MariaDB 10.11 |
| RabbitMQ | 3.x ou superior *(opcional — envio assíncrono)* |
| Redis | *(opcional — envio direto sem bloquear o trigger, limitador de taxa e correlação de respostas)* |

---

## Instalação

```bash
# 1. Clonar na pasta de plugins
cd /var/www/html/docroot/plugins
git clone <url> DialogHSMBundle

# 2. Registrar o plugin (cria as tabelas)
php bin/console cache:clear
php bin/console mautic:plugins:reload

# 3. Ativar: Configurações → Plugins → 360dialog WhatsApp → Publicar
```

## Atualização

```bash
cd /var/www/html/docroot/plugins/DialogHSMBundle
git pull
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

Depois do `cache:clear`, reinicie o servidor web / PHP-FPM para o webhook carregar o código novo.

> **Migrations:** o `mautic:plugins:reload` só roda as migrations quando a versão em `Config/config.php` é maior que a salva no banco. Toda migration nova exige incrementar a versão. Para forçar a reexecução: `UPDATE plugins SET version = '0.0.0' WHERE bundle = 'DialogHSMBundle';` e rode o reload de novo (as migrations são idempotentes).

---

## Configuração

### Plugin (global)

**Configurações → Plugins → 360dialog WhatsApp**

| Campo | Padrão | Descrição |
|---|---|---|
| URL Base da API | `https://waba-v2.360dialog.io/messages` | Usada pelos números sem URL própria |
| Limite do Consumer | `50` | Mensagens processadas por execução do consumer bulk |
| Limite do Consumer Batch | `100` | Mensagens processadas por execução do consumer batch |
| Limite de Envio Bulk (msg/min) | `0` | Taxa máxima da fila bulk. `0` = sem limite |
| Limite de Envio Batch (msg/min) | `0` | Taxa máxima da fila batch. `0` = sem limite |
| Máximo de Registros no Log | `0` | Tamanho máximo da tabela de logs. `0` = sem limite |
| Retenção de Logs (dias) | `30` | Logs mais antigos são removidos. `0` = sem limite |
| Partner ID / Partner API Key | — | Credenciais do Partner Hub da 360dialog, para consulta de saldo |
| Alerta de saldo baixo | `10` | Limiar, destinatários e envio opcional por e-mail |

O limite de taxa é exato: 30/min envia 1 mensagem a cada 2 s, e 90/min envia 3 a cada 2 s. Ele é aplicado por número e exige Redis; sem Redis, não há limite.

Mensagens ainda na fila (`queued`) nunca são removidas pela limpeza de logs.

### Números WhatsApp

**WhatsApp → Números WhatsApp → Novo**

| Campo | Obrigatório | Descrição |
|---|---|---|
| Nome | ✅ | Identificador (ex.: `Comercial SP`) |
| Número de Telefone | ✅ | Formato E.164 (ex.: `+5511999999999`) |
| Chave da API | ✅ | `D360-API-KEY` da 360dialog (armazenada criptografada) |
| URL Base da API | ❌ | Sobrepõe a URL global |
| Fila Massiva | ❌ | Fila do RabbitMQ para envio bulk |
| Fila Batch | ❌ | Fila do RabbitMQ para envio em horário comercial |
| Client ID / Channel ID | ❌ | Identificadores da Partner API (saldo e webhook) |

Na tela do número estão os botões para **registrar e conferir o webhook** na 360dialog e para **consultar o saldo**.

---

## Campanhas

### Ações

| Ação | Como envia |
|---|---|
| **Enviar WhatsApp (360dialog)** | Direto: no próprio `campaigns:trigger` (inline) ou por um lote no Redis, quando `MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT` aponta para Redis |
| **Enviar WhatsApp (360dialog) com RabbitMQ** | Enfileira no RabbitMQ (fila bulk ou batch do número), e o consumer envia em background |
| **Enviar WhatsApp via Marketing Message** | Usa uma mensagem cadastrada em **Canais → WhatsApp Mensagens** e envia pela fila do número |

**Payload do template** (pares rótulo → valor, aceita tokens do contato):

```
content  = nome_do_template
nome     = {contactfield=firstname}
```

`content` é o nome do template. Os demais itens são as variáveis, na ordem em que aparecem.

### Decisões

| Decisão | Dispara quando |
|---|---|
| **WhatsApp entregue** | A Meta confirma a entrega (um `read` sem `delivered` também conta como entrega) |
| **WhatsApp lido** | O contato lê a mensagem |
| **WhatsApp respondido** | O contato responde, seja citando o HSM ou em texto livre até 24h depois |
| **WhatsApp botão clicado** | O contato clica num botão de resposta rápida |

As decisões precisam vir logo depois de uma das ações acima, e só avaliam os envios daquela ação.

### Comportamento

- **O contato é concluído na ação assim que a mensagem é entregue à fila** (ou ao envio direto). O status real da Meta chega depois pelo webhook e fica na timeline do contato. Para seguir caminhos diferentes conforme entregue, lido ou respondido, use os nós de decisão. Os próximos passos da campanha não esperam a confirmação da Meta.
- **Campanha desativada ou expirada:** as mensagens dela que já estão no RabbitMQ não são enviadas. Elas voltam para a fila a cada 5 minutos e saem quando a campanha é reativada. Se a campanha for excluída, são descartadas com o motivo "Campanha removida".
- **Reentrada:** com "permitir reinício", ou quando o contato é removido e readicionado, ele recebe a mensagem de novo na nova rotação.
- **Erros:**
  - **Erro de montagem** (integração desativada, número sem chave ou sem fila, payload inválido): os contatos não são consumidos. A ação falha com o motivo e tenta de novo em 15 minutos.
  - **Erro do contato ou do envio** (telefone inválido, recusa da Meta): o contato é concluído com `failed=1` e o motivo, sem contar no limite de 10% de falhas que desativa a campanha.

---

## Broadcast (Marketing Messages)

Mensagens em **Canais → WhatsApp Mensagens**, ligadas a segmentos, são enviadas pelo comando de broadcast do Mautic:

```bash
php bin/console mautic:broadcasts:send --channel=whatsapp --limit=1000 --batch=100
```

`--limit`, `--batch`, `--min-contact-id` e `--max-contact-id` são respeitados. Cada contato recebe uma única mensagem, mesmo estando em vários segmentos, e quem já recebeu não entra de novo.

---

## Webhook de status

Configure na 360dialog (pelo botão do número, ou via multi_webhook) a URL:

```
https://<seu-mautic>/dialoghsm/webhook/<telefone-do-número>
```

O plugin registra `sent`, `delivered`, `read` e `failed`, além de respostas e cliques em botão. A partir disso ele:
- atualiza o log e a timeline do contato;
- atualiza os campos `dialoghsm_status`, `dialoghsm_last_response` e `dialoghsm_last_reply` do contato, sempre pelo envio mais recente;
- dispara as decisões de campanha e as ações de pontos **"Mensagem HSM lida pelo contato"** e **"Mensagem HSM respondida pelo contato"**;
- dispara os eventos de webhook do Mautic: enviado, entregue, lido, respondido, falhou e botão clicado.

Comportamento do webhook:
- **Webhook repetido ou concorrente** é processado uma única vez: a transição de status é atômica no banco.
- **Falha transitória** (banco indisponível) responde `503`, e a 360dialog reentrega.
- **Status que chega antes de o Mautic gravar a ação agendada** também recebe `503`, para a decisão não se perder.

> Os eventos de webhook do Mautic rodam dentro da requisição do webhook quando o modo de fila de webhooks do Mautic é `immediate_process`. Em volume alto, prefira `command_process`.

---

## Envio assíncrono

### 1. Variáveis de ambiente (`.env.local`)

```bash
# Filas do RabbitMQ (ação "com RabbitMQ" e Marketing Messages)
MAUTIC_MESSENGER_DSN_WHATSAPP=amqp://user:password@localhost:5672/%2f

# Envio direto em lote pelo Redis (ação "Enviar WhatsApp" sem bloquear o trigger).
# Também usado pelo limitador de taxa e pela correlação de respostas.
# Aceita senha: redis://senha@host, redis://:senha@host ou redis://usuario:senha@host
MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT=redis://localhost:6379/3

# Mensagens que esgotam os 3 retries vão para o failure transport global do Mautic.
# Sem ele, a mensagem é descartada e só fica o log com status DLQ.
MAUTIC_MESSENGER_DSN_FAILED=doctrine://default?queue_name=failed
```

Sem essas variáveis, o padrão é `null://null`: a ação direta envia inline, dentro do trigger, e não há fila.

### 2. RabbitMQ

O plugin publica pelo **exchange default** (`''`), que roteia direto para a fila pelo nome. Não há bindings para criar.

> ⚠️ **Não execute `messenger:setup-transports`.** O exchange default não pode ser declarado via AMQP e o comando falha com `ACCESS_REFUSED`. Crie a exchange e as filas manualmente.

A exchange **`delays`** é obrigatória. O Messenger a usa nos retries de falha transitória e para reter mensagens de campanha desativada. Sem ela, o worker morre sem confirmar a mensagem, e a fila daquele número trava.

```bash
rabbitmqadmin declare exchange name=delays type=direct durable=true

# Uma vez por fila configurada nos números
rabbitmqadmin declare queue name=minha_fila durable=true
rabbitmqadmin declare queue name=minha_fila_lote durable=true
```

### 3. Cron

```bash
# Campanhas — uma linha própria, fora de scripts com lock
* * * * * php bin/console mautic:campaigns:trigger

# Consumer direto (Redis) — a cada minuto
* * * * * flock -n /tmp/dialoghsm-direct.lock php bin/console messenger:consume whatsapp_direct --limit=120 --time-limit=55

# Fila bulk — a cada minuto
* * * * * flock -n /tmp/dialoghsm-bulk.lock php bin/console dialoghsm:consume --mode=bulk --time-limit=55

# Fila batch — seg a sex, a cada 10 minutos
*/10 * * * 1-5 flock -n /tmp/dialoghsm-batch.lock php bin/console dialoghsm:consume --mode=batch --time-limit=540
```

- **`flock -n`** impede duas execuções simultâneas da mesma linha. No consumer Redis ele é obrigatório: o nome do consumer é fixo por servidor, e duas execuções sobrepostas releem o mesmo lote e enviam em duplicidade.
- **Não passe `--limit` no `dialoghsm:consume`.** Ele sobrescreve os limites das configurações do plugin, e mensagens de campanha desativada não contam nesses limites.
- **Fuso horário:** o cron segue o fuso do servidor, normalmente UTC. Para limitar o batch ao horário comercial de Brasília, use as horas em UTC: 11h–19h BRT = `14-21`.
- **`--batch-limit`:** o `campaigns:trigger` processa a campanha inteira numa execução, em lotes de `--batch-limit` (padrão 100). Aumentar esse valor só muda o tamanho de cada lote.

### Consumer manual

```bash
php bin/console dialoghsm:consume --mode=bulk --time-limit=60
php bin/console dialoghsm:consume --mode=batch --time-limit=60
php bin/console dialoghsm:consume --queue=nome_da_fila --time-limit=60
```

| Opção | Padrão | Descrição |
|---|---|---|
| `--mode` | — | `bulk` ou `batch` (todas as filas desse tipo, um worker por fila) |
| `--queue` | — | Nome exato da fila (tem prioridade sobre `--mode`) |
| `--limit` | configuração do plugin | Máximo de mensagens processadas por worker |
| `--time-limit` | `60` | Para depois de N segundos (`0` = sem limite) |

---

## Logs e dashboard

- **WhatsApp → Logs de Envio:** um registro por envio.
- **Dashboard WhatsApp:** cards de 24h e 7 dias, gráfico diário e disparos agrupados. As datas são exibidas no fuso do Mautic.

| Status | Significado |
|---|---|
| `queued` | Na fila do RabbitMQ / lote, aguardando o consumer |
| `pending_webhook` | Aceita pela 360dialog, aguardando confirmação da Meta |
| `sent` | Meta confirmou o envio |
| `delivered` | Entregue no aparelho |
| `read` | Lida |
| `failed` | Recusada pela API ou pela Meta (motivo e código no log) |
| `dlq` | Esgotou os 3 retries de falha transitória |

**Limpar Mensagens Pendentes** apaga os logs `queued` e **cancela** esses envios: quando a mensagem sair da fila, o consumer não envia.

---

## Comandos

| Comando | Uso |
|---|---|
| `dialoghsm:consume` | Consumer das filas do RabbitMQ (ver acima) |
| `dialoghsm:balance:sync` | Atualiza o saldo dos números pela Partner API |
| `dialoghsm:encrypt-api-keys` | Criptografa chaves de API ainda em texto plano (atualização da v1.1.x) |
| `dialoghsm:backfill-lead-event-log` | Recria a timeline do contato a partir dos logs de envio |
| `dialoghsm:backfill-campaign-metadata` | Grava o status do WhatsApp nos registros das ações de campanha. Com `--convert-json`, converte para o formato do Mautic a metadata que versões antigas gravaram em JSON. Aceita `--dry-run` |

---

## Segurança

- Chaves de API armazenadas criptografadas (prefixo `ENC:`).
- URLs da API e de mídia passam por validação contra SSRF: só HTTPS e IPs públicos, com o IP resolvido fixado na conexão contra DNS rebinding. **Nunca** defina `DIALOGHSM_DISABLE_SSRF_CHECK=1` em produção; ela existe só para a API falsa do ambiente local.
- Requisições à 360dialog têm timeout: 10 s para conectar e 30 s no total.
- Telefones validados no formato E.164.

---

## Desenvolvimento e testes

```bash
# Suítes: unit, integration, database e all (todas)
docker exec -w /var/www/html/docroot/plugins/DialogHSMBundle mautic_app \
  php /tmp/phpunit.phar --bootstrap /var/www/html/vendor/autoload.php --testsuite all
```

A suíte **database** roda o kernel real do Mautic contra um MySQL de teste. Ela cobre `campaigns:trigger`, webhook, decisões de campanha, consumer, broadcast e repositórios. O banco é criado com:

```bash
plugins/DialogHSMBundle/Test/Database/setup-test-database.sh
```

Detalhes em [Test/Database/README.md](Test/Database/README.md). Sem o banco de teste, esses testes são pulados.

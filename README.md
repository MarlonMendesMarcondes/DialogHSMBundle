# DialogHSMBundle — 360dialog WhatsApp para Mautic 5

Plugin que integra o Mautic com a API 360dialog para envio de mensagens WhatsApp via templates HSM.

---

## Dependências

| | Versão |
|---|---|
| Mautic | 5.x |
| PHP | 8.1 – 8.3 |
| MySQL | 5.7 / 8.x |
| RabbitMQ | 3.x *(opcional — envio assíncrono)* |

---

## Instalação

```bash
# 1. Clonar na pasta de plugins
cd /var/www/html/docroot/plugins
git clone <url> DialogHSMBundle

# 2. Registrar o plugin
php bin/console cache:clear
php bin/console mautic:plugins:reload

# 3. Ativar: Configurações → Plugins → 360dialog WhatsApp → Publicar
```

> **Migrações:** o `mautic:plugins:reload` só roda migrations quando a versão em `Config/config.php` é maior que a salva no banco. Toda migration nova exige incremento da versão.

---

## Configuração

### Plugin (global)

**Configurações → Plugins → 360dialog WhatsApp**

| Campo | Padrão | Descrição |
|---|---|---|
| URL Base da API | `https://waba-v2.360dialog.io/messages` | URL padrão para números sem URL própria |
| Limite do Consumer | `50` | Mensagens por execução (sobreposto por `--limit`) |
| Rate Bulk (msg/min) | `0` | Throttle do consumer assíncrono. `0` = sem limite |
| Máx. Registros de Log | `100.000` | Limite de registros na tabela de logs |
| Retenção de Log (dias) | `30` | Registros mais antigos são removidos. `0` = sem limite |

### Números WhatsApp

**Canais → Números WhatsApp → Novo**

| Campo | Obrigatório | Descrição |
|---|---|---|
| Nome | ✅ | Identificador (ex: `Comercial SP`) |
| Telefone | ✅ | Formato E.164 (ex: `+5511999999999`) |
| API Key | ✅ | Chave `D360-API-KEY` da 360dialog |
| URL Base | ❌ | Sobrepõe a URL global |
| Fila Massiva | ❌ | Nome da fila RabbitMQ para envio bulk |
| Fila Batch | ❌ | Nome da fila RabbitMQ para envio em horário comercial |

---

## Como funciona

O plugin adiciona duas ações ao builder de campanhas:

### Ação 1 — Enviar WhatsApp (síncrono)

Chama a API 360dialog diretamente no momento em que o contato entra no nó.

### Ação 2 — Enviar WhatsApp com Fila (assíncrono)

Enfileira a mensagem no RabbitMQ. Um consumer processa a fila em background (cron).

| Tipo de Fila | Quando usar |
|---|---|
| **Massivo** | Qualquer horário |
| **Batch** | Horário comercial (seg–sex) |

### Payload do template

```
content  = nome_do_template
nome     = {{ contact.firstname }}
telefone = {{ contact.phone }}
```

A chave `content` define o template. As demais são variáveis passadas ao template.

---

## Envio Assíncrono (RabbitMQ)

### 1. Variáveis de ambiente (`.env.local`)

```bash
# Transport principal (AMQP)
MAUTIC_MESSENGER_DSN_WHATSAPP=amqp://user:password@localhost:5672/%2f

# Transport direto (Redis) — para a ação síncrona não bloquear o worker
MAUTIC_MESSENGER_DSN_WHATSAPP_DIRECT=redis://localhost:6379/3

# Dead Letter Queue — mensagens que esgotam os 3 retries
MAUTIC_MESSENGER_DSN_WHATSAPP_FAILED=amqp://user:password@localhost:5672/%2f?exchange[name]=whatsapp_failed&exchange[type]=fanout
```

Sem configuração, o padrão é `null://null` (envio inline, sem fila).

### 2. Setup inicial do RabbitMQ

Execute **uma única vez** após configurar o `.env.local`:

```bash
# Cria o exchange 'whatsapp' e o exchange 'delays' (retry)
php bin/console messenger:setup-transports whatsapp

# Para cada fila do número, criar e vincular ao exchange 'whatsapp'
rabbitmqadmin declare queue name=minha_fila durable=true
rabbitmqadmin declare binding source=whatsapp destination=minha_fila routing_key=minha_fila
```

> Mensagens enviadas para uma fila sem binding ao exchange `whatsapp` são descartadas silenciosamente.

**Dead Letter Queue** (opcional):

```bash
rabbitmqadmin declare exchange name=whatsapp_failed type=fanout durable=true
rabbitmqadmin declare queue name=whatsapp_failed durable=true
rabbitmqadmin declare binding source=whatsapp_failed destination=whatsapp_failed
```

### 3. Cron

```bash
# Consumer direto (Redis) — a cada minuto
* * * * * php bin/console messenger:consume whatsapp_direct --limit=120 --time-limit=55

# Fila bulk — a cada minuto
* * * * * php bin/console dialoghsm:consume --mode=bulk --limit=120 --time-limit=55

# Fila batch — seg a sex, a cada 10 minutos
*/10 * * * 1-5 php bin/console dialoghsm:consume --mode=batch --limit=100 --time-limit=540
```

### Consumer manual

```bash
# Por tipo
php bin/console dialoghsm:consume --mode=bulk --time-limit=60
php bin/console dialoghsm:consume --mode=batch --time-limit=60

# Por fila específica
php bin/console dialoghsm:consume --queue=nome_da_fila --time-limit=60
```

| Opção | Padrão | Descrição |
|---|---|---|
| `--mode` | — | `bulk` ou `batch` (atalho para as filas do número) |
| `--queue` | — | Nome exato da fila (tem prioridade sobre `--mode`) |
| `--limit` | configuração do plugin | Máximo de mensagens |
| `--time-limit` | `60` | Para após N segundos (`0` = sem limite) |

---

## Logs de Envio

**Canais → Logs de Envio**

| Status | Significado |
|---|---|
| `queued` | Na fila, aguardando consumer |
| `sent` | Enviado com sucesso (HTTP 200) |
| `failed` | Falha no envio |
| `dlq` | Esgotou os 3 retries, movido para DLQ |

---

## Atualizações

```bash
cd /var/www/html/docroot/plugins/DialogHSMBundle
git pull
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

Se uma migration ficou para trás (versão não foi incrementada):

```sql
UPDATE plugins SET version = '0.0.0' WHERE bundle = 'DialogHSMBundle';
```

```bash
php bin/console mautic:plugins:reload
```

---

## Segurança

- API Keys são armazenadas criptografadas (prefixo `ENC:`). Após atualizar da v1.1.x para v1.2+:

```bash
php bin/console dialoghsm:encrypt-api-keys
```

- URLs de mídia passam por validação anti-SSRF (apenas HTTPS + IPs públicos).
- Telefones são validados no formato E.164 (`+` + código do país + número, sem zeros iniciais).

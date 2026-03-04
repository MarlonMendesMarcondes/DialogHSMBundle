# 360dialog WhatsApp — Plugin para Mautic 5

Plugin que integra o Mautic com a API 360dialog para envio de mensagens WhatsApp HSM (templates aprovados pelo Meta).

---

## Requisitos

| Dependência | Versão mínima |
|-------------|---------------|
| Mautic      | 5.x           |
| PHP         | 8.1, 8.2, 8.3 |
| MySQL       | 5.7 / 8.x     |
| RabbitMQ    | 3.x *(opcional — necessário apenas para envio com fila)* |

---

## Instalação

### 1. Clonar o repositório

```bash
cd /var/www/html/docroot/plugins
git clone <url-do-repositorio> DialogHSMBundle
```

> Com Docker:
> ```bash
> docker exec mautic_app bash -c "cd /var/www/html/docroot/plugins && git clone <url-do-repositorio> DialogHSMBundle"
> ```

### 2. Limpar o cache e rodar as migrações

```bash
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

> Com Docker:
> ```bash
> docker exec mautic_app php /var/www/html/bin/console cache:clear
> docker exec mautic_app php /var/www/html/bin/console mautic:plugins:reload
> ```

### 3. Ativar o plugin

1. Acesse **Configurações → Plugins**
2. Localize **360dialog WhatsApp** e clique em **Ativar/Publicar**

### Atualizações futuras

```bash
cd /var/www/html/docroot/plugins/DialogHSMBundle
git pull
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

---

## Configuração Global do Plugin

Acesse **Configurações → Plugins → 360dialog WhatsApp → Configuração**.

| Campo             | Descrição                                                        | Padrão                              |
|-------------------|------------------------------------------------------------------|-------------------------------------|
| **URL Base da API** | URL padrão da API 360dialog usada quando o número não define a sua | `https://waba-v2.360dialog.io/messages` |
| **Limite do Consumer** | Máximo de mensagens processadas por execução do consumer | `50` |

---

## Cadastro de Números WhatsApp

Acesse **Canais → Números WhatsApp → Novo**.

| Campo          | Descrição                                                                            | Obrigatório |
|----------------|--------------------------------------------------------------------------------------|-------------|
| **Nome**       | Identificador amigável (ex: `Comercial SP`)                                          | ✅          |
| **Telefone**   | Número remetente no formato internacional (ex: `+5511999999999`)                     | ✅          |
| **API Key**    | Chave D360-API-KEY fornecida pela 360dialog                                          | ✅          |
| **URL Base**   | URL customizada da API. Se vazio, usa a URL global configurada no plugin             | ❌          |
| **Fila Massiva (RabbitMQ)** | Nome da fila para envio massivo em qualquer horário (ex: `queue`)       | ❌          |
| **Fila Batch (RabbitMQ)**   | Nome da fila para envio em lote no horário comercial (ex: `batch`)      | ❌          |

---

## Uso no Builder de Campanhas

O plugin adiciona **duas ações** ao builder de campanhas do Mautic:

### Ação 1 — Enviar WhatsApp (síncrono)

Envia a mensagem diretamente via API no momento em que o contato entra no nó.

| Campo           | Descrição                                                           |
|-----------------|---------------------------------------------------------------------|
| **Número**      | Número WhatsApp remetente                                           |
| **Payload**     | Lista de `chave → valor` enviados ao template (ex: `content = nome_do_template`, `nome = {{ contact.firstname }}`) |
| **Delay (ms)**  | Aguardar X ms entre cada envio. `0` = sem delay                     |
| **Limite/Lote** | Agrupar envios em lotes de N antes de aplicar o delay. `0` = delay entre cada mensagem individualmente |

### Ação 2 — Enviar WhatsApp com Fila (assíncrono)

Enfileira a mensagem no RabbitMQ para processamento posterior pelo consumer.

Possui os mesmos campos da ação síncrona, mais:

| Campo            | Opções                  | Descrição                                                                                           |
|------------------|-------------------------|-----------------------------------------------------------------------------------------------------|
| **Tipo de Fila** | **Massivo** / **Batch** | **Massivo** usa a *Fila Massiva* do número (qualquer horário). **Batch** usa a *Fila Batch* (horário comercial). |

### Estrutura do Payload

A chave `content` define o nome do template. As demais chaves são variáveis enviadas ao template:

```
content  = nome_do_template
nome     = {{ contact.firstname }}
telefone = {{ contact.phone }}
custom   = {{ contact.custom_field }}
```

---

## Envio Assíncrono via RabbitMQ

### 1. Configurar a conexão

Adicione no `.env.local` (na raiz do Mautic):

```bash
MAUTIC_MESSENGER_DSN_WHATSAPP=amqp://user:password@localhost:5672/%2f/whatsapp
```

> Em Docker, substitua `localhost` pelo nome do serviço RabbitMQ (ex: `rabbitmq`).

### 2. Criar as filas no RabbitMQ

Crie as filas com os **mesmos nomes** configurados nos campos *Fila Massiva* e *Fila Batch* de cada número:

```bash
# Exemplo para o número "Comercial"
rabbitmqctl add_queue comercial_sp_massiva
rabbitmqctl add_queue comercial_sp_batch
```

> Se a fila não existir no RabbitMQ, a mensagem é descartada silenciosamente.

### 3. Configurar o Cron

```bash
# Fila massiva: qualquer horário, a cada minuto
* * * * * php /var/www/html/bin/console dialoghsm:consume --queue=queue --limit=50 --time-limit=60

# Fila de lotes (horário comercial): seg a sex, 8h às 18h, a cada 10 minutos
*/10 8-17 * * 1-5 php /var/www/html/bin/console dialoghsm:consume --queue=batch --limit=100 --time-limit=540
```

### Consumer Manual

```bash
# Consumir uma fila específica
php bin/console dialoghsm:consume --queue=queue --limit=50 --time-limit=60

# Consumir todas as filas
php bin/console dialoghsm:consume
```

| Opção           | Descrição                                                       | Padrão           |
|-----------------|-----------------------------------------------------------------|------------------|
| `--queue`       | Nome da fila a consumir. Omitir = consome todas                 | *(todas)*        |
| `--limit`       | Máximo de mensagens a processar                                 | Limite do plugin |
| `--time-limit`  | Para o consumer após N segundos. `0` = sem limite               | `60`             |

---

## Logs de Envio

Acesse **Canais → Logs de Envio** para auditar todos os envios realizados pelo plugin.

| Coluna        | Descrição                                              |
|---------------|--------------------------------------------------------|
| **Data/Hora** | Timestamp do envio                                     |
| **Contato**   | Link para o perfil do contato no Mautic                |
| **Telefone**  | Número de destino                                      |
| **Template**  | Nome do template HSM enviado                           |
| **Status**    | `sent` (verde) ou `failed` (vermelho)                  |
| **HTTP**      | Código de resposta da API 360dialog                    |
| **Erro**      | Mensagem de erro quando o envio falha                  |

> Os logs são mantidos automaticamente em no máximo **10.000 registros**.

---

## Solução de Problemas

**Cache desatualizado após instalar:**
```bash
php bin/console cache:clear
```

**Mensagens não sendo enviadas:**
- Verifique se o plugin está publicado em Configurações → Plugins
- Confirme que a API Key do número está correta
- Consulte os logs: `var/logs/mautic_prod-YYYY-MM-DD.php`

**Consumer não finaliza:**
- Sempre use `--time-limit` ao rodar manualmente

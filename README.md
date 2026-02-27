# 360dialog WhatsApp — Plugin para Mautic 5

Plugin que integra o Mautic com a API 360dialog para envio de mensagens WhatsApp HSM (templates aprovados pelo Meta).

---

## Requisitos

| Dependência | Versão mínima |
|-------------|---------------|
| Mautic      | 5.x           |
| PHP         | 8.1, 8.2, 8.3 |
| MySQL       | 5.7 / 8.x     |
| RabbitMQ    | 3.x *(opcional — necessário apenas para envio assíncrono)* |

---

## Instalação

### 1. Clonar o repositório

Clone diretamente na pasta `plugins/` do Mautic:

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
php bin/console mautic:migrations:execute --bundle=DialogHSMBundle
```

> Com Docker:
> ```bash
> docker exec mautic_app php /var/www/html/bin/console cache:clear
> docker exec mautic_app php /var/www/html/bin/console mautic:migrations:execute --bundle=DialogHSMBundle
> ```

### 3. Ativar o plugin

1. Acesse **Configurações → Plugins**
2. Localize **360dialog WhatsApp** e clique em **Ativar/Publicar**

### Atualizações futuras

Para atualizar o plugin após uma nova versão:

```bash
cd /var/www/html/docroot/plugins/DialogHSMBundle
git pull
php bin/console cache:clear
php bin/console mautic:migrations:execute --bundle=DialogHSMBundle
```

---

## Configuração Global do Plugin

Acesse **Configurações → Plugins → 360dialog WhatsApp → Configuração**.

| Campo             | Descrição                                                        | Padrão                              |
|-------------------|------------------------------------------------------------------|-------------------------------------|
| **URL Base da API** | URL padrão da API 360dialog usada quando o número não define a sua | `https://waba-v2.360dialog.io/messages` |
| **Limite do Consumer** | Número máximo de mensagens processadas por execução do `dialoghsm:consume` | `50` |

---

## Cadastro de Números WhatsApp

Acesse **Canais → Números WhatsApp → Novo**.

| Campo          | Descrição                                                                            | Obrigatório |
|----------------|--------------------------------------------------------------------------------------|-------------|
| **Nome**       | Identificador amigável (ex: `Comercial SP`)                                          | ✅          |
| **Telefone**   | Número remetente no formato internacional (ex: `+5511999999999`)                     | ✅          |
| **API Key**    | Chave D360-API-KEY fornecida pela 360dialog                                          | ✅          |
| **URL Base**   | URL customizada da API. Se vazio, usa a URL global configurada no plugin             | ❌          |
| **Fila RabbitMQ** | Nome da fila RabbitMQ para envio assíncrono (ex: `queue`, `batch`)               | ❌          |

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

| Campo              | Descrição                                                                                           |
|--------------------|-----------------------------------------------------------------------------------------------------|
| **Fila (override)** | Sobrescreve a fila RabbitMQ do número para esta ação. Se vazio, usa o `queue_name` do número. Ex: `queue`, `batch` |

> O delay **não é aplicado** no enfileiramento — todas as mensagens vão para o RabbitMQ imediatamente.
> O ritmo de envio é controlado pelo consumer (cron).

### Estrutura do Payload

A chave `content` define o nome do template. As demais chaves são variáveis enviadas ao template:

```
content  = nome_do_template
nome     = {{ contact.firstname }}
telefone = {{ contact.phone }}
codigo   = {{ contact.custom_field }}
```

---

## Envio Assíncrono via RabbitMQ

### Configuração das Filas

O plugin usa o transport `whatsapp` do Symfony Messenger. Configure no `messenger.yaml` do Mautic:

```yaml
framework:
  messenger:
    transports:
      whatsapp:
        dsn: "amqp://user:password@rabbitmq:5672/%2f/whatsapp"
        options:
          queues:
            queue: ~   # fila massiva
            batch: ~   # fila horário comercial (lotes)
```

### Consumer Manual

```bash
# Consumir a fila massiva
php bin/console dialoghsm:consume --queue=queue --limit=50 --time-limit=60

# Consumir a fila de lotes (horário comercial)
php bin/console dialoghsm:consume --queue=batch --limit=100 --time-limit=540

# Consumir todas as filas (usa limite configurado no plugin)
php bin/console dialoghsm:consume
```

**Opções disponíveis:**

| Opção           | Descrição                                                       | Padrão              |
|-----------------|-----------------------------------------------------------------|---------------------|
| `--queue`       | Nome da fila RabbitMQ a consumir. Omitir = consome todas        | *(todas)*           |
| `--limit`       | Máximo de mensagens a processar. Substitui o limite global      | Limite do plugin    |
| `--time-limit`  | Para o consumer após N segundos. `0` = sem limite               | `60`                |

### Configuração do Cron

Recomenda-se dois crons independentes por fila:

```bash
# Fila massiva: qualquer horário, a cada minuto
* * * * * php /var/www/html/bin/console dialoghsm:consume --queue=queue --limit=50 --time-limit=60

# Fila de lotes (horário comercial): seg a sex, 8h às 18h, a cada 10 minutos
*/10 8-17 * * 1-5 php /var/www/html/bin/console dialoghsm:consume --queue=batch --limit=100 --time-limit=540
```

> O `--time-limit` deve ser menor que o intervalo do cron para evitar sobreposição de execuções.
> Para cron de 10 minutos, use `--time-limit=540` (9 minutos).

---

## Estrutura de Banco de Dados

O plugin cria as seguintes tabelas via migrations:

| Tabela                     | Descrição                                      |
|----------------------------|------------------------------------------------|
| `dialog_hsm_numbers`       | Números WhatsApp cadastrados                   |
| `dialog_hsm_message_log`   | Log de todos os envios realizados              |

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
- Sem `--time-limit`, o consumer aguarda novas mensagens indefinidamente (comportamento de daemon)

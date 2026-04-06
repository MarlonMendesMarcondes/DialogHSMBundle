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
| Extensão PHP `amqp` | qualquer *(obrigatória se usar RabbitMQ)* |

> **Atenção:** O transport `amqp://` do Symfony exige a extensão nativa `amqp` do PHP. Ela **não vem instalada por padrão** — inclusive em ambientes LiteSpeed (lsphp). Veja a seção [Instalando a extensão amqp](#instalando-a-extensão-amqp-litespeed--vps) se estiver usando LiteSpeed ou VPS sem Docker.

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

### 3. Criptografar API Keys existentes (obrigatório em atualizações)

A partir da versão **1.2.0** as API Keys são armazenadas criptografadas. Após o `mautic:plugins:reload`, execute o comando abaixo para migrar os registros existentes:

```bash
php bin/console dialoghsm:encrypt-api-keys
```

> Com Docker:
> ```bash
> docker exec mautic_app php /var/www/html/bin/console dialoghsm:encrypt-api-keys
> ```

Use `--dry-run` para visualizar o que seria alterado sem efetuar mudanças:

```bash
php bin/console dialoghsm:encrypt-api-keys --dry-run
```

> Números criados após a atualização são criptografados automaticamente ao salvar. O comando é necessário apenas para migrar chaves já existentes no banco.

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
| **Telefone**   | Número remetente no formato E.164 (ex: `+5511999999999`)                             | ✅          |
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
MAUTIC_MESSENGER_DSN_WHATSAPP=amqp://user:password@localhost:5672/%2f
```

> Em Docker, substitua `localhost` pelo nome do serviço RabbitMQ (ex: `rabbitmq`).

**Dead Letter Queue (opcional):** mensagens que falharam em todas as 3 tentativas de reenvio são marcadas como `dlq` no log. Por padrão são descartadas. Para armazená-las em uma fila separada:

```bash
MAUTIC_MESSENGER_DSN_WHATSAPP_FAILED=amqp://user:password@localhost:5672/%2f?queues[]=whatsapp_failed
```

Sem essa variável o comportamento é idêntico — apenas o status no log muda para `dlq`.

### 2. Criar as filas no RabbitMQ

Crie as filas com os **mesmos nomes** configurados nos campos *Fila Massiva* e *Fila Batch* de cada número:

```bash
rabbitmqctl add_queue bulk
rabbitmqctl add_queue batch
```

> Se a fila não existir no RabbitMQ, a mensagem é descartada silenciosamente.

### 3. Configurar o Cron

```bash
# Fila bulk: qualquer horário, a cada minuto
* * * * * php /var/www/html/bin/console dialoghsm:consume --mode=bulk --time-limit=60

# Fila batch (horário comercial): seg a sex, 8h às 18h, a cada 10 minutos
*/10 8-17 * * 1-5 php /var/www/html/bin/console dialoghsm:consume --mode=batch --time-limit=540
```

### Consumer Manual

```bash
# Fila padrão bulk
php bin/console dialoghsm:consume --mode=bulk --time-limit=60

# Fila padrão batch
php bin/console dialoghsm:consume --mode=batch --time-limit=60

# Fila com nome customizado
php bin/console dialoghsm:consume --queue=nome_da_fila --time-limit=60

# Todas as filas
php bin/console dialoghsm:consume
```

| Opção           | Descrição                                                                      | Padrão           |
|-----------------|--------------------------------------------------------------------------------|------------------|
| `--mode`        | Atalho para filas padrão: `bulk` ou `batch`                                    | *(nenhum)*       |
| `--queue`       | Nome exato da fila. Tem prioridade sobre `--mode`                              | *(todas)*        |
| `--limit`       | Máximo de mensagens a processar                                                | Limite do plugin |
| `--time-limit`  | Para o consumer após N segundos. `0` = sem limite                              | `60`             |

---

## Logs de Envio

Acesse **Canais → Logs de Envio** para auditar todos os envios realizados pelo plugin.

| Coluna        | Descrição                                              |
|---------------|--------------------------------------------------------|
| **Data/Hora** | Timestamp do envio                                     |
| **Contato**   | Link para o perfil do contato no Mautic                |
| **Telefone**  | Número de destino                                      |
| **Template**  | Nome do template HSM enviado                           |
| **Status**    | `sent` (verde), `failed` (vermelho) ou `dlq` (amarelo) |
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

**Contatos não recebem mensagem (status `failed` com `invalid_phone`):**
- O telefone do contato deve estar no formato E.164: `+` seguido do código do país sem zeros à esquerda, ex: `+5511999999999`
- Números sem `+`, com espaços ou com código de país `0xx` são rejeitados antes do envio

**Consumer não finaliza:**
- Sempre use `--time-limit` ao rodar manualmente

---

## Instalando a extensão amqp (LiteSpeed / VPS)

Se estiver usando **LiteSpeed** (`lsphp83`) ou qualquer VPS sem Docker, a extensão `amqp` precisa ser compilada manualmente. O pacote `lsphp83-amqp` **não existe** nos repositórios padrão.

### Passo 1 — Instale as dependências

```bash
apt install -y lsphp83-dev librabbitmq-dev build-essential autoconf
```

### Passo 2 — Baixe e extraia o código-fonte da extensão

```bash
cd /tmp
wget https://pecl.php.net/get/amqp-2.1.2.tgz
tar xzf amqp-2.1.2.tgz
cd amqp-2.1.2
```

### Passo 3 — Compile usando o phpize do lsphp83

```bash
/usr/local/lsws/lsphp83/bin/phpize
./configure --with-php-config=/usr/local/lsws/lsphp83/bin/php-config
make && make install
```

### Passo 4 — Ative a extensão

```bash
echo "extension=amqp.so" > /usr/local/lsws/lsphp83/etc/php/8.3/mods-available/amqp.ini
```

### Passo 5 — Confirme

```bash
/usr/local/lsws/lsphp83/bin/php -m | grep amqp
# deve retornar: amqp
```

### Alternativas sem compilação

Se não quiser compilar, o plugin suporta dois outros transports que não precisam de extensão adicional:

**Redis** (recomendado — extensão já vem com o lsphp83):
```bash
# .env.local
MAUTIC_MESSENGER_DSN_WHATSAPP=redis://localhost:6379
```

**Banco de dados** (zero dependência extra):
```bash
# .env.local
MAUTIC_MESSENGER_DSN_WHATSAPP=doctrine://default?table_name=messenger_messages

# Criar a tabela uma única vez:
php bin/console messenger:setup-transports
```

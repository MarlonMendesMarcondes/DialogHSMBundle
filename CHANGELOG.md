# Changelog

Um bloco por dia, do mais novo para o mais antigo. Formato fixo: `## AAAA-MM-DD` e uma linha `- ` por mudança (a aba Atualizações da configuração do plugin lê este arquivo).

## 2026-11-27
- Configuração do plugin separada em abas: Conexão, Filas e velocidade, Lotes programados, Proteções de envio, Saldo e alertas, Logs e Atualizações (este histórico)
- Lotes programados na ação de fila (Batch — lotes programados)

## 2026-11-26
- Horário do gráfico por hora da campanha

## 2026-11-25
- Expõe botão clicado no relatório de mensagens HSM

## 2026-11-24
- Tema escuro e papel de parede do chat na prévia do WhatsApp
- Seletor de origem das variáveis no formulário guiado
- Resolve no envio as variáveis com origem do formulário guiado
- Largura mínima de 360px na prévia, sem largura fixa

## 2026-11-23
- Prévia de carrossel e visualização vertical no componente _wa_preview
- Mantém o comportamento anterior nos envios do n8n e de outros plugins
- Formulário guiado na Mensagem WhatsApp
- Formulário guiado de template nas ações novas
- Separa as ações de envio em versão legada e versão nova
- Pausa os envios do número bloqueado por falta de pagamento
- Pré-valida o template antes do envio e registra o status invalid

## 2026-11-18
- Roteia template pela categoria no endpoint de Marketing Messages
- Reenvia por /messages template recusado pelo endpoint de Marketing Messages

## 2026-11-11
- Aceita template de carrossel (cards de imagem) na DialogHSMApi

## 2026-10-23
- Evita quebra do painel de Config quando balance_alert_recipients não é array

## 2026-10-14
- Botao da dashboard
- Aba Disparos agrupa por campanha e template, com opção por data

## 2026-10-09
- Backfill converte metadata antiga em JSON para o formato do Mautic
- Envio direto inline marca na campanha o envio recusado pela API
- Gráfico do dashboard em UTC e com as mensagens aguardando a Meta
- Broadcast respeita --limit, --batch e o intervalo de contatos
- Índices para as consultas quentes de dialog_hsm_message_log
- Webhook sem efeito duplicado em concorrência e sem decisão perdida
- Dashboard em UTC, timeline sem "Enviada" falsa e edição sem zerar throttle
- Webhook não perde resposta e grava status na execução certa
- Log da fila gravado antes do envio e "Limpar pendentes" cancela de verdade
- Clientes Redis do plugin autenticam com a senha do DSN
- Limitador de taxa respeita exatamente a taxa configurada
- Marketing Messages com campanha na mensagem, fila obrigatória e taxa certa
- Envio à 360dialog com timeout, DNS transitório e payload inválido permanente
- Metadata da campanha no formato do Mautic, DLQ e resposta no log certo
- Limpeza de logs roda no consumo da fila e preserva mensagens na fila
- Contato que reentra na campanha volta a receber o HSM
- Read sem delivered registra a entrega para o nó "Entregue" disparar
- Broadcast envia um HSM por contato mesmo com o contato em vários segmentos
- Mantém no RabbitMQ mensagens de campanha desativada até a reativação
- Decide contato da ação HSM ao enfileirar para o trigger não abortar a campanha

## 2026-10-08
- Não consumir contatos quando a ação HSM falha na montagem

## 2026-09-18
- Aumenta timeout de webhook de status para evitar desativação automática de campanhas

## 2026-08-24
- Corrige erro 500 ao salvar campanha 100% WhatsApp sem ação de e-mail

## 2026-08-21
- Arredondar exibição de saldo e custo mensal para 2 casas decimais

## 2026-08-19
- Mantém Partner API Key ao salvar a tela de Config com o campo vazio

## 2026-08-18
- Corrige reset do MailHelper entre alertas e UX dos botões da tela de saldo
- Adiciona filtro de período (3M/6M/12M/Tudo) no gráfico de custo mensal
- Adiciona consulta de saldo por número via 360dialog Partner API

## 2026-08-17
- Marca MessageLog como failed quando dispatch para whatsapp_direct falha

## 2026-07-21
- Reatribuir logs de WhatsApp ao mesclar contatos duplicados
- Propaga sent/failed/dlq para lead_event_log nos 3 pontos que faltavam
- Não gerar evento "sent" falso no backfill para envios que falharam

## 2026-07-13
- Não contar restrição de entrega da Meta como falha da campanha

## 2026-07-10
- Adiciona tabelas de últimos templates enviados no relatório HSM
- Corrige labels sem tradução nos gráficos de pizza do relatório HSM

## 2026-07-09
- Adiciona suporte à Webhook nativa do Mautic para eventos de WhatsApp
- Completa +55 automaticamente em números BR sem código de país

## 2026-07-02
- Esconde botão "Visualizar relatório completo" no widget HSM da Dashboard
- Habilita auto_setup no transporte whatsapp_direct (Redis)
- Ignora envio quando número WhatsApp está desativado

## 2026-06-30
- Consumer name único no whatsapp_direct e corrige warning replied
- Adiciona respondidas nos gráficos e corrige gráficos de linha

## 2026-06-29
- Substitui microtime por sleep spy no handler de lote
- Adiciona barra "Respondido" no gráfico do dashboard

## 2026-06-27
- Adiciona evento "Enviado via Marketing Messages" no timeline do contato
- Corrige eventos de timeline e adiciona Respondido nas stats do MM

## 2026-06-26
- Normaliza phone para dígitos puros no RedisContactCache
- Usa fallback correto waba-v2 quando baseUrl não configurado
- Gera 'vars' automaticamente em resolveTokens e adiciona testes
- Isola operações pós-flush em try-catch no persistReply
- Corrige markReplied no Cenário A para normalizar número BR
- Corrige Scenario B — free text reply não registrava resposta
- Normaliza telefone no RedisContactCache removendo + da chave
- Corrige Cenário B de resposta livre e integra cache de contato
- Manter um padrao na escrita do replied nos actions
- Status "Respondida" no timeline e na lista de logs HSM
- Evento timeline para resposta inbound WhatsApp
- Redis Hash para correlação de resposta WhatsApp (Scenario A/B)
- Correlação precisa de resposta via context.id (Scenario A/B)
- Métrica de respostas inbound na dashboard

## 2026-06-25
- Sistema de pontos e cache Redis para respostas inbound
- Integra sistema de pontos ao webhook de leitura HSM

## 2026-06-24
- Popula metadata da ação de campanha ao receber webhook
- Corrige exibição de datas locais nos detalhes dos eventos
- Corrige timezone e gap de tempo nos eventos WhatsApp
- TTL garantido nas chaves Redis do rate limiter
- Guard de idempotência no SendWhatsAppMessageHandler
- Alinha timestamp "sent" com date_triggered da campanha

## 2026-06-23
- Corrige fuso horário dos eventos HSM na timeline do contato
- Corrige query de falha de campanha no WebhookCampaignSubscriber
- Exibe dados WhatsApp nas entradas de campanha na timeline do contato

## 2026-06-01
- Compatibilidade com MariaDB nos gráficos HSM
- Gráficos HSM no builder de relatórios e correções
- Relatórios HSM nativos, correção do fluxo webhook e taxa de disparos

## 2026-05-29
- Adiciona fonte de relatório HSM nativa do Mautic

## 2026-05-20
- DC-03 inicializar $valid = false antes do bloco if em newAction()
- DC-02 remover AjaxController.php sem uso (sem rotas nem referências)
- DC-01 remover método privado pinHostToIp() morto de DialogHSMApi
- Trata 409 do multi_webhook como estado desabilitado em vez de erro
- Corrige classes Bootstrap do botão no painel de estatísticas WhatsApp
- Remove validação SSRF de URL de mídia no buildHeaderParameter
- Envia URL de mídia com hostname original para a 360dialog
- Corrige logs duplicados em campanhas, HTTP 400 no MM e migra broadcasts para Redis
- Corrige payload MM — resolveTokens converte lista para key-value

## 2026-05-19
- Corrige envios MM, duplicação de logs e permissões de publicação
- Implementa canal WhatsApp em Mensagens de Marketing

## 2026-05-18
- Adiciona dialoghsm_meta_error_code e converte status para select

## 2026-05-16
- Aguarda confirmação da Meta antes de avançar campanha e distingue origens de falha

## 2026-05-15
- Adiciona pending_webhook na dashboard e lista de logs
- Aguarda webhook da Meta antes de avançar contato na campanha

## 2026-05-14
- Campo de link do multiwebhook ajustado
- Corrige timezone no gráfico , cache unificado e volta funil cumulativo
- Corrige funil cumulativo no gráfico e dessincronização de cache

## 2026-05-13
- Corrige inconsistências visuais no dashboard e timeline
- Consolida queries de timeline em 1 e adiciona cache no dashboard
- Melhora timeline de contato com labels descritivos e timestamps de entrega/leitura
- Reverte migrations para sintaxe MariaDB (IF NOT EXISTS)
- Timestamps de entrega e leitura para linha do tempo do contato
- Registra eventos WhatsApp na linha do tempo do contato
- Reordena colunas da aba Disparos e aplica funil cumulativo nas métricas

## 2026-05-12
- Implementa callbacks de status delivered/read via 360dialog multi_webhook

## 2026-05-05
- Adiciona limpeza de mensagens pendentes (queued) nos logs

## 2026-04-16
- Configurado para usar a exchange padrao do rabbitmq
- Redis exchange
- Botao do switch on e off do plugin, liga e desliga o plugin do mauitc
- Filtro por template nos logs e controle exato do consumer batch

## 2026-04-15
- Substitui btn-default por btn-secondary nos botões de período
- Corrige seletor de período e tema dos botões do gráfico
- Unifica rate limiting de bulk e batch via Redis sliding window

## 2026-04-14
- Rate limiting via Redis, retry automático para erros transitórios e registro DLQ — v1.3.2
- Rate limit por número isolado (5/s × N números)

## 2026-04-13
- Declara exchange whatsapp em todos os transportes AMQP
- Evita log queued orfao quando dispatch para RabbitMQ falha
- Filtro de remetente como dropdown na tela de logs
- Corrige exibição de datas usando timezone configurado no Mautic
- Corrige falhas pré-existentes na suite unitária
- Classifica erros da API 360dialog para retry seletivo
- Prune em lotes

## 2026-04-12
- Corrige SSRF e DNS rebinding em DialogHSMApi

## 2026-04-10
- Corrige exchange AMQP vazio e elimina prune por mensagem na fila batch
- Dashboard de logs aceita seletor de período (7/14/30 dias)
- Fila inexistente no RabbitMQ retorna sucesso (NOT_FOUND = fila vazia)
- Retenção configurável de logs por contagem e por dias
- Respeitar log_max_records configurado no plugin getLogMaxRecords() adicionado em SendWhatsAppMessageHandler lê o valor de log_max_records via IntegrationsHelper (cache 30s, fail-open em 10.000). Método tornado público para reutilização pelo SendWhatsAppDirectBatchMessageHandler via $this->handler->getLogMaxRecords(), sem injeção extra. Ambos os handlers passam o valor para prune() em vez do default hardcoded.
- Respeitar log_max_records nas configurações do plugin

## 2026-04-09
- Corrige travamento síncrono, rate limiter e confiabilidade de testes Guard inline (CampaignSubscriber): - send_delay > 0 com null://null agora é zerado automaticamente + warning no log,   evitando que usleep() bloqueie o processo mautic:campaigns:trigger - Redis configurado: send_delay passa intacto ao worker isolado BulkRateLimiter: - Corrige getRedis() para selecionar o DB correto do DSN (ex: /3),   mantendo consistência com o transporte Symfony Messenger - Antes com null://null o rate limiter era fail-open (desativado silenciosamente)
- Registra DLQ para todos os itens de SendWhatsAppDirectBatchMessage
- Rate limiter Redis para envio bulk + correções de UX
- Create or update do numero estava quebrando pelo uso de variaveis de implementacao deletadas Remove campos send_delay e batch_limit da ação Queue

## 2026-04-08
- Theme mode da cor dos botoes na dashboard e nos logs
- Exibe status queued no dashboard e no filtro de logs
- Limite de logs configurável pelo painel do plugin
- Status 'queued' para rastreamento de mensagens na fila

## 2026-04-07
- Dashboard de métricas WhatsApp (v1.3.1)

## 2026-04-06
- Validar nomes de fila contra caracteres especiais
- Prevenir SSRF em URLs de mídia do webhook de campanha
- Rate limiting no webhook e correção de injeção do cache. README a regra crítica de migrations: o mautic:plugins:reload
- Criptografa api_key de WhatsApp no banco de dados: Implementa criptografia transparente do campo api_key na tabela dialog_hsm_numbers usando o EncryptionHelper do Mautic

## 2026-03-29
- Messages.ini do filter
- Messages.ini do filtro

## 2026-03-27
- Registra falhas de campanha no MessageLog e normaliza telefone

## 2026-03-25
- Botão de publicar/despublicar número WhatsApp não funcionava
- V1.1.2 — validação E.164 no envio de campanha

## 2026-03-24
- V1.1.1 — Dead Letter Queue (DLQ) com log e retry automático

## 2026-03-23
- Migrações idempotentes com IF NOT EXISTS e WHERE NOT EXISTS
- Suporte a Redis no envio direto com rate limiting via formulário de campanha

## 2026-03-19
- Exceção caso nao exista fila configurada e teste
- Form nao salvando url custom e maxlength na apikey

## 2026-03-18
- Ajuste de ms para s no envio direto e testes

## 2026-03-09
- Readme rabbit

## 2026-03-06
- Campaignsubscriber e teste

## 2026-03-05
- Command e test

## 2026-02-27
- Builder de envio direto
- Adiciona fila batch no numero WhatsApp e seleciona modo de override nas filas
- Corrige descrição das filas
- Adiciona campo queue_override e melhora tratamento de erros no envio síncrono

# Configurações dos nós e preparação para o CRM

## O que mudou

Todos os 44 tipos de nós possuem um formulário próprio. Um clique no card abre o painel lateral; a engrenagem e o duplo clique continuam funcionando. Alterações ficam no rascunho do canvas ao trocar de nó. **Salvar configuração** valida o nó e salva o fluxo no backend; o fechamento do painel, sozinho, não grava no servidor. O cabeçalho indica alterações não salvas e o navegador avisa ao sair.

- Listas: público inicial da jornada, lista local específica ou referência de lista do CRM.
- Segmentos: escolha do segmento de entrada/saída.
- Campanhas: escolha por canal e atividade esperada; seleção de template com verificação de vínculo à campanha.
- WhatsApp: template, idioma, parâmetros e saídas de botões editáveis sem JSON.
- Formulários, metas, lojas, responsáveis, funis e etapas: seleção de referências do CRM.
- Campos, tags e estágios: busca no catálogo ou chave informada manualmente.
- Condições e divisão múltipla: campos, operadores e valores em formulário.
- Tempo: minutos, horas ou dias; armazenamento normalizado em minutos.
- Webhook/Sync CRM: payload JSON avançado, sem credenciais nos nós.

Parâmetros declarados pelo template precisam ser preenchidos para validar a configuração. Botões não podem repetir nomes nem usar portas reservadas (`sent`, `delivered`, `read`, `failed`). Não é possível remover/renomear um botão com conexão existente sem antes remover essa conexão.

## Como preparar agora

1. Acesse **Referências do CRM** na barra lateral, ou **Gerenciar referências** dentro de um seletor do nó.
2. Escolha o tipo, clique **Adicionar referência** e informe nome e ID no CRM. Campanhas/templates/remetentes também têm canal.
3. Em templates, podem ser informados o ID da campanha, assunto, idioma, parâmetros e botões. Isso descreve o recurso que o CRM fornecerá; não cria uma campanha nem envia mensagens.
4. Selecione a referência no nó e clique **Salvar configuração**.
5. Use **Validar fluxo** para conferir a preparação sem ativar a jornada nem exigir provedores conectados.

O catálogo não foi preenchido com campanhas ou segmentos reais de outro projeto. Referências cadastradas manualmente recebem origem **Preparado**. Recursos recebidos pela API assinada recebem origem **Sincronizado**. A identidade é composta por tipo e ID externo; os nomes podem mudar sem invalidar as seleções.

Referências preparadas podem ser salvas e validadas. A ativação verifica referências sincronizadas e, quando necessário, a conexão de execução. O objetivo desta entrega é deixar a configuração pronta; não conectar os provedores agora.

## Contrato do catálogo — versão 1

Interface PHP: `App\Contracts\CrmCatalog`, ligada a `DatabaseCrmCatalog` no `AppServiceProvider`. Ao incorporar o módulo, o CRM pode substituir essa implementação por consultas ao próprio domínio ou manter o catálogo espelhado. O editor consome o mesmo contrato em ambos os casos.

Tipos: `segment`, `campaign`, `list`, `template`, `field`, `tag`, `stage`, `user`, `form`, `goal`, `pipeline`, `store`, `sender`.

Consulta com autenticação de sessão:

```http
GET /api/crm/catalog?kind=campaign&channel=email&q=boas&page=1
GET /api/crm/catalog?kind=segment&id=segmento-vip
```

Resposta de busca: `data`, `page`, `has_more`, `total`; páginas de 25 itens ativos, com busca por nome/ID. Consulta por ID também devolve referências inativas, para que o editor explique seleções que perderam validade. Cada item contém `kind`, `external_id`, `name`, `channel`, `origin`, `active`, `metadata`. IDs são strings opacas; não é exigido ID numérico do CRM.

Cadastro manual: `POST /api/crm/catalog`, com sessão e CSRF. Recursos já gerenciados pelo CRM não podem ser sobrescritos por esse formulário.

Sincronização para o CRM futuro:

```http
POST /crm/catalog/sync
Content-Type: application/json
X-MA-Timestamp: <timestamp Unix em segundos>
X-MA-Signature: <HMAC-SHA256 hexadecimal>
```

Assinatura: `HMAC_SHA256(MA_CRM_CATALOG_SECRET, timestamp + "." + corpo JSON exato)`. Tolerância de cinco minutos. O segredo é exclusivo do catálogo e não foi configurado nesta entrega; a sincronização fica fechada até a integração. Não reutilizar senhas de usuário. Os headers seguem o formato dos eventos, com segredo separado.

Exemplo de corpo:

```json
{
  "resources": [
    {"kind":"segment","external_id":"segmento-vip","name":"Clientes VIP","active":true},
    {"kind":"campaign","external_id":"campanha-boas-vindas","name":"Boas-vindas","channel":"email","active":true},
    {"kind":"template","external_id":"template-boas-vindas","name":"E-mail inicial","channel":"email","active":true,
     "metadata":{"campaign_id":"campanha-boas-vindas","subject":"Bem-vindo","parameters":["nome"]}}
  ]
}
```

Até 200 recursos por lote. A operação é transacional e faz upsert por `kind + external_id`; repetir o mesmo lote não duplica os recursos. Ausência em um lote não exclui recursos. Para desativar, enviar explicitamente `active:false`. Sincronizar uma referência preparada com o mesmo tipo/ID preserva sua identidade e a marca como gerenciada pelo CRM. O emissor deve enviar atualizações em ordem; este contrato não implementa resolução por versão externa para lotes fora de ordem.

Metadados aceitos: `description`, `data_type` (`text`, `number`, `date`, `boolean`), `campaign_id`, `parameters` (nomes), `buttons` (identificadores), `subject`, `language`. Tokens/credenciais e payloads arbitrários não são aceitos como metadados do catálogo.

## Configuração — versão 2

Novos nós usam `_config_version:2`. As escolhas ficam em `node.data.settings` e nas tabelas/versionamento existentes. Exemplos:

```json
{"key":"segment_added","settings":{"_config_version":2,"segment_id":"segmento-vip"}}
```

```json
{"key":"email_event","settings":{"_config_version":2,"campaign_id":"campanha-boas-vindas","event":"clicked"}}
```

```json
{"key":"send_whatsapp","settings":{"_config_version":2,"campaign_id":"campanha-agendamento","template":"template-agendamento","language":"pt_BR","parameters":{"nome":"{{contact.name}}"},"buttons":["quero_agendar"]}}
```

`_labels` guarda apenas nomes para exibição no card. A validação e o roteamento usam os IDs, nunca esses rótulos. A resolução de `{{contact.name}}` e outros parâmetros de envio será responsabilidade do adaptador do CRM; o módulo guarda o mapeamento e não simula o envio.

Schemas: `config/node-schemas.json`. Validação compartilhada: `NodeConfiguration`. API para configuração individual: `POST /api/node-config/validate` com `key` e `settings`. API de preparação do fluxo: `POST /api/graph/validate` com `graph`. Ambas exigem sessão/CSRF. A resposta válida inclui `activation_pending`, sem exigir a ativação para salvar o planejamento.

Fluxos anteriores permanecem legíveis. Templates em texto dos nós antigos continuam disponíveis por compatibilidade; ao usar o novo formulário eles passam a selecionar uma referência do catálogo.

## Eventos filtrados pelas escolhas

`POST /events` mantém o HMAC de eventos com `MA_WEBHOOK_SECRET`. O contrato foi ampliado com `attributes`, para que o gatilho não admita eventos de campanhas/segmentos diferentes da configuração.

```json
{
  "event_key":"crm-evento-unico-123",
  "contact_id":42,
  "type":"email_event",
  "attributes":{"campaign_id":"campanha-boas-vindas","event":"clicked"}
}
```

Para segmentos, usar `type:segment_added` ou `segment_removed` e `attributes.segment_id`. Outros filtros: `form_id`, `goal_id`, `url` exata, `tag`, `stage`, `event_name`, `store_id`, `order_status`, `list_id`, `audience_ids`. Em WhatsApp podem ser informados `campaign_id`, `event` e `button_key`.

O nó de espera por atividade também verifica a campanha, quando configurada; uma atividade diferente da esperada não antecipa o timeout. Callbacks continuam exigindo `token_id` para correlacionar a espera à execução.

Esta entrega não implementa autenticação compartilhada, mapeamento de contatos externos nem avaliação de segmentos no CRM. A admissão continua usando os contatos e o público local da jornada; ao integrar o módulo, esses vínculos deverão ser mapeados pelo CRM. O catálogo de referências contém definições, não os membros dos segmentos.

## Implantação e verificação

Alteração restrita a `/srv/zyrex-ma`: migração aditiva `crm_resources`, backend e assets novos. Backup de banco e código anterior em `backups/`; código validado em diretório candidato antes da promoção. Nenhuma configuração do Nginx, banco ou aplicação de outro projeto foi modificada.

Testes: `NodeConfigurationTest` e suíte anterior. Evidências: `evidence/node-config/`. Referências oficiais de implementação: [eventos de nós do Vue Flow](https://vueflow.dev/guide/node.html) e [validação do Laravel](https://laravel.com/docs/12.x/validation).

Resultado final: 25 testes / 163 assertions aprovados. Chromium validou clique simples, cadastro de referência pelo painel, seleção de segmento/campanha/template, parâmetros, botões, validação sem CRM, salvar/reabrir e preservação de rascunho ao trocar de nó; nenhum erro JavaScript. Dados temporários de homologação foram excluídos ou desativados. Na comparação desta implantação, os 34 containers de outros projetos mantiveram IDs e horários de início.

# Dashboard por jornada

Acesso: **Cadências → Relatórios → Dashboard por jornada**. No fluxo de voz e WhatsApp, **Ver dashboard** abre o painel filtrado pela jornada. O relatório anterior de chamadas e tabulação continua disponível.

O período usa America/Sao_Paulo, padrão de 30 dias e máximo de 366 dias por consulta. Indicadores, barras e ranking de botões abrem detalhes paginados (25 registros); a evolução diária permite filtrar um dia. Cada registro abre um diálogo com horários, mensagem, botão, vínculo com a mensagem original ou histórico de tabulação. A comparação entre jornadas aparece ao selecionar todas.

## Definições

- **Chamadas feitas:** tentativas reais com início registrado. Tentativas repetidas ao mesmo telefone contam individualmente; contatos únicos são apresentados à parte.
- **Atendidas:** início registrado e atendimento técnico da telefonia ou conclusão com atendimento. Isso não comprova conversa humana; essa informação vem da tabulação.
- **Não atenderam:** resultado `no_answer`, sem atendimento registrado. Ocupadas, canceladas e falhas são separadas.
- **Falhas de chamada:** falhas técnicas, incluindo solicitações que falharam antes da discagem; por isso podem existir fora do total de chamadas iniciadas.
- **Disparadas:** solicitações reais de mensagem, exceto canceladas. Inclui pendentes e falhas, preservando a visão de todas as tentativas de envio.
- **Entregues:** estados `delivered` e `read`; **lidas:** estado `read`. São etapas acumuladas, não categorias para somar.
- **Falhas WhatsApp:** `failed` ou `undelivered`. Mensagens sem confirmação de entrega permanecem separadas; ausência de confirmação não é falha comprovada.
- **Respostas:** mensagens recebidas no período, incluindo respostas por botão; o número de pessoas distintas também é mostrado.
- **Cliques em botões:** respostas QR com contexto validado contra a mensagem original, remetente, destinatário e ID de um botão de resposta enviado. O título vem do conteúdo enviado, não do texto informado pelo cliente. Digitar o título não conta como clique. Exibição/leitura não conta como clique. Botões de link não têm rastreamento nesta versão. O ranking lista até 100 combinações de ID e título; o total não é truncado.

As datas se referem ao início da chamada (ou criação da solicitação sem início), criação do envio e recebimento da resposta. Entrega e leitura mostram o estado atual das mensagens enviadas no período, mesmo se a confirmação chegou depois. O painel não mistura chamadas simuladas ou automações MA sem eventos do discador.

## Atribuição e histórico

Respostas com contexto válido são vinculadas à mensagem original. Sem contexto, só há inferência quando existe uma única jornada entre as mensagens enviadas pelo mesmo número ao destinatário nos sete dias anteriores. Mensagens avulsas concorrentes ou múltiplas jornadas deixam a resposta sem atribuição. Eventos de botão sem contexto válido não são considerados cliques. A interface identifica inferências e respostas sem vínculo.

`ma:backfill-journey-replies` classifica eventos recebidos anteriormente, de forma idempotente, sem disparos e sem alterar datas originais. Não inventa respostas, cliques ou horários de confirmações ausentes. Novas confirmações QR entram em `wa_events`; confirmações antigas podem ter apenas o estado atual.

## API e acesso

- `GET /api/voice/journey-reports`: indicadores, evolução diária, comparação e botões.
- `GET /api/voice/journey-reports/details`: registros por `metric`, com `campaign_id`, `from`, `to`, `day`, `phone`, `button_id`, `button_label` e `page`.
- `GET /api/voice/journey-reports/records/{calls|messages}/{uuid}`: detalhes de um registro.

Exige sessão de administrador/supervisor do workspace. Agentes não recebem acesso administrativo por este painel. Consultas não chamam provedores, não conciliam estados, não enviam mensagens e não iniciam filas. Nenhuma credencial, grant ou hash de autorização é exposto.

Validação: testes automatizados em SQLite e PostgreSQL isolados; navegador em desktop e celular; conferência dos dados reais da jornada de teste com duas chamadas e duas mensagens, sem novos disparos.

## Conferência após publicação

Jornada 5, teste de dois contatos de homologação, período 04–05/10/2026: 2 chamadas feitas, 0 atendidas, 2 não atendidas; 2 mensagens disparadas e entregues, 1 leitura confirmada, 0 falhas; 2 respostas e 2 cliques no botão **Podemos falar agora** (`agora`), por duas pessoas. As duas interações foram vinculadas pelo contexto original, sem inferência. Evidência em `evidence/journey-dashboard-20261005/live-report.json`.

212 testes e 1.676 asserções passaram em SQLite e PostgreSQL. A verificação no navegador da versão publicada passou sem mutações. A implantação preservou as 21 chamadas e 3 mensagens de saída existentes, além das configurações de campanhas e filas; nenhum serviço foi reiniciado.

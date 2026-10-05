# Equipe, receptivo, integrações, conversas e supervisão

Entrega de 05/10/2026 no projeto MA. O CRM e os outros projetos da VM permanecem fora do escopo. Implementação e validação automática não substituem homologação de áudio, aparelho e permissões dos provedores.

## Acesso às entregas

- **Equipe e acessos:** cadastro de atendentes com senha inicial, perfis administrador/supervisor/atendente, ativação e vínculos às filas existentes. Supervisores gerenciam atendentes; administradores também gerenciam perfis privilegiados. A senha tem no mínimo 12 caracteres e não é devolvida pela API. O usuário deve compartilhá-la por canal privado. Não há convite por e-mail nesta entrega.
- **Minha operação:** operação de saída existente e conexão do receptivo no navegador. A disponibilidade é compartilhada. Conectar o receptivo habilita a recepção, mas não inicia a sequência de saída; o agente ainda escolhe sua fila e confirma a disponibilidade no discador.
- **Receptivo:** número, fila, ativação, tempo de toque e espera máxima. “Aplicar na Twilio” configura os callbacks de voz e encerramento do número configurado no MA; recusa substituir outra rota, aplicação ou tronco existente. A configuração original é guardada de forma privada e criptografada. A rota recebida tem ativação independente da campanha de saída.
- **Conversas:** histórico por remetente e cliente, status de mensagens, botões respondidos, responsável, fila, encerramento/reabertura e resposta manual. A configuração “Distribuição” associa remetente e fila, com limite de conversas abertas por atendente. A distribuição prioriza agentes disponíveis com menos conversas abertas. Sem agente elegível, a conversa aguarda na fila.
- **Integrações:** credenciais com escopos e revogação, listas e atendentes permitidos, origem do componente incorporável e webhook opcional. Disponível somente ao administrador. [Contrato OpenAPI](https://ma.zyrex.ia.br/integrations/openapi.json) e [cliente de referência](https://ma.zyrex.ia.br/integrations/reference).
- **Saúde e limites:** presença e ocupação da equipe, contagem diária UTC, pausa de novos atendimentos/envios, limites adicionais, custos conhecidos e conferência de chamadas. A pausa não encerra chamadas em andamento nem impede registrar callbacks e mensagens recebidas.

## Receptivo e capacidade compartilhada

A primeira implementação receptiva utiliza **Twilio Programmable Voice**, com áudio no navegador. A saída por SIP/Asterisk existente é preservada; recepção e transferência SIP não são declaradas homologadas por esta entrega.

1. Cadastre os atendentes e vincule-os à fila.
2. Configure a rota de entrada e aplique-a na Twilio.
3. Cada atendente acessa Minha operação, permite o microfone e conecta o receptivo. A disponibilidade pode ser pausada na mesma tela.
4. Ligue para o número configurado. O MA reserva um agente elegível e toca no navegador. Ao recusar ou não atender, a distribuição procura outro agente; a espera tem limite.
5. Durante a conversa, o atendente pode transferir para outro agente disponível da fila. A transferência é direta, sem consulta prévia. As duas capacidades ficam reservadas até a confirmação da mudança pelo provedor.
6. O resultado confirmado libera a telefonia. A tabulação pendente continua bloqueando novas reservas para o agente responsável.

Reservas são serializadas pelo bloqueio da linha `voice_runtime` no PostgreSQL. Entrada, saída, reservas e tabulações compartilham a verificação de ocupação. Uma segunda aba não assume a sessão receptiva de um atendente com sessão recente ou chamada reservada. Presença vencida não prova que uma chamada acabou.

O limite global permanece **uma chamada externa por vez**. A duração de conversa respeita o limite privado de homologação. Não elevar concorrência a partir do resultado de um teste de banco: ele não mede mídia, qualidade de áudio, CPS contratado ou capacidade do provedor.

Se callbacks faltarem, a capacidade permanece reservada. “Consultar provedor” consulta a chamada existente; não origina outra ligação. SIP segue o procedimento de conferência do PBX já existente. A desativação de usuário impede novas reservas; a remoção de filas/troca de perfil ou senha aguarda o encerramento e a tabulação.

## Respostas de WhatsApp e leads

Respostas manuais exigem assumir uma conversa aberta, ter recebido mensagem nas últimas 24 horas e respeitar opt-out e os limites privados do canal. Essa regra foi aplicada também à caixa QR como política conservadora do produto. Ela não transforma o conector QR em API oficial. Na Twilio, a janela de texto livre decorre da mensagem recebida do usuário; fora dela é necessário um template aprovado. [Conceitos oficiais](https://www.twilio.com/docs/whatsapp/key-concepts).

A resposta manual não remove `replied_at`, não restaura consentimento e não reativa a cadência. Cada envio tem uma chave idempotente e o resultado incerto nunca é reenviado automaticamente. Botões QR permanecem experimentais, com seleção e confirmação explícitas. A personalização utiliza os dados do público MA quando há contexto de campanha.

Mensagens efetivamente recebidas da Twilio podem guardar a referência do anúncio quando os campos de referral estiverem presentes. A ausência de campos não é convertida em atribuição inventada. [Webhook oficial da Twilio](https://www.twilio.com/docs/messaging/guides/webhook-request).

**Formulários sociais:** `POST /api/v1/leads` aceita dados normalizados enviados por um integrador autorizado, deduplica por origem + ID externo e cria um lead para revisão. Não cria uma mensagem recebida, não abre janela de WhatsApp nem envia abordagem. Dados de autorização existentes não são sobrescritos. A ligação direta com Facebook/Instagram Graph API depende da aplicação, páginas, tokens e permissões da Meta; não foi apresentada como conectada. O integrador pode enviar os eventos normalizados pelo contrato publicado.

## Contrato externo e discador incorporável

A API v1 atende o workspace do MA atual (workspace 1). Os recursos genéricos de automação ainda não foram convertidos em uma plataforma SaaS com várias organizações. Não expor essa instalação como serviço multiempresa sem essa evolução.

Crie uma credencial para cada sistema. O token `mai_` é mostrado uma vez e armazenado somente como hash. Escopos:

| Escopo | Uso |
| --- | --- |
| `lists:read` | Consultar as listas autorizadas e seus campos |
| `lists:write` | Importar até 100 contatos por requisição nas listas autorizadas |
| `calls:read` | Consultar chamadas e tabulação do workspace |
| `reports:read` | Consultar indicadores de jornadas |
| `events:read` | Consultar eventos por cursor e receber webhook opcional |
| `voice:embed` | Emitir sessões temporárias para atendentes explicitamente permitidos |
| `leads:write` | Receber leads normalizados do CRM ou de um integrador social |

Importação e leads exigem `Idempotency-Key` UUID. A mesma chave e corpo retornam o mesmo resultado; conteúdo diferente retorna 409. A importação é incremental e atômica, preserva exclusões, histórico e autorização existente. Não habilita campanhas nem disca por si só. São até 120 requisições por minuto por credencial.

O servidor do CRM solicita `POST /api/v1/embed-sessions` com `user_id`. A resposta traz `iframe_url`, `origin`, `access_token` temporário e `expires_in=600`. O navegador carrega o iframe com permissão de microfone. Ao receber `zyrex.ready`, o integrador envia `zyrex.session` com o token temporário por `postMessage`, usando a origem exata do MA. Ambos os lados validam origem e janela. O iframe avisa `zyrex.session-expiring` antes da expiração; o servidor emite uma nova sessão. O token principal e credenciais Twilio/SIP não entram nesse contrato de navegador. O componente usa Twilio API; não entrega senha SIP estática a um CRM externo.

O cliente de referência roda no próprio MA, com login de administrador e uma integração cuja origem permitida seja `https://ma.zyrex.ia.br`. Ele permite testar o handshake e a operação do agente sem alterar o CRM real. A presença de um iframe não comprova áudio bidirecional: microfone, políticas do navegador e infraestrutura devem ser homologados no CRM de destino.

Os eventos têm ID, versão, tipo, assunto, horário e dados mínimos. O webhook usa:

- `X-MA-Event-Id`: ID estável para deduplicação.
- `X-MA-Timestamp`: instante Unix.
- `X-MA-Signature`: HMAC-SHA256 de `timestamp + "." + corpo bruto`, com o segredo mostrado ao criar a integração.

O consumidor verifica assinatura em tempo constante e tolerância de horário de até cinco minutos, persiste e responde 2xx. Há até seis tentativas com espera exponencial; uma entrega pode ocorrer mais de uma vez. A fila de falhas permite reprocessamento explícito com o mesmo ID. Destinos exigem domínio HTTPS público, validação de DNS e conexão fixada ao IP validado; redirecionamentos e redes internas são recusados. A revogação bloqueia novas requisições, sessões incorporadas e entregas pendentes.

## Custos, limites e recuperação

Os limites da tela são adicionais: o menor limite aplicável prevalece sobre a configuração privada de telefonia/canal. A interface não aumenta automaticamente a concorrência, a lista privada de destinos nem a cota do provedor.

Custos são somados por moeda, usando apenas pernas de voz de saída já conciliadas. Campos ausentes permanecem desconhecidos; mensagens, receptivo, impostos e pernas ainda não conciliadas não estão incluídos. O valor configurável em USD é um **alerta de custo conhecido**, não um teto financeiro garantido.

O scheduler existente executa `ma:inbox-sync` (vínculo de histórico e distribuição pendente) e `ma:events-deliver` (entrega do outbox), ambos com proteção contra sobreposição. Nenhum desses comandos origina chamadas. Os envios manuais e automáticos continuam sob os controles do canal e da cadência.

## Validação e pendências externas

Publicação realizada com backup de banco e arquivos, migrações aditivas e sem reiniciar containers. Passaram **236 testes / 1.828 asserções**, tanto em SQLite quanto em PostgreSQL isolado. Compilação e testes de navegador passaram, incluindo celular e componente incorporado em outra origem com dados simulados. A conferência das cinco novas telas e do contrato OpenAPI em produção retornou HTTP 200, sem erros de JavaScript.

O teste isolado de contenção aceitou uma admissão e recusou 39 pelo limite compartilhado, sem reservas duplicadas. P95 de 1.013,32 ms no container limitado a uma CPU; esse número descreve somente o ensaio de controle de admissão, sem áudio.

O número **ORIGEM_CONFIGURADA** recebeu a rota receptiva da fila **“Zyrex | Fila da jornada 5 tentativas”**, com 20 segundos de toque e 120 segundos de espera. A rota de entrada está habilitada; isso não habilita a campanha nem inicia a fila de saída. Nenhuma ligação ou mensagem de teste foi disparada na publicação. As campanhas, filas e os históricos de 21 chamadas de saída e três mensagens enviadas foram preservados.

As evidências da entrega ficam em `evidence/milestones-20261005`, incluindo regressão SQLite/PostgreSQL, concorrência isolada, compilação e navegador com fixtures. O experimento de concorrência usa 40 admissões simultâneas em lotes de quatro processos, com banco descartável, sem chamadas e sem mídia. O relatório deve ser interpretado como verificação de exclusão mútua e latência do controle, não como capacidade homologada de um call center.

Continuam dependendo de teste real:

- **4D:** confirmação visual após a retirada do selo de IA e continuidade/áudio da cadência. A exibição anterior dos três botões e os cliques já foram confirmados pelo usuário; não repetir chamadas automaticamente nesta entrega.
- **5:** chamada de entrada, áudio em ambos os sentidos, recusa, transferência entre dois navegadores e abandono na rota publicada.
- **6A:** chamada e tabulação no navegador do sistema integrador escolhido, após configurar sua origem e delegação.
- **6B:** remetente oficial Twilio/Meta e entrada direta de formulários sociais dependem dos acessos e permissões correspondentes. O contrato de leads normalizados está disponível.
- **6C:** carga de mídia e aumento de concorrência dependem desses testes e das cotas do provedor. Retenção/arquivamento e operação SaaS multiempresa não foram ativados.

Referências técnicas do receptivo: [Dial Client](https://www.twilio.com/docs/voice/twiml/client), [Device do SDK JavaScript](https://www.twilio.com/docs/voice/sdks/javascript/twiliodevice) e [atualização da chamada](https://www.twilio.com/docs/voice/api/call-resource).

## Incremento de 05/10 — supervisão e diagnóstico sem contato externo

O roteiro vigente está em [Marcos e homologação](roteiro-proximos-marcos.md). Foram acrescentados o relatório receptivo com gráfico e detalhe das ofertas, triagem de leads com responsável/notas e proteção contra edição concorrente, métricas de áudio WebRTC e prévia de retenção técnica sem exclusão.

A revisão de um lead emite `lead.reviewed` com `subject_id` igual ao UUID do lead e `data` contendo `status`, `assigned_user_id` e `revision`. Estados: `awaiting_review`, `qualified`, `discarded`. O evento usa o mesmo contrato versionado, escopo `events:read`, assinatura e deduplicação existentes. Não concede consentimento e não inicia comunicação. O endpoint de revisão é administrativo; a API pública de entrada continua `POST /api/v1/leads`.

O diagnóstico interno guarda codec, perdas, jitter, RTT e série de até 90 amostras, sem gravar voz, endereços IP ou SDP. A classificação é informativa e não corresponde à qualidade do trecho PSTN. Não aumenta concorrência ou habilita campanhas. Retenção efetiva e custo completo de todos os canais continuam pendentes.

Validação do incremento: **244 testes / 1.892 asserções**, em SQLite e PostgreSQL isolados; três testes das estatísticas WebRTC; compilação e navegador com dados simulados, incluindo telas de celular. Contenção: 40 requisições em quatro processos, uma admissão, 39 recusas e zero duplicidades; p95 439,8 ms no controle de admissão, sem mídia. Os limites de produção não foram aumentados.

Publicação com backup em `/srv/zyrex-ma/backups/quality-next-before-20261005T032745Z`, migração aditiva da triagem e nenhum reinício de container. Campanhas, filas e históricos de 21 chamadas externas e três mensagens enviadas preservados. A conferência das novas telas em produção retornou HTTP 200, sem erros JavaScript. Evidências: `evidence/quality-next-20261005`; eco interno: `evidence/voice-quality-20261005`.

## Incremento operacional do marco 6C — 05/10/2026

Custos por canal (voz de saída/receptiva e WhatsApp), recuperação de eventos com histórico e proteção contra respostas atrasadas, e retenção técnica configurável com arquivamento/restauração. Consulte o [guia operacional](custos-recuperacao-retencao.md). O automático permanece desativado; nenhum contato com pessoas faz parte desta validação.

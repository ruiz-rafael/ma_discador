# Marcos e roteiro de homologação

Atualizado em 05/10/2026. Este é o roteiro vigente; documentos de testes anteriores preservam o estado da época. O projeto permanece no MA e não altera o CRM nem os outros sistemas da VM.

## Situação das entregas

| Marco | Implementado | O que falta comprovar ou concluir |
| --- | --- | --- |
| **1–3 — Base e canais** | Cadências visuais, listas, configuração de Twilio API/SIP e laboratório | Homologar conversação real nos métodos utilizados; configuração não equivale a áudio validado |
| **4A–4C — Operação** | Tabulação, relatórios por jornada, importação, tentativas, saída após atendimento/resposta, filas preview/progressivas e origens autorizadas por DDD | Validar sequência contínua após correções; números brasileiros habilitados para a regra de mesmo DDD |
| **4D — Botões e homologação** | Templates personalizados, botões QR experimentais, registro de respostas e opção escolhida. O usuário confirmou os três botões no celular | Conferir novamente após retirada do selo de IA; validar outros aparelhos e continuidade completa. Nenhum novo envio nesta rodada |
| **4E — Equipe** | Tela de atendentes, perfis, filas, disponibilidade, desativação e acesso restrito | Exercício com atendentes reais, seus navegadores e headsets |
| **5 — Receptivo** | Entrada Twilio API, distribuição, capacidade compartilhada, transferência e tabulação. Incremento: relatório por período/fila/atendente, gráfico diário e detalhe das ofertas | Homologar áudio recebido e transferência com pessoas em horário adequado. Recepção SIP permanece fora da implementação atual |
| **6A — Integrações** | API v1 documentada, escopos, idempotência, eventos assinados e discador incorporável. Incremento: consultas de receptivo, conversas/botões e custos; contrato 1.1.0 e guia para o integrador | Homologar áudio incorporado no domínio consumidor; integração efetiva ao CRM será posterior. Operação atual restrita ao workspace 1 |
| **6B — Conversas** | Caixa WhatsApp, responsáveis, distribuição e recebimento de leads pela API. Incremento: triagem com responsável, estado, notas, controle de versão e evento `lead.reviewed` | Integração direta de formulários Meta depende de aplicação/permissões; qualificação não autoriza contato nem inicia cadência |
| **6C — Operação e escala** | Supervisão, limites, métricas de áudio interno e teste de disputa por capacidade. Incremento: custos por canal, histórico e recuperação protegida de eventos, retenção configurável com arquivamento/restauração | Homologar operação e mídia com pessoas antes de elevar concorrência; conferir custos reais com a fatura e definir se a política de arquivamento deve ser habilitada |

Detalhes da base publicada: [marcos 4E–6C](marcos-4e-6c.md), [dashboard por jornada](dashboard-jornadas.md), [operação e supervisão](operacao-supervisao.md), [listas e webhooks](listas-contatos-webhooks.md).

## Incremento sem chamadas externas

O relatório receptivo conta uma chamada por registro, mesmo quando transferida. Distingue atendidas (inclusive em andamento), aguardando atendimento, abandonadas e encerradas sem disponibilidade. O detalhe registra as ofertas aos atendentes e a tabulação; a agregação por responsável usa o responsável final. Filtros usam America/Sao_Paulo, até 90 dias. Recusas da operadora/MA antes da admissão não constam nesses totais. Médias sem amostra ficam indisponíveis.

A triagem fica em **Conversas → Leads de integrações**. Supervisor e administrador podem atribuir responsáveis, qualificar, descartar com motivo e reabrir revisão. Atualizações concorrentes exigem recarregar. A revisão preserva consentimento, supressão e histórico; não envia mensagens, não telefona e não inclui automaticamente o lead em listas. O evento `lead.reviewed` é versionado e pode ser consultado/recebido pelos consumidores autorizados.

O diagnóstico fica em **Cadências → Testes → Áudio** e na **Minha operação** fora do discador incorporado. **Saúde e limites** reúne as últimas medições da equipe. Sessões de diagnóstico e chamadas externas não podem disputar os recursos ao mesmo tempo; o diagnóstico recusa início durante atendimento/reserva/tabulação. Ele não habilita agentes ou filas.

**Saúde e limites** inclui custos por canal, histórico de entregas e retenção reversível. Os prazos são configuráveis; o arquivamento automático começa desativado. A restauração preserva estados e não reenvia eventos. Chamadas, mensagens, custos, auditorias e consentimentos não são arquivados. Detalhes: [custos, recuperação e retenção](custos-recuperacao-retencao.md).

O incremento do **marco 6A** disponibiliza consultas para o futuro CRM em **Integrações → Guia de integração**. As permissões de conversas e custos são explícitas; nenhuma credencial existente recebe esses acessos automaticamente. Consulte [a API de consultas](api-consultas-crm.md).

## Qualidade de voz — alcance da verificação

Foi executado eco interno real do navegador automatizado até o Asterisk do MA, com tom sintético de 660 Hz, sem destino telefônico externo. Na medição inicial de 25 segundos: **0% de perda, jitter p95 de 3 ms e RTT p95 de 137 ms**. Na conferência após publicação, foram 26 segundos, **0% de perda, jitter p95 de 5 ms e RTT ICE p95 de 146 ms** (RTCP p95 de 141,45 ms exibido no painel), com diagnóstico persistido no painel. O retorno do tom foi detectado em aproximadamente 656 Hz. O codec negociado foi PCMU/G.711, 8 kHz mono. Isso comprova mídia no percurso medido; não comprova microfone/headset do atendente nem qualidade Twilio/PSTN até o cliente. Não é uma medição perceptual MOS.

O painel guarda somente estatísticas, sem áudio, SDP, endereços IP ou credenciais. Métricas ausentes continuam desconhecidas. As referências do produto para atenção são jitter acima de 30 ms, RTT acima de 400 ms e perda acima de 1%, com pelo menos 10 segundos e cinco amostras para classificação. Valores informados pelo navegador são diagnósticos, não comprovação para cobrança.

Referências técnicas: [semântica das estatísticas WebRTC](https://www.w3.org/TR/webrtc-stats/) e [eventos de qualidade do SDK Twilio](https://www.twilio.com/docs/voice/voice-insights/api/call/details-sdk-call-quality-events). Referências de SDK não constituem garantia de qualidade do trecho da operadora.

## Próxima homologação com pessoas

1. Atendente conecta seu headset e executa eco interno, ouvindo clareza, volume e retorno.
2. Em horário combinado, chamada externa autorizada para validar os dois sentidos, identificação e encerramento, separadamente por método API/SIP utilizado.
3. Chamada receptiva e transferência entre dois agentes; conferir disponibilidade, abandono e tabulação sem duplicidade.
4. Cadência controlada: atendimento interrompe jornada; não atendimento chega ao limite configurado e produz uma mensagem; resposta/botão interrompe abordagem futura. Conferir dashboard e opção escolhida.
5. Validar discador incorporado em domínio de homologação, com permissões de microfone e eventos idempotentes, antes da integração ao CRM.
6. Medir carga de mídia e consumo antes de aumentar a concorrência, que permanece em 1 na política externa atual.

Nenhuma chamada externa ou mensagem é necessária para validar permissões, relatórios, revisão de leads e conflitos de versão. A autorização desta rodada é para configuração e testes internos; o roteiro acima não inicia contato com pessoas.

Filas de atendimento são independentes das campanhas: receptivas, de saída ou mistas. A fila pode atender várias campanhas, e o administrador define equipe e telefonia. A caixa de conversas organiza os números em fichas e permite vincular pendências sem responsável à equipe. Consulte [Operação e supervisão](operacao-supervisao.md).

O atendente controla Online/Pausado/Offline pelo headset do topo e escolhe sua disponibilidade por fila em **Gerenciar filas**. O teclado permite ligações manuais autorizadas, registradas fora das tentativas das cadências. A operação permanece conectada ao navegar para Conversas. Consulte o [guia do atendimento](operacao-supervisao.md#headset-disponibilidade-por-fila-e-teclado--05102026).

## Marco 7 — Experiência de operação e administração

A rodada de 05/10 organiza o produto para uso cotidiano, sem alterar a telefonia ou colocar a equipe online:

| Entrega | Comportamento |
| --- | --- |
| **7A — Visão geral administrativa** | Entrada do administrador com cards de cadências, filas e equipe, atalhos para conversas e relatórios e uma sequência de configuração. Menus agrupados em Operação, Público, Gestão e Integrações. |
| **7B — Configuração progressiva das filas** | Catálogo com cards e busca. Edição em Visão geral, Números e canais, Equipe e Permissões e pausas. Valores permanecem no formulário ao trocar de aba; validação nativa revela campos obrigatórios ocultos. |
| **7C — Espaço do agente e discador persistente** | Operação com resumo compacto, ações de atendimento e última chamada recolhível. Discador centralizado: clique fora, Escape, erros, respostas e tabulação não o fecham. Fechar é uma ação explícita; não encerra a ligação. O rascunho da tabulação permanece ao reabrir. |

Os cards da visão geral indicam cadastro, não homologação ou disponibilidade garantida. A atualização é manual, com horário da consulta; falha de consulta mantém os últimos valores e exibe aviso. A abertura da página não ativa filas, disponibilidade, sincronização de operadora ou contatos.

Validação desta rodada: compilação Vite, oito testes existentes de áudio/microfone/transporte e navegador desktop/celular com SDK e mutações simulados. Cenários de discagem, resultado do provedor, falha de rede sem rediscagem, tabulação, foco, fechamento explícito, busca e edição de filas. O backend não muda nesta entrega.

### Próximos passos que dependem de acesso ou homologação

1. **Operação com pessoas:** áudio de ida e volta, receptivo, transferência entre dois agentes e continuidade da cadência, conforme roteiro acima. Não aumentar concorrência antes da homologação.
2. **Operadora SIP escolhida:** configurar credenciais e números autorizados; conferir tarifação e qualidade. O receptivo SIP requer implementação própria e continua pendente, distinto do receptivo Twilio API existente.
3. **Meta oficial direta:** cadastro de número/WABA e aplicação com permissões; implementar e homologar o conector Cloud API e a entrada direta de leads sociais. Ter QR ou Twilio configurado não equivale a possuir este conector.
4. **CRM e escala:** homologar o discador incorporado no domínio consumidor, validar contratos de eventos e medir mídia/capacidade antes de ampliar o uso. O CRM permanece fora desta intervenção.

Essas pendências não são marcadas como concluídas pelos cards da interface nem pelos testes simulados.


### Distribuição e concorrência interna — 06/10/2026

A fila passa a oferecer rodízio ou prioridade para o atendente há mais tempo livre; filas existentes preservam a regra anterior. A homologação interna utiliza três agentes e telefonia simulada em banco separado, com concorrência real entre processos. Consulte [Operação e supervisão](operacao-supervisao.md#distribuição-entre-atendentes--06102026).

A capacidade de produção permanece em uma chamada simultânea. A próxima homologação de escala deve verificar três conexões de áudio reais, qualidade, receptivo e transferência, limites da operadora e consumo. Os testes de distribuição não substituem essa etapa. Um modo preditivo exige ainda um mecanismo próprio de estimativa de discagem e controle de atendimentos sem agente; ele não está implementado por esta entrega.


### Configuração da operação por fila — 06/10/2026

Implementados horário próprio, tempo de oferta ao agente, pausa por ofertas receptivas perdidas, pausa opcional por perda da conexão receptiva, permissões de destinos e gravação Twilio API com acesso, pausa e retenção. A tela organiza essas opções por abas; a gravação permanece desativada nas filas existentes. Consulte [o guia da fila](operacao-supervisao.md#configurações-operacionais-da-fila--06102026).

Ainda dependem de homologação real: áudio gravado de entrada/saída, transferência com gravação e comandos de pausa/retomada na operadora. Gravação no SIP/Asterisk permanece uma implementação futura, explicitamente indisponível na interface.

### Participações e reentrada — 06/10/2026

Entregue para as cadências de voz e WhatsApp: participação única, saída/retorno ao segmento, intervalo e evento; limite de participações e motivos permitidos; espera por resposta; histórico por execução; API com escopo próprio e teste sem inserir contatos. A reentrada mantém limites globais, descadastro, histórico e disponibilidade exigida pela fila. A configuração das campanhas existentes é preservada. Homologação externa de um ciclo completo continua dependendo de teste autorizado; a validação desta entrega usa serviços e bancos isolados, sem telefonia ou mensagens reais.

### Cadastro de WhatsApp pela plataforma — 06/10/2026

Assistente com dois caminhos: números da própria empresa (descoberta, cadastro adicional e código de verificação) e números de clientes (Embedded Signup Meta com validação da subconta dedicada). Inclui recuperação de cadastro sem confirmação, bloqueio de criação duplicada, configuração administrativa recolhida e vínculo manual avançado preservado.

A ativação externa continua pendente: primeiro remetente via Self Sign-up, aprovação Meta/Tech Provider, parceria Twilio e subconta dedicada para clientes. Cadastro automático de vários workspaces/subcontas na mesma instalação não faz parte desta entrega. Consulte [operação e pré-requisitos](whatsapp-twilio.md).

Validação automatizada: 384 testes / 2.952 asserções em SQLite e PostgreSQL, além de 11 testes JavaScript. Homologação com SMS e conta Meta reais não realizada nesta entrega.

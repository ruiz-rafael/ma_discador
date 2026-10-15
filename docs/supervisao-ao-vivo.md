# Supervisão ao vivo

Acesso: menu **Operação → Supervisão ao vivo**, para administrador e supervisor. A área do atendente permanece focada no atendimento.

## Visões

- **Equipe:** uma linha por usuário habilitado, estado operacional exclusivo, filas atribuídas, telefone em atendimento e tempo no estado. Busca por nome e filtros de estado.
- **Filas:** presença selecionada, ocupação, números, cadências vinculadas, horário e canais de entrada/saída. A pausa de cada canal exige confirmação e revisão atual; não encerra chamadas existentes nem muda a permissão de discagem manual.
- **Cadências:** status, tentativas no período e acesso à configuração existente.
- **Chamadas e resultados:** entrada e saída, contato, telefone, origem, agente, fila, cadência, horários, resultado da telefonia, classificação AMD e tabulação. Pesquisa, filtros e paginação de 25 registros. Detalhes em modal com fechamento explícito.

Atualização a cada 10 segundos enquanto a aba estiver visível, desativável. Período de até 31 dias no fuso America/Sao_Paulo. Equipe e chamadas em curso mostram o estado atual, independentemente da data do histórico. Em falha de atualização, mantém a última consulta identificada e suspende os cronômetros. Sem atualização por 30 segundos, também suspende os cronômetros.

## Como interpretar

- Chamadas no período: saídas cujo início foi registrado e chamadas recebidas. Preparações sem início não contam como tentativas.
- Pessoas identificadas: classificação humana registrada ou confirmação humana na tabulação.
- Atendida sem classificação: conexão técnica sem AMD. Não comprova conversa humana.
- Caixa postal/fax/inconclusivo: classificação registrada; não inferida da duração.
- “Não atendeu” não garante que o aparelho chegou a tocar.
- Em curso: chamadas que ainda reservam capacidade, incluindo resultados a conferir. Não equivale ao número de agentes falando.
- Disponível: presença recente, sem ocupação/pós-atendimento e com fila selecionada. Os controles de áudio, autorização, limites e horários continuam sendo avaliados pelo distribuidor antes de qualquer chamada.
- Tempos de estado anteriores à implantação, sem registro confiável, aparecem como “—”. Heartbeats não reiniciam o relógio de pausa.

Os status “Caixa postal pré/pós-atendimento” de outros produtos não são reproduzidos sem evento que justifique essa distinção. Este painel não implementa discagem preditiva nem amplia simultaneidade.

## Operação e segurança

`GET /api/voice/supervision` e `GET /api/voice/supervision/calls` são projeções de leitura: não liquidam reservas, não consultam provedores e não iniciam chamadas. Endpoints restritos à supervisão do workspace do deployment isolado; respostas privadas sem cache. Payloads usam seleção explícita de colunas e não incluem tokens de chamadas, credenciais ou dados de outros workspaces.

`POST /api/voice/supervision/queues/{id}/pause` recebe `channel` e `revision`; usa transação, bloqueio da operação e auditoria. Não existe ação de ativação no painel. Configurações completas continuam na tela de filas e cadências.

Migração aditiva: `2026_10_14_000002_add_presence_state_clock.php`. Acrescenta `state_changed_at` nullable à presença, sem alterar disponibilidade ou configurações existentes.

Referências de UX: [status de agente da 3C Plus](https://alo.3cplusnow.com/help/status-do-agente-no-discador-3c-plus-help-center) e anexos fornecidos pelo usuário. Layout e classificação refletem exclusivamente os estados existentes na DiscAI.

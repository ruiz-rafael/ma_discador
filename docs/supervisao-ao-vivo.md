# Supervisão ao vivo

Acesso: menu **Operação → Supervisão ao vivo**, para administrador e supervisor. A área do atendente permanece focada no atendimento.

## Visões

- **Equipe:** uma linha por usuário habilitado, estado operacional exclusivo, filas atribuídas, telefone em atendimento e tempo no estado. Busca por nome e filtros de estado.
- **Filas:** capacidade atribuída, agentes online/disponíveis, em conversa, preparando chamada, em pós-atendimento, pausados e offline; chamadas discando/conectadas; tentativas e resultados do período; números, cadências, horário e canais de entrada/saída. Há acesso direto aos analistas, às chamadas, ao diagnóstico e à configuração da fila. A pausa de cada canal exige confirmação e revisão atual; não encerra chamadas existentes nem muda a permissão de discagem manual.
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

Os status “Caixa postal pré/pós-atendimento” de outros produtos não são reproduzidos sem evento que justifique essa distinção. O modo atual é progressivo: uma chamada por atendente disponível. O painel não implementa discagem preditiva nem amplia simultaneidade.

## Privacidade durante a discagem

Na distribuição automática, **Minha operação** mantém nome e telefone ocultos enquanto a chamada está sendo preparada ou discando. O contato aparece quando a telefonia informa atendimento e a conversa é entregue ao atendente. Ligações manuais continuam mostrando o destino escolhido pelo próprio atendente. Essa proteção de interface não altera a reserva, a telefonia ou a capacidade da fila.

Na discagem preditiva futura, a plataforma deverá originar e acompanhar múltiplas tentativas no servidor, reconhecer atendimento, aplicar ritmo e limites de abandono, cancelar pernas excedentes e só então distribuir a conversa. Ocultar o contato no navegador, isoladamente, não transforma a discagem progressiva em preditiva.

## Operação e segurança

`GET /api/voice/supervision` e `GET /api/voice/supervision/calls` são projeções de leitura: não liquidam reservas, não consultam provedores e não iniciam chamadas. Endpoints restritos à supervisão do workspace do deployment isolado; respostas privadas sem cache. Payloads usam seleção explícita de colunas e não incluem tokens de chamadas, credenciais ou dados de outros workspaces.

`POST /api/voice/supervision/queues/{id}/pause` recebe `channel` e `revision`; usa transação, bloqueio da operação e auditoria. Não existe ação de ativação no painel. Configurações completas continuam na tela de filas e cadências.

Migração aditiva: `2026_10_14_000002_add_presence_state_clock.php`. Acrescenta `state_changed_at` nullable à presença, sem alterar disponibilidade ou configurações existentes.

Referências de UX: [status de agente da 3C Plus](https://alo.3cplusnow.com/help/status-do-agente-no-discador-3c-plus-help-center) e anexos fornecidos pelo usuário. Layout e classificação refletem exclusivamente os estados existentes na DiscAI.

## Diagnóstico de fila — 17/10/2026

Em **Filas → Diagnosticar fila**, administrador e supervisor consultam os impedimentos em um modal com fechamento explícito. Os cartões mostram operação/saída/entrada pausada, horário da fila, capacidade, limite diário, cadências habilitadas, presença selecionada, ocupação e pós-atendimento. No receptivo, incluem rota ativa e navegador com áudio pronto. Cada impedimento traz uma orientação para revisão.

O público é avaliado pelos mesmos critérios de elegibilidade da discagem, em uma amostra de até 100 participações já registradas: permissão de destino, consentimento, atendimento/resposta anterior, reserva, intervalo, horário e limites. Os motivos são agrupados com até três exemplos de contato/telefone/cadência. A mesma pessoa em duas cadências conta duas participações. A amostra segue ID de cadência/contato, não a ordem de distribuição. Quando parcial, não permite concluir que toda a fila está sem contatos elegíveis.

`GET /api/voice/supervision/queues/{id}/diagnostic` exige perfil de administrador/supervisor e fila do workspace; limita a consulta a 10 por minuto e retorna `Cache-Control: no-store, private`. Contrato: `source/postman/queue-diagnostic.openapi.json`.

A consulta não chama operadoras, não reserva contatos, não liquida reservas, não atualiza segmentos/reentradas e não modifica presenças. Até 200 atendentes e 50 cadências são detalhados. Não verifica mídia, autorização de números na operadora ou disponibilidade externa. Resultado sem impedimentos indica somente as verificações realizadas, nunca garantia de ligação. A distribuição reavalia as regras antes de discar. Atualização manual independente do painel; falhas preservam a última consulta identificada. Sem migração ou alteração de capacidade.

Validação da entrega: 532 testes/4.065 asserções em SQLite, 81 testes/746 asserções em PostgreSQL e 11 testes JavaScript. Navegador em 1440, 390 e 320 px: detalhes de equipe/contato, fechamento explícito, retorno do foco e preservação da consulta em erro. Bancos de QA isolados; nenhuma chamada ou mensagem real.

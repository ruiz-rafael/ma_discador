# Marco 3 — filas de trabalho e equipe

A aba **Voz e cadências → Filas e equipe** implementa filas de contatos para chamadas de saída, em simulação. Ela pode ser usada antes da homologação da Twilio e não inicia telefonia ou mensagens. Não é uma fila de áudio de chamadas recebidas.

Evolução planejada em 03/10/2026: [roteiro dos próximos marcos](roteiro-proximos-marcos.md) e [requisitos de operação e integrações](requisitos-operacao-integracoes.md). A tabulação real entra em 4A, regras de listas/tentativas em 4B, filas reais de saída em 4C e equipe ativa/receptiva em 5. Esse planejamento não altera o escopo simulado descrito aqui.

## O que está disponível

- Uma fila por campanha, com nome, atendentes vinculados, pausa/início, ordem por elegibilidade/FIFO ou prioridade e pós-atendimento de 0 a 120 segundos.
- Carregamento idempotente do público da campanha. Novos contatos são adicionados; carregar novamente preserva histórico, reservas, prioridades e agendamentos existentes.
- Presença por usuário: disponível, pausado com motivo, offline, ocupado e pós-atendimento. A interface renova a presença a cada 20 segundos nesta aba. Após 90 segundos sem presença, as reservas simuladas podem expirar na próxima consulta/operação.
- Solicitação do próximo contato pelo atendente disponível. A distribuição é sob demanda, com reserva exclusiva; não existe despacho automático de áudio ou discagem em segundo plano.
- Resultado, qualificação, notas e retorno agendado. A data é informada no horário local do navegador e enviada em ISO/UTC. O retorno continua sujeito à janela da campanha e ao limite de tentativas.
- Visão da equipe, contagens de itens, motivos de impedimento, horários de elegibilidade, prioridade por contato e histórico dos atendimentos simulados.
- Resultado comercial separado do resultado de telefonia: nenhum resultado manual desta tela escreve em `voice_outbound_calls` nem comprova chamada real.

## Como testar agora

1. Na aba **Contatos e CSV**, cadastre contatos de teste com autorização e evidência.
2. Em **Campanhas**, crie uma campanha, escolha esses contatos, configure dias/horários/fuso, roteiro, intervalos e máximo de tentativas. Inicie sua simulação.
3. Abra **Filas e equipe → Nova fila**, selecione a campanha e os usuários do MA que poderão atendê-la. As contas vêm do MA, não do CRM.
4. Use **Carregar público da campanha**, **Iniciar fila simulada** e **Ficar disponível**.
5. Clique em **Solicitar próximo contato**. Um contato ficará reservado e o roteiro será exibido, sem ligar para ele.
6. Registre o resultado. Para testar retorno, escolha **Atendida → Retorno agendado** e informe uma data futura. Para testar exclusão, escolha **Pediu interrupção**; o contato será retirado das abordagens.
7. Teste pausa, prioridade, notas e pós-atendimento. Com dois usuários distintos do MA, confirme que o mesmo contato não aparece para os dois ao mesmo tempo.

Mantenha a aba aberta enquanto estiver em atendimento simulado. Fechar ou abandonar a aba faz a presença vencer; a reserva não deve ser tratada como chamada de áudio em andamento. Pausar fila/atendente impede novas reservas e permite concluir as atuais. As reservas também têm o teto anterior de dez minutos do laboratório.

## Regras de distribuição

Antes da reserva, o servidor revalida: workspace, vínculo do atendente, presença, pós-atendimento, estado da fila e campanha, janela de horário, participação do contato, consentimento/evidência, exclusão, atendimento já iniciado, intervalo de retorno e máximo de tentativas.

Transações com bloqueio em `voice_runtime` serializam decisões curtas entre processos. As tentativas simuladas continuam limitadas a duas no laboratório, uma por atendente e uma por contato, inclusive entre campanhas. O limite por campanha continua valendo. Os limites externos de API/SIP não são ampliados por esta entrega.

Uma reserva de fila impede iniciar ligação real para o mesmo contato ou pelo mesmo atendente. A fila também ignora contatos/atendentes com ligação real ainda pendente de encerramento. Nenhum resultado desconhecido da Twilio é liberado por expiração de presença.

Repetir a solicitação com a mesma chave devolve a mesma reserva. Repetir a conclusão é aceito somente com o mesmo resultado, qualificação, retorno e notas. O endpoint anterior de atendimento não permite concluir uma reserva pertencente à fila; ela deve ser tabulada na própria fila.

Alterar configuração exige fila pausada, revisão atual e nenhuma reserva ativa. Alterar a campanha vinculada exige criar outra fila. Histórico e notas permanecem imutáveis. A prioridade só muda para itens aguardando reserva.

## Limites desta entrega

- Filas de chamadas recebidas, transferência de áudio, toque simultâneo/round-robin, supervisão de áudio e distribuição de chamadas reais ainda dependem de implementação e homologação.
- O modo é apenas simulação; não há flag pública que converta a fila em discador real.
- Não cria usuários, altera contas nem implementa novos papéis de supervisor. Usa o escopo de workspace já adotado pelo laboratório. Gestão de permissões para produção continua pendente.
- Esta VM segue como laboratório. O bloqueio central garante consistência, mas a capacidade de produção deve ser medida antes de ampliar concorrência. Não se afirma escalabilidade ilimitada.
- A tela exibe até 500 itens e 100 reservas recentes por workspace. Indicadores são de trabalho simulado, não de SLA, faturamento ou atendimento real.
- Reconciliação de reservas ocorre nas operações da fila, sem novo serviço ou job. A retomada de um callback é feita ao solicitar o próximo contato depois do horário elegível.
- Nenhum envio WhatsApp real é feito pela fila. O motor anterior pode preparar passos simulados de cadência, mantendo a separação dos canais reais.

## Dados e implantação

Migração aditiva `2026_10_02_000001_create_voice_queues.php`: filas, membros, presença, itens e reservas. Reutiliza contatos, campanhas, tentativas simuladas e auditoria do próprio MA. Não acessa o banco do CRM.

Código: `VoiceQueue`, `VoiceQueueController`, `VoiceQueuePanel.vue`. Endpoints autenticados em `/api/voice/queues`. Todos os identificadores são revalidados no workspace, e as respostas de consulta são privadas/sem cache.

Validação: testes funcionais de exclusividade, autorização, presença, prioridade, pausa, limite, retorno, opt-out, idempotência e isolamento. Teste PostgreSQL multiprocesso verifica disputa pelo mesmo contato em campanhas distintas, limite global de duas simulações e repetição simultânea da mesma requisição. Evidências em `evidence/queues-20261002/`.

## Evidências da entrega

- 116 testes / 979 assertions aprovados em SQLite e PostgreSQL separado do banco do MA.
- Corrida PostgreSQL com três processos: uma reserva para o contato compartilhado entre campanhas; duas simulações no limite global; duas requisições idênticas devolvendo a mesma reserva sem duplicação.
- Build Vite aprovado. Chromium autenticado em desktop/celular: consulta real da nova aba, fluxo de interface com fixtures locais para criar fila, carregar público, priorizar, reservar, tabular retorno, anotar e pausar. Fixtures do navegador não alteraram registros de produção.
- Seleção dos dois métodos Twilio e diagnóstico do áudio interno preservados. Zero registros de chamadas externas após a entrega.
- Backup próprio `20261002T024659Z`, migração aditiva, nenhum container do MA reiniciado e hashes do PBX preservados. O worker de outro projeto `zyrex-crm-r38-whatsapp-worker` manteve o ID, estava ativo sem OOM e avançou seu início em 3603,17 segundos, compatível com a reciclagem preexistente `--max-time=3600`. Nenhum comando desta implantação reiniciou ou alterou esse worker.

Reversão: restaurar os arquivos e o manifesto de `backups/queues-code-before-20261002T024659Z.tar.gz`, remover as novas rotas/classes se necessário e limpar o cache de rotas. Preservar as tabelas e o histórico. Não há configuração de PBX a reverter.

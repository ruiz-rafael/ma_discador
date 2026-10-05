# Operação e supervisão das cadências

A partir dos ajustes de 04/10/2026, o atendimento e a gestão têm telas e permissões distintas.

## Supervisor / administrador

Em **Cadências**, configura público, horários, tentativas, mensagem e remetente e habilita a cadência. Em **Cadências → Supervisão**, configura a fila e os atendentes, habilita ou pausa a fila e acompanha a disponibilidade da equipe. Os relatórios continuam em Cadências → Relatórios.

A conta administrativa também pode usar **Minha operação** para atuar como atendente nas filas às quais foi vinculada. Essa tela mostra somente as filas desse usuário, mesmo quando ele é administrador.

## Agente

Ao entrar, o usuário com perfil de agente é direcionado a **Minha operação**. Escolhe sua fila e o método de voz, confirma que está pronto e clica em **Ficar disponível**, permitindo o microfone. No modo progressivo, não há um segundo botão para iniciar a sequência: ela acompanha a disponibilidade do agente e a habilitação da fila/campanha. No modo preview, o contato é reservado antes de iniciar a ligação.

O agente pode pausar, ficar offline, concluir a ligação e tabular. Pausar impede novas tentativas, permitindo concluir a ligação atual. A navegação é bloqueada enquanto há chamada ou tabulação pendente. É necessário manter a aba aberta; o servidor revalida a presença antes da discagem e deixa de considerá-la atual após 90 segundos sem heartbeat.

Agentes não têm acesso administrativo a campanhas, contatos gerais, listas, números, templates ou integrações. Essas restrições são verificadas na API. O acesso a chamadas é do próprio usuário; a discagem exige reserva de uma fila à qual ele está vinculado.

## Distribuição e continuidade

A fila prioriza contatos com menos tentativas iniciadas na campanha. Em empate, escolhe a tentativa mais antiga e depois a ordem dos IDs. A autorização, a exclusão, o atendimento anterior, os limites e o intervalo continuam sendo verificados antes de reservar e antes de discar.

Se nenhum contato estiver elegível, a operação aguarda e consulta novamente automaticamente. O mesmo vale quando a fila aguarda habilitação pela supervisão ou falta capacidade de telefonia. Não são reduzidos horários, limites ou intervalos para conseguir outro contato.

Um retorno de não atendimento confirmado pelo servidor não é tratado como falha fatal do SDK. A operação só prossegue após a liberação confirmada da chamada. Um resultado incerto mantém a capacidade reservada e interrompe a sequência; na API Twilio há a ação para consultar o resultado. Atendimento confirmado exige tabulação antes de continuar.

## Personalização e contadores

Para campanhas com lista do MA, a personalização usa o nome atual dessa lista. A identidade de voz, o telefone, o consentimento e as exclusões não são sobrescritos. Novas reservas guardam o nome resolvido no retrato da chamada para relatórios. Registros históricos e mensagens já enviadas permanecem como ocorreram.

Alterações apenas de horário, fuso, roteiro, título e intervalo não reiniciam a versão da regra de WhatsApp nem cancelam uma mensagem pendente válida. Alterar mensagem, remetente, limiar, modo de envio ou origem do público invalida os passos pendentes conforme a nova configuração. Remover um contato cancela seus passos pendentes, preservando os demais.

A regra continua: atendimento encerra a abordagem; WhatsApp só é criado após o número configurado de **não atendimentos reais confirmados**, na versão válida da regra. Ocupado, falha, cancelamento e resultado incerto não contam como não atendimento.

## Validação desta publicação

193 testes automatizados em SQLite e PostgreSQL, incluindo distribuição, consentimentos, isolamento, callbacks, personalização e revisão da regra. Testes de navegador com APIs e SDK simulados verificam supervisor/agente, espera automática, continuidade após não atendimento, pausa, bloqueio por resultado incerto e tela móvel. Nenhuma chamada ou mensagem real foi disparada para validar esta publicação.

A campanha do teste de 04/10 foi mantida pausada. O teste real anterior não foi repetido e seu histórico não foi reiniciado.

Na conferência após a publicação, a nova pasta de middleware estava sem permissão de leitura para o processo web e causou um erro 500 temporário. A permissão foi corrigida e o script de publicação foi ajustado para criar novas pastas com acesso adequado. O teste final no endereço público confirmou login, tela de supervisão, menu de operação, fila e campanha pausadas, sem erros no navegador. Evidências em `evidence/queue-improvements-20261004/`.

## Salvamento dos cartões — correção de 04/10/2026

Ao reduzir o máximo de tentativas abaixo do limiar do WhatsApp, o editor informa o conflito junto ao botão de salvar. A ação **Ajustar WhatsApp para X não atendimento(s)** altera os dois limites no formulário; só **Salvar etapa** persiste os valores. Não se modifica automaticamente a regra da campanha. A API permite salvar os dois limites juntos no cartão de voz, com validação, revisão e transação únicas.

O aviso de saída desaparece quando as alterações correspondentes são salvas. Configurações da etapa e posições do mapa têm estados distintos: **Salvar etapa** não salva posições; trocar de cartão e descartar sua edição não descarta movimentos no mapa. Clicar ou organizar o mapa sem alterar as posições não gera aviso de pendência. Falhas de salvamento ficam visíveis no rodapé fixo e preservam o formulário.

Validação: 204 testes / 1.585 asserções em SQLite e PostgreSQL isolados; navegador com gravações interceptadas, reproduzindo máximo 1 versus WhatsApp após 5, ajuste explícito, salvamento/reabertura, erro, saída e posições. Evidências em `evidence/journey-save-20261004/`. A publicação não altera os valores da campanha e não realiza chamadas ou envios.

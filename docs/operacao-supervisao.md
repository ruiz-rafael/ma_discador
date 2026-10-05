# Operação e supervisão das cadências

A partir dos ajustes de 04/10/2026, o atendimento e a gestão têm telas e permissões distintas.

## Supervisor / administrador

Em **Cadências**, configura público, horários, tentativas, mensagens e a fila responsável, que fornece os números utilizados. Em **Filas de atendimento**, cria filas receptivas, de saída ou mistas, configura números de voz e WhatsApp, atendentes, permissões e telefonia API/SIP. Entrada e saída têm controles de habilitação independentes. Em **Receptivo**, publica e ativa as rotas dos números de entrada. Os relatórios continuam em Cadências → Relatórios.

A conta administrativa também pode usar **Minha operação** para atuar como atendente nas filas às quais foi vinculada. Essa tela mostra somente as filas desse usuário, mesmo quando ele é administrador.

## Agente

Ao entrar, o usuário com perfil de agente é direcionado a **Minha operação**. Usa o **headset no topo** para escolher Online, Pausado ou Offline. Online inclui todas as filas atribuídas pelo administrador; **Gerenciar filas** permite uma seleção específica. O método de voz é definido pelo administrador. Antes de ativar a disponibilidade de voz, a interface verifica o microfone e conecta o receptivo quando a seleção inclui números de entrada. No modo progressivo, não há um segundo botão para iniciar a sequência: ela acompanha a disponibilidade do agente e a habilitação da fila/campanha. No modo preview, o contato é reservado antes de iniciar a ligação.

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

## Filas independentes e microfone — 05/10/2026

Uma fila reúne atendentes e distribui trabalho. Uma campanha define contatos, horários, tentativas e a continuidade da cadência. Cada campanha pode pertencer a uma fila de saída; uma fila pode receber várias campanhas. A seleção alterna campanhas pela reserva mais antiga e mantém a prioridade de contatos com menos tentativas dentro de cada campanha. Cada reserva guarda sua campanha, inclusive depois de uma mudança de vínculo.

Uma fila receptiva pode existir sem campanha e receber vários números. A fila mista compartilha a mesma equipe entre entrada e saída. Os agentes podem pertencer a várias filas, e a ocupação continua compartilhada para impedir duas chamadas simultâneas para a mesma pessoa. Alterações exigem saída pausada, revisão atual e conclusão de reservas, chamadas e tabulações. Remover uma campanha da fila não apaga seu histórico.

A migração preserva as filas existentes: aquelas com rota receptiva tornam-se de entrada e saída; as outras, de saída. Nomes, campanhas, agentes, pausas e históricos são mantidos. O vínculo dos números existentes continua no mesmo lugar. A saída permanece com o método padrão Twilio API; o administrador pode alterá-lo na fila.

**Verificar microfone** abre e libera o dispositivo local, sem telefonia, gravação ou mudança de presença. O erro “Requested device not found” indica que o navegador não encontrou uma entrada de áudio utilizável. A interface agora distingue microfone ausente, permissão bloqueada e dispositivo ocupado, com instruções em português. A disponibilidade não é ativada se essa verificação falhar. Um microfone fisicamente ausente ou desabilitado precisa ser conectado/habilitado no computador.

## Conversas por número e acesso da equipe — 05/10/2026

Em **Conversas**, as fichas no topo mostram cada número de WhatsApp, conexão QR/API e contadores de abertas, concluídas e não lidas. Clique no número e depois no telefone do contato para ver mensagens e botões escolhidos. Os contadores obedecem às mesmas permissões das conversas; o filtro de estado permite abertas, concluídas ou todas.

O administrador configura **Distribuição**: número, fila e atribuição automática opcional. Mesmo com a atribuição automática desmarcada, novas conversas entram na fila para os membros assumirem manualmente. A opção **Incluir conversas abertas deste número que ainda estão sem fila e sem responsável** corrige pendências anteriores sem transferir conversas já atribuídas, mudar filas existentes ou reabrir atendimentos concluídos.

Agentes veem suas conversas atribuídas e as conversas sem responsável nas próprias filas. Conversas sem fila e sem responsável ficam disponíveis apenas à supervisão até receberem um vínculo. Assumir é uma ação explícita; abrir o histórico não envia mensagem e não reinicia cadências.

Validação desta evolução: **275 testes / 2.199 asserções** em SQLite e PostgreSQL isolados, seis testes de áudio/microfone e navegador em desktop/celular. Os cenários incluem fila receptiva sem campanha, várias campanhas por fila, proteção de reservas, falta/permissão de microfone e visibilidade das conversas por equipe. O teste de contenção admitiu uma requisição e recusou 39, sem duplicidades e sem mídia. Evidências privadas em `evidence/queue-routing-20261005/`.

## Headset, disponibilidade por fila e teclado — 05/10/2026

O headset permanece no topo ao navegar entre Minha operação e Conversas. A operação, a conexão receptiva e o heartbeat permanecem montados; trocar de tela não coloca o agente offline. Fechar a aba encerra a disponibilidade, e o servidor desconsidera presença sem heartbeat recente.

- **Online:** torna o agente elegível em todas as filas atribuídas, respeitando as habilitações do administrador. Filas progressivas de saída podem iniciar chamadas após essa ação. A consulta alterna as filas selecionadas, mantendo as regras de elegibilidade de cada campanha.
- **Gerenciar filas:** abre uma caixa compacta com as filas permitidas. Aplicar uma seleção torna o agente online somente nelas. Limpar a seleção e aplicar coloca-o offline. Desmarcar uma fila não remove seu vínculo administrativo nem transfere conversas já atribuídas.
- **Pausado / Offline:** impedem novas distribuições, preservando a chamada e a tabulação em andamento. A alteração vale para voz de saída, ofertas/transferências receptivas e atribuição automática de WhatsApp.
- **Teclado:** permite digitar ou clicar no telefone de destino e escolher um número de saída autorizado. Somente o botão **Ligar** solicita a chamada manual; abrir a caixa e digitar não faz chamadas.

O administrador pode desmarcar **Permitir discagem manual pelos atendentes** na configuração da fila. A discagem manual precisa estar permitida, o agente precisa estar online em uma fila vinculada ao número e o telefone precisa corresponder a um contato cadastrado com autorização. Continuam valendo a lista privada de destinos, os limites globais, o bloqueio por opt-out, a capacidade compartilhada e o pós-atendimento. Um número brasileiro pode ser informado com DDD; a normalização acrescenta +55 quando aplicável.

Chamadas manuais guardam fila, atendente e contato, com `manual=true` e `campaign_id=null`. Exigem ação explícita do atendente e usam a mesma telefonia, conciliação e tabulação. Não contam como tentativas da cadência, não iniciam WhatsApp automático e não limpam o histórico de resposta. Um contato que já respondeu pode receber um retorno manual autorizado; pedidos de interrupção continuam bloqueados.

A seleção de filas é validada no servidor, inclusive imediatamente antes da discagem. Uma sessão de navegador identifica o controlador da presença: outra aba não pode renovar ou encerrar a sessão ativa. Se a conexão receptiva cair, a interface pausa novas distribuições e orienta a reconexão. Os vínculos e conversas existentes não são alterados na implantação.

Validação: **285 testes / 2.293 asserções** em SQLite e PostgreSQL isolados, seis testes de áudio/microfone, compilação e testes do navegador com telefonia simulada. Evidências privadas em `evidence/agent-console-20261005/`. A validação não telefona para contatos nem envia WhatsApp.

## Discagem manual e permissões por destino — 05/10/2026

A pausa da fila controla as cadências automáticas. Uma ligação manual exige agente online na fila e a opção **Permitir discagem manual pelos atendentes**; não exige habilitar as cadências. Isso corrige a mensagem de indisponibilidade que aparecia mesmo com o agente online. Erros de discagem ficam no teclado, sem abrir o seletor de status.

Na configuração da fila, a supervisão escolhe **Permitir telefones fixos · qualquer DDD** e **Permitir celulares**. Os agentes herdam a configuração. A política é aplicada na reserva manual, na escolha progressiva, na concessão da chamada e imediatamente antes da discagem pelo provedor. Desabilitar uma categoria após a reserva impede a discagem dessa chamada. Valores iniciais preservam as categorias anteriormente permitidas; não foram habilitadas cadências nem feitas chamadas na publicação.

A classificação brasileira segue os indicadores publicados pela [Anatel](https://www.gov.br/anatel/pt-br/regulado/numeracao/perguntas-frequentes). Números brasileiros fora das categorias reconhecidas são recusados pela fila. Destinos internacionais continuam sujeitos às autorizações existentes na configuração da telefonia. Consentimento, opt-out, lista privada de homologação e limites permanecem aplicados.


## Canais da fila e vínculo pela cadência — 05/10/2026

Em **Filas de atendimento → Editar fila e vínculos**, o administrador configura origem de voz autorizada, telefonia (API/SIP), remetente WhatsApp (API/QR), número único ou separado, equipe e permissões. O formulário é dividido em identificação, canais, permissões e atendentes. Os números receptivos também podem ser vinculados nessa tela; um novo vínculo é salvo pausado, e a publicação/ativação na operadora continua em Receptivo. Rotas já existentes mantêm o estado e não são transferidas silenciosamente entre filas.

Em **Cadências → cartão Ligar para o cliente → Fila da cadência**, selecione a fila e salve a etapa. A configuração geral também oferece essa seleção. O cartão WhatsApp apresenta o remetente herdado; conteúdo, template, variáveis e tentativas continuam na cadência. A fila exibe suas cadências como links, sem repetir a seleção de campanhas. Uma fila atende várias cadências; cada cadência tem uma fila de saída. Referências externas de campanhas do CRM continuam no cartão de público.

A origem da fila vale para discagem manual e de campanha, e é revalidada antes de discar. O número deve ser o autorizado na conexão ou estar habilitado e recentemente verificado no catálogo da mesma conta. O modo legado de origem por DDD só se aplica a campanhas sem canais definidos pela fila; uma fila com número selecionado usa essa origem. Salvar uma fila sem enviar `campaign_ids` preserva seus vínculos; o campo legado continua aceito por compatibilidade. A API de configuração da cadência recebe `queue_id` (também em `config.queue_id` na etapa `voice`).

Trocas de fila respeitam revisão, workspace, cadências pausadas e ausência de atendimentos na fila. Alterações de canal atualizam as configurações herdadas das cadências pausadas, invalidam formulários antigos e cancelam WhatsApps pendentes ligados à configuração anterior. Históricos de chamadas permanecem. Templates precisam ser compatíveis com o provedor escolhido. Nenhuma edição habilita cadências, coloca agentes online ou dispara chamadas/mensagens.

A migração preserva as escolhas atuais quando as cadências de uma fila compartilham os mesmos canais. Filas sem uma configuração inequívoca permanecem pendentes de seleção explícita.

Validação dos canais herdados: **312 testes / 2.500 asserções** em cada banco isolado (SQLite e PostgreSQL), seis testes de áudio/microfone, build Vite e navegador desktop/celular. Cenários cobrem vínculo/movimentação de cadências, origem de chamada manual e revalidação na telefonia, isolamento, revisões concorrentes, rejeição de remetente incompatível, preservação dos demais vínculos, número receptivo inicialmente pausado e cancelamento de mensagens pendentes sem apagar chamadas. Nenhuma chamada ou mensagem real foi iniciada durante a homologação. Evidências privadas em `evidence/queue-channels-20261005/`.


## Discagem por número e aviso de outra conexão — 05/10/2026

No teclado, **Número de saída** mostra os telefones das filas atribuídas que permitem discagem manual, possuem canais configurados e uma conexão de voz pronta. O mesmo número aparece uma vez, mesmo vinculado a várias filas. A lista não contém origens arbitrárias nem números exclusivos de outras equipes. A escolha de filas continua em **Gerenciar filas**, para disponibilidade.

Ao solicitar a ligação, o servidor escolhe a primeira fila por ID que corresponda ao número, esteja selecionada na presença online e permita o destino. Uma fila offline não concede permissão adicional. As verificações de consentimento, categoria, opt-out, capacidade e limites continuam obrigatórias. Uma alteração administrativa entre abrir o teclado e clicar em Ligar é revalidada; selecionar o número não habilita fila nem inicia campanha.

`GET /api/voice/operations/queues` retorna `manual_origins`, agrupado por `number`, com `routes` contendo `queue_id`, `method`, `allow_landline` e `allow_mobile`. A reserva manual recebe `origin_number` em E.164, `session_id` da presença, `number` do destino e `idempotency_key`. O servidor resolve a fila; enviar também `queue_id` é rejeitado. O contrato legado por `queue_id` permanece por compatibilidade.

**Outra conexão** significa que uma identificação diferente da aba atual controla a presença do mesmo usuário e enviou heartbeat nos últimos 90 segundos. O sistema não informa o dispositivo nem comprova que exista outra pessoa conectada. Uma recarga também gera nova identificação; se o encerramento anterior não chegou ao servidor, é necessário aguardar o vencimento. A caixa explica o caso e impede usar os controles de status para encerrar a outra conexão. Quando o heartbeat vence, a interface volta a Offline e permite uma nova ação explícita de Online. A implantação não transfere nem encerra atendimentos ativos.

Validação desta alteração: **318 testes / 2.544 asserções** em cada banco isolado, seis testes de áudio/microfone e compilação Vite. A homologação de navegador utiliza API e telefonia simuladas; a conferência em produção é somente de leitura. Evidências privadas em `evidence/manual-origin-20261005/`.

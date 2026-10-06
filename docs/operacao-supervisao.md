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


## Retorno da ligação e pós-atendimento por fila — 05/10/2026

**Minha operação** e o teclado mantêm o resultado da última chamada do próprio atendente, mesmo após a reserva sair da tela. São mostrados destino, estado, tempo até o encerramento e retorno técnico disponível. “Não atendeu” é o resultado informado pela telefonia: não comprova que o aparelho tocou. Áudio conectado no navegador também não comprova atendimento do destinatário. Falhas locais de conexão são comunicadas à tela do agente, além do cartão da ligação.

Em **Filas de atendimento → Editar fila e vínculos → Pós-atendimento**, a supervisão define se existe intervalo, sua duração (até 3.600 segundos) e se o atendente pode encerrá-lo antes. As configurações anteriores de duração são preservadas; encerramento antecipado começa desabilitado. O intervalo só é criado quando houve atendimento confirmado. Não atendimento, ocupado, falha e cancelamento antes de atender não criam pós-atendimento.

O cronômetro começa no encerramento confirmado da chamada, tanto na saída quanto no receptivo. A tabulação utiliza esse mesmo tempo; salvá-la ou corrigir suas anotações não reinicia o intervalo. Mesmo se o tempo acabar ou estiver desabilitado, uma tabulação obrigatória pendente continua bloqueando uma nova chamada. Novas distribuições de voz e WhatsApp respeitam o intervalo.

O agente vê a fila responsável e a contagem regressiva no headset, teclado e operação. **Encerrar pós-atendimento** aparece somente quando permitido e exige concluir a chamada e salvar a tabulação. O encerramento não muda status online/pausado/offline nem a seleção de filas. Trocar de fila ou colocar-se online novamente não elimina o intervalo obrigatório. Duração e permissão são preservadas para o intervalo em andamento; alterações da supervisão valem para próximos atendimentos.

A resposta de `GET /api/voice/operations/queues` inclui `last_call` e `wrapup`. O encerramento antecipado usa `POST /api/voice/operations/wrapup/finish`, com `session_id` e `token` do intervalo. A versão incorporável oferece a mesma operação. O servidor valida usuário, workspace, sessão, política e identificação do intervalo; um pedido atrasado não pode encerrar outro intervalo.

Homologação: **329 testes / 2.659 asserções** em cada banco isolado, testes de áudio/microfone e compilação. Os testes do navegador simulam telefonia, resultados e cronômetros; a conferência em produção não disca nem muda a presença. Evidências privadas em `evidence/wrapup-feedback-20261005/` e `evidence/wrapup-admin-20261005/`.


## Andamento da chamada no teclado — 05/10/2026

A caixa **Fazer uma ligação** permanece aberta depois de clicar em **Ligar**. Mostra preparação, discagem aguardando atendimento, atendimento confirmado e resultado final no mesmo lugar. A confirmação **Cliente atendeu** depende do estado registrado pela telefonia; a conexão de áudio do navegador não é usada como prova de atendimento.

**Encerrar ligação** controla a chamada local em andamento. Fechar a caixa apenas a recolhe; o áudio continua no componente de atendimento e o ícone de telefone permite reabrir o acompanhamento. Uma ligação manual atendida e encerrada apresenta a tabulação dentro do próprio teclado, sem mudar de página. As anotações são preservadas ao recolher e reabrir a caixa. Não atendimento, ocupado, falha e cancelamento permanecem visíveis como resultado da última chamada.

O resultado é atualizado nas consultas periódicas ao servidor, com intervalo de aproximadamente cinco segundos. A preparação aparece imediatamente. Enquanto há uma chamada ou tabulação pendente, uma segunda discagem permanece bloqueada. A abertura ou fechamento da caixa não muda filas, disponibilidade, destino ou origem.

Homologação com telefonia e APIs simuladas: permanência da caixa, estado de discagem mesmo após conexão do SDK, confirmação de atendimento pelo servidor, encerramento pelo teclado, acesso à tabulação, reabertura durante a chamada e resultado final. Conferência visual em desktop e celular, sem chamadas reais. Evidências privadas em `evidence/dial-progress-20261005/`.


### Discagem e tabulação na mesma caixa

Os controles de chamadas manuais ficam no teclado do headset. A ficha em Minha operação oferece **Acompanhar no teclado**, sem duplicar os controles de áudio. Uma reserva manual recuperada ao carregar o MA também abre essa caixa, mostrando o estado registrado; isso não reconecta nem reinicia a chamada.

Quando a telefonia confirma o encerramento de uma ligação atendida, a tabulação aparece no mesmo local. O formulário permanece montado ao recolher o teclado, preservando as anotações enquanto o atendimento estiver pendente. Fechar o teclado não salva nem cancela a tabulação. **Atualizar resultado** permite consultar a ligação existente; **Permitir reprodução do áudio** continua disponível quando exigido pelo navegador para SIP.

Falhas de transporte apresentam orientação em português, sem significar “não atendeu” e sem repetição automática da ação. O aviso de consulta é retirado quando os dados voltam a ser obtidos. A causa da falha de rede do navegador não pode ser determinada apenas pelo texto “Failed to fetch”. Homologação: oito testes de áudio, microfone e transporte; fluxo simulado de discagem, falha de rede, recuperação, tabulação e preservação do rascunho, em desktop e celular. Nenhuma chamada ou mensagem real enviada na validação. Evidências privadas: `evidence/dial-unified-20261005/`.


### Recarregar a aba e liberar o receptivo — 05/10/2026

O aviso de desconexão (`ready=false`) libera o registro temporário do dispositivo quando não há oferta, chamada ou tabulação receptiva pendente. Antes, esse aviso atualizava a data da conexão e renovava por 60 segundos o bloqueio contra outra sessão, causando um falso aviso de outra aba após Ctrl + F5.

A liberação e o registro usam o mesmo bloqueio transacional. Mensagens atrasadas de uma sessão anterior não alteram a nova. Uma chamada ou oferta pendente preserva sua identidade e impede troca de conexão, mesmo após expirar o prazo. Se o navegador não conseguir comunicar o fechamento, permanece o prazo de proteção de 60 segundos; a mensagem informa a possibilidade de recarregamento em vez de afirmar que existe outra aba.

Cada conexão do SDK recebe um identificador próprio. Eventos atrasados de dispositivos destruídos são ignorados. A atualização não coloca agentes online e não inicia chamadas. Testes isolados cobrem liberação imediata, mensagem atrasada, registro em andamento, expiração e oferta receptiva ativa, além da reconexão após recarregar no navegador. Evidências privadas em `evidence/inbound-release-20261005/`.

### Experiência do administrador e modal persistente — 05/10/2026

O administrador inicia em **Visão geral** quando abre o MA sem um endereço específico. Links antigos de cadências, relatórios e demais páginas continuam válidos. O agente mantém Minha operação como entrada. A visão geral consulta apenas cadastros e filas existentes; seus cards não ativam canais nem comprovam áudio ou entrega de mensagens. Dados sem consulta bem-sucedida aparecem como indisponíveis, e erros de atualização são informados.

**Filas de atendimento** apresenta cards pesquisáveis. Selecione **Ver fila** para os controles operacionais ou **Editar fila e vínculos** para as abas Visão geral, Números e canais, Equipe e Permissões e pausas. A equipe atual fica recolhida em **Equipe agora**. A única ação de persistência continua sendo **Salvar fila**; alternar abas preserva os valores editados.

No agente, **Discagem manual** abre um modal centralizado e adapta-se a telas menores. Clique no fundo e Escape não fecham o discador. Os estados da ligação, erro e resultado final permanecem nele. O teclado numérico é recolhido durante a preparação/chamada para dar prioridade ao acompanhamento. A tabulação permanece no mesmo modal. O botão × fecha a interface, preservando a chamada e o rascunho; Encerrar ligação é uma ação independente. Pausas automáticas por erro não fecham nem substituem o modal por uma caixa de status. A tela de fundo fica inerte enquanto a caixa está aberta, e o foco de teclado volta ao controle de origem ao fechar.

O resumo da última chamada em Minha operação pode ser expandido para detalhes. A abertura de telas e cards não disca, não envia WhatsApp e não altera a disponibilidade. Homologação desta rodada com telefonia simulada e conferência pública somente de leitura; evidências privadas em `evidence/workspace-experience-20261005/`.


### Distribuição entre atendentes — 06/10/2026

Em **Filas de atendimento → Ver fila → Editar fila e vínculos → Permissões e pausas**, escolha **Regra de distribuição** e salve. A alteração exige fila de saída pausada e ausência de atendimentos ou tabulações pendentes, como as demais configurações da fila.

- **Rodízio entre atendentes:** prioriza quem recebeu uma atribuição há mais turnos, entre os elegíveis. O registro de turnos é separado por canal e não muda com o heartbeat do navegador.
- **Há mais tempo livre:** considera entrada em disponibilidade, fim da última chamada, pós-atendimento e última atribuição.
- **Padrão por canal:** preserva o comportamento anterior das filas existentes. Novas filas sugerem rodízio.

A regra vale para ofertas receptivas, reservas de saída progressiva e distribuição automática de conversas WhatsApp. Preview, discagem manual e transferência com destinatário escolhido mantêm a escolha explícita. No progressivo, participam do rodízio os navegadores que pediram trabalho nos últimos 15 segundos; um navegador que para de pedir não bloqueia a fila indefinidamente. Agentes sem elegibilidade, pausados ou offline são ignorados. Chamadas e tabulações pendentes impedem atribuir outra chamada ao mesmo agente. No WhatsApp continua valendo o limite de conversas abertas da rota.

A configuração interna `MA_VOICE_SIMULTANEOUS_CALLS` limita conjuntamente chamadas de entrada e saída. O padrão e o ambiente em uso continuam em **1**; a homologação isolada usa **3**. Reservas de agentes e contatos, callbacks e contadores são protegidos pelo mesmo bloqueio transacional no PostgreSQL. Um limite configurável não constitui homologação de operadora, SIP, áudio, consumo ou modo preditivo. O modo implementado continua sendo progressivo.

Foram criadas três contas de homologação com perfil de agente. As credenciais são privadas e não fazem parte do repositório. Evidências de testes isolados e publicação ficam em `evidence/queue-distribution-20261006/`. Os testes usam sinalização sintética, sem chamadas ou mensagens para clientes e sem colocar as contas reais online.

Resultados: **341 testes e 2.724 asserções em cada banco (SQLite e PostgreSQL)**. Contenção PostgreSQL com 90 solicitações e até seis processos concorrentes: três ofertas receptivas a três agentes; três reservas de saída a três agentes; duas entradas junto com uma saída, sem duplicidade de agente ou contato. Foram verificados também três atendimentos e três tabulações receptivas sintéticos. Uma bateria adicional com 40 solicitações confirmou uma admissão e 39 rejeições quando a capacidade está em um. Não são medições de áudio nem de desempenho da operadora.


### Configurações operacionais da fila — 06/10/2026

Em **Filas de atendimento → Ver fila → Editar fila e vínculos**, as opções ficam em Visão geral, Números e horários, Equipe, Distribuição e permissões e Gravação. Alternar abas mantém o formulário; **Salvar fila** é a ação que aplica os valores. A saída deve estar pausada e os atendimentos/tabulações concluídos para alterar a configuração.

**Horário:** a opção é desativada nas filas existentes. Ao habilitar, escolha fuso, dias de abertura e início/fim. Fechamento anterior à abertura indica expediente que termina no dia seguinte, atribuído ao dia de início. O fim é exclusivo. O horário limita novas chamadas receptivas, progressivas e manuais, sem encerrar chamadas em andamento; no progressivo também se aplica o horário da cadência. Não muda os horários de envio do WhatsApp.

**Agentes:** o tempo de aceite substitui o tempo da rota receptiva; vazio preserva o valor da rota. Não altera o tempo para o cliente atender uma chamada de saída. A pausa após X ofertas conta apenas retornos receptivos de não atendimento ou ocupado/recusa, uma vez por oferta. Atendimento ou retorno explícito a Online zera o contador. A pausa é global para o agente, preservando sua sessão, e informa qual fila a provocou. A opção de pausar por desconexão receptiva considera eventos do navegador e registros sem atualização por 60 segundos, conferidos a cada minuto. Ofertas a dispositivos sem conexão continuam bloqueadas mesmo se a pausa automática estiver desligada.

**Destinos:** fixos de qualquer DDD e celulares mantêm as permissões existentes. Internacionais e serviços brasileiros 0300/0500/0800/0900 têm opções próprias; a telefonia, os destinos privados de homologação e os limites operacionais continuam sendo verificados. A migração preserva a permissão internacional anterior; o formulário de uma nova fila sugere internacionais e especiais desligados. Números curtos de emergência não são suportados.

**Gravação:** implementada para Twilio Programmable Voice em entrada e saída. A gravação por SIP/Asterisk não está implementada e sua ativação é recusada pelo servidor. A opção permanece desligada nas filas existentes. Quando habilitada, o TwiML solicita gravação a partir do atendimento e callbacks assinados. Credenciais e URLs da operadora não são expostas ao navegador. A configuração é registrada na chamada e vale para seus registros, sem alterar retrospectivamente gravações anteriores.

O administrador/supervisor pode consultar **Gravação → Gravações desta fila** (50 registros recentes). O agente visualiza o estado e ouve o áudio de chamadas sob sua responsabilidade quando autorizado; a opção independente de pausa permite pausar/retomar durante a chamada. No atendimento manual, os controles ficam no modal do discador; no receptivo e progressivo, no atendimento correspondente. Fechar o modal não encerra a ligação. As operações de acesso e controle ficam na auditoria.

O prazo é de 1 a 365 dias. Ao vencer, o acesso ao áudio é negado imediatamente; uma tarefa a cada hora solicita a exclusão na Twilio. Falhas da operadora preservam o registro para nova tentativa. O histórico da chamada permanece. A exclusão é definitiva quando confirmada pela operadora. Gravação e armazenamento podem gerar cobrança. A validação desta entrega usa APIs simuladas; gravação e reprodução com telefonia real dependem de homologação. Referências: [TwiML Dial](https://www.twilio.com/docs/voice/twiml/dial) e [API de gravações](https://www.twilio.com/docs/voice/api/recording).

Nenhuma fila, chamada ou gravação foi ativada para esta entrega. Evidências privadas em `evidence/queue-settings-20261006/`.

Validação: rodada completa com 351 testes e 2.785 asserções em SQLite e PostgreSQL; após os ajustes finais de contador e retenção, os 10 testes específicos passaram novamente nos dois bancos (63 asserções). O navegador verifica gravação/pausa no modal, persistência dos formulários, mensagens de pausa e adaptação a celular com telefonia simulada. Nenhuma mídia real foi gravada nesta validação.

### Reentrada nas cadências de voz e WhatsApp — 06/10/2026

Em **Cadências → abrir a cadência → Público da jornada → Entrada e reentrada**, configure e salve com a cadência pausada. O segmento continua sendo a origem do público; concluir uma participação não remove seu cadastro nem o vínculo com o segmento. A política pertence à cadência e não à fila.

- **Uma única vez:** admite uma participação por contato.
- **Ao sair e voltar ao segmento:** exige uma transição real de saída e retorno ao segmento vinculado; permanecer nele não reinicia a jornada. Saídas por regras e remoções explícitas são observadas. Uma exclusão manual continua exigindo restauração explícita.
- **Após um intervalo:** espera de 1 a 3.650 dias desde o encerramento anterior e reavalia se o contato ainda está no público.
- **Por novo evento:** exige uma requisição autorizada por ocorrência de negócio. Eventos duplicados retornam o primeiro resultado. Eventos bloqueados durante uma execução não são guardados para disparo posterior; um novo evento só deve representar uma nova ocorrência real.

Nas opções de reentrada, o limite de 1 a 100 participações inclui a primeira e o ciclo histórico anterior. Escolha quais encerramentos permitem voltar: atendimento por voz, resposta WhatsApp, esgotamento de tentativas, WhatsApp sem resposta, falha confirmada de mensagem, saída do segmento ou cancelamento. O padrão sugere três participações e permite esgotamento, ausência de resposta e saída do segmento; o modo sugerido inicialmente é **uma única vez**. Nada é habilitado automaticamente nas campanhas existentes.

Cada participação possui um identificador e número sequencial, chamadas, follow-up e mensagens próprios. As cinco tentativas, por exemplo, passam a pertencer à participação, sem apagar as cinco do ciclo anterior. Limites diários da campanha, limites globais do contato, restrições de números e consentimento continuam cumulativos e podem impedir novas chamadas mesmo quando a reentrada é elegível. Os estados da fila e a disponibilidade dos agentes continuam necessários para discar.

O primeiro salvamento da política organiza o histórico real anterior como participação #1, preservando resultados e tentativas. A política de retorno é reavaliada para a próxima admissão; a janela de espera por WhatsApp é registrada em cada participação. A configuração não reabre uma execução concluída e não redefine a revisão dos templates.

A espera por resposta ao WhatsApp é de 1 a 720 horas (sugestão: 24). Começa quando o acompanhamento confirma que a mensagem foi enviada, entregue ou lida. Após esse prazo, encerra como sem resposta. Mensagens bloqueadas, em processamento ou de resultado incerto não encerram automaticamente como sem resposta; exigem resolução. Atendimento ou resposta encerra a abordagem e cancela passos pendentes. Respostas ambíguas entre participações não ganham atribuição artificial no relatório, mas continuam interrompendo a prospecção por segurança.

Nunca há duas participações ativas da mesma cadência para o mesmo contato: transação e índice único garantem isso. Chamada, reserva e tabulação pendentes impedem reentrada. Uma conversa WhatsApp com resposta e ainda aberta também impede nova entrada. A reentrada não apaga `replied_at` nem remove um descadastro; utiliza o contexto autorizado da nova participação, sem liberar abordagens avulsas ou outras cadências.

**Histórico de participações**, no cartão Entrada, mostra o número do ciclo, resultado, chamadas e mensagens; ao abrir uma participação, exibe seus detalhes. O relatório existente aceita `run_id` como filtro e devolve esse identificador nos registros. Os totais por jornada continuam incluindo todas as participações.

**Evento:** `POST /api/v1/journeys/{id}/entries`, com `Authorization: Bearer <credencial>`, `Idempotency-Key: <UUID>` e `{"contact_id":123,"event_key":"pedido:456"}`. Exige o novo escopo `journeys:write`, workspace correspondente e autorização da integração para o segmento vinculado. A chave de negócio é única na cadência e não pode ser reutilizada para outro contato. Consulte o contrato em `/integrations/openapi.json`. O webhook de cadastro de segmento continua atualizando atributos; ele não é, por si só, um evento de reentrada. O integrador pode atualizar o contato e então enviar o evento de negócio.

No modo por evento, **Testar sem inserir na jornada** verifica a configuração salva e informa o motivo de bloqueio; não cria participação, chamada, mensagem ou recibo de evento. A reavaliação automática ocorre a cada minuto e antes da reserva progressiva. Estas opções pertencem ao construtor das cadências de voz e WhatsApp; gatilhos do construtor de automação MA continuam seguindo seu motor de eventos existente.

Validação desta entrega: 370 testes e 2.874 asserções em SQLite e PostgreSQL; depois do ajuste final, 19 testes específicos e 87 asserções passaram novamente em cada banco. Concorrência PostgreSQL com 40 pedidos, quatro processos e uma admissão por ciclo. Navegador validado em desktop e celular, incluindo as quatro opções, salvamento, erro recuperável, histórico e prévia de evento. Nenhuma chamada ou mensagem externa foi usada. Evidências privadas em `evidence/reentry-20261006/`.

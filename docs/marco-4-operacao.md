# Marco 4 — operação do discador

Atualização de 04/10/2026. Implementação no MA, em `ma.zyrex.ia.br`, sem alteração do CRM. A publicação não inicia campanhas, filas, chamadas ou mensagens.

## Acesso e sequência de uso

Acesse **Voz e cadências → Operação e relatórios**. O administrador do MA tem acesso às configurações. Atendentes consultam suas chamadas e tabulam seus próprios resultados; administrador e supervisor consultam o conjunto do workspace. Novos usuários têm perfil de voz `agent` por padrão; a gestão de usuários e atribuição de perfis ainda não tem tela própria.

1. **Listas:** crie uma lista, selecione CSV ou XLSX, indique as colunas de nome/telefone e documente a origem e a autorização. Confira a prévia e baixe os erros por linha antes de confirmar os contatos válidos. Até 1.000 contatos/2 MB por arquivo; XLSX usa a primeira aba. Telefones em XLSX devem ser texto; fórmulas não são executadas.
2. **Campanhas:** configure o público, horário, máximo de tentativas, números de voz/WhatsApp e o limiar de não atendimentos. Para alterar regras operacionais, deixe a campanha pausada ou em rascunho.
3. **Regras de tentativas:** vincule a lista; ajuste limites por contato/dia, limites entre campanhas, prazo e intervalos para não atendimento, ocupado, falha e cancelamento. Alterar regras cancela os passos de WhatsApp ainda pendentes; não zera o histórico de tentativas.
4. **Filas reais:** crie a fila, vincule campanha e atendentes, escolha preview ou progressivo e tempo de pós-atendimento. A fila nasce pausada. A campanha também precisa estar iniciada e dentro do horário. Fique disponível para reservar um contato.
5. **Preview:** reserve, confira contato e evidência de autorização, escolha API ou SIP e inicie a ligação. **Progressivo:** confirme a autorização e clique em Iniciar progressivo; a sequência aguarda o pós-atendimento e busca o próximo contato elegível. Parar sequência impede novas discagens e permite concluir a chamada atual. Fechar a aba interrompe a sequência; ela não reinicia sozinha.
6. **Relatórios e tabulação:** classifique chamadas encerradas, registre observações ou retorno solicitado e exporte os filtros em CSV. Uma chamada atendida na fila exige tabulação antes do próximo contato. Retorno solicitado registra data para acompanhamento; não agenda ligação nem reinsere automaticamente o contato na jornada.

Os limites privados já existentes de homologação continuam valendo: uma chamada externa por vez, destinatários autorizados, duração e cota diária compartilhadas por API e SIP. A interface não aumenta esses limites.

## Jornada e elegibilidade

O resultado técnico da operadora fica separado da tabulação comercial. A confirmação de atendimento cancela os passos de WhatsApp pendentes imediatamente, sem esperar o encerramento ou a tabulação. Depois do atendimento, o contato não é discado novamente naquela campanha. Classificar como caixa postal ou corrigir a tabulação não reabre a jornada automaticamente.

A etapa de voz verifica autorização, exclusão, resposta, participação na campanha/lista, horário, validade, tentativas, intervalos e chamadas pendentes. As verificações ocorrem na reserva e novamente antes de permitir a discagem no callback Twilio ou no evento do Asterisk. Retirar da lista também cancela o WhatsApp pendente associado à lista e impede o envio na revalidação. Mensagens já aceitas pelo provedor não podem ser recolhidas.

Os limites globais contam tentativas reais iniciadas, inclusive avulsas e de outras campanhas. Prevalece o menor limite das políticas relacionadas ao contato, considerando vínculos atuais e campanhas com histórico de chamadas. O limite diário global usa UTC; o diário de campanha usa o fuso da campanha. Falhas técnicas possuem teto diário separado. Reservas sem início não são chamadas realizadas, mas continuam sujeitas à cota de segurança da homologação.

Reimportar preserva nome, autorização, exclusão e histórico de contatos existentes. Reincluí-los na lista não remove bloqueios nem reinicia contadores. Importações posteriores acrescentam contatos às campanhas vinculadas à lista. A tela de participação mostra o total de tentativas e o motivo de inelegibilidade por campanha.

O passo após X não atendimentos usa a cadência de WhatsApp entregue anteriormente: API Twilio ou conector QR, mesmo número ou número separado. Não foi criada nesta entrega uma entrada genérica em qualquer grafo de jornada do MA; essa ampliação exige definir e validar o contrato de eventos.

## Relatórios e origem das métricas

Os relatórios usam registros reais de telefonia, separados dos registros do simulador. O ambiente atual é homologação; a migração não reclassifica registros antigos como produção. Filtros: datas, campanha, lista, atendente, método, resultado, telefone e pendência de tabulação. A API também aceita fila, origem, ambiente e código de tabulação.

Indicadores: tentativas iniciadas, atendidas tecnicamente, contatos humanos confirmados, contatos convertidos, pendências, tabulações faltantes e segundos de conversa. Atendimento técnico não prova conversa com humano; a confirmação humana depende da tabulação. Correções exigem motivo e revisão atual; o histórico conserva o rótulo registrado na época. Novas chamadas preservam nomes do contato/campanha/atendente em um snapshot; registros antigos sem snapshot usam os nomes disponíveis.

A consulta de custos é manual e somente leitura. Para Programmable Voice, busca as pernas de navegador e telefone correlacionadas pelos SIDs; preços indisponíveis continuam nulos. A soma agrupa por moeda e inclui somente pernas com preço confirmado; não representa necessariamente o total da fatura. SIP permanece sem custo conciliado até existir correlação confiável com o registro tarifado. O CSV exporta as chamadas filtradas, timestamps UTC e metadados de geração, com proteção contra fórmulas de planilha. Valores de custo por perna estão no painel/API; não estão nas colunas deste CSV inicial.

Referência do provedor: [Call resource — campos de preço e correlação](https://www.twilio.com/docs/voice/api/call-resource).

## Filas e números de saída

As filas reais são separadas das filas do simulador. Reserva, chamada, tabulação e pós-atendimento compartilham exclusão por atendente/contato. Uma reserva sem chamada expira em três minutos; presença exige atualização recente. Falta de confirmação de encerramento mantém o bloqueio, sem repetir automaticamente uma chamada de resultado desconhecido. Distribuição inicial FIFO; receptivo e transferências não fazem parte desta entrega.

Em **Números de saída**, a sincronização lê números de voz e identificadores verificados na conta Twilio configurada. Novas origens entram desabilitadas; habilite as que deseja usar. A opção “mesmo DDD” escolhe aleatoriamente entre origens habilitadas, da mesma conta e DDD do destino, verificadas nas últimas 24 horas. A escolha é fixada na reserva e preservada em repetições idempotentes. Sem origem elegível, bloqueia; não compra números nem escolhe outro DDD. Com número único de voz/WhatsApp, a origem também precisa coincidir com o remetente configurado.

O número dos EUA contratado pode continuar no modo de origem configurada. Ele não atende ao requisito de mesmo DDD brasileiro. A apresentação do identificador e a permissão de uso de cada origem na rota API/SIP ainda precisam ser homologadas com a operadora. Sincronizações com mais de 100 itens em uma categoria são recusadas sem substituir parcialmente o catálogo; paginação ampliada fica para a evolução de escala.

Referências: [números da conta](https://www.twilio.com/docs/phone-numbers/api/incomingphonenumber-resource) e [identificadores verificados](https://www.twilio.com/docs/voice/api/outgoing-caller-ids).

## Verificação e publicação

- 152 testes / 1.187 asserções em SQLite e PostgreSQL isolado, com HTTP dos provedores simulado e bloqueio de requisições inesperadas.
- Build Vite aprovado. A nova área carrega sob demanda.
- Cobertura de permissões, tabulação/revisões, CSV, XLSX/entidades, reimportação, limites entre campanhas, bloqueios antes de API/SIP, fila/tabulação/pós-atendimento, origens e preços pendentes; regressão do cancelamento imediato do WhatsApp ao atender.
- Backup de código e PostgreSQL antes da migração aditiva. Publicação sem reinício de serviços. Contadores de telefonia e mensagens são comparados antes/depois.
- Conferência do navegador usa consultas autenticadas e fixtures interceptadas para todas as ações de escrita, sem ligar ou enviar WhatsApp.

Evidências em `evidence/operations-20261003/`. A data da pasta corresponde ao início do trabalho. Nenhuma chamada de homologação foi realizada nesta entrega, por instrução do usuário. Áudio e telefonia reais serão testados pela manhã.

## Próximos marcos

**5:** receptivo, retorno aos números de saída, transferências e equipe compartilhada entre entrada/saída. **6A:** API pública autenticada, webhooks, importação por integração e discador incorporável ao CRM, sem alterar o CRM nesta fase. **6B:** inbox, distribuição de conversas e leads sociais. **6C:** supervisão, retenção, capacidade, custos e escala. Homologação de áudio API/SIP e dos remetentes WhatsApp continua necessária antes de ampliar a operação.

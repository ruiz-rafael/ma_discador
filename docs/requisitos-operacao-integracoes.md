# Requisitos de operação e integrações

Especificação de planejamento de 03/10/2026, incorporada ao [roteiro de próximos marcos](roteiro-proximos-marcos.md). Os requisitos abaixo descrevem entregas futuras; não significam que foram implementados ou publicados. O MA permanece independente do CRM e dos demais projetos da VM.

## 1. Tabulação e relatórios — marco 4A

### Dois resultados por atendimento

Preservar separadamente o **resultado técnico**, recebido do provedor/PBX, e a **tabulação comercial**, informada pelo atendente. Um não sobrescreve o outro.

- Técnico: aguardando, chamando, atendida, encerrada, ocupado, não atendida, falha, cancelada ou resultado incerto; preservar também status bruto, código SIP, causa e eventos.
- Comercial: interessado, sem interesse, retorno agendado, conversão, pessoa errada, telefone inválido confirmado, caixa postal ou pedido de interrupção. Não inferir esses resultados somente do código SIP.
- Catálogo de tabulações configurável por campanha, com códigos estáveis, campos obrigatórios, motivo e próxima ação. Alterações futuras não mudam o significado dos registros históricos.
- Exigir tabulação ao concluir um atendimento humano, com tratamento de abandono da tela e pendências. Retornos exigem data; interrupção exige cancelamento das abordagens pendentes.
- Correções de tabulação geram revisão auditada, com autor, data e motivo. Não alterar silenciosamente o resultado anterior.

Uma chamada marcada como atendida pela operadora pode ter chegado à caixa postal. Não comprova conversa humana, qualidade de áudio nem conversão. Nos testes atuais, preservar `480` e `603` sem afirmar bloqueio do aparelho ou recusa consciente do destinatário.

### Registro e reconciliação

Cada tentativa deve relacionar organização/workspace, contato, telefone usado naquele momento, campanha/segmento e referências externas, lista/lote, ciclo, versão das regras, atendente, fila, direção, método API/SIP, origem escolhida e DDD. Registrar início, atendimento e término, duração disponível, resultado técnico, tabulação, próxima ação e custos quando conhecidos.

Manter UUID próprio da tentativa e identificadores do provedor, incluindo pernas de áudio. Na API, a conexão do navegador e a chamada ao telefone pertencem a uma única tentativa comercial: não contar duas ligações por existirem dois registros do provedor. Somar custos das pernas faturadas quando disponíveis, sem duplicação.

Eventos repetidos ou fora de ordem devem ser conciliados de forma idempotente. Resultado incerto permanece pendente até confirmação/reconciliação. Corrigir o telefone de um contato não altera o destino histórico: as tentativas com DDD 13 e a posterior tentativa com DDD 11 continuam distintas.

Usar os registros reais de `voice_outbound_calls` e suas evidências como ponto de partida; projetar armazenamento adicional para eventos, pernas e tabulações. `voice_attempts` e reservas simuladas permanecem identificados como laboratório. Classificar testes reais existentes como homologação, preservando a procedência dessa classificação.

### Relatórios e definições de indicadores

Filtros: período/fuso, laboratório/homologação/produção, campanha, segmento, lista/lote, atendente, fila, direção, método, origem/DDD, resultado e tabulação. Primeira entrega: histórico paginado, resumo e exportação CSV com os mesmos filtros e permissões. Exportações grandes podem evoluir para processamento assíncrono; neutralizar fórmulas em células provenientes de dados externos.

| Indicador | Definição prevista |
| --- | --- |
| Solicitações e reservas | UUIDs próprios únicos, separados das tentativas efetivamente enviadas à telefonia |
| Tentativas de telefonia | Chamadas ao destinatário efetivamente submetidas ao provedor/PBX; excluir pernas do navegador, callbacks duplicados e bloqueios anteriores ao envio |
| Atendidas tecnicamente | Tentativas com evento de atendimento; taxa sobre tentativas de telefonia do recorte, com pendências visíveis |
| Contatos humanos | Contatos distintos com confirmação comercial de conversa humana; ausência de confirmação é desconhecido |
| Conversão | Contatos distintos com conversão confirmada / contatos humanos distintos do recorte; sem denominador, mostrar indisponível |
| Duração | Tempo de conversa e de espera quando disponíveis; não assumir equivalência com minutos faturados |
| Tentativas por contato | Por ciclo/campanha e acumuladas entre listas, sem reinício por reimportação |
| Saídas e passagem ao MA | Quantidade e motivo de conclusão, exclusão, esgotamento, retorno ou entrada em jornada |
| Custos | Valores efetivos por moeda e perna faturada; preço pendente é indisponível, não zero; estimativas identificadas separadamente |

O filtro temporal inicial usa a data de início da tentativa, armazenada em UTC e exibida no fuso selecionado. Eventos posteriores atualizam o resultado desse registro; relatórios com chamadas pendentes são provisórios. Exportações registram filtros e data de geração. Não somar moedas diferentes sem critério explícito de conversão.

No marco 5, acrescentar espera receptiva, abandono, nível de serviço com limiar configurado, transferência e pós-atendimento. Supervisão ampliada e conciliação financeira evoluem no 6C.

**Aceite:** reconciliar as duas chamadas atendidas já documentadas, mostrar falhas e destinos corrigidos sem perda de histórico, não duplicar tentativas por evento/perna, manter testes fora dos indicadores de produção e auditar revisão comercial sem alterar o resultado técnico.

## 2. Tentativas, listas e passagem ao MA — marco 4B

### Política configurável

Por campanha/ciclo: limite total e diário, intervalo por resultado, janela/dias/fuso, prazo de permanência, regras de retorno, resultados elegíveis para nova tentativa e limiar/condição para iniciar uma jornada do MA. Aplicar também limites globais por contato/telefone na organização, independentemente da quantidade de listas ou campanhas.

- Bloqueio ou cancelamento antes de submeter a chamada não conta como tentativa de telefonia; permanece no histórico operacional.
- Ocupado, não atendimento e rejeição após envio consomem tentativa. Repetir depende da política configurada, do intervalo e dos demais limites.
- Falhas de credencial/rota e resultados incertos têm orçamento técnico e reconciliação próprios. Não devem disparar marketing por esgotarem um contador técnico. Contabilizar separadamente se a solicitação chegou ao provedor.
- Atendimento interrompe a sequência automática até tabulação. Retorno abre ação explicitamente agendada e continua sujeito aos limites aplicáveis.
- Resposta, conversão, exclusão ou interrupção solicitada retiram o contato das abordagens incompatíveis e cancelam ações pendentes.

Manter inicialmente a condição conservadora já usada no laboratório: apenas `no_answer` é elegível ao gatilho automático de WhatsApp. Ocupado, rejeição e caixa postal exigem política explícita; não convertê-los silenciosamente em autorização para envio.

Exemplo configurável: três não atendimentos elegíveis, respeitando intervalos e horários, encerram a etapa de voz e criam **uma entrada na jornada selecionada do MA**. Não significa envio imediato: o motor revalida consentimento, exclusão, resposta, horário, template e canal antes de enviar.

A decisão registra contadores, motivo e versão das regras. Usar chave idempotente por organização/contato/ciclo/ação/versão para evitar duas entradas na mesma jornada. Alterar configurações cancela ou reavalia pendências de forma explícita; não reinicia limites por acidente. A passagem entre voz e MA deve possuir um único responsável pelo agendamento, evitando duas automações concorrentes.

### Entrada e saída das listas

Estados previstos: aguardando elegibilidade, reservado, em chamada, retorno agendado, concluído, tentativas esgotadas, encaminhado ao MA ou suprimido. Motivos de saída e reentrada ficam no histórico.

Entradas podem ocorrer por arquivo, API, segmento/campanha referenciada ou retorno autorizado. Saídas consideram máximo de chamadas, prazo, tabulação, conversão, retirada da origem ou pedido de interrupção. Retirar da lista impede novas abordagens; não apaga histórico nem encerra uma conversa em andamento sem ação explícita.

Reimportar ou mover entre listas não remove exclusão, redefine consentimento, zera contadores globais nem duplica reservas. Novo ciclo exige decisão explícita e ainda respeita bloqueios globais. Revalidar elegibilidade tanto na reserva quanto imediatamente antes de discar ou enviar mensagem.

**Aceite:** testar limiar de tentativas, janela/intervalo, reimportação, contato em duas campanhas, edição de política, opt-out durante espera e callbacks concorrentes. Em todos os casos, limites e exclusões prevalecem e a jornada não é duplicada.

## 3. Arquivos e integração de listas — marcos 4B e 6A

O CSV atual é limitado a 500 linhas/512 KB. Evoluir para importação em lotes e XLSX, com mapeamento de colunas, prévia, validação, relatório de erros por linha, identificação do lote e reprocessamento idempotente. Dimensionar limites novos com testes; não anunciar importação ilimitada.

Arquivo e API usam as mesmas regras: telefone original e normalizado em E.164, identificador externo, origem, referências de campanha/segmento, evidência de autorização quando aplicável e atribuição do lead. Número ambíguo vai para correção, sem inventar DDD. Deduplicação por organização e telefone normalizado, conciliando identificadores externos sem misturar organizações.

Oferecer inclusão incremental e, futuramente, sincronização explícita de membros. Uma importação parcial não deve excluir os ausentes como se fosse uma substituição completa. A prévia deve distinguir criar, atualizar, manter, suprimir e rejeitar. Consentimento e exclusões existentes não são sobrescritos por um arquivo genérico.

**Aceite:** reenviar o mesmo lote não duplica contatos/membros ou reativa excluídos; linhas inválidas ficam rastreáveis; arquivo e API produzem os mesmos resultados de validação.

## 4. DDD, seleção aleatória e máscara — marco 4C

Manter catálogo de números de origem por organização/provedor: número E.164, DDD, titularidade/autorização verificada, recursos de voz/WhatsApp, método compatível, estado, limites e rota de retorno. O servidor seleciona aleatoriamente somente entre origens habilitadas do mesmo DDD do destinatário. Guardar a escolha na tentativa e reutilizá-la ao repetir a mesma requisição idempotente.

**Regra padrão: DDD obrigatório.** Sem origem elegível, bloquear com motivo claro. Não substituir silenciosamente por outro DDD ou pelo número dos EUA. Uma nova tentativa pode escolher outra origem elegível, mas não reinicia contadores nem permite contornar exclusões. Destinos sem DDD brasileiro reconhecido exigem política específica antes da discagem.

“Máscara” significa apresentar um identificador de chamada autorizado e aceito na rota. A Twilio exige número da conta ou identificador verificado tanto na [API de chamadas](https://www.twilio.com/docs/voice/api/call-resource) quanto na [terminação SIP](https://www.twilio.com/docs/sip-trunking#allowed-caller-id-numbers-in-termination-calls). Ter VoIP ou tronco SIP não autoriza usar qualquer número. A apresentação efetiva deve ser homologada na rota de destino.

Não assumir que verificar um identificador habilita recebimento: cada origem ofertada para retorno precisa de uma rota funcional. Aquisição, disponibilidade e habilitação dos números por DDD são dependências de operadora; o conjunto de números locais ainda não está contratado.

Preservar `number_mode=single|separate`:

- **Único:** a origem de voz selecionada também precisa ser remetente WhatsApp habilitado. Ao combinar com rotação de origens, cada número elegível precisa dessa capacidade; a cadência mantém a associação ao remetente escolhido. Rejeitar combinações incompatíveis.
- **Separados:** a voz seleciona sua origem por DDD e o WhatsApp usa o remetente configurado, com associação preservada no histórico.

**Aceite:** nenhuma chamada sai com origem arbitrária, de outra organização ou de outro DDD na política estrita; repetir uma requisição mantém a origem; indisponibilidade gera bloqueio auditável; retornos e compatibilidade de canal são verificados.

## 5. Filas de saída e agentes ativos/receptivos — marcos 4C e 5

Conectar reserva, tentativa real, eventos e tabulação em uma máquina de estados comum. Começar por preview, no qual o atendente vê o contato e inicia a chamada; acrescentar progressivo somente após homologar a ocupação real e os limites. Preditivo não faz parte desta entrega.

O usuário pode participar de filas ativas, receptivas ou ambas. Disponibilidade é compartilhada entre os sentidos e entre abas: disponível, reservado/tocando, em chamada, pós-atendimento, pausa ou offline. A prioridade receptiva afeta a próxima distribuição, sem interromper uma conversa em andamento.

A disputa entre entrada e saída deve reservar capacidade atomicamente. Incluir toque com timeout, redistribuição, transbordo/indisponibilidade, retorno aos números de origem e transferência com histórico. Expiração de presença não libera chamada real de resultado incerto; primeiro reconciliar com a telefonia. Pausar impede novas distribuições e permite concluir as atuais.

**Aceite:** demonstrar entrada e saída simultâneas disputando o mesmo agente sem dupla ocupação, duas abas sem capacidade duplicada, redistribuição após timeout, transferência, pós-atendimento e recuperação de desconexão do navegador.

## 6. API aberta e discador incorporável — marco 6A

“Aberta” significa API documentada e disponível a integrações autorizadas. Propor `/api/v1`, contrato OpenAPI e versionamento, sem tratar os endpoints atuais da sessão do MA como API pública já pronta. Definir IDs e contratos desde 4A para evitar retrabalho.

| Recurso | Operações previstas |
| --- | --- |
| Contatos, listas e referências | Importar/sincronizar membros; referenciar campanha e segmento do CRM sem alterar seu catálogo |
| Chamadas | Solicitar, consultar, encerrar quando permitido e tabular; correlacionar IDs do MA e externos |
| Atendimento | Obter sessão curta de áudio, consultar presença e filas permitidas |
| Relatórios | Consultar indicadores e solicitar exportações autorizadas |
| Conversas | Vincular lead/contato e consultar eventos permitidos, após 6B |

Credenciais de integração ficam no servidor do CRM, com escopos por organização e operação, revogação, limites, auditoria e chaves de idempotência. O navegador recebe somente sessão curta e delegada ao usuário autorizado; nunca Auth Token, segredo de API ou senha SIP da infraestrutura. Isolamento multi-organização e papéis de acesso são requisitos anteriores à abertura externa, não capacidades presumidas do laboratório atual.

Webhooks assinados têm ID, versão, correlação e tentativas de reentrega. Consumidores deduplicam eventos; não prometer entrega exatamente uma vez. Prever fila de falhas, consulta/reprocessamento e validação dos destinos de webhook, incluindo restrições a endereços internos.

Entregar componente de discador mantido no MA, incorporável por SDK/componente ou iframe com origens permitidas e comunicação validada. A chamada dentro do CRM exige sessão de áudio WebRTC, permissão de microfone e gestão de presença; uma requisição REST isolada não fornece áudio ao navegador. Definir expiração/reconexão e validar origem das mensagens entre janelas.

**Aceite:** cliente de referência separado simula um CRM, importa lista, incorpora o discador, inicia atendimento, tabula e recebe eventos. Testar sessão expirada, integração revogada, repetição de requisição e acesso cruzado entre organizações. O projeto de CRM existente permanece intocado.

## 7. Leads sociais e conversas WhatsApp — marco 6B

A preparação atual de WhatsApp registra eventos e exclusões, mas não entrega uma caixa de conversas completa para a equipe. Este marco inclui essa interface, associação ao contato, atribuição ao atendente/fila, histórico, status de mensagem e contexto de campanha/origem.

Escopo inicial proposto: Facebook/Instagram, com adaptadores para outras redes posteriormente. Separar os caminhos:

1. **Anúncio que abre WhatsApp:** criar/atualizar a conversa quando chegar uma mensagem real do usuário. Vincular contato e atribuição disponível, deduplicar o evento e distribuir a conversa. Apenas clicar no anúncio não comprova mensagem recebida.
2. **Formulário de lead:** receber o evento autorizado da plataforma, obter os dados permitidos, deduplicar pelo ID do lead e vincular o contato. Criar tarefa/etapa de abordagem; não fabricar uma mensagem recebida. Sem telefone válido ou autorização adequada, aguardar complementação.
3. **Lead enviado pelo CRM:** usar a API de 6A e as mesmas regras de origem, deduplicação, elegibilidade e encaminhamento.

Guardar rede, IDs de anúncio/campanha/formulário/lead e referência de clique quando fornecidos. A Twilio documenta dados de referência nos [webhooks de mensagens](https://www.twilio.com/docs/messaging/guides/webhook-request) e o campo [ReferralCtwaClid](https://www.twilio.com/en-us/changelog/click-to-whatsapp-ad-click-id-parameter-available-on-event-streams). Campos ausentes permanecem desconhecidos; não inventar atribuição.

Formulário preenchido não abre a janela de atendimento do WhatsApp. Uma mensagem recebida do usuário abre a janela de 24 horas; fora dela, o início/retomada de mensagem exige template aprovado e condições de autorização aplicáveis. Ver [conceitos oficiais do WhatsApp na Twilio](https://www.twilio.com/docs/whatsapp/key-concepts). Respostas e pedidos de interrupção devem cancelar cadências incompatíveis em todos os pontos de entrada.

Dependerá de remetente WhatsApp habilitado, templates quando necessários, acesso autorizado às páginas/contas de anúncios e permissões da plataforma social. Confirmar permissões e versões oficiais na implementação; não pressupor acesso por já existir uma conta Twilio. O uso de número de outra operadora segue as condições de onboarding do WhatsApp, não transforma a Twilio em ponte genérica para qualquer BSP externo.

**Aceite:** validar mensagem originada em anúncio, formulário sem mensagem, evento duplicado, atribuição parcial, ausência de telefone, janela expirada e opt-out. Cada evento gera no máximo uma ação correspondente e preserva a origem; somente mensagem efetivamente recebida aparece como entrada do usuário na conversa.

## 8. Ordem, isolamento e evidências

Iniciar por 4A, depois 4B e 4C. Desenhar contratos de 6A durante essas entregas; desenvolver o cliente de referência sem alterar o CRM. Preparar interface de conversas e adaptadores com eventos controlados enquanto canais/permissões são homologados.

Cada implementação exige migrações compatíveis, testes das regras e registro de evidências no projeto MA. Capacidade medida, orçamento e política de concorrência limitam a automação; não elevar limites com base somente no desenho. Nenhuma alteração de infraestrutura, contratação ou chamada faz parte desta revisão documental.

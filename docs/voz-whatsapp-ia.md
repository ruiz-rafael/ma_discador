# Voz WhatsApp e IA no Zyrex MA

Atualização de 03/10/2026: entregue a [configuração de WhatsApp após X não atendimentos reais, por API Twilio ou QR Code](cadencia-whatsapp-api-qr.md). Esta entrega antecipa parte do marco 4B; filas de discagem reais, inbox completo e integração com o CRM continuam pendentes. A nova opção QR complementa a escolha anterior de API oficial.

O documento **Projeto de SaaS para prospecção por voz e WhatsApp com IA**, versão 1.0 de 30/09/2026, passa a ser a referência de produto. Sua cópia original está em [referencias/Projeto_SaaS_Voz_WhatsApp_IA.pdf](referencias/Projeto_SaaS_Voz_WhatsApp_IA.pdf). SHA-256: `c30278d7fa6551ccddfc08885f9eacf7de162036667bb913ca3ceb5c8443bba7`.

Esta especificação combina o PDF com o MA já implementado e a pesquisa dos discadores. A autorização atual é desenvolver e testar na mesma VM do MA, preservando os demais projetos. As metas comerciais, preços, escalabilidade, prazo de 24 semanas e SLA apresentados no PDF são hipóteses; não representam capacidades entregues nem compromissos deste ambiente.

Atualização de planejamento em 03/10/2026: o [roteiro dos próximos marcos](roteiro-proximos-marcos.md) e os [requisitos de operação e integrações](requisitos-operacao-integracoes.md) detalham tabulação, relatórios, listas, tentativas, origens por DDD, equipe ativa/receptiva, API para CRMs e leads sociais. As entregas históricas abaixo conservam seu escopo; novos requisitos não devem ser interpretados como funcionalidades já disponíveis.

## Decisões adotadas

- Manter Laravel 12, PHP 8.4, Vue 3, PostgreSQL 17 e Redis existentes. A sugestão de React/TypeScript/Python no PDF é uma alternativa de arquitetura, não uma obrigação de reescrita.
- Permitir número único para voz/WhatsApp ou números separados, conforme decisão posterior e configuração `number_mode=single|separate`. A proposta de número único do PDF é uma opção. Rotação de origens por DDD exige números autorizados e compatibilidade com os canais escolhidos; o campo de número não autoriza alterar arbitrariamente o identificador de chamada.
- Começar por clique/preview e progressivo. IA de texto pertence ao MVP futuro; voz conversacional, supervisão avançada e preditivo vêm depois.
- Manter CRM como origem de segmentos, campanhas e contatos quando conectado. Selecionar referências não importa membros automaticamente.
- Reutilizar o motor de jornadas do MA para as cadências reais. A avaliação do laboratório é isolada e não dispara handlers de produção, provedores ou IA.
- WhatsApp oficial substitui a proposta anterior de sessões não oficiais como caminho de lançamento.
- Desenvolver na VM atual com limites pequenos. A leitura de 01/10 encontrou 8 vCPUs, aproximadamente 7,3 GiB de memória disponível e 484–485 GB livres. Isto não comprova capacidade para a escala de 10 chamadas simultâneas do PDF.

A organização de campanhas, agentes, horários e qualificações tem como referência a [3C Plus](https://alo.3cplusnow.com/help/configura%C3%A7%C3%B5es-da-campanha-3c-plus-help-center). A tela com contexto do CRM é inspirada nas capacidades públicas da [Aircall](https://aircall.io/en-au/call-center-software-features/power-dialer/). A coordenação entre voz e canais digitais segue a direção apresentada pela [Genesys](https://www.genesys.com/capabilities/outbound). A pesquisa usou páginas públicas, não acesso autenticado ou aferição de desempenho desses produtos.

Atualização: o [marco 2 de telefonia](marco-2-telefonia.md) acrescenta um teste interno de áudio real, autorizado após a confirmação de que ainda não há operadora. As descrições abaixo documentam a entrega do primeiro marco.

## Primeiro marco implementado

Menu **Voz e cadências** no MA, identificado como laboratório. Inclui configuração e edição de campanhas, contatos próprios de teste, prévia/importação CSV de até 500 linhas/512 KB, normalização de telefone, deduplicação, origem e evidência de autorização, histórico de chamadas simuladas, qualificação, retorno agendado, exclusão e registro de resposta.

Campanhas configuram modo, referências do CRM, número pretendido, público explícito, fuso, dias, horários, máximo de tentativas, intervalo, simultaneidade, roteiro e passo condicional de WhatsApp. Campos de voz não substituem os catálogos do CRM. O template selecionado ainda não tem aprovação comprovada pelo provedor.

A área de atendimento oferece escolha manual e seleção do próximo contato elegível. O teste exige um usuário autenticado e admite uma tentativa aberta por usuário, uma por contato em todo seu workspace e duas simultâneas no laboratório inteiro. A reserva expira após dez minutos; a próxima seleção libera reservas vencidas. Não existe áudio, agente autônomo ou discagem contínua em segundo plano neste marco.

Cada tentativa tem UUID, chave de idempotência, campanha, contato, agente e resultado. O encerramento repetido com o mesmo resultado não cria novo passo. Um resultado diferente é rejeitado. Resultados técnicos são distintos da qualificação comercial. A seleção aplica dias/horários, intervalo global por contato, limite por campanha, resposta e exclusões.

O passo de WhatsApp aparece somente após o limiar configurado de não atendimentos em 24 horas; ocupado, rejeitada e caixa postal não geram esse passo automaticamente. Existe no máximo um passo por contato/campanha em 24 horas. Sem autorização/evidência, ele fica impedido. Resposta e exclusão cancelam os passos pendentes entre campanhas. O botão **Simular vencimento** reavalia o passo usando as condições atuais do contato e a janela prevista, preservando um snapshot das configurações. O resultado é `simulated`, nunca `sent` ou `delivered`. Uma alteração de campanha cancela passos pendentes da configuração anterior.

O laboratório tem tabelas próprias e vinculação de usuário a workspace. Os administradores existentes são vinculados ao workspace inicial; novos usuários não recebem acesso automaticamente. Não existe cadastro público de empresas. Somente o workspace inicial consulta o catálogo legado do MA. **O restante do MA ainda não tem isolamento completo entre empresas nem papéis de acesso**; não abrir acesso a empresas externas com base apenas no isolamento deste módulo.

## Contrato interno do laboratório

Todas as rotas usam sessão, CSRF e autenticação do MA, com limite de requisições. Não são o contrato público de provedores do PDF.

| Método e rota | Finalidade |
| --- | --- |
| GET `/api/voice` | Campanhas, contatos, últimos resultados e pendências do workspace |
| GET `/api/voice/reference` | Download autenticado do PDF |
| POST `/api/voice/contacts` | Cadastro e deduplicação sem sobrescrever consentimento existente |
| POST `/api/voice/imports` | CSV, mapeamento de colunas e `commit=0` para prévia ou `1` para importar |
| POST `/api/voice/contacts/{id}/stop` | `type=reply` ou `opt_out`; interrompe a cadência do contato |
| POST `/api/voice/campaigns` | Criar rascunho com público explícito |
| PUT `/api/voice/campaigns/{id}` | Editar rascunho/pausada com revisão otimista |
| POST `/api/voice/campaigns/{id}/status` | Simular, pausar, concluir ou cancelar |
| POST `/api/voice/campaigns/{id}/activate` | Rejeita operação real e retorna as pendências |
| POST `/api/voice/calls` | Abrir tentativa simulada com chave de idempotência |
| POST `/api/voice/calls/{id}/finish` | Registrar resultado e qualificação |
| POST `/api/voice/actions/{id}/simulate` | Avaliar passo pendente sem envio externo |

A autorização de contato no laboratório registra somente o escopo simplificado de comunicações da empresa. Não substitui a modelagem completa de finalidades, escopos por canal, revogação e política de retenção exigida pelo PDF. A importação registra erros (até 20 na resposta), duplicados e uma prévia de dez contatos. O envio confirmado importa somente linhas válidas; não atualiza contatos existentes. Importação massiva em chunks, XLSX e exportação de relatório são etapas posteriores.

## Critérios do PDF e próximas entregas

| Requisito | Estado neste marco | Próxima evidência exigida |
| --- | --- | --- |
| RF01 Contas e permissões | Parcial, somente laboratório com escopo por workspace | Isolamento e papéis no MA inteiro, busca, mídia, filas e exportações |
| RF02 CSV | Amostra de até 500 linhas com prévia e deduplicação | Importação assíncrona em volume, relatório completo e recuperação |
| RF03 Origem e autorização | Registro básico e revalidação local | Finalidades e escopos por canal, evidências restritas e retenção |
| RF04 Número unificado | Pendente | Número móvel legítimo em voz, retorno e WhatsApp; teste em três redes |
| RF05 Discador | Preview/progressivo simulados | SIP/API, WebRTC, áudio, estados dos agentes, callbacks e limites reais |
| RF06 Cadência | Simulação condicional | Execução pela jornada com reservas, outbox e revalidação no envio |
| RF07 Templates | Referências preparadas | Aprovação, parâmetros, envio, entrega e falha comprovados pelo provedor |
| RF08 Atendimento | Registro de resposta e interrupção | Caixa de entrada, filas, responsável e transferência |
| RF09 IA de texto | Pendente | Conhecimento publicado, ferramentas restritas e transferência humana |
| RF10 Histórico | Eventos e tentativas simuladas | Correlacionar mensagens, gravações e oportunidades reais |
| RF11 Agenda e CRM | IDs e referências preparadas | Escolher conectores e comprovar sincronização idempotente |
| RF12 Exclusões | Bloqueio e cancelamento no laboratório | Escopos e cancelamento concorrente em todos os canais reais |
| RF13 Consumo | Não há consumo real nem cobrança | Reserva de orçamento, ledger, tarifas e conciliação por fornecedor |
| RF14 Indicadores | Contagens de teste | Denominadores de negócio, atribuição e exportação por período |
| RF15 Resiliência | Idempotência/reserva local testáveis | Inbox/outbox, eventos fora de ordem, timeout incerto e recuperação |
| RF16 Editor visual | MA existente | Nós de voz conectados ao executor real e ao CRM |
| RF17 Áudio e análise | Pendente | Áudio elegível e resumo revisável |
| RF18 IA de voz | Posterior ao MVP | Latência, interrupção, transferência e qualidade homologadas |

A integração externa depende da operadora/tronco e da comprovação do número. O primeiro marco não usava telefonia; o marco 2 agora possui Asterisk isolado na mesma VM para o teste interno de áudio, com os limites e portas documentados na sua especificação. Asterisk via ARI precisa de eventos, reconciliação e pontes de mídia, além de originate; Reverb transporta eventos da interface e não o áudio WebRTC.

Antes de um piloto externo, comprovar todos os P0 do PDF. Nenhum indicador comercial, preço, SLA ou capacidade de IA é apresentado como funcionalidade pronta nesta entrega.

## Validação da entrega em 01 de outubro de 2026

- Build Vite aprovado. Última suíte SQLite: 39 testes e 327 assertions. A suíte completa também passou em PostgreSQL isolado, com 39 testes e 325 assertions antes da adição da verificação do identificador do atendente.
- Corridas reais com três processos em PostgreSQL: uma única reserva para o mesmo contato e, com contatos diferentes, somente duas chamadas abertas. A base temporária exclusiva foi removida após os testes.
- Chromium autenticado no domínio público: CSV com prévia, deduplicação e erros; seleção de referências e configuração persistida; progressivo simulado; resultado e passo condicional; exclusão; ativação real bloqueada; recarga de tela e PDF com SHA-256 conferido. Sem erros JavaScript no fluxo final.
- Screenshots de desktop e viewport móvel preservadas em `evidence/voice-foundation-20261001/`. O teste automatizado de navegador está em `ops/browser_voice_lab.py`.
- Comparação dos 239 containers de outros projetos: mesmos IDs e horários de início. Os seis serviços do MA permanecem em execução, com HTTPS 200.
- Backup do banco e dos arquivos anteriores realizado antes da migração. Migração restrita às tabelas do laboratório e vínculo opcional de workspace nos usuários. Nenhuma porta, serviço de telefonia ou configuração global de Nginx foi alterada.

Para desfazer a interface, restaurar somente os arquivos do MA guardados em `backups/voice-code-before-*.tar.gz`, incluindo o manifest anterior, mantendo seus assets. As tabelas novas podem permanecer sem uso. Não reverter o banco inteiro sobre novos dados; a remoção do laboratório requer exportação e avaliação própria.

## Atualização: números e WhatsApp via Twilio

Cada campanha agora permite número único ou números separados. A aba WhatsApp prepara remetentes de outra operadora ou Twilio, templates de texto e testes manuais pela API Twilio. O cadastro/credenciais, a aprovação e a homologação reais dependem da conta do fornecedor. Cadências continuam simuladas; CRM preservado. Consulte [documentação WhatsApp](whatsapp-twilio.md).

## Estúdio de voz próprio — 07/10/2026

Disponível em **Cadências → Canais → Voz e áudios**, para administradores e supervisores. O processamento usa Kokoro-82M v1.0 em um serviço privado do MA, com ONNX em CPU. Não depende de credenciais ou geração de fala da Twilio. O modelo usa Apache 2.0 e o wrapper `kokoro-onnx` usa MIT; há custo de infraestrutura, mesmo sem licença por minuto/caractere. Licenças e identificação dos artefatos estão em `speech/`.

O editor permite salvar templates versionados, escolher Dora, Alex ou Santa em português brasileiro, ajustar a velocidade e preencher variáveis como `{primeiro_nome}` e `{empresa}`. Variáveis são substituídas uma vez, sem execução de expressões. Gerar uma prévia não insere contatos nem inicia chamadas, filas ou mensagens. O arquivo gerado mantém o texto daquela geração; editar o template não altera áudios anteriores.

Os formatos são MP3 mono/24 kHz para prévia, WAV PCM16 mono/8 kHz para telefonia e OGG/Opus mono/48 kHz para WhatsApp. Os áudios expiram em 30 dias; a rotina horária remove arquivos e texto personalizado da biblioteca, preservando os templates. Há limite inicial de 100 gerações por workspace/dia, um processamento simultâneo, até 1.200 caracteres no template, 1.500 após personalização e 120 segundos de áudio. Áudios idênticos ainda disponíveis são reutilizados. Esses limites protegem a VM de testes e não significam capacidade de geração em tempo real para muitas chamadas.

### Uso em WhatsApp

Depois de ouvir a prévia, abra **Enviar este áudio pelo WhatsApp**, escolha a conversa e clique explicitamente em **Enviar áudio agora**. A conversa deve estar aberta, atribuída ao usuário e ter recebido mensagem nas últimas 24 horas. Mantêm-se os bloqueios por descadastro, limite diário, pausa da supervisão e lista de homologação do canal.

O envio usa o número da conversa: QR envia OGG/Opus como mensagem de voz; Twilio usa mídia com URL assinada e temporária. O histórico de Conversas exibe o áudio, seu texto e o status retornado pelo canal. Uma repetição da mesma operação não cria outro envio; resultado desconhecido exige conferência, sem repetição automática. O template de voz da biblioteca não é um template WhatsApp aprovado pela Meta e não abre uma janela de atendimento.

A implementação de transporte foi validada com provedores simulados. A aparência do áudio em celulares via QR/Twilio e a entrega externa ainda requerem homologação autorizada; esta entrega não enviou mensagens reais.

### Telefonia e caixa postal: escopo

O WAV permite reutilizar a síntese com Asterisk/SIP; a entrega assinada também serve como base para reprodução por provedor. Esta entrega **não altera o fluxo das chamadas nem ativa recados de caixa postal automaticamente**. A ligação do template à cadência, a reprodução após detecção e a distinção entre humano, caixa postal e resultado inconclusivo continuam sendo a próxima etapa de telefonia.

Kokoro faz síntese de voz; não identifica quem atendeu. AMD é um mecanismo separado (Twilio ou Asterisk), sujeito a erro e latência. Os resultados históricos de rede não devem ser reinterpretados como confirmação de atendimento humano. Antes de ativar o recurso, deve-se ajustar o encerramento/reentrada da participação, cancelamento de WhatsApp, contagem de tentativas e relatórios para usar a classificação confirmada. Uma classificação inconclusiva precisa permanecer explícita. Não habilitar este comportamento nas campanhas existentes sem configuração própria.

### Instalação e isolamento

O arquivo `deploy/compose.speech.yaml` complementa o compose do MA. O serviço não publica portas externas e roda com usuário 82, raiz somente leitura, até 1 CPU e 1,5 GiB de RAM. O diretório temporário precisa permitir mapeamento executável porque o phonemizer carrega uma cópia da biblioteca eSpeak; nenhum código enviado pelo usuário é executado.

Preparar, exclusivamente no projeto MA:

- `speech-runtime/models/kokoro-v1.0.onnx` e `voices-v1.0.bin`, do release `model-files-v1.1` de `thewh1teagle/kokoro-onnx`; conferir SHA-256 em `speech/NOTICE.txt`.
- `speech-runtime/service.env`, modo 600, com `SPEECH_TOKEN` aleatório de pelo menos 32 caracteres.
- `source/storage/app/private/voice/speech.json`, modo 600, usuário 82, com `url` igual a `http://speech:8091` e o mesmo `token`.
- `source/storage/app/private/speech`, usuário 82, modo 700, compartilhado somente pelo app e o serviço de voz.
- Executar a migração `2026_10_07_000001_add_speech_studio.php` e compilar os assets. Subir apenas `speech` com o compose complementar; não recriar serviços dos demais projetos.

O conector QR com suporte a áudio mantém seu volume de sessão e usa a imagem `zyrex-ma-whatsapp-qr:audio-20261007`. A atualização não altera números, filas, campanhas ou disponibilidade dos agentes.

Validação: 400 testes e 3.058 asserções em SQLite e PostgreSQL, 11 testes JavaScript da aplicação e 11 do conector QR. Após a revisão final, os 19 testes de estúdio/conversas passaram novamente, com 132 asserções. Navegador desktop e celular validaram template, personalização, geração, player, download e envio simulado com a mesma chave de operação. As três vozes geraram arquivos reais dentro do serviço local; codecs, frequências e canais foram conferidos. A prévia final no navegador reproduz 6,73 segundos com dados fictícios. Não houve ligação ou mensagem externa de homologação.

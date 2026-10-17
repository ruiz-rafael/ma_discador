# Agentes de IA DiscAI

O administrador de cada empresa acessa **Agentes de IA** no menu. Recepção, SDR, atendimento e atendimento adaptativo são pontos de partida editáveis, não agentes já treinados com informações comerciais.

## Preparar e publicar

1. Defina identidade, saudação e objetivo. O agente se apresenta como assistente virtual.
2. Cadastre perguntas e respostas aprovadas (até 30, total de 50 KB).
3. Defina perguntas de qualificação, limites e fila humana.
4. Escolha português brasileiro, inglês americano ou espanhol latino-americano; voz, velocidade, espera e interrupções.
5. Salve, teste e publique. Rascunhos não alteram versões publicadas. Sessões em andamento usam uma cópia da configuração com que começaram.
6. Habilite cada canal explicitamente, após homologação. Publicar não liga nem envia mensagens.

Não há ingestão de sites/documentos, agenda externa ou ferramentas arbitrárias nesta entrega. A base é editada diretamente no estúdio. O agente pode coletar preferências, mas não afirma que reservou um horário ou executou uma ação externa.

## Atendimento adaptativo

Escolha o modelo **Atendimento adaptativo** ou ative **Conversa e limites → Adaptar ao ritmo e ao contexto** em um agente existente. A opção começa desligada nos outros modelos e não modifica agentes já publicados. Salve, teste e publique uma nova versão; conversas em andamento mantêm a configuração anterior.

Na telefonia, o modelo recebe instruções para considerar ritmo, pausas, hesitação e entonação junto ao conteúdo, adaptar a extensão das respostas e manter sua própria voz. No WhatsApp, usa somente a conversa escrita. Emoções são hipóteses sujeitas a erro, nunca diagnósticos ou certeza. O relato e a correção do cliente prevalecem; não há classificador validado, pontuação nem perfil emocional persistido.

Orientações incluem pressa, confusão, reclamações, objeções, conflito, pedidos de humano e de interrupção. A adaptação não permite inventar soluções, pressionar uma pessoa vulnerável, alterar preços ou ignorar consentimento. Transferências e encerramentos continuam usando os mecanismos e limites de cada canal; não se promete uma ação que a ferramenta não confirmou.

**Testar agente → Roteiros para testar** oferece seis situações em português brasileiro, inglês americano e espanhol latino-americano. Escolher um roteiro apenas prepara a mensagem ou mostra uma frase para falar; não envia nada nem inicia cobrança. A simulação gratuita testa regras locais, não interpretação emocional. Texto generativo e voz exigem conexão e autorização de custos.

Para homologar a conversa, repita a mesma frase com ritmos e entonações diferentes, inclua ruído e pausas, corrija uma interpretação e peça atendimento humano. Avalie respeito à correção, clareza, ausência de suposições, fidelidade à base e encaminhamento. Nenhum teste automatizado com resposta substituta comprova naturalidade, precisão emocional ou desempenho em áudio real. A avaliação auditiva segue pendente até realizar testes autorizados de voz.

Referências: [conversas Realtime](https://developers.openai.com/api/docs/guides/realtime-conversations), [orientações para voz](https://developers.openai.com/api/docs/guides/voice-prompting).

## Custos antes de ativar

**Conexão e custos** mostra modelos, tarifas e autorização de consumo. Chave de API por empresa, criptografada no servidor; nunca retornada ao navegador. O limite diário conta respostas de texto e sessões de voz, não representa um teto em dinheiro. Configure também limites financeiros no provedor.

Referência consultada em 16/10/2026, USD por milhão de tokens:

| Modelo | Entrada | Saída |
|---|---:|---:|
| GPT-4.1 mini, texto | 0,40 | 1,60 |
| GPT Realtime 2.1, áudio | 32 | 64 |
| GPT Realtime 2.1, texto dentro da sessão | 4 | 24 |

[Preço oficial](https://developers.openai.com/api/docs/pricing). Telefonia, WhatsApp, câmbio e impostos são separados. Texto tem estimativa a partir do uso retornado; custo real da voz deve ser consultado no provedor. Não apresentamos tarifa fixa por minuto.

Kokoro e Chatterbox continuam disponíveis no Estúdio de voz para áudio gerado. Não são usados como motor da conversa em tempo real.

## Testar sem chamada

- **Simulação gratuita:** regras locais e correspondência exata com a base. Não é um modelo generativo.
- **Texto com IA:** consulta a API após autorização de custo.
- **Voz ao vivo:** microfone do navegador via WebRTC. Não disca para um telefone. Permite interromper, ouvir e comparar pronúncia; fecha o microfone ao encerrar.

O teste de voz não grava áudio. Sua transcrição temporária fica no navegador. Chave, saldo/acesso ao modelo e permissão de microfone são necessários. Testes internos com substitutos do provedor não comprovam naturalidade, latência real ou qualidade da rota telefônica.

## WhatsApp

Após um teste premium de texto da mesma configuração publicada, vincule um remetente e autorize respostas automáticas. Apenas novas mensagens recebidas depois da ativação entram na IA. Não reproduzimos o histórico anterior. A resposta automática respeita janela de 24 horas, restrições de destinatários, limites do remetente e pausa da operação.

A equipe pode assumir a conversa. Pedido de humano, falta de conhecimento, falha ou limite encerram o fluxo automático e deixam a conversa na fila humana. Na passagem humana, o sistema registra o motivo; nesta etapa não envia uma promessa automática de transferência. Descadastro de um contato identificado bloqueia novos contatos e cancela ações pendentes. Eventos repetidos e respostas em processamento não devem duplicar envios; confirmação de envio incerta exige revisão.

## Telefonia SIP

A rota de entrada é independente da Twilio. Operadora ou Asterisk encaminha SIP/TLS para o projeto Realtime e adiciona o segredo `X-DiscAI-Route`. Configure o webhook assinado `realtime.call.incoming` no projeto OpenAI. No estúdio, uma seção técnica permite preparar a fila, o destino fixo de transferência humana e a ativação da rota.

Um serviço interno por tenant acompanha eventos por WebSocket, envia saudação, aplica encerramento e solicita transferência somente ao destino previamente configurado quando há humano disponível na fila. A confirmação do REFER significa solicitação aceita, não prova que um humano atendeu. O controle suporta até 20 sessões SIP simultâneas por ambiente e respeita o limite de telefonia, se menor. A capacidade permanece reservada até a reconciliação da sessão antiga. O destino no PABX precisa encaminhar à mesma fila escolhida.

Assinatura do provedor, segredo da rota, capacidade, horário e pausa são verificados antes de aceitar. A API interna do serviço usa HMAC, janela temporal e proteção contra repetição. Chamadas sem serviço disponível são recusadas. Credenciais não podem ser trocadas durante chamadas; desabilitar consumo ainda permite encerrar sessões existentes.

**Limites desta etapa:** entrada SIP e laboratório WebRTC preparados; campanhas de saída não passam automaticamente para atendimento de IA. Não há gravação nem transcrição do cliente na rota SIP; o histórico contém as falas do agente. O identificador do chamador não é usado sozinho para alterar o cadastro de um contato. A homologação da operadora e de transferência real continua necessária.

## Operação e implantação

- Migrar `2026_10_16_000001_add_ai_agents.php` antes de atualizar serviços que consultam as tabelas.
- Manter worker Laravel e scheduler ativos. A rotina reconcilia sessões expiradas, trabalhos interrompidos e remove conteúdo de sessões com mais de 30 dias.
- Construir `ai-voice/Dockerfile` e instalar o serviço privado em cada tenant. Ele usa `DISCAI_ORIGIN=http://web` e chave somente leitura em `/run/secrets/ai-worker.key`; a mesma chave fica em `storage/app/private/ai-worker.key` do tenant.
- Serviço sem porta pública, usuário 82, sistema de arquivos somente leitura, limites de recursos e rede interna do próprio tenant. Credenciais do provedor permanecem exclusivas de cada empresa.
- Reimplantar arquivos e assets, reiniciar graciosamente workers Laravel. Não habilitar canais ou consumo como efeito da implantação.

## Validação reproduzível

Testes PHP em SQLite e PostgreSQL, `tests/Feature/AiAgentsTest.php` e `AiSipTest.php`; integração HTTP real contra simulação local Postman em `tests/Transport/AiTransportTest.php`; teste do worker em `ai-voice/test.mjs`; fluxo de navegador em ambiente isolado. Contrato e respostas substitutas em `source/postman`. Nenhuma chamada real nem consulta premium é necessária para essa suíte.

Referências: [Fonio — configuração](https://fonio.info/en/get-started/first-steps), [ElevenLabs — agentes](https://elevenlabs.io/docs/eleven-agents/overview), [OpenAI — WebRTC](https://developers.openai.com/api/docs/guides/voice-webrtc), [OpenAI — SIP](https://developers.openai.com/api/docs/guides/voice-sip?voice-api=realtime).

## Supervisão de IA — 17/10/2026

Administradores e supervisores acessam **Supervisão ao vivo → Conversas de IA**. O estúdio também oferece **Acompanhar conversas**. Supervisores consultam os dados do seu tenant; criação, publicação, custos e ativação continuam exclusivos do administrador.

- **Atendimento real:** sessões de WhatsApp e telefonia SIP. **Laboratório:** simulações gratuitas, testes de texto e de voz. O filtro inicial mostra conversas iniciadas nos últimos sete dias, no horário de Brasília. Resultados e consumo dessas conversas são acumulados até a coleta, não apenas dentro do período filtrado.
- Filtre por período (até 31 dias), agente, canal e resultado. Cards, barras por dia e por canal abrem os recortes correspondentes. A atualização automática é opcional, a cada 15 segundos, apenas com a página visível e sem detalhes abertos.
- Conversas com falha e passagem humana podem aparecer nos dois indicadores; os totais não devem ser somados. Sessão encerrada não comprova resolução. Aceite de transferência SIP significa solicitação aceita pelo provedor, não humano conectado. O evento permanece consultável após o encerramento da sessão.
- Sessões com prazo excedido aparecem separadas das ativas. Ler o painel não altera o estado nem tenta chamadas ao provedor.
- A estimativa financeira inclui apenas tokens de texto de sessões premium. Voz permanece **Consultar provedor**, nunca zero presumido. O tempo médio é o processamento de respostas de texto concluídas, incluindo regras locais dentro dessas sessões; não representa latência de áudio.
- Detalhes incluem resumo registrado, configuração capturada (nome, idioma, adaptação e revisão), falas paginadas e últimos eventos de processamento/transferência. Não expõem chave, instruções completas da empresa, identificadores do provedor nem hashes internos. Histórico tem retenção de 30 dias; períodos antigos podem estar incompletos.

Contrato de consulta: `source/postman/ai-supervision.openapi.json`. Testes de isolamento, papéis, paginação, fuso horário, sobreposição de resultados e custos desconhecidos: `tests/Feature/AiSupervisionTest.php`. Esta entrega não mede qualidade auditiva nem classifica emoções.

Próximas etapas dependentes de configuração: cadastrar uma chave premium e apresentar custos para autorizar o laboratório; avaliar conversas em PT-BR, EN-US e ES-MX com uma pessoa; homologar a rota SIP e a passagem humana antes da ativação por canal. Campanhas de saída com IA e agenda externa permanecem etapas posteriores.

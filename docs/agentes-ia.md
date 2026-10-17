# Agentes de IA DiscAI

O administrador de cada empresa acessa **Agentes de IA** no menu. Recepção, SDR e atendimento são pontos de partida editáveis, não agentes já treinados com informações comerciais.

## Preparar e publicar

1. Defina identidade, saudação e objetivo. O agente se apresenta como assistente virtual.
2. Cadastre perguntas e respostas aprovadas (até 30, total de 50 KB).
3. Defina perguntas de qualificação, limites e fila humana.
4. Escolha português brasileiro, inglês americano ou espanhol latino-americano; voz, velocidade, espera e interrupções.
5. Salve, teste e publique. Rascunhos não alteram versões publicadas. Sessões em andamento usam uma cópia da configuração com que começaram.
6. Habilite cada canal explicitamente, após homologação. Publicar não liga nem envia mensagens.

Não há ingestão de sites/documentos, agenda externa ou ferramentas arbitrárias nesta entrega. A base é editada diretamente no estúdio. O agente pode coletar preferências, mas não afirma que reservou um horário ou executou uma ação externa.

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

# Marco 2 — telefonia e áudio

O marco está **parcialmente implementado**. Como ainda não existe operadora contratada, esta entrega disponibiliza uma chamada interna de áudio entre o navegador e o Asterisk. A chamada reproduz um sinal inicial e devolve o áudio do microfone. Não liga para celulares ou telefones, não usa número empresarial e não grava áudio. Campanhas, resultados comerciais e cadências continuam no laboratório simulado.

## Como testar

1. Entre em https://ma.zyrex.ia.br com seu acesso ao MA.
2. Abra **Voz e cadências → Teste de áudio real**.
3. Use fones de ouvido, clique em **Iniciar teste de áudio** e permita o microfone.
4. Após o sinal inicial, fale e confira o retorno da sua voz. Os contadores mostram o envio e recebimento de pacotes.
5. Clique em **Encerrar teste** ou aguarde o limite de 60 segundos. Confira o estado confirmado pelo servidor no histórico.

São permitidos dois testes simultâneos no ambiente, um por usuário. Cada reserva tem autorização de uso único com prazo de 60 segundos para começar e expiração de segurança em 150 segundos. A expiração aparece como “Sem confirmação final”; não é convertida em resultado de chamada atendida. Redes que bloqueiam UDP podem impedir áudio mesmo com a conexão do navegador estabelecida. TURN ainda não está configurado.

## Infraestrutura exclusiva

- Asterisk 22.9.0 em Alpine 3.24.1; imagem `zyrex-ma-pbx:22.9.0-1`, container `zyrex-ma-pbx-1`.
- Limites: 384 MB, 0,35 CPU, 96 processos, duas chamadas e 60 segundos por chamada.
- Compose complementar: `deploy/compose.voice.yaml`, instalado em `/srv/zyrex-ma/compose.voice.yaml`.
- Rede existente exclusiva do MA: `zyrex-ma_default`; PBX em `10.241.50.10`.
- Sinalização HTTPS/WSS pela rota autenticada `/voice/ws` no virtualhost do MA. Porta HTTP do PBX somente em `127.0.0.1:18488`.
- Mídia DTLS-SRTP: UDP `20000–20019`. As portas foram conferidas livres antes da publicação. Não há SIP público em 5060, tronco, rota `Dial()`, ARI habilitado ou AMI.
- Runtime privado em `/srv/zyrex-ma/voice-runtime/asterisk/` e `source/storage/app/voice-runtime.json`. O provisionador gera segredos e preserva os existentes ao executar novamente. Não versionar nem compartilhar esses arquivos.

O navegador recebe uma credencial SIP restrita ao endpoint de eco, somente após autenticação e reserva. Ela não é uma credencial de operadora. Cada destino contém uma autorização opaca; o PBX valida o formato e consulta o MA antes de atender. Uma futura rota externa exige outro desenho de autorização, credenciais e controle de consumo; não adicionar saída PSTN a esse endpoint compartilhado.

O PBX informa início, atendimento e término via AGI/HMAC pelo endereço interno do MA. O callback público retorna 404. A assinatura inclui timestamp e corpo; eventos verificam canal, estado e validade. O navegador não pode declarar que o PBX encerrou uma chamada. As métricas RTP enviadas pelo navegador são diagnósticos informados pelo cliente e ficam separadas dos eventos do servidor.

## Contrato interno

| Rota | Finalidade |
| --- | --- |
| GET `/api/voice/audio` | Disponibilidade e últimos 30 testes do próprio usuário/workspace |
| GET `/api/voice/audio/authorize` | Autorização da conexão WSS pelo Nginx |
| POST `/api/voice/audio/sessions` | Reserva idempotente e dados temporários da chamada |
| POST `/api/voice/audio/sessions/{id}/abandon` | Cancela somente uma reserva ainda não usada |
| POST `/api/voice/audio/sessions/{id}/metrics` | Registra diagnóstico declarado pelo navegador |
| POST `/internal/voice/audio/event` | Eventos assinados do PBX; bloqueado no virtualhost público |

As rotas do navegador usam sessão, CSRF e escopo por usuário/workspace. A criação tem limite de seis solicitações por minuto. A tabela `voice_audio_sessions` é própria: não produz tentativas de campanhas, mensagens enviadas ou métricas comerciais.

## Próximos passos para concluir o marco

1. Contratar operadora/tronco SIP ou API e confirmar a capacidade de usar o número pretendido para saída e retorno. A compatibilidade do mesmo número com WhatsApp oficial precisa ser comprovada pelo fornecedor.
2. Configurar credenciais em área privada, destinos de teste autorizados, tarifas, orçamento, limites e reconciliação.
3. Homologar o clique para ligar e os eventos de ocupado, não atendimento, atendimento e término, cuja implementação de saída está preparada em [chamadas-twilio.md](chamadas-twilio.md).
4. Homologar identificação do número, áudio bidirecional e retorno em redes distintas; testar interrupções e eventos repetidos/fora de ordem.
5. Validar TURN se as redes dos atendentes precisarem de alternativa ao UDP direto.

A contratação da operadora não liga automaticamente o discador de campanhas: essa integração e os testes acima ainda precisam ser executados. WhatsApp oficial, caixa de entrada e IA seguem como entregas posteriores.

## Validação em 01/10/2026

- Suíte final SQLite: 46 testes e 395 assertions. PostgreSQL isolado: 45 testes e 378 assertions, antes da inclusão do teste de separação do limite de criação e diagnóstico.
- Build Vite concluído. Chromium autenticado no domínio público transmitiu e recebeu áudio real com microfone sintético; o teste analisa também a frequência recebida para distinguir o eco do sinal inicial do PBX.
- Corte automático comprovado: 60 segundos entre início e término no PBX, 59 segundos de áudio atendido; 2.946 pacotes enviados e 2.838 recebidos. Eco do tom de 660 Hz detectado no navegador em 656,25 Hz (resolução da análise).
- Encerramento manual, histórico confirmado pelo PBX, ausência de novas tentativas de campanha e controles de acesso conferidos. WebSocket sem login: 401; origem incorreta: 403; callback pela Internet: 404.
- Os seis containers anteriores do MA mantiveram IDs e horários de início. Os 239 containers dos outros projetos mantiveram IDs; um worker de WhatsApp do CRM realizou sua reciclagem horária preexistente (`--max-time=3600`), permaneceu em execução e não apresentou OOM. Nenhum comando desta implantação reiniciou esse worker.
- Nenhum arquivo de configuração Nginx dos outros sites foi alterado. O master do Nginx manteve o PID durante a recarga graciosa do virtualhost do MA.
- PBX saudável; snapshot sem chamadas: aproximadamente 10 MiB de memória. Esse dado não dimensiona operação em escala.

Evidências: `evidence/voice-audio-20261001/`. Automação de navegador: `ops/browser_voice_audio.py`. Inventário comparativo: `ops/verify_voice_audio_vm.py`. Testes internos permanecem no histórico, identificados separadamente das campanhas.

## Operação e reversão

Para consultar somente o PBX, executar em `/srv/zyrex-ma`:

```sh
docker compose -f compose.yaml -f compose.voice.yaml ps pbx
docker compose -f compose.yaml -f compose.voice.yaml exec -T pbx asterisk -rx 'core show channels count'
```

Para desativar, definir `enabled=false` no runtime privado do MA e parar **somente** o serviço `pbx` com os mesmos dois arquivos Compose. Não executar `compose down` no projeto. As tabelas e o histórico podem permanecer. Backups anteriores da aplicação, banco e virtualhost ficam em `backups/voice-audio-*-before-*`; não restaurar todo o banco sobre dados novos. A retirada da rota WSS exige editar apenas o virtualhost do MA, validar com `nginx -t` e recarregar graciosamente.

Referências de implementação: [Asterisk WebRTC](https://docs.asterisk.org/Configuration/WebRTC/Configuring-Asterisk-for-WebRTC-Clients/), [SIP.js User Agent](https://sipjs.com/guides/user-agent/), [configuração RTP do Asterisk 22](https://github.com/asterisk/asterisk/blob/22/configs/samples/rtp.conf.sample).

## Provedor escolhido

Foi escolhida a Twilio. A preparação, o cadastro privado e os requisitos específicos estão em [twilio.md](twilio.md). Escolher o provedor ainda não conecta o tronco nem conclui este marco.

## Atualização: números e WhatsApp via Twilio

Cada campanha agora permite número único ou números separados. A aba WhatsApp prepara remetentes de outra operadora ou Twilio, templates de texto e testes manuais pela API Twilio. O cadastro/credenciais, a aprovação e a homologação reais dependem da conta do fornecedor. Cadências continuam simuladas; CRM preservado. Consulte [documentação WhatsApp](whatsapp-twilio.md).

## Atualização: chamadas manuais de saída

A aba **Voz e cadências → Chamadas** e a rota autorizada de saída estão preparadas para homologação. Incluem contato autorizado, limites, histórico e eventos do PBX. Faltam credenciais reais, instalação do bundle no PBX e homologação com a operadora. Recebimento e cadências automáticas permanecem pendentes. Consulte [chamadas Twilio](chamadas-twilio.md).

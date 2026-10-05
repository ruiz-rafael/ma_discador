# Chamadas de saída no MA — preparação e homologação

> Estado em 03/10/2026: credenciais reais e rota instaladas; autenticação SIP
> confirmada. O destino retornou SIP 480 e a entrega do áudio ainda não foi
> homologada. Veja os resultados atuais (registro operacional privado).

> Atualização: o MA também oferece Programmable Voice via API. Consulte [os dois métodos e sua configuração](voz-dois-metodos.md). Este documento detalha o método Asterisk/SIP.

A aba **Voz e cadências → Chamadas** prepara a primeira ligação manual do navegador para um contato, usando Asterisk 22.9 e Twilio Elastic SIP Trunking. É uma implementação de saída para homologação; não habilita discagem automática de campanhas nem recebimento de chamadas de retorno. O CRM permanece fora desta implantação.

## O que está preparado

- Tela com estado das credenciais, política, instalação no PBX e disponibilidade para teste; seleção de contato, campanha opcional, evidência de autorização, iniciar/encerrar, diagnóstico de pacotes e histórico por usuário.
- Endpoint WebRTC próprio `ma-calling`, diferente do endpoint `ma-audio` de eco. Ambos usam a rota WSS autenticada existente, mas têm contextos e credenciais diferentes.
- Reserva idempotente e autorização opaca, de uso único por canal Asterisk, válida por 45 segundos. O endereço SIP enviado ao navegador contém o token, nunca o número de destino. O servidor escolhe o telefone a partir do contato e o revalida no início da chamada.
- Validação de consentimento, exclusão, resposta anterior e lista privada de destinos. Se houver campanha vinculada, o contato deve ser membro e seu número de voz deve corresponder ao caller ID do tronco. O número de WhatsApp pode ser igual ou separado, conforme a configuração da campanha.
- Limites de homologação: uma chamada externa por vez, até uma nova tentativa por segundo, 1–50 tentativas/dia UTC, 30–180 segundos de conversa e 10–45 segundos de toque. Padrões: 20 tentativas, 120 segundos de conversa, 30 de toque. Reservas canceladas também contam no limite diário.
- A chamada externa usa duas pernas Asterisk (navegador e operadora). Na VM de teste, não se mistura com o eco interno. Não é uma promessa de capacidade de produção; os limites planejados de CPS/simultaneidade no cadastro do tronco continuam sendo planejamento.
- Eventos HMAC do PBX: início autorizado, atendimento e encerramento. Histórico distingue atendida/encerrada, ocupado, não atendeu, falha, cancelada e sem confirmação. O navegador não declara atendimento ou encerramento no banco.
- Rota com validação de destino, TLS para o tronco, SRTP/SDES, DTLS-SRTP para o navegador, bloqueio de transferência e de encaminhamento SIP no Dial. Sem gravação de áudio, AMI, ARI ou novas portas públicas.

## O que falta para a primeira chamada externa

São necessários os dados reais do tronco Twilio, uma origem autorizada para a rota, um contato de teste com autorização, confirmação das tarifas/permissões geográficas e instalação da rota preparada no PBX. Esses requisitos não são atendidos apenas por cadastrar um número no WhatsApp.

A Twilio não usa SIP REGISTER neste produto. “Arquivos instalados” não significa “operadora homologada”. Somente a chamada real poderá confirmar autenticação, identificação de origem, áudio nos dois sentidos e resultado do provedor. Não foi feita ligação externa nesta entrega.

## Configuração privada, depois de receber os dados

Em terminal privado da VM, com acesso administrativo:

```sh
cd /srv/zyrex-ma
docker compose exec --user 82:82 app php artisan voice:twilio:configure
docker compose exec --user 82:82 app php artisan voice:calling:configure
```

O primeiro comando já existente cadastra Account/Trunk SID, Termination URI, Credential List SIP, caller ID e capacidade planejada. O segundo cadastra destinos autorizados e limites, exige confirmação da elegibilidade da origem e gera credenciais próprias do navegador/assinatura dos eventos. Não colocar senhas em argumentos de shell ou no chat.

A política fica cifrada com APP_KEY em `source/storage/app/private/voice/calling/policy.enc`. Os arquivos de implantação contêm segredos necessários ao PBX e ficam no mesmo diretório privado, 0700, arquivos 0600. Alterar a política desabilita novas reservas até reinstalar o bundle; alterações com chamadas/reservas pendentes são recusadas. A política não é exposta em um formulário de administrador do navegador nesta etapa.

Gerados pelo comando:

- `ma-calling-pjsip.conf`: transporte TLS, autenticação do tronco e endpoint WebRTC dedicado.
- `ma-calling-extensions.conf`: autorização de uso único, Dial, corte de duração e callbacks.
- `ma-calling-event.py` e `ma-calling-secret`: AGI e assinatura dos eventos.
- `bundle.json`: hashes dos arquivos e impressão digital da configuração atual.

Depois de conferir conta, origem, destino e tarifas:

```sh
cd /srv/zyrex-ma
python3 ops/install_calling_pbx.py
python3 ops/install_calling_pbx.py --apply
# Primeiro transporte TLS: usar --apply --initialize-tls com o PBX ocioso.
```

Sem `--apply`, o instalador apenas verifica hashes e carrega os arquivos em um Asterisk isolado, sem rede nem portas publicadas. Com `--apply`, exige PBX sem canais, faz backup exclusivo, acrescenta os includes ao PJSIP/dialplan do MA e verifica endpoint, transporte e contextos. Atualizações usam `module reload res_pjsip.so` e recarga do dialplan. A primeira inclusão do transporte TLS exige `--initialize-tls`: reinicia somente o PBX ocioso do MA, pois o resolvedor do Asterisk mantém os transportes disponíveis desde a inicialização. Só então publica `installed.json`. Não faz chamadas. Mudança do transporte TLS já instalado é recusada e exige um plano próprio; troca de credenciais e política mantém esse transporte.

Se alguma etapa falhar, a liberação não é publicada e os arquivos alterados são restaurados. Logs/configurações privados não devem ser copiados para conversas ou repositórios. O instalador **não foi aplicado no PBX ativo nesta entrega**, pois não há credenciais do tronco.

## Homologar no navegador

1. Cadastrar em **Contatos e CSV** um contato real cujo responsável autorizou este teste. Incluir o mesmo telefone na lista privada; contatos fictícios de simulação não servem para ligar.
2. Confirmar o microfone no **Teste de áudio real** e encerrar o eco antes de ligar.
3. Abrir **Chamadas** e conferir a disponibilidade e o número de origem. Selecionar o contato, registrar a autorização e clicar em **Ligar para contato**.
4. Verificar no telefone chamado a origem e o áudio nos dois sentidos. Encerrar e conferir o resultado registrado pelo PBX.
5. Homologar ocupado/não atendimento, corte de duração, desligamento no navegador/aparelho, perda de conexão e repetição de eventos. Testar outra rede; TURN continua pendente se necessário para a rede do atendente.

O teste manual não executa horário, tentativas ou cadência da campanha simulada. Vincular uma campanha é uma associação para validação de número/público e histórico. A ativação de campanhas automáticas requer uma etapa adicional, após essa homologação.

## Resultado incerto e operação

Sem callback final, o prazo de segurança muda o estado para `unknown`, mas **não libera capacidade automaticamente**. Isso evita que uma falha de comunicação com o PBX permita iniciar outra ligação sobre uma chamada possivelmente ativa. O limite de duração também é aplicado dentro do Asterisk, sem depender do navegador.

Para bloquear novas ligações sem desligar uma ligação já em andamento:

```sh
cd /srv/zyrex-ma
docker compose exec --user 82:82 app php artisan voice:calling:configure --disable
```

Depois, conferir o PBX:

```sh
docker compose -f compose.yaml -f compose.voice.yaml exec -T pbx asterisk -rx 'core show channels count'
```

Somente após confirmar **zero canais**, o operador pode liberar uma reserva incerta usando o UUID exato do histórico:

```sh
docker compose exec --user 82:82 app php artisan voice:calling:release UUID_DA_TENTATIVA --pbx-confirmed-empty
```

A ação é auditada e mantém o resultado desconhecido: não inventa atendimento, duração ou cobrança. O argumento é uma declaração operacional, não uma consulta automática do comando ao PBX. Reinstalar/validar o bundle para voltar a permitir testes. Não usar comandos globais de parada ou reinício da VM.

Duração no histórico vem do PBX; não é conciliação financeira com a Twilio. Os limites são de tentativas/tempo, não um teto monetário garantido. Controle financeiro e reconciliação com registros da operadora continuam para a etapa de operação contínua.

## Contratos e isolamento

| Rota | Uso |
|---|---|
| GET `/api/voice/calling` | Estado e últimas 50 tentativas do usuário |
| POST `/api/voice/calling/calls` | Reserva; exige contato, UUID idempotente, confirmação/evidência de consentimento e campanha opcional |
| POST `/api/voice/calling/calls/{uuid}/cancel` | Cancela apenas reserva ainda não consumida, do próprio usuário |
| POST `/internal/voice/calling/event` | HMAC de timestamp/corpo, prazo de 30s, correlação por token e canal |

Gestão de saída restrita ao workspace original (1). APIs do navegador usam sessão, CSRF e limite de frequência. O callback não depende de sessão; exige assinatura mesmo se acessado pela Internet. O segredo de eventos não vai para o navegador. A tabela `voice_outbound_calls` é própria, preservando a diferença entre simulação, eco e ligação externa.

## Recebimento e escala

Chamadas de retorno ainda precisam de Origination URI, restrição/autenticação de origem Twilio, rota de entrada e distribuição ao atendente. O endpoint de navegador atual rejeita chamadas recebidas. Não anunciar atendimento de retorno antes dessa entrega. Discagem progressiva automática, filas, múltiplos PBXs, controle monetário e operação multiempresa também não estão habilitados.

## Referências

- [Twilio Elastic SIP Trunking](https://www.twilio.com/docs/sip-trunking).
- [Asterisk 22 — Dial, subrotina de atendimento e limite de duração](https://docs.asterisk.org/Asterisk_22_Documentation/API_Documentation/Dialplan_Applications/Dial/).
- [Preparação do tronco no projeto](twilio.md) e [áudio interno](marco-2-telefonia.md).

## Validação desta preparação

- 85 testes / 623 assertions em SQLite e em PostgreSQL isolado: autorização, escopo, consentimento, destinos permitidos, idempotência, assinatura, expiração, resultado incerto, limites e capacidade compartilhada com o eco.
- Asterisk 22.9 isolado, com rede desabilitada: transporte/endpoint carregados, operadora substituída apenas no teste por um destino Local, evento de atendimento e encerramento confirmados. Corte configurado em 30 segundos, observado em cerca de 31,1 segundos incluindo o processamento dos callbacks. Tokens inválidos e reutilizados não produziram outra chamada atendida.
- Build Vue/Vite concluído. A validação isolada não comprova TLS/SRTP com a Twilio, caller ID ou áudio pela operadora; esses itens dependem da homologação real.
- Migração aditiva `voice_outbound_calls`, backup próprio de código/banco e publicação apenas no MA. O instalador de rota externa foi entregue, mas não executado no PBX ativo. Nenhuma chamada externa foi realizada.

Evidências em `evidence/calling-20261001/`; teste de PBX em `ops/test_calling_pbx.py`. Reversão da aplicação: restaurar os arquivos do backup `backups/calling-code-before-*.tar.gz` correspondente e limpar o cache de rotas; preservar a nova tabela e os dados eventualmente registrados. A preparação não requer reversão do PBX ativo, pois seus arquivos não foram alterados nesta entrega.

A conferência Chromium autenticada passou em desktop/celular: aba Chamadas disponível, botão de ligação bloqueado sem configuração, reserva retornando HTTP 503 sem criar tentativa, acesso anônimo HTTP 401, WhatsApp preservado e diagnóstico do áudio disponível. Nenhum erro JavaScript.

A comparação de implantação preservou IDs/horários de todos os containers do MA e hashes de todos os arquivos do PBX. O worker `zyrex-crm-r38-whatsapp-worker` manteve o ID e estava em execução sem OOM; seu horário de início avançou 3.608 segundos, compatível com a reciclagem preexistente `--max-time=3600` e política `unless-stopped`. Nenhum comando da implantação reiniciou ou alterou esse worker. Evidência em `other-worker-lifecycle.json`.

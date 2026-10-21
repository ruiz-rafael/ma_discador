# Recebimento SIP

As rotas são escolhidas pelo DID contratado e pelo tronco identificado no Asterisk. O navegador não escolhe empresa, rota ou destino de uma chamada recebida. Twilio API continua usando os callbacks existentes; Twilio Elastic SIP e Fale Vono usam o adaptador SIP.

- `GET /api/voice/inbound`: números SIP atribuídos também aparecem em `available_numbers`; `sip_numbers` distingue a integração. Rotas/chamadas incluem `provider` (`programmable_voice` ou `sip_trunk`).
- `POST /api/voice/inbound/token`: requer usuário habilitado, integrante de fila receptiva e uma sessão UUID exclusiva. Retorna `sip: {username,password,aor,server}` quando há SIP; pode retornar também o token Twilio. Credenciais do navegador são pessoais e derivadas do tenant e da versão de autenticação; nunca são as credenciais da operadora. Resposta privada, sem cache.
- `POST /api/voice/inbound/device`: `session_id`, `ready` e `sip_ready`; heartbeat a cada 15 segundos. A presença disponível na fila permanece independente da conexão de áudio.
- `POST /internal/voice/calling/event`: mantém a assinatura HMAC SHA256 de `timestamp.body`, janela de 30s, até 4096 bytes. Eventos SIP receptivos: `inbound_start`, `inbound_dispatch`, `inbound_answer`, `inbound_result`, `inbound_end`. Campos comuns `endpoint`, `channel_id`. Start exige `number`, aceita `from`; answer/result exigem `offer_id`; end aceita `bill_seconds`. Backend valida a combinação número/tronco antes de admitir. Sem autenticação válida, 401. Sem admissão, `allowed:false`.

A aplicação reserva o agente de forma transacional; saída e entrada compartilham a capacidade. Recusa ou falta de resposta tenta outro agente uma vez. Ausência de agentes aguarda até o limite configurado e reproduz aviso em português. Atendimento encerra em tabulação, respeitando o pós-atendimento. O reconciliador verifica canais ativos para recuperar um callback de encerramento perdido, sem inventar a duração da conversa.

O PBX aceita sinalização externa apenas das redes oficiais Twilio e dos endereços resolvidos de vono2.me. A entrada Twilio usa TLS/SRTP. As contas WebRTC receptivas possuem contexto sem discagem. Todos os DIDs têm roteamento exato e isolamento por tenant. Portas publicadas: TLS 5061→15062 e UDP Vono 15060→15060. O firewall filtra também o tráfego encaminhado pelo Docker.

Limites desta entrega: transferência e gravação pela interface existente são da integração Twilio API; os controles não são apresentados como disponíveis nas chamadas SIP receptivas. Homologação sem chamadas externas verifica distribuição, registro WebRTC e mídia local; recebimento pela rede pública precisa de uma ligação externa ao DID.

As contas receptoras definem `from_domain=app.discai.ia.br`: nomes internos de contêiner iniciados por dígitos podem gerar cabeçalhos SIP que o parser do navegador rejeita. O registro isolado não comprova recebimento; verificar OPTIONS/200, INVITE, mídia em ambos os sentidos e liberação após tabulação.

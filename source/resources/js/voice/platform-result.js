// Provider events, not browser audio, determine whether the destination answered.
export function callResult(call) {
  if (!call) return {title:'Pronto para ligar',detail:'Escolha um número de saída e informe o destino.'}
  const labels={pending:'Preparando ligação',dialing:'Discando · aguardando atendimento',answered:'Chamada atendida',completed:'Ligação encerrada',no_answer:'Não atendeu',busy:'Destino ocupado',failed:'Não foi possível completar',cancelled:'Ligação cancelada',unknown:'Resultado ainda não confirmado'}
  let detail=call.message|| (call.ended?'Resultado confirmado pela telefonia.':'Aguardando atualização da telefonia. Mantenha esta página aberta.')
  if(call.status==='no_answer') detail='A operadora retornou “não atendeu”. Esse retorno não confirma que o aparelho chegou a tocar.'
  if(call.ended&&call.answered_at) detail=`Conversa encerrada · ${Number(call.bill_seconds)||0} s de duração informada pela telefonia.`
  if(call.dial_status||call.cause) detail+=` Retorno: ${call.dial_status||''}${call.cause?' · '+call.cause:''}.`
  return {title:labels[call.status]||'Aguardando resultado',detail}
}
export function activeAttempt(calls){return calls.find(c=>c.state!=='ended')||null}
export function trustedGrant(grant){
 if(!grant||!['sip_trunk','programmable_voice'].includes(grant.method)||!(grant.max_seconds>0&&grant.max_seconds<=180)||!(grant.ring_seconds>0&&grant.ring_seconds<=45))return false
 if(grant.method==='programmable_voice')return typeof grant.access_token==='string'&&typeof grant.params?.Reservation==='string'&&typeof grant.params?.Grant==='string'
 try {const u=new URL(grant.server);return u.protocol==='wss:'&&u.pathname==='/voice/ws'&&(/(^|\.)discai\.ia\.br$/.test(u.hostname)||u.hostname==='ma.zyrex.ia.br')&&typeof grant.username==='string'&&typeof grant.password==='string'&&grant.aor.startsWith('sip:')&&grant.destination.startsWith('sip:')}catch{return false}
}

/** Share concurrent Online clicks and retry a stale lease once, without taking over another tab. */
export function singleFlight(action){
 let pending=null
 return (...args)=>{
  if(pending)return pending
  pending=Promise.resolve().then(()=>action(...args)).finally(()=>{pending=null})
  return pending
 }
}
export async function acquireReceiverLease(request,{disposed=()=>false,onWait=()=>{},sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms))}={}){
 try{return await request()}catch(error){
  if(error.code!=='inbound_session_lease'||!Number.isInteger(error.retryAfter)||error.retryAfter<1||error.retryAfter>61)throw error
  try{
   for(let remaining=error.retryAfter;remaining>0;remaining--){
    if(disposed())throw Error('Reconexão cancelada ao sair da página.')
    onWait(remaining);await sleep(1000)
   }
   if(disposed())throw Error('Reconexão cancelada ao sair da página.')
   return await request()
  }finally{onWait(0)}
 }
}

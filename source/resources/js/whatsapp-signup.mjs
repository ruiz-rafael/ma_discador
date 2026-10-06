// Validate the exact origin, message type and shape; ignore unsolicited messages.
export function signupEvent(event, active) {
  if (!active || !['https://www.facebook.com', 'https://web.facebook.com'].includes(event.origin)) return null
  let data
  try { data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data } catch { return null }
  if (data?.type !== 'WA_EMBEDDED_SIGNUP') return null
  if (['CANCEL', 'ERROR'].includes(data.event)) return {event:data.event}
  if (data.event !== 'FINISH' || !/^[0-9]{5,40}$/.test(data.data?.waba_id || '') || !/^[0-9]{5,40}$/.test(data.data?.phone_number_id || '')) return null
  return {event:'FINISH', waba_id:String(data.data.waba_id)}
}
let sdkPromise
export function loadMetaSdk(settings) {
  if (!sdkPromise) sdkPromise = new Promise((resolve,reject) => {
    if (window.FB) return resolve(window.FB)
    const script=document.createElement('script'); script.src='https://connect.facebook.net/pt_BR/sdk.js';script.async=true;script.crossOrigin='anonymous'
    const timer=setTimeout(()=>reject(new Error('A Meta demorou para carregar. Recarregue esta página para tentar novamente.')),15000)
    script.onload=()=>{clearTimeout(timer);window.FB?resolve(window.FB):reject(new Error('Não foi possível carregar a Meta.'))}
    script.onerror=()=>{clearTimeout(timer);reject(new Error('Não foi possível carregar a Meta. Confira os bloqueadores do navegador.'))}
    document.head.appendChild(script)
  }).catch(e=>{sdkPromise=null;throw e})
  return sdkPromise.then(FB=>{FB.init({appId:settings.app_id,version:settings.graph_version,autoLogAppEvents:false,xfbml:false});return FB})
}

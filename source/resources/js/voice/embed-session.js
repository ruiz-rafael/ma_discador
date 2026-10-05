let token=''
export function setEmbeddedToken(value){token=value}
export const isEmbedded=()=>!!document.querySelector('meta[name="ma-embed-origin"]')
export const voiceBase=()=>isEmbedded()?'/embed/api/voice':'/api/voice'
export const voiceAuth=()=>isEmbedded()?{Authorization:'Bearer '+token}:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}
export const voiceCredentials=()=>isEmbedded()?'omit':'same-origin'

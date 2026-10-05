<script setup>
import {ref,onMounted,onBeforeUnmount} from 'vue'
import VoiceAgentPanel from './components/VoiceAgentPanel.vue'
import {setEmbeddedToken} from './voice/embed-session'
import {voiceApi} from './voice/operations'
const ready=ref(false),error=ref(''),name=ref(''),expired=ref(false)
const origin=document.querySelector('meta[name="ma-embed-origin"]').content
let expiry=null
async function message(e){if(e.origin!==origin||e.source!==window.parent||e.data?.type!=='zyrex.session'||typeof e.data.access_token!=='string'||!/^mae_[a-f0-9]{64}$/.test(e.data.access_token))return;setEmbeddedToken(e.data.access_token);try{await voiceApi('/operations/catalog');ready.value=true;expired.value=false;error.value='';clearTimeout(expiry);expiry=setTimeout(()=>{expired.value=true;window.parent.postMessage({type:'zyrex.session-expiring'},origin)},540000);window.parent.postMessage({type:'zyrex.connected'},origin)}catch(e){error.value=e.message}}
onMounted(()=>{window.addEventListener('message',message);window.parent.postMessage({type:'zyrex.ready'},origin)})
onBeforeUnmount(()=>{clearTimeout(expiry);window.removeEventListener('message',message)})
</script>
<template><main class="embedded"><header><strong>Zyrex · Atendimento</strong><span>Sessão delegada</span></header><p v-if="error" class="error">{{error}}</p><p v-if="expired" class="error">Solicite a renovação da sessão ao sistema integrador antes de iniciar outro atendimento.</p><VoiceAgentPanel v-if="ready"/><p v-else>Aguardando sessão autorizada pelo sistema integrador.</p></main></template>
<style scoped>.embedded{padding:20px;background:#f6f6f0;min-height:100vh}.embedded>header{display:flex;justify-content:space-between;color:#a37e2c;border-bottom:1px solid #dedfce;padding:16px 0}.embedded>header span{font-size:12px}</style>

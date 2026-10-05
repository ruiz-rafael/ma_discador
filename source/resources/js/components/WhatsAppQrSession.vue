<script setup>
import {ref,onUnmounted,computed} from 'vue'
import {QrCode,RefreshCw,CheckCircle2,Smartphone,LogOut} from 'lucide-vue-next'
const props=defineProps({sender:Object})
const emit=defineEmits(['changed'])
const session=ref(null),error=ref(''),busy=ref(false),confirmDisconnect=ref(false)
let timer=null,alive=true
const connected=computed(()=>session.value?session.value.status==='connected':props.sender.status==='ONLINE')
const labels={connected:'Conexão confirmada',connecting:'Preparando seu QR Code…',qr:'Pronto para ler no celular',disconnected:'Este número está desconectado',logged_out:'Sessão encerrada no WhatsApp',number_mismatch:'O celular conectado não corresponde ao número cadastrado',error:'Não foi possível consultar a conexão',storage_error:'Sessão indisponível',event_error:'Falha ao processar evento'}
async function request(method='GET'){
 if(busy.value||!alive)return;busy.value=true;error.value='';clearTimeout(timer)
 try{const r=await fetch(`/api/voice/whatsapp/senders/${props.sender.id}/qr`,{method,headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}});const d=await r.json();if(!r.ok)throw new Error(d.message||'Conexão indisponível.');if(!alive)return;session.value=d;confirmDisconnect.value=false;emit('changed');if(['qr','connecting','disconnected'].includes(d.status)&&method!=='DELETE')timer=setTimeout(()=>request(),4000)}catch(e){if(alive)error.value=e.message;clearTimeout(timer)}finally{busy.value=false}
}
onUnmounted(()=>{alive=false;clearTimeout(timer)})
</script>
<template>
 <div class="qr-session">
  <div v-if="connected" class="qr-connected"><CheckCircle2 :size="22"/><div><strong>WhatsApp conectado</strong><p>Este número pode ser selecionado nas suas cadências. Para receber mensagens, mantenha o aparelho vinculado.</p></div></div>
  <template v-else><p class="qr-intro">Vincule o WhatsApp de <strong>{{sender.number}}</strong> ao MA.</p><ol class="qr-steps"><li><span>1</span><div>Abra o WhatsApp no celular.</div></li><li><span>2</span><div>Entre em <strong>Aparelhos conectados</strong> e toque em <strong>Conectar um aparelho</strong>.</div></li><li><span>3</span><div>Gere o código abaixo e aponte a câmera para ele.</div></li></ol></template>
  <div v-if="session?.qr&&!connected" class="qr-code"><img :src="session.qr" alt="QR Code para vincular o WhatsApp ao MA" width="260" height="260"><p>Aguardando leitura pelo celular…<br>A conexão será confirmada automaticamente.</p></div>
  <p v-if="session" role="status" class="qr-state">{{labels[session.status]||session.status}}</p>
  <div class="qr-actions"><button v-if="!connected" type="button" class="primary" :disabled="busy" @click="request('POST')"><QrCode :size="17"/>{{session?.qr?'Gerar novo QR Code':'Gerar QR Code'}}</button><button type="button" class="secondary" :disabled="busy" @click="request()"><RefreshCw :size="15"/>{{busy?'Consultando…':'Verificar conexão'}}</button></div>
  <details v-if="connected||session" class="qr-manage"><summary>Gerenciar aparelho vinculado</summary><p>Para trocar de aparelho, desconecte esta sessão e gere outro QR Code.</p><button type="button" class="qr-disconnect" :disabled="busy" @click="confirmDisconnect=true"><LogOut :size="14"/>Desconectar aparelho</button><div v-if="confirmDisconnect" class="qr-confirm" role="group" aria-label="Confirmar desconexão"><strong>Desconectar {{sender.number}}?</strong><p>Os envios deste número ficarão indisponíveis até você conectar novamente.</p><div class="qr-actions"><button type="button" class="secondary" :disabled="busy" @click="confirmDisconnect=false">Manter conectado</button><button type="button" class="qr-disconnect" :disabled="busy" @click="request('DELETE')">Confirmar desconexão</button></div></div></details>
  <p v-if="error" role="alert" class="error">{{error}}</p>
 </div>
</template>
<style scoped>
.qr-session{min-width:0}.qr-session p{font-size:12px;line-height:1.75;color:#737e68}.qr-connected{display:flex;gap:12px;padding:16px;background:#edf6ef;border:1px solid #d5e8d9;border-radius:12px;margin-bottom:22px;color:#34764f}.qr-connected>svg{flex-shrink:0;margin-top:1px}.qr-connected strong{font-size:13px}.qr-connected p{margin:5px 0 0;color:#5c7d65}.qr-steps{list-style:none;display:flex;flex-direction:column;gap:16px;padding:0;margin:22px 0 26px}.qr-steps li{display:flex;gap:12px;font-size:12px;line-height:1.7;color:#5e6a52}.qr-steps li>span{display:grid;place-items:center;flex-shrink:0;width:25px;height:25px;background:#f2eddf;border-radius:50%;color:#977124}.qr-steps li>div{padding-top:2px}.qr-actions{display:flex;gap:9px;flex-wrap:wrap}.qr-actions button,.qr-disconnect{display:inline-flex;align-items:center;justify-content:center;gap:7px}.qr-session button:disabled{opacity:.55;cursor:not-allowed}.qr-session button:focus-visible,.qr-manage summary:focus-visible{outline:3px solid #be9134;outline-offset:3px}.qr-code{display:flex;flex-direction:column;align-items:center;background:#fafbf7;border:1px dashed #d3dbc9;padding:18px;margin-bottom:20px;border-radius:12px}.qr-code img{max-width:100%;height:auto;background:white;border-radius:10px;padding:8px}.qr-code p{text-align:center;margin-bottom:0;font-size:11px}.qr-state{font-weight:500}.qr-manage{margin-top:22px;border-top:1px solid #e5e9de;padding-top:16px}.qr-manage summary{font-size:11px;color:#7c866f;cursor:pointer}.qr-manage p{font-size:11px}.qr-disconnect{font:inherit;font-size:11px;background:#fff;border:1px solid #e5d2c9;color:#a55a43;padding:10px 12px;border-radius:8px;cursor:pointer}.qr-confirm{margin-top:14px;padding:16px;border:1px solid #e8cdc0;background:#fff9f4;border-radius:10px}.qr-confirm strong{font-size:12px}.qr-session .error{color:#ae4934}@media(max-width:760px){.qr-actions button{font-size:12px}}
</style>

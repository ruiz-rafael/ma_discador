<script setup>
import {ref} from 'vue'
import {checkMicrophone,microphoneMessage} from '../voice/microphone'
const props=defineProps({disabled:Boolean}),status=ref(''),error=ref(''),busy=ref(false)
async function check(){busy.value=true;error.value='';status.value='';try{const m=await checkMicrophone();status.value=m.label+' disponível. Nenhuma chamada foi iniciada.'}catch(e){error.value=microphoneMessage(e)}finally{busy.value=false}}
</script>
<template><section class="microphone-check"><div><h3>Microfone e headset</h3><p>Verifique o áudio antes de ficar disponível. Use um headset conectado e permita o microfone nas configurações deste site.</p></div><button class="secondary" :disabled="disabled||busy" @click="check">{{busy?'Verificando…':'Verificar microfone'}}</button><p v-if="status" role="status">{{status}}</p><p v-if="error" class="error" role="alert">{{error}}</p></section></template>
<style scoped>.microphone-check{padding:20px;border:1px solid #e1e3d8;border-radius:14px;margin:18px 0;background:#fff}.microphone-check h3{margin:0 0 8px;font-size:16px}.microphone-check p{color:#727d67;font-size:13px;line-height:1.7}.microphone-check .error{color:#9e302d}</style>

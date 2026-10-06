<script setup>
import {ref,onMounted,onBeforeUnmount,watch} from 'vue'
import {voiceApi as api} from '../voice/operations'
import {voiceBase,voiceAuth,isEmbedded} from '../voice/embed-session'
const props=defineProps({kind:{type:String,default:'outbound'},callId:String})
const state=ref(null),error=ref(''),busy=ref(false),audio=ref('');let timer=null,version=0
async function load(){const v=version;try{const s=await api('/recordings/calls/'+props.kind+'/'+props.callId);if(v===version)state.value=s}catch(e){if(v===version)error.value=e.message}}
async function control(r){busy.value=true;error.value='';try{await api('/recordings/'+r.id+'/control','POST',{status:r.status==='paused'?'in-progress':'paused'});await load()}catch(e){error.value=e.message}finally{busy.value=false}}
async function play(r){busy.value=true;error.value='';const v=version;try{const response=await fetch(voiceBase()+'/recordings/'+r.id+'/audio',{credentials:isEmbedded()?'omit':'same-origin',headers:voiceAuth()});if(!response.ok)throw Error('Não foi possível abrir esta gravação. Confira o prazo e sua permissão.');const blob=await response.blob();if(v!==version)return;if(audio.value)URL.revokeObjectURL(audio.value);audio.value=URL.createObjectURL(blob)}catch(e){error.value=e.message}finally{busy.value=false}}
const labels={'in-progress':'Gravando',paused:'Gravação pausada',completed:'Gravação disponível',absent:'Áudio não gerado',deleted:'Gravação excluída'}
function reset(){version++;state.value=null;error.value='';if(audio.value)URL.revokeObjectURL(audio.value);audio.value='';load()}
watch(()=>[props.callId,props.kind],reset);onMounted(()=>{load();timer=setInterval(load,10000)});onBeforeUnmount(()=>{version++;clearInterval(timer);if(audio.value)URL.revokeObjectURL(audio.value)})
</script>
<template><section v-if="state?.enabled||error" class="recording-control"><strong>Gravação</strong><p v-if="error" class="error" role="alert">{{error}}</p><p v-if="state?.restricted">Gravação habilitada · acesso reservado à supervisão.</p><p v-else-if="state?.enabled&&!state.recordings.length">Aguardando confirmação da gravação pela operadora.</p><div v-for="r in state?.recordings||[]" :key="r.id"><span>{{labels[r.status]||r.status}}</span><button type="button" v-if="r.can_control" class="secondary" :disabled="busy" @click="control(r)">{{r.status==='paused'?'Retomar gravação':'Pausar gravação'}}</button><button type="button" v-if="r.can_play" class="secondary" :disabled="busy" @click="play(r)">Ouvir gravação</button></div><audio v-if="audio" :src="audio" controls preload="none" aria-label="Gravação da chamada"/></section></template>
<style scoped>.recording-control{border:1px solid #e1dfd5;border-radius:12px;padding:16px;margin-block:12px}.recording-control>div{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:10px}.recording-control audio{max-width:100%;margin-top:12px}</style>

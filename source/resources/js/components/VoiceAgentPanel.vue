<script setup>
import {ref,onMounted,defineAsyncComponent} from 'vue'
const VoiceAudioPanel=defineAsyncComponent(()=>import('./VoiceAudioPanel.vue'))
import {voiceApi} from '../voice/operations'
import {isEmbedded} from '../voice/embed-session'
import InboundVoicePanel from './InboundVoicePanel.vue'
import VoiceLiveQueuePanel from './VoiceLiveQueuePanel.vue'
const diagnostic=ref(false),audio=ref(null)
function toggleAudio(){if(diagnostic.value&&audio.value)audio.value.requestLeave(()=>diagnostic.value=false);else diagnostic.value=true}
const catalog=ref(null),error=ref(''),operation=ref(null),inbound=ref(null)
onMounted(async()=>{try{catalog.value=await voiceApi('/operations/catalog')}catch(e){error.value=e.message}})
function leaveOperation(fn){return inbound.value?inbound.value.requestLeave(()=>operation.value?operation.value.requestLeave(fn):fn()):fn()}
defineExpose({requestLeave:fn=>audio.value?audio.value.requestLeave(()=>leaveOperation(fn)):leaveOperation(fn)})
</script>
<template><div class="operations agent-workspace"><div class="section-heading"><div><span class="eyebrow">ATENDIMENTO</span><h1>Minha operação</h1><p>Sua disponibilidade, suas chamadas e a tabulação de cada conversa.</p></div></div><button v-if="!isEmbedded()" class="secondary" @click="toggleAudio">{{diagnostic?'Fechar diagnóstico':'Testar áudio sem ligar para clientes'}}</button><VoiceAudioPanel v-if="diagnostic" ref="audio"/><p v-if="error" class="error" role="alert">{{error}}</p><InboundVoicePanel v-if="catalog" ref="inbound" :codes="catalog.codes"/><VoiceLiveQueuePanel v-if="catalog" ref="operation" :catalog="catalog" view="agent"/></div></template>

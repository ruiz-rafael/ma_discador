<script setup>
import {ref} from 'vue'
import {voiceApi as api,stamp} from '../voice/operations'
import VoiceRecordingPanel from './VoiceRecordingPanel.vue'
const props=defineProps({queueId:Number});const rows=ref(null),selected=ref(null),error=ref('')
async function load(){try{rows.value=await api('/recordings/queues/'+props.queueId);error.value=''}catch(e){error.value=e.message}}
</script>
<template><details class="table-card" @toggle="$event.target.open&&load()"><summary>Gravações desta fila</summary><p v-if="error" class="error">{{error}}</p><p v-if="rows&&!rows.length">Nenhuma gravação registrada. Ativar a opção vale apenas para chamadas futuras.</p><p v-if="rows?.length">Até 50 gravações recentes.</p><button v-for="r in rows||[]" :key="r.id" class="secondary" @click.prevent="selected=r">{{stamp(r.created_at)}} · {{r.kind==='inbound'?'Entrada':'Saída'}} · {{r.duration??0}} s</button><VoiceRecordingPanel v-if="selected" :key="selected.id" :call-id="selected.call_id" :kind="selected.kind"/></details></template>

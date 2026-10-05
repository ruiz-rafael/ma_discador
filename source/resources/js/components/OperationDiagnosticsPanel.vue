<script setup>
import {ref,onMounted} from 'vue'
import {voiceApi,stamp} from '../voice/operations'
import {qualityState} from '../voice/audio-quality'
const state=ref(null),days=ref(90),error=ref(''),busy=ref(false)
const labels={good:'Indicadores bons',attention:'Requer atenção',insufficient:'Amostra insuficiente'}
const number=(v,suffix='')=>typeof v==='number'?v.toLocaleString('pt-BR',{maximumFractionDigits:2})+suffix:'Não disponível'
async function load(){busy.value=true;error.value='';try{state.value=await voiceApi('/health/diagnostics?retention_days='+days.value)}catch(e){error.value=e.message}finally{busy.value=false}}
onMounted(load)
</script>
<template><section class="table-card diagnostics"><h2>Qualidade do áudio da equipe</h2><p>Últimos testes internos de conexão. Nenhuma gravação de voz é armazenada.</p><p v-if="error" class="error" role="alert">{{error}}</p><div class="scroll"><table><thead><tr><th>Atendente / data</th><th>Avaliação</th><th>Atraso p95</th><th>Variação p95</th><th>Perda</th><th>Codec</th></tr></thead><tbody><tr v-for="s in state?.sessions" :key="s.id"><td>{{s.agent_name}}<small>{{stamp(s.created_at)}}</small></td><td>{{labels[qualityState(s.quality)]}}</td><td>{{number(s.quality?.rtt_p95_ms,' ms')}}</td><td>{{number(s.quality?.jitter_p95_ms,' ms')}}</td><td>{{number(s.quality?.loss_percent,' %')}}</td><td>{{s.quality?.codec||'Não disponível'}}</td></tr></tbody></table></div><p v-if="!state?.sessions.length">Nenhum teste registrado.</p><p>{{state?.scope}}</p><a class="secondary" href="#/cadences/tests/audio">Abrir teste de áudio interno</a><button class="secondary" :disabled="busy" @click="load">Atualizar diagnósticos</button></section></template>
<style scoped>.diagnostics{padding:24px;margin-bottom:22px}.diagnostics h2{font-size:20px}.diagnostics p,.diagnostics small{color:#737d68;line-height:1.7}.scroll{overflow:auto;margin:20px 0}.diagnostics table{font-size:12px}.diagnostics small{display:block}.retention{margin-top:26px;border-top:1px solid #e1e3d8;padding-top:22px}.retention form{display:flex;gap:16px;align-items:end;flex-wrap:wrap}.retention label{display:flex;gap:8px;flex-direction:column;font-size:12px}.retention ul{line-height:2}.diagnostics a{display:inline-flex;text-decoration:none}@media(max-width:600px){.diagnostics{padding:16px}}</style>

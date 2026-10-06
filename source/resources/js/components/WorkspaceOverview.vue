<script setup>
import {ref,computed,onMounted} from 'vue'
import {Workflow,Layers,Users,ArrowRight,Phone,MessageCircle,BarChart3,Plug,Check,RefreshCw} from 'lucide-vue-next'
import {voiceApi} from '../voice/operations'
const props=defineProps({journeys:{type:Array,default:()=>[]},name:String})
const emit=defineEmits(['navigate','cadences'])
const snapshot=ref(null),catalog=ref(null),loading=ref(false),error=ref(''),updated=ref(null)
async function refresh(){loading.value=true;error.value='';try{const [s,c]=await Promise.all([voiceApi('/operations/queues'),voiceApi('/operations/catalog')]);snapshot.value=s;catalog.value=c;updated.value=new Date()}catch{error.value='Não foi possível atualizar a visão geral. Tente novamente.'}finally{loading.value=false}}
const queues=computed(()=>snapshot.value?.queues||[])
const checks=computed(()=>[
 {title:'Conectar os canais',detail:'Configure a telefonia e o WhatsApp que sua equipe vai usar.',done:!!catalog.value?.queue_channels?.voice_numbers?.length||!!catalog.value?.queue_channels?.senders?.length,action:()=>emit('cadences','channels'),icon:Plug},
 {title:'Organizar as filas',detail:'Escolha os números, as permissões e os atendentes.',done:queues.value.some(q=>q.agent_ids?.length&&(q.voice_number||q.whatsapp_sender_id||q.incoming_numbers?.length)),action:()=>emit('navigate','queues'),icon:Layers},
 {title:'Desenhar uma cadência',detail:'Defina o público, as tentativas e a próxima mensagem.',done:props.journeys.length>0,action:()=>emit('navigate','journeys'),icon:Workflow},
])
onMounted(refresh)
</script>
<template><div class="workspace-overview">
 <header class="overview-hero"><div><span class="eyebrow">SEU CENTRO DE OPERAÇÃO</span><h1>Boas conexões começam aqui.</h1><p>{{name?.split(' ')[0]}}, organize a equipe e acompanhe cada próximo passo.</p></div><button class="primary" @click="emit('navigate','journeys')">Abrir cadências <ArrowRight :size="17"/></button></header>
 <div class="overview-heading"><h2>Visão geral</h2><button class="secondary" :disabled="loading" @click="refresh"><RefreshCw :size="14"/> {{loading?'Atualizando…':'Atualizar visão geral'}}</button></div>
 <p v-if="error" class="error" role="alert">{{error}} {{updated?'Os dados abaixo são da última atualização.':''}}</p>
 <div class="overview-metrics">
  <button @click="emit('navigate','journeys')"><span class="overview-icon"><Workflow :size="21"/></span><div><strong>{{journeys.length}}</strong><span>Cadências criadas</span></div><ArrowRight :size="16"/></button>
  <button @click="emit('navigate','queues')"><span class="overview-icon sage"><Layers :size="21"/></span><div><strong>{{snapshot?queues.length:'—'}}</strong><span>Filas de atendimento</span></div><ArrowRight :size="16"/></button>
  <button @click="emit('navigate','team')"><span class="overview-icon blue"><Users :size="21"/></span><div><strong>{{catalog?catalog.agents.length:'—'}}</strong><span>Atendentes no catálogo</span></div><ArrowRight :size="16"/></button>
 </div>
 <section class="setup-section"><div class="overview-heading"><div><h2>Prepare sua operação</h2><p>Um caminho simples, da conexão à cadência.</p></div><span class="setup-tag">Configuração</span></div><div class="setup-cards"><button v-for="(step,i) in checks" :key="step.title" @click="step.action()"><div class="setup-card-top"><span class="overview-icon"><component :is="step.icon" :size="21"/></span><span class="setup-state" :class="{done:catalog&&step.done}"><Check v-if="catalog&&step.done" :size="13"/>{{!catalog?'A conferir':step.done?'Cadastrado':'Passo '+(i+1)}}</span></div><h3>{{step.title}}</h3><p>{{step.detail}}</p><span class="setup-link">{{catalog&&step.done?'Revisar configuração':'Configurar'}} <ArrowRight :size="15"/></span></button></div><p class="overview-footnote">Cadastro não comprova funcionamento. Faça a homologação de voz e mensagens antes de ampliar a operação.</p></section>
 <div class="overview-bottom"><section class="overview-shortcuts"><h2>Seu dia a dia</h2><button @click="emit('navigate','conversations')"><MessageCircle :size="19"/><span><strong>Conversas</strong><small>Continue de onde seu cliente parou</small></span><ArrowRight :size="16"/></button><button @click="emit('cadences','reports')"><BarChart3 :size="19"/><span><strong>Relatórios</strong><small>Chamadas, mensagens e respostas</small></span><ArrowRight :size="16"/></button><button @click="emit('navigate','operation')"><Phone :size="19"/><span><strong>Minha operação</strong><small>Atenda e faça ligações pela sua conta</small></span><ArrowRight :size="16"/></button></section>
 <section class="overview-queues"><div class="overview-heading"><h2>Filas em foco</h2><button @click="emit('navigate','queues')">Ver todas <ArrowRight :size="14"/></button></div><p v-if="!snapshot">{{loading?'Carregando filas…':'Atualize para consultar as filas.'}}</p><p v-else-if="!queues.length">Crie uma fila para reunir números e atendentes.</p><div v-for="q in queues.slice(0,4)" :key="q.id" class="overview-queue"><span class="overview-icon sage"><Layers :size="17"/></span><div><strong>{{q.name}}</strong><small>{{q.agent_ids?.length||0}} atendente(s) · {{({inbound:'Receptivo',outbound:'Saída',mixed:'Entrada e saída'})[q.direction]}}</small><small v-if="q.direction!=='inbound'">Saída {{q.status==='running'?'habilitada':'pausada'}}</small><small v-if="q.direction!=='outbound'">Entrada {{q.inbound_enabled?'habilitada':'pausada'}}</small></div></div></section></div>
 <p class="overview-footnote" v-if="updated">Atualizado às {{updated.toLocaleTimeString('pt-BR')}}. Atualize para consultar mudanças da equipe.</p>
</div></template>

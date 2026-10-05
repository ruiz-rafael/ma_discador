<script setup>
import {ref,watch,onBeforeUnmount} from 'vue'
import {Search,RefreshCw,Link2} from 'lucide-vue-next'
const props=defineProps({modelValue:[String,Number],kind:String,label:String,channel:String,allowCustom:Boolean,builtin:{type:Array,default:()=>[]}})
const emit=defineEmits(['update:modelValue','select','catalog'])
const query=ref(''),items=ref([]),selected=ref(null),loading=ref(false),error=ref(''),page=ref(1),more=ref(false),total=ref(0)
let timer,serial=0,resolveSerial=0
async function load(append=false){const seq=++serial;loading.value=true;error.value='';try{const params=new URLSearchParams({kind:props.kind,q:query.value,page:String(append?page.value+1:1),...(props.channel?{channel:props.channel}:{})});const r=await fetch('/api/crm/catalog?'+params,{headers:{Accept:'application/json'}});if(!r.ok)throw new Error('Não foi possível consultar o catálogo.');const d=await r.json();if(seq!==serial)return;items.value=append?[...items.value,...d.data]:d.data;page.value=d.page;more.value=d.has_more;total.value=d.total||0}catch(e){if(seq===serial)error.value=e.message}finally{if(seq===serial)loading.value=false}}
async function resolve(){const seq=++resolveSerial;selected.value=null;if(!props.modelValue)return;try{const r=await fetch('/api/crm/catalog?'+new URLSearchParams({kind:props.kind,id:String(props.modelValue)}),{headers:{Accept:'application/json'}});if(!r.ok)return;const d=await r.json();if(seq===resolveSerial)selected.value=d.data[0]||null}catch{}}
watch(()=>[props.kind,props.channel],()=>{query.value='';load();resolve()},{immediate:true})
watch(()=>props.modelValue,resolve)
watch(query,()=>{clearTimeout(timer);timer=setTimeout(()=>load(),220)})
onBeforeUnmount(()=>{clearTimeout(timer);serial++;resolveSerial++})
function choose(value){const item=items.value.find(i=>i.external_id===value)||props.builtin.find(i=>i.external_id===value)||selected.value;emit('update:modelValue',value);emit('select',item?.external_id===value?item:null)}
</script>
<template>
 <div class="resource-picker">
  <div class="resource-search"><Search :size="14"/><input v-model="query" type="search" :aria-label="'Buscar '+label" :placeholder="'Buscar '+label.toLowerCase()+'…'"><button type="button" title="Atualizar catálogo" @click="load();resolve()"><RefreshCw :size="13"/></button></div>
  <select :aria-label="label" :value="modelValue??''" @change="choose($event.target.value)">
   <option value="">Selecione {{label.toLowerCase()}}</option>
   <optgroup v-if="builtin.length" label="Campos padrão"><option v-for="item in builtin" :key="item.external_id" :value="item.external_id">{{item.name}}</option></optgroup>
   <option v-if="modelValue&&!items.some(i=>i.external_id===String(modelValue))&&!builtin.some(i=>i.external_id===String(modelValue))" :value="modelValue">{{selected?.name||modelValue}}{{selected&&!selected.active?' · inativo':''}}</option>
   <option v-for="item in items" :key="item.external_id" :value="item.external_id">{{item.name}}{{item.origin==='preparation'?' · referência preparada':''}}</option>
  </select>
  <input v-if="allowCustom" :aria-label="'Chave manual: '+label" :value="modelValue??''" @input="choose($event.target.value)" placeholder="Ou informe a chave do campo / tag">
  <small v-if="loading" class="resource-status">Consultando catálogo…</small>
  <small v-else-if="error" class="field-error">{{error}}</small>
  <small v-else-if="selected&&!selected.active" class="field-error">A referência foi desativada. Escolha outra antes de validar.</small>
  <small v-else-if="!items.length" class="resource-status">{{query?'Nenhum resultado para esta busca.':'O CRM ainda não forneceu estas referências.'}}</small>
  <small v-if="selected" class="resource-status">ID: {{selected.external_id}} · {{selected.origin==='crm'?'Sincronizado pelo CRM':'Preparado para integração'}}</small>
  <div class="resource-actions"><button v-if="more" type="button" @click="load(true)" :disabled="loading">Carregar mais ({{items.length}}/{{total}})</button><button type="button" @click="emit('catalog',kind)"><Link2 :size="12"/> Gerenciar referências</button></div>
 </div>
</template>

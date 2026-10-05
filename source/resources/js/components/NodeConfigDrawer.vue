<script setup>
import {computed,ref,watch} from 'vue'
import {X,Check,Trash2,Plus,GitBranch,Link2} from 'lucide-vue-next'
import ResourcePicker from './ResourcePicker.vue'
const props=defineProps({node:Object,meta:Object,audiences:Array,journeys:Array,journeyId:[String,Number],busy:Boolean,error:String})
const emit=defineEmits(['update','save','close','delete','catalog'])
const settings=computed(()=>props.node.data.settings||{})
const schema=computed(()=>props.meta.schema||{fields:[]})
const fields=computed(()=>schema.value.fields.filter(f=>Object.entries(f.when||{}).every(([k,v])=>(settings.value[k]??schema.value.fields.find(x=>x.key===k)?.default)===v)))
const builtins=[{external_id:'name',name:'Nome'},{external_id:'email',name:'E-mail'},{external_id:'phone',name:'Telefone'},{external_id:'score',name:'Pontuação'},{external_id:'stage',name:'Estágio'},{external_id:'tags',name:'Tags'}]
const jsonDraft=ref({}),jsonErrors=ref({}),durationUnits=ref({})
watch(()=>props.node.id,()=>{jsonDraft.value={};jsonErrors.value={};durationUnits.value={}}, {immediate:true})
const value=f=>settings.value[f.key]??f.default??''
function update(key,v){const next={...settings.value,_config_version:2,[key]:v};for(const f of schema.value.fields)if(!(f.key in next)&&'default'in f)next[f.key]=f.default;
 if(key==='campaign_id'||key==='channel'){next.template='';next.parameters={};next._labels={...next._labels,template:''};}
 emit('update',next)}
function selected(f,item){if(!item)return;const next={...settings.value,_config_version:2,[f.key]:item.external_id,_labels:{...settings.value._labels,[f.key]:item.name}};
 if(f.key==='campaign_id'){next.template='';next.parameters={};next._labels.template='';}
 if(f.key==='template'){const m=item.metadata||{};if(m.parameters)next.parameters=Object.fromEntries(m.parameters.map(k=>[k,settings.value.parameters?.[k]||'']));if(m.buttons)next.buttons=m.buttons;if(m.language)next.language=m.language;if(m.subject)next.subject=m.subject;}
 emit('update',next)}
function durationValue(f){return Number(value(f)||0)/Number(durationUnits.value[f.key]||1)}
function setDuration(f,v){update(f.key,v===''?null:Number(v)*Number(durationUnits.value[f.key]||1))}
function setUnit(f,u){const amount=durationValue(f);durationUnits.value[f.key]=Number(u);update(f.key,amount*Number(u))}
function changeBranch(index,key,value){const branches=JSON.parse(JSON.stringify(settings.value.branches||[{field:'',operator:'eq',value:''},{field:'',operator:'eq',value:''}]));branches[index][key]=value;update('branches',branches)}
function pairs(){return Object.entries(settings.value.parameters||{})}
function changePair(index,part,v){const entries=pairs();entries[index][part]=v;update('parameters',Object.fromEntries(entries))}
function addPair(){let i=pairs().length+1;while(Object.hasOwn(settings.value.parameters||{},'parametro_'+i))i++;update('parameters',{...settings.value.parameters,['parametro_'+i]:''})}
function removePair(key){const p={...settings.value.parameters};delete p[key];update('parameters',p)}
function changeButton(index,v){const buttons=[...(settings.value.buttons||[])];buttons[index]=v;update('buttons',buttons)}
function jsonChange(f,raw){jsonDraft.value[f.key]=raw;try{const parsed=JSON.parse(raw||'{}');if(!parsed||typeof parsed!=='object'||Array.isArray(parsed))throw new Error();jsonErrors.value[f.key]='';update(f.key,parsed)}catch{jsonErrors.value[f.key]='Informe um objeto JSON válido.'}}
function save(){if(Object.values(jsonErrors.value).some(Boolean))return;emit('save')}
</script>
<template>
 <aside class="config-drawer node-config" aria-label="Configurações do nó">
  <div class="drawer-header"><div><span class="eyebrow">CONFIGURAÇÕES DO NÓ</span><h3>{{meta.label}}</h3></div><button title="Fechar configurações" @click="emit('close')"><X :size="19"/></button></div>
  <form class="node-config-form" @submit.prevent="save">
   <div class="drawer-body"><p class="node-description">{{schema.description}}</p><div v-if="schema.fields.some(f=>f.type==='resource')" class="crm-preparation-note"><Link2 :size="15"/><span>Prepare as referências agora. O CRM fornecerá os dados e os envios quando for conectado.</span></div>
    <div v-for="f in fields" :key="f.key" class="config-field">
     <label :for="'config-'+f.key">{{f.label}} <span v-if="f.required" class="required-star">*</span><span v-else class="optional">opcional</span></label>
     <ResourcePicker v-if="['resource','combo','field'].includes(f.type)" :key="node.id+f.key" :kind="f.resource||'field'" :label="f.label" :channel="f.channel||(f.channel_field?(settings[f.channel_field]||schema.fields.find(x=>x.key===f.channel_field)?.default):undefined)" :model-value="value(f)" :allow-custom="['combo','field'].includes(f.type)" :builtin="f.type==='field'&&!f.data_type?builtins:[]" @update:model-value="update(f.key,$event)" @select="selected(f,$event)" @catalog="emit('catalog',$event)"/>
     <select v-else-if="f.type==='select'" :id="'config-'+f.key" :value="value(f)" @change="update(f.key,$event.target.value)"><option value="">Selecione</option><option v-for="o in f.options" :value="o.value">{{o.label}}</option></select>
     <select v-else-if="f.type==='boolean'" :id="'config-'+f.key" :value="value(f)" @change="update(f.key,$event.target.value==='true')"><option value="">Selecione</option><option :value="true">Ativa</option><option :value="false">Cancelada</option></select>
     <select v-else-if="f.type==='local_list'" :id="'config-'+f.key" :value="value(f)" @change="update(f.key,$event.target.value?Number($event.target.value):null)"><option value="">Selecione a lista</option><option v-for="a in audiences" :value="a.id">{{a.name}}</option></select>
     <select v-else-if="f.type==='journey'" :id="'config-'+f.key" :value="value(f)" @change="update(f.key,$event.target.value?Number($event.target.value):null)"><option value="">Selecione a jornada</option><option v-for="j in journeys.filter(j=>j.id!==journeyId)" :value="j.id">{{j.title}}</option></select>
     <div v-else-if="f.type==='duration'" class="duration-input"><input :id="'config-'+f.key" type="number" min="1" :value="durationValue(f)||''" @input="setDuration(f,$event.target.value)"><select :aria-label="'Unidade: '+f.label" :value="durationUnits[f.key]||1" @change="setUnit(f,$event.target.value)"><option :value="1">Minutos</option><option :value="60">Horas</option><option :value="1440">Dias</option></select></div>
     <div v-else-if="f.type==='rules'" class="branch-rules"><article v-for="(branch,i) in settings.branches||[{field:'',operator:'eq',value:''},{field:'',operator:'eq',value:''}]" :key="i"><h4><GitBranch :size="13"/> Caminho {{i+1}}</h4><label :for="'branch-field-'+i">Campo</label><input :id="'branch-field-'+i" :value="branch.field" @input="changeBranch(i,'field',$event.target.value)" placeholder="Ex.: score"><label :for="'branch-op-'+i">Operador</label><select :id="'branch-op-'+i" :value="branch.operator" @change="changeBranch(i,'operator',$event.target.value)"><option value="eq">Igual a</option><option value="neq">Diferente de</option><option value="gt">Maior que</option><option value="lt">Menor que</option><option value="contains">Contém</option></select><label :for="'branch-value-'+i">Valor</label><input :id="'branch-value-'+i" :value="branch.value" @input="changeBranch(i,'value',$event.target.value)"></article><small>Outros: contatos que não atendem a nenhuma regra.</small></div>
     <div v-else-if="f.type==='pairs'" class="structured-list"><div v-for="([key,val],i) in pairs()" :key="i" class="parameter-row"><input :aria-label="'Nome do parâmetro '+(i+1)" :value="key" @change="changePair(i,0,$event.target.value)" placeholder="Parâmetro"><input :aria-label="'Valor do parâmetro '+(i+1)" :value="val" @input="changePair(i,1,$event.target.value)" placeholder="Valor ou campo"><button type="button" title="Remover parâmetro" @click="removePair(key)"><X :size="13"/></button></div><button type="button" class="add-config-row" @click="addPair"><Plus :size="13"/> Adicionar parâmetro</button></div>
     <div v-else-if="f.type==='buttons'" class="structured-list"><div v-for="(button,i) in settings.buttons||[]" :key="i" class="button-row"><input :aria-label="'Identificador do botão '+(i+1)" :value="button" @input="changeButton(i,$event.target.value)" placeholder="Ex.: quero_agendar"><button type="button" title="Remover botão" @click="update('buttons',settings.buttons.filter((_,index)=>index!==i))"><X :size="13"/></button></div><button type="button" class="add-config-row" :disabled="(settings.buttons||[]).length>=8" @click="update('buttons',[...(settings.buttons||[]),'botao_'+((settings.buttons||[]).length+1)])"><Plus :size="13"/> Adicionar saída de resposta</button></div>
     <template v-else-if="f.type==='json'"><textarea :id="'config-'+f.key" rows="5" spellcheck="false" :value="jsonDraft[f.key]??JSON.stringify(settings[f.key]||{},null,2)" @input="jsonChange(f,$event.target.value)"/><small v-if="jsonErrors[f.key]" class="field-error">{{jsonErrors[f.key]}}</small></template>
     <input v-else :id="'config-'+f.key" :type="f.type==='number'?'number':f.format==='email'?'email':'text'" :min="f.min" :max="f.max" :value="value(f)" @input="update(f.key,f.type==='number'?($event.target.value===''?null:Number($event.target.value)):$event.target.value)">
     <small v-if="f.help" class="config-hint">{{f.help}}</small>
    </div><p v-if="schema.help" class="config-explanation">{{schema.help}}</p><div v-if="error" class="error" role="alert">{{error}}</div>
   </div><div class="drawer-footer"><button type="button" class="danger-text" @click="emit('delete')"><Trash2 :size="15"/> Excluir nó</button><button class="primary" :disabled="busy||Object.values(jsonErrors).some(Boolean)"><Check :size="15"/> Salvar configuração</button></div>
  </form>
 </aside>
</template>

<script setup>
import {computed} from 'vue'
import {Plus,Trash2,ArrowUp,ArrowDown,MessageCircle,ExternalLink,Phone} from 'lucide-vue-next'
const props=defineProps({modelValue:{type:Array,default:()=>[]},disabled:Boolean})
const emit=defineEmits(['update:modelValue'])
const mode=computed(()=>!props.modelValue.length?'none':props.modelValue[0].type==='reply'?'reply':'action')
function set(rows){emit('update:modelValue',rows)}
function add(type){set([...props.modelValue,{type,title:'',...(type==='reply'?{action:'reply'}:type==='url'?{url:'https://'}:{phone:'+55'})}])}
function choose(value){if(value===mode.value)return;set(value==='none'?[]:[value==='reply'?{type:'reply',title:'',action:'reply'}:{type:'url',title:'',url:'https://'}])}
function update(i,field,value){set(props.modelValue.map((b,n)=>n===i?{...b,[field]:value}:b))}
function move(i,d){const rows=[...props.modelValue];[rows[i],rows[i+d]]=[rows[i+d],rows[i]];set(rows)}
function preset(){set([{type:'reply',title:'Tenho interesse',action:'reply'},{type:'reply',title:'Não receber ofertas',action:'opt_out'}])}
const count=type=>props.modelValue.filter(b=>b.type===type).length
</script>
<template>
 <fieldset class="template-buttons" :disabled="disabled">
  <legend>Botões da mensagem</legend>
  <p>Escolha como o cliente poderá responder. A prévia acompanha suas alterações.</p>
  <label>Tipo de botões<select :value="mode" @change="choose($event.target.value)"><option value="none">Sem botões</option><option value="reply">Respostas rápidas</option><option value="action">Link ou telefone</option></select></label>
  <button v-if="mode!=='action'" type="button" class="secondary preset" @click="preset"><MessageCircle :size="15"/>Usar interesse e descadastro</button>
  <article v-for="(b,i) in modelValue" :key="i" class="button-card">
   <header><strong><component :is="b.type==='reply'?MessageCircle:b.type==='url'?ExternalLink:Phone" :size="15"/>Botão {{i+1}} · {{b.type==='reply'?'Resposta':b.type==='url'?'Link':'Telefone'}}</strong><div class="button-tools"><button type="button" :disabled="i===0" :aria-label="'Mover botão '+(i+1)+' para cima'" @click="move(i,-1)"><ArrowUp :size="15"/></button><button type="button" :disabled="i===modelValue.length-1" :aria-label="'Mover botão '+(i+1)+' para baixo'" @click="move(i,1)"><ArrowDown :size="15"/></button><button type="button" :aria-label="'Remover botão '+(i+1)" @click="set(modelValue.filter((_,n)=>n!==i))"><Trash2 :size="15"/></button></div></header>
   <label>Texto do botão {{i+1}}<input :value="b.title" required maxlength="20" :placeholder="b.type==='reply'?'Ex.: Tenho interesse':b.type==='url'?'Ex.: Ver imóveis':'Ex.: Ligar para a Bossa'" @input="update(i,'title',$event.target.value)"><small>{{b.title.length}}/20 caracteres</small></label>
   <label v-if="b.type==='reply'">Ao tocar no botão {{i+1}}<select :value="b.action" @change="update(i,'action',$event.target.value)"><option value="reply">Registrar resposta na conversa</option><option value="opt_out">Interromper abordagens ao contato</option></select></label>
   <label v-if="b.type==='url'">Endereço do botão {{i+1}}<input type="url" :value="b.url" required pattern="https://.+" placeholder="https://www.suaempresa.com.br/imoveis" @input="update(i,'url',$event.target.value)"><small>Use um endereço HTTPS público e completo, sem variáveis.</small></label>
   <label v-if="b.type==='phone'">Telefone do botão {{i+1}}<input type="tel" :value="b.phone" required pattern="\+[1-9][0-9]{7,14}" placeholder="+551149330254" @input="update(i,'phone',$event.target.value)"><small>Inclua +, país, DDD e número. Abre uma ligação telefônica.</small></label>
   <p v-if="b.action==='opt_out'" class="optout-help">O pedido será registrado e novas abordagens aos cadastros desse telefone serão bloqueadas nesta empresa.</p>
  </article>
  <div class="add-buttons"><button v-if="mode==='reply'" type="button" class="secondary" :disabled="modelValue.length>=10" @click="add('reply')"><Plus :size="15"/>Adicionar resposta</button><template v-if="mode==='action'"><button type="button" class="secondary" :disabled="count('url')>=2" @click="add('url')"><Plus :size="15"/>Adicionar link</button><button type="button" class="secondary" :disabled="count('phone')>=1" @click="add('phone')"><Plus :size="15"/>Adicionar telefone</button></template></div>
  <p v-if="mode==='reply'" class="limit">Até dez respostas rápidas. O WhatsApp pode agrupar as opções adicionais.</p><p v-if="mode==='action'" class="limit">Até dois links e um telefone. O texto da mensagem pode ter até 640 caracteres.</p>
 </fieldset>
</template>
<style scoped>
.template-buttons{margin-top:24px!important;padding:20px!important;border:1px solid #dbe4d4!important;border-radius:14px;background:#f7f9f3;min-width:0}.template-buttons legend{padding:0 8px;font-weight:650;font-size:14px;color:#304c3d}.template-buttons p{font-size:12px;color:#637361;line-height:1.65;margin:0 0 14px}.template-buttons label{display:grid;gap:7px;font-size:12px;margin:14px 0}.template-buttons input,.template-buttons select{width:100%;min-width:0;box-sizing:border-box;border:1px solid #d2deca;border-radius:9px;padding:11px;background:#fff;font:inherit;color:#304c3d}.template-buttons small{font-size:11px;color:#6f7e68}.button-card{background:#fff;border:1px solid #dce5d6;border-radius:11px;padding:15px;margin-top:14px}.button-card header{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}.button-card strong{display:flex;align-items:center;gap:7px;font-size:12px}.button-tools{display:flex;gap:4px}.button-tools button{display:grid;place-items:center;padding:7px;border:1px solid #e1e7dc;border-radius:7px;background:#fff;color:#66765f;cursor:pointer}.button-tools button:disabled{opacity:.3;cursor:default}.add-buttons{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}.add-buttons button,.preset{display:inline-flex;align-items:center;gap:7px;font-size:12px}.optout-help{background:#f1f6ed;padding:10px;border-radius:8px}.limit{margin-top:12px!important}.template-buttons :focus-visible{outline:2px solid #b68b32;outline-offset:2px}@media(max-width:600px){.template-buttons{padding:14px!important}.button-card{padding:12px}.add-buttons button{flex:1}}
</style>

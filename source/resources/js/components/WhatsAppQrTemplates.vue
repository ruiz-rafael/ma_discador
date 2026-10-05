<script setup>
import {ref} from 'vue'
import {personalizationFields} from '../voice/personalization'
import PersonalizationPreview from './PersonalizationPreview.vue'
const props=defineProps({templates:Array,contacts:Array,busy:Boolean})
const emit=defineEmits(['create'])
const fresh=()=>({name:'',body:'Olá, {primeiro_nome}! Qual é o melhor horário para conversarmos?',buttons:[{type:'reply',id:'horario',label:'Combinar horário'},{type:'reply',id:'SAIR',label:'Não quero contato'}]})
const form=ref(fresh()),editing=ref(false)
function duplicate(t){form.value=JSON.parse(JSON.stringify({...t,name:t.name+' · nova versão'}));editing.value=true}
function add(){form.value.buttons.push({type:'reply',id:'opcao_'+crypto.randomUUID().slice(0,8),label:''})}
function save(){emit('create',{name:form.value.name,body:form.value.body,buttons:form.value.buttons.map(b=>({...b,...(b.type==='reply'?{url:null}:{})}))},()=>{form.value=fresh();editing.value=false})}
</script>
<template>
 <section class="table-card qr-templates">
  <div class="qr-title"><div><span class="eyebrow">MODELOS PARA QR CODE</span><h3>Mensagens com botões</h3></div><button type="button" class="secondary" @click="editing=!editing">{{editing?'Fechar editor':'Novo template QR'}}</button></div>
  <p>Personalize o texto com os dados do contato e ofereça até três opções de resposta. Os botões por QR Code são experimentais: confira a exibição no celular antes de usar na cadência.</p>
  <form v-if="editing" @submit.prevent="save">
   <label>Nome do template QR<input v-model="form.name" required maxlength="160" placeholder="Retorno após 5 tentativas"></label>
   <label>Mensagem do template QR<textarea v-model="form.body" required maxlength="3000" rows="4"/></label>
   <div class="qr-chips"><button v-for="(label,key) in personalizationFields" type="button" class="secondary" @click="form.body+='{'+key+'}'">{{'{'+key+'}'}} · {{label}}</button></div>
   <div v-for="(button,i) in form.buttons" :key="i" class="qr-button-row">
    <strong>Botão {{i+1}}</strong><label>Tipo<select v-model="button.type" :aria-label="'Tipo do botão '+(i+1)"><option value="reply">Enviar resposta</option><option value="url">Abrir link HTTPS</option></select></label>
    <label>Título<input v-model="button.label" :aria-label="'Título do botão '+(i+1)" required maxlength="20"></label>
    <label>Identificador da resposta<input v-model="button.id" :aria-label="'Identificador do botão '+(i+1)" required pattern="[a-zA-Z0-9_-]{1,64}" maxlength="64"></label>
    <label v-if="button.type==='url'">Link HTTPS<input v-model="button.url" :aria-label="'Link do botão '+(i+1)" required type="url" pattern="https://.*" maxlength="1000"></label>
    <button type="button" class="secondary" :disabled="form.buttons.length===1" :aria-label="'Remover botão '+(i+1)" @click="form.buttons.splice(i,1)">Remover</button>
   </div>
   <button type="button" class="secondary" :disabled="form.buttons.length>=3" @click="add">Adicionar botão</button>
   <p>Até 3 botões. O identificador <code>SAIR</code> registra pedido de interrupção. Respostas encerram a abordagem; abrir um link não envia resposta nem comprova clique. Títulos e links são fixos; o texto aceita variáveis.</p>
   <PersonalizationPreview :text="form.body" :contacts="contacts" campaign-name="Exemplo de cadência"/>
   <div class="qr-preview" aria-label="Prévia dos botões"><span v-for="(button,i) in form.buttons" :key="i">{{button.type==='url'?'↗ ':''}}{{button.label||'Botão '+(i+1)}}</span></div>
   <p>Salvar uma edição cria outra versão. As cadências mantêm o modelo anterior até você selecionar a nova versão.</p>
   <button class="primary" :disabled="busy">Salvar modelo QR</button>
  </form>
  <p v-if="!templates?.length">Nenhum template QR criado.</p>
  <article v-for="t in templates" :key="t.id" class="qr-saved"><strong>{{t.name}}</strong><p class="qr-body">{{t.body}}</p><div class="qr-preview"><span v-for="b in t.buttons" :key="b.id">{{b.type==='url'?'↗ ':''}}{{b.label}}</span></div><button type="button" class="secondary" :disabled="busy" @click="duplicate(t)">Duplicar e editar</button></article>
 </section>
</template>
<style scoped>
.qr-templates{padding:26px;margin-bottom:22px;border:1px solid #dfe3d8;border-radius:17px}.qr-templates h3{font-size:18px;margin:8px 0}.qr-templates button:focus-visible{outline:3px solid #be9134;outline-offset:3px}.qr-templates input:focus,.qr-templates select:focus,.qr-templates textarea:focus{outline:2px solid #c4994350;border-color:#bc9039}.qr-title{display:flex;align-items:center;justify-content:space-between;gap:16px}.qr-templates p{font-size:13px;line-height:1.6;color:#707966}.qr-templates label{display:flex;flex-direction:column;gap:8px;font-size:13px;margin:14px 0}.qr-templates input,.qr-templates select,.qr-templates textarea{width:100%;min-width:0;padding:10px;border:1px solid #daddd0;border-radius:8px;background:#fff}.qr-chips,.qr-preview{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.qr-preview span{padding:10px 16px;border:1px solid #d6c59a;border-radius:9px;color:#856519;background:#fffaf0;font-size:13px;overflow-wrap:anywhere}.qr-button-row{border:1px solid #e2e5da;background:#fafbf7;border-radius:12px;padding:16px;margin:16px 0}.qr-saved{border-top:1px solid #e2e5da;padding:20px 0}.qr-body{white-space:pre-wrap}@media(max-width:700px){.qr-templates{padding:16px}.qr-title{align-items:flex-start;flex-direction:column}}
</style>

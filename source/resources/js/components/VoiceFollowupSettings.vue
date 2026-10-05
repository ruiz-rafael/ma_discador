<script setup>
import {computed,watch} from 'vue'
import PersonalizationPreview from './PersonalizationPreview.vue'
import {personalizationFields} from '../voice/personalization'
const props=defineProps({settings:Object,senders:Array,templates:Array,qrTemplates:Array,references:Array,contacts:Array,campaignName:String})
const sender=computed(()=>props.senders?.find(s=>s.id===props.settings.whatsapp_sender_id))
const template=computed(()=>props.templates?.find(t=>t.id===props.settings.whatsapp_real_template_id))
watch(()=>sender.value?.provider,(next,old)=>{if(old==='qr'&&next!=='qr'){props.settings.whatsapp_qr_template_id=null;props.settings.whatsapp_qr_buttons_confirmed=false}})
const qrTemplate=computed(()=>props.qrTemplates?.find(t=>t.id===props.settings.whatsapp_qr_template_id))
function qrChanged(){props.settings.whatsapp_qr_buttons_confirmed=false}
const variables=computed(()=>[...new Set([...((template.value?.body||'').matchAll(/\{\{([1-9][0-9]*)\}\}/g))].map(m=>m[1]))])
const previewText=computed(()=>sender.value?.provider==='qr'?(qrTemplate.value?.body||props.settings.whatsapp_text):(template.value?.body||'').replace(/\{\{([1-9][0-9]*)\}\}/g,(_,key)=>props.settings.whatsapp_variables?.[key]||'[preencha a variável '+key+']'))
function changedTemplate(){props.settings.whatsapp_variables=Object.fromEntries(variables.value.map(k=>[k,'']))}
</script>
<template>
 <fieldset class="followup-settings">
  <legend>WhatsApp após não atendimento</legend>
  <label class="check-label"><input type="checkbox" v-model="settings.whatsapp_enabled">Enviar mensagem quando o cliente não atender</label>
  <template v-if="settings.whatsapp_enabled">
   <label>Modo de execução<select v-model="settings.whatsapp_delivery"><option value="simulation">Somente simular</option><option value="automatic">Enviar após chamadas reais da campanha</option></select></label>
   <div class="followup-grid"><label>{{settings.whatsapp_delivery==='automatic'?'Quantidade de não atendimentos (X)':'Não atendimentos simulados em 24 horas'}}<input type="number" v-model.number="settings.whatsapp_after" min="1" :max="settings.max_attempts" required></label><label>Espera antes da mensagem (minutos)<input type="number" v-model.number="settings.whatsapp_delay" min="0" max="1440" required></label></div>
   <template v-if="settings.whatsapp_delivery==='automatic'">
    <p>Conta apenas novas chamadas reais vinculadas à campanha e encerradas como “Não atendeu”. Ocupado, falha e cancelamento não somam ao X. O envio ocorre uma vez por contato nesta campanha, respeitando o horário configurado.</p>
    <p v-if="!sender" class="error">Selecione acima um remetente cadastrado para o número de WhatsApp escolhido.</p>
    <p v-else><strong>{{sender.provider==='qr'?'Conexão por QR Code':'API oficial Twilio'}}</strong> · {{sender.label}} · {{sender.number}} · {{sender.status}}</p>
    <template v-if="sender?.provider==='qr'"><label>Modelo da mensagem QR<select aria-label="Modelo da mensagem QR" :value="settings.whatsapp_qr_template_id||''" @change="settings.whatsapp_qr_template_id=$event.target.value||null;qrChanged()"><option value="">Texto sem botões</option><option v-for="t in qrTemplates||[]" :key="t.id" :value="t.id">{{t.name}} · botões experimentais</option></select></label><template v-if="qrTemplate"><p class="preview">{{qrTemplate.body}}</p><div class="qr-buttons"><span v-for="b in qrTemplate.buttons" :key="b.id">{{b.type==='url'?'↗ ':''}}{{b.label}}</span></div><label class="check-label"><input type="checkbox" v-model="settings.whatsapp_qr_buttons_confirmed" required>Usar botões experimentais nesta cadência</label><small>A exibição depende do WhatsApp do destinatário e precisa de teste real. Crie ou duplique modelos em Conexões e templates. Respostas interrompem a abordagem; abrir link não é uma resposta.</small></template></template>
    <label v-if="sender?.provider==='qr'&&!qrTemplate">Mensagem para o cliente<textarea v-model="settings.whatsapp_text" required maxlength="4000" rows="4" placeholder="Olá, {nome}! Tentamos falar com você por telefone. Qual seria um bom horário para conversarmos?"/><small>Use os campos abaixo para identificar cada cliente. Conecte o número pela aba WhatsApp.</small></label>
    <div v-if="sender?.provider==='qr'&&!qrTemplate" class="variable-chips"><button v-for="(label,key) in personalizationFields" type="button" @click="settings.whatsapp_text=(settings.whatsapp_text||'')+'{'+key+'}'">{{'{'+key+'}'}} · {{label}}</button></div>
    <template v-if="sender&&sender.provider!=='qr'">
     <label>Template Twilio<select v-model="settings.whatsapp_real_template_id" required @change="changedTemplate"><option :value="null">Selecione</option><option v-for="t in templates" :key="t.id" :value="t.id">{{t.name}} · {{t.approval_status}}</option></select></label>
     <p v-if="template" class="preview">{{template.body}}</p>
     <label v-for="key in variables" :key="key">Valor da variável {{key}}<input v-model="settings.whatsapp_variables[key]" required maxlength="500" placeholder="Texto, {nome} ou {telefone}"><span class="variable-chips"><button v-for="(label,field) in personalizationFields" type="button" :aria-label="'Inserir '+field+' na variável '+key" @click="settings.whatsapp_variables[key]=(settings.whatsapp_variables[key]||'')+'{'+field+'}'">{{'{'+field+'}'}}</button></span></label>
     <small>Crie o template na aba WhatsApp. O envio exige aprovação e remetente conectado na Twilio.</small>
    </template>
    <PersonalizationPreview v-if="sender" :text="previewText" :contacts="contacts" :campaign-name="campaignName"/>
    <p>Atendimento por voz, resposta no WhatsApp ou pedido de interrupção impedem a mensagem. Alterar a regra de mensagem cancela passos ainda pendentes. Um envio sem confirmação não será repetido automaticamente.</p>
   </template>
   <label v-else>Template de WhatsApp<select aria-label="Template de WhatsApp" v-model="settings.whatsapp_template_id" required><option value="">Selecione um template</option><option v-for="r in references" :key="r.id" :value="r.external_id">{{r.name}}</option></select></label>
  </template>
 </fieldset>
</template>
<style scoped>
.qr-buttons{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0}.qr-buttons span{padding:8px;border:1px solid #d6c59a;border-radius:8px;color:#856519;font-size:12px}.variable-chips{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}.variable-chips button{font-size:11px;background:#f4f1e9;color:#6f551e;border:1px solid #e1d5b9;padding:6px 9px;border-radius:7px}.followup-settings{padding:18px!important}.followup-settings label:not(.check-label){display:flex;flex-direction:column;gap:7px;font-size:13px;margin:15px 0}.followup-settings select{padding:11px;border:1px solid #ddd5e7;border-radius:8px;background:white}.followup-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.followup-settings p,.followup-settings small{font-size:12px;line-height:1.6;color:#776e80}.preview{white-space:pre-wrap;background:#f7f3fa;padding:12px}@media(max-width:700px){.followup-grid{grid-template-columns:1fr}}
</style>

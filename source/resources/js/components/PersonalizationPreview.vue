<script setup>
import {computed,ref} from 'vue'
import {MessageCircle,Sparkles} from 'lucide-vue-next'
import {personalize,exampleContact} from '../voice/personalization'
const props=defineProps({text:String,contacts:{type:Array,default:()=>[]},campaignName:String})
const selected=ref('example'),contact=computed(()=>props.contacts.find(c=>c.id===selected.value)||exampleContact)
const result=computed(()=>personalize(props.text,contact.value,props.campaignName||'Jornada Zyrex'))
</script>
<template><section class="personalization-preview"><header><span><Sparkles :size="15"/> Prévia personalizada</span><small>Nenhuma mensagem será enviada</small></header><label>Visualizar com os dados de<select v-model="selected"><option value="example">Ana Souza · dados de exemplo</option><option v-for="c in contacts" :key="c.id" :value="c.id">{{c.name}} · {{c.phone}}</option></select></label><div class="message-bubble"><MessageCircle :size="17"/><p>{{result||'Escreva a mensagem para visualizar.'}}</p></div><small>Os dados são preenchidos novamente para cada destinatário no momento do envio. Dados obrigatórios ausentes impedem o envio.</small></section></template>
<style scoped>
.personalization-preview{padding:18px;background:#f0f5f3;border:1px solid #dce8e1;border-radius:16px;margin-top:18px}.personalization-preview header{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;margin-bottom:14px}.personalization-preview header span{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:700;color:#28654e}.personalization-preview small{font-size:11px;line-height:1.6;color:#61776b;display:block}.personalization-preview label{font-size:12px;display:grid;gap:8px}.personalization-preview select{border-radius:9px;padding:10px;background:white;border:1px solid #cddfd4}.message-bubble{display:flex;gap:10px;padding:16px;background:white;border-radius:2px 14px 14px 14px;margin:14px 0;color:#254c3b;box-shadow:0 4px 15px #1a4f2f06}.message-bubble svg{flex-shrink:0;margin-top:3px}.message-bubble p{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.7;font-size:13px}
</style>

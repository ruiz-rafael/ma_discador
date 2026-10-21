<script setup>
import {MessageCircle,ExternalLink,Phone} from 'lucide-vue-next'
defineProps({text:{type:String,default:''},buttons:{type:Array,default:()=>[]}})
</script>
<template>
 <div class="official-preview" aria-label="Prévia do template">
  <div class="message-text">{{text||'Sua mensagem aparecerá aqui.'}}<small>Exemplo ilustrativo</small></div>
  <div v-if="buttons.length" class="preview-buttons" aria-label="Prévia dos botões"><div v-for="(b,i) in buttons" :key="i" class="preview-button"><component :is="b.type==='reply'?MessageCircle:b.type==='url'?ExternalLink:Phone" :size="15"/><span>{{b.title||'Texto do botão'}}</span></div></div>
 </div>
</template>
<style scoped>
.official-preview{border:1px solid #dfe6d9;border-radius:0 13px 13px 13px;background:#fff;box-shadow:0 3px 9px #29391c08;overflow:hidden;margin:18px 0;min-width:0}.message-text{white-space:pre-wrap;overflow-wrap:anywhere;font-size:14px;line-height:1.75;padding:18px}.message-text small{display:block;text-align:right;font-size:10px;color:#7c8975;margin-top:12px}.preview-buttons{border-top:1px solid #e6ece1}.preview-button{display:flex;align-items:center;justify-content:center;gap:9px;padding:13px;color:#326d58;font-size:13px;font-weight:600;text-align:center;overflow-wrap:anywhere}.preview-button+.preview-button{border-top:1px solid #edf1e8}.preview-button svg{flex-shrink:0}
</style>

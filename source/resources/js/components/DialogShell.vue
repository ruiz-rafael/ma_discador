<script setup>
import {ref,computed,onBeforeUnmount,onMounted} from 'vue'
import {X,ShieldCheck} from 'lucide-vue-next'
const props=defineProps({title:String,description:String,eyebrow:{type:String,default:'CONFIGURAÇÃO'},wide:Boolean,busy:Boolean,draft:{default:undefined}})
const emit=defineEmits(['close'])
const initial=JSON.stringify(props.draft),confirm=ref(false)
const dirty=computed(()=>props.draft!==undefined&&JSON.stringify(props.draft)!==initial)
function close(){if(props.busy)return;if(dirty.value){confirm.value=true;return}emit('close')}
function unload(e){if(dirty.value||props.busy){e.preventDefault();e.returnValue=''}}
onMounted(()=>window.addEventListener('beforeunload',unload))
onBeforeUnmount(()=>window.removeEventListener('beforeunload',unload))
defineExpose({close,dirty})
</script>
<template>
 <Teleport to="body"><div class="ds-backdrop"><section v-dialog class="ds-dialog" :class="{'ds-wide':wide}" :aria-label="title" :aria-busy="busy">
  <header class="ds-header"><div><span class="ds-eyebrow">{{eyebrow}}</span><h2>{{title}}</h2><p v-if="description">{{description}}</p></div><button type="button" class="ds-close" aria-label="Fechar configuração" :disabled="busy" @click="close"><X :size="19"/></button></header>
  <div class="ds-content"><fieldset class="ds-fields" :disabled="busy"><slot/></fieldset></div>
  <footer v-if="$slots.footer" class="ds-footer"><slot name="footer" :close="close" :dirty="dirty"/></footer>
 </section></div>
 <div v-if="confirm" class="ds-backdrop"><section v-dialog class="ds-dialog ds-confirm" aria-label="Alterações não salvas"><header class="ds-header"><div><span class="ds-eyebrow">ANTES DE SAIR</span><h2>Manter suas alterações?</h2><p>Você ainda não salvou esta configuração. Continue editando ou descarte as alterações para fechar.</p></div></header><div class="ds-footer"><button type="button" class="secondary" @click="confirm=false"><ShieldCheck :size="16"/>Continuar editando</button><button type="button" class="danger-text" :disabled="busy" @click="confirm=false;emit('close')">Descartar e fechar</button></div></section></div>
 </Teleport>
</template>

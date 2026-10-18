import {createApp} from 'vue'
import App from './App.vue'
import {dialogLayer} from './ui/dialog-layer'
import './style.css'
import './discai-theme.css'
import './workspace-experience.css'
import './dialogs.css'
import './visual-refinement.css'
if(document.querySelector('meta[name="ma-embed-origin"]'))import('./Embed.vue').then(({default:Embed})=>createApp(Embed).directive('dialog',dialogLayer).mount('#app'));else createApp(App).directive('dialog',dialogLayer).mount('#app')

import {createApp} from 'vue'
import App from './App.vue'
import './style.css'
import './zyrex-theme.css'
if(document.querySelector('meta[name="ma-embed-origin"]'))import('./Embed.vue').then(({default:Embed})=>createApp(Embed).mount('#app'));else createApp(App).mount('#app')

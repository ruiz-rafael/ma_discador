import {readFileSync} from 'node:fs'
import {createHmac,randomBytes} from 'node:crypto'
import WebSocket from 'ws'
const origin=process.env.DISCAI_ORIGIN||'http://web',key=readFileSync(process.env.NODE_ENV==='test'?process.env.DISCAI_TEST_KEY_FILE:'/run/secrets/ai-worker.key','utf8').trim(),active=new Map()
let stopping=false,polling=false
async function api(path,body={}){const raw=JSON.stringify(body),time=String(Math.floor(Date.now()/1000)),nonce=randomBytes(16).toString('hex');const signature=createHmac('sha256',key).update(time+'.'+nonce+'.'+path.slice(1)+'.'+raw).digest('hex');const r=await fetch(origin+path,{method:'POST',redirect:'error',signal:AbortSignal.timeout(12000),headers:{'Content-Type':'application/json',Accept:'application/json','X-DiscAI-Time':time,'X-DiscAI-Nonce':nonce,'X-DiscAI-Signature':signature},body:raw});if(!r.ok)throw Error('Internal API '+r.status);return r.json()}
function attach(s){if(active.has(s.id)||stopping)return;const ws=new WebSocket((process.env.NODE_ENV==='test'?process.env.DISCAI_TEST_WS:'wss://api.openai.com/v1/realtime')+'?call_id='+encodeURIComponent(s.call_id),{headers:{Authorization:'Bearer '+s.api_key},handshakeTimeout:10000,maxPayload:1024*1024});const state={ws,ended:false,timer:null,seen:new Set()};active.set(s.id,state);s.api_key=null
const event=d=>api('/internal/ai/voice/'+s.id+'/event',d)
async function finish(){if(state.ended)return;state.ended=true;clearTimeout(state.timer);active.delete(s.id);ws.close();try{await event({type:'ended'})}catch{console.error('AI voice end awaiting reconciliation')}}
state.timer=setTimeout(finish,Math.max(1000,(s.expires_at-Math.floor(Date.now()/1000))*1000))
ws.on('open',()=>{ws.send(JSON.stringify({type:'response.create',response:{instructions:'Cumprimente com esta saudação e aguarde: '+s.greeting}}))})
ws.on('message',async raw=>{try{const e=JSON.parse(raw.toString());if(e.type==='response.output_audio_transcript.done'&&e.transcript){await event({type:'transcript',event_id:e.item_id||e.event_id,text:e.transcript.slice(0,6000)})}
if(e.type==='response.function_call_arguments.done'&&!state.seen.has(e.call_id)){state.seen.add(e.call_id);if(e.name==='end_conversation'){await finish();return}const result=e.name==='request_human'?await event({type:'handoff'}):{ok:false,message:'Ação não autorizada.'};if(result.ended){state.ended=true;clearTimeout(state.timer);active.delete(s.id);ws.close();return}if(ws.readyState===WebSocket.OPEN){ws.send(JSON.stringify({type:'conversation.item.create',item:{type:'function_call_output',call_id:e.call_id,output:JSON.stringify(result)}}));ws.send(JSON.stringify({type:'response.create'}))}}
if(e.type==='error')await finish()
}catch{await finish()}})
ws.on('error',()=>finish());ws.on('close',()=>finish())}
async function poll(){if(polling||stopping)return;polling=true;try{const {sessions}=await api('/internal/ai/voice/poll');const wanted=new Set(sessions.map(s=>s.id));for(const [id,a]of active)if(!wanted.has(id)){a.ws.close()}for(const s of sessions)attach(s)}catch{console.error('AI voice control connection unavailable')}finally{polling=false}}
const interval=setInterval(poll,1000);poll();process.on('SIGTERM',()=>{stopping=true;clearInterval(interval);for(const {ws}of active.values())ws.close();setTimeout(()=>process.exit(0),13000).unref()})

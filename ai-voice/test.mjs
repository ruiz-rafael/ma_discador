import test from 'node:test'
import assert from 'node:assert/strict'
import {createServer} from 'node:http'
import {WebSocketServer} from 'ws'
import {spawn} from 'node:child_process'
import {mkdtempSync,writeFileSync,rmSync} from 'node:fs'
import {tmpdir} from 'node:os'
import {join} from 'node:path'
import {createHmac} from 'node:crypto'
test('SIP sideband: signed control, greeting, transcript, restricted tools and end', {timeout:15000}, async()=>{
 const events=[],clientEvents=[],key='k'.repeat(32),dir=mkdtempSync(join(tmpdir(),'discai-ai-'));writeFileSync(join(dir,'key'),key)
 let ended=false,authValid=true
 const api=createServer(async(req,res)=>{let raw='';for await(const b of req)raw+=b;const h=req.headers;const expected=createHmac('sha256',key).update(h['x-discai-time']+'.'+h['x-discai-nonce']+'.'+req.url.slice(1)+'.'+raw).digest('hex');authValid&&=expected===h['x-discai-signature'];res.setHeader('Content-Type','application/json');if(req.url.endsWith('/poll'))res.end(JSON.stringify({sessions:ended?[]:[{id:'fixture',call_id:'rtc_fixture',api_key:'fake-key',greeting:'Olá, sou uma assistente virtual.',expires_at:Math.floor(Date.now()/1000)+10}]}));else{const e=JSON.parse(raw);events.push(e);if(e.type==='ended')ended=true;res.end(JSON.stringify(e.type==='handoff'?{ok:false,message:'Sem agente disponível.'}:{ok:true}))}})
 await new Promise(r=>api.listen(0,'127.0.0.1',r));const wss=new WebSocketServer({port:0,host:'127.0.0.1'});await new Promise(r=>wss.on('listening',r));let providerAuth
 wss.on('connection',(ws,req)=>{providerAuth=req.headers.authorization;ws.on('message',m=>{const e=JSON.parse(m.toString());clientEvents.push(e);if(e.type==='response.create'&&clientEvents.length===1){ws.send(JSON.stringify({type:'response.output_audio_transcript.done',item_id:'item_1',transcript:'Olá!'}));ws.send(JSON.stringify({type:'response.function_call_arguments.done',name:'request_human',call_id:'tool_1',arguments:'{}'}))}if(e.type==='function_call_output'||e.item?.type==='function_call_output')setTimeout(()=>ws.send(JSON.stringify({type:'response.function_call_arguments.done',name:'end_conversation',call_id:'tool_2',arguments:'{}'})),50)})})
 const child=spawn(process.execPath,['server.mjs'],{env:{...process.env,NODE_ENV:'test',DISCAI_TEST_KEY_FILE:join(dir,'key'),DISCAI_TEST_WS:'ws://127.0.0.1:'+wss.address().port,DISCAI_ORIGIN:'http://127.0.0.1:'+api.address().port},stdio:'ignore'})
 try{for(let i=0;i<100&&!ended;i++)await new Promise(r=>setTimeout(r,75));assert.equal(ended,true);assert.equal(authValid,true);assert.equal(providerAuth,'Bearer fake-key');assert.ok(events.some(e=>e.type==='transcript'&&e.text==='Olá!'));assert.ok(events.some(e=>e.type==='handoff'));assert.ok(clientEvents.some(e=>e.item?.type==='function_call_output'&&e.item.output.includes('Sem agente')))}finally{child.kill('SIGKILL');for(const c of wss.clients)c.terminate();await new Promise(r=>wss.close(r));await new Promise(r=>api.close(r));rmSync(dir,{recursive:true,force:true})}
})

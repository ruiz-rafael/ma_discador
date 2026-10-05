import test from 'node:test'
import assert from 'node:assert/strict'
import {voiceFetch} from '../resources/js/voice/request.js'
test('transport failure is actionable, preserves ambiguity, and never retries a write',async t=>{
 for(const failure of [new TypeError('Failed to fetch'),Object.assign(new Error(),{name:'TimeoutError'})]){
  let calls=0;t.mock.method(globalThis,'fetch',async()=>{calls++;throw failure})
  await assert.rejects(voiceFetch('/test',{method:'POST',body:'{}'}),e=>e.name==='VoiceConnectionError'&&e.message.includes('aguarde a atualização'))
  assert.equal(calls,1);t.mock.restoreAll()
 }
})
test('HTTP rejection remains available for the server validation message',async t=>{
 const response=new Response(JSON.stringify({message:'Permissão de fila negada.'}),{status:422})
 t.mock.method(globalThis,'fetch',async()=>response)
 assert.equal(await voiceFetch('/test',{}),response)
 assert.equal((await response.json()).message,'Permissão de fila negada.')
})

import test from 'node:test'
import assert from 'node:assert/strict'
import {checkMicrophone,microphoneMessage} from '../resources/js/voice/microphone.js'
test('missing device, blocked permission and busy device have distinct actionable messages',async()=>{
 for(const [name,text]of [['NotFoundError','Nenhum microfone'],['NotAllowedError','bloqueado'],['NotReadableError','ocupado'],['OverconstrainedError','entrada padrão']])await assert.rejects(checkMicrophone({getUserMedia:async()=>{throw Object.assign(new Error('Audio error'),{name})}}),new RegExp(text))
 assert.match(microphoneMessage({name:'NotAllowedError'}),/bloqueado/)
 assert.match(microphoneMessage({name:'NotReadableError'}),/ocupado/)
 assert.match(microphoneMessage({message:'Requested device not found'}),/Nenhum microfone/)
})
test('preflight releases every track and never requires a provider or presence request',async()=>{let stopped=0;const track={label:'Headset QA',readyState:'live',stop(){stopped++}};const result=await checkMicrophone({getUserMedia:async c=>{assert.deepEqual(c,{audio:true,video:false});return {getAudioTracks:()=>[track],getTracks:()=>[track]}}});assert.equal(result.label,'Headset QA');assert.equal(stopped,1)})
test('empty or ended streams are rejected and their tracks released',async()=>{let stopped=0;const track={readyState:'ended',stop(){stopped++}};await assert.rejects(checkMicrophone({getUserMedia:async()=>({getAudioTracks:()=>[track],getTracks:()=>[track]})}),/Nenhum microfone/);assert.equal(stopped,1);await assert.rejects(checkMicrophone({}),/HTTPS/)})

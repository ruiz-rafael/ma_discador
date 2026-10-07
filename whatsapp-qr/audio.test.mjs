import test from 'node:test'
import assert from 'node:assert/strict'
import {audioPayload,transmitAudio} from './audio.mjs'
test('audio accepts only bounded local OGG/Opus bytes and preserves the reserved ID',async()=>{
 const b=Buffer.from('OggS'+'0'.repeat(32)+'OpusHead'+'0'.repeat(100));const a=audioPayload({base64:b.toString('base64')})
 assert.equal(a.hash.length,64);assert.deepEqual(a.buffer,b)
 const calls=[];await transmitAudio({sendMessage:async(...args)=>calls.push(args)},'qa@s.whatsapp.net',a,'ID_QA')
 assert.equal(calls.length,1);assert.equal(calls[0][1].ptt,true);assert.equal(calls[0][2].messageId,'ID_QA')
})
test('audio rejects URLs, malformed buffers, and oversized inputs',()=>{
 for(const v of [{url:'http://localhost'}, {base64:'!@#'}, {base64:Buffer.from('not audio').toString('base64')},{base64:'A'.repeat(1500004)}])assert.throws(()=>audioPayload(v))
})

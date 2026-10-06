import test from 'node:test'
import assert from 'node:assert/strict'
import {signupEvent} from '../resources/js/whatsapp-signup.mjs'
const event={origin:'https://www.facebook.com',data:JSON.stringify({type:'WA_EMBEDDED_SIGNUP',event:'FINISH',data:{waba_id:'123456',phone_number_id:'987654'}})}
test('only active signup accepts a validated Meta completion',()=>{assert.deepEqual(signupEvent(event,true),{event:'FINISH',waba_id:'123456'});assert.equal(signupEvent(event,false),null)})
test('rejects lookalike origins, malformed and unrelated messages',()=>{for(const origin of ['https://evilfacebook.com','https://facebook.com.evil.test','http://www.facebook.com','null'])assert.equal(signupEvent({...event,origin},true),null);for(const data of ['bad',{},null,JSON.stringify({type:'WA_EMBEDDED_SIGNUP',event:'FINISH',data:{waba_id:'123456'}})])assert.equal(signupEvent({...event,data},true),null)})
test('cancellation and provider errors do not expose provider content',()=>{for(const type of ['CANCEL','ERROR'])assert.deepEqual(signupEvent({...event,data:{type:'WA_EMBEDDED_SIGNUP',event:type,data:{error_message:'private'}}},true),{event:type})})

import test from 'node:test'
import assert from 'node:assert/strict'
import {singleFlight,acquireReceiverLease} from '../../resources/js/voice/receiver-lease.js'
test('concurrent Online actions share one registration and a failed action can retry',async()=>{
 let calls=0,finish
 const start=singleFlight(()=>{calls++;return new Promise(resolve=>finish=resolve)})
 const a=start(),b=start();assert.equal(a,b);await Promise.resolve();assert.equal(calls,1);finish('ready');assert.equal(await a,'ready')
 const next=start();await Promise.resolve();assert.equal(calls,2);finish('again');await next
 const failed=singleFlight(()=>{throw Error('unavailable')});await assert.rejects(failed());await assert.rejects(failed())
})
test('expired lease retries once with a visible countdown',async()=>{
 let calls=0;const progress=[]
 const result=await acquireReceiverLease(async()=>{if(++calls===1)throw Object.assign(Error('leased'),{code:'inbound_session_lease',retryAfter:2});return 'ready'},{sleep:async()=>{},onWait:n=>progress.push(n)})
 assert.equal(result,'ready');assert.deepEqual(progress,[2,1,0]);assert.equal(calls,2)
})
test('an active call is never retried or replaced',async()=>{
 let calls=0
 await assert.rejects(acquireReceiverLease(async()=>{calls++;throw Object.assign(Error('call'),{code:'inbound_session_busy',retryAfter:null})},{sleep:()=>assert.fail('must not wait')}))
 assert.equal(calls,1)
})
test('closing page cancels retry; a continuously active other tab is not taken over',async()=>{
 let calls=0,closed=false
 const request=async()=>{calls++;throw Object.assign(Error('leased'),{code:'inbound_session_lease',retryAfter:2})}
 await assert.rejects(acquireReceiverLease(request,{disposed:()=>closed,sleep:async()=>{closed=true}}));assert.equal(calls,1)
 calls=0;await assert.rejects(acquireReceiverLease(request,{sleep:async()=>{}}));assert.equal(calls,2)
})

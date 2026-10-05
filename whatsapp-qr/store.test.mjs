import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,readFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {Store,phone,authorized} from './store.mjs';
test('encrypted atomic persistence survives reload and fails closed with a wrong key',async()=>{
 const dir=await mkdtemp(tmpdir()+'/ma-qr-');try{
 const s=new Store(dir,'a'.repeat(64));await Promise.all([s.write('session',{token:'private-secret',n:1}),s.write('session',{token:'private-secret',n:2})]);
 assert.equal((await s.read('session')).n,2);assert.equal((await readFile(dir+'/session.enc')).includes(Buffer.from('private-secret')),false);
 await assert.rejects(()=>new Store(dir,'b'.repeat(64)).read('session'));
 }finally{await rm(dir,{recursive:true,force:true});}
});
test('phone identity excludes groups, LIDs and malformed identities',()=>{
 assert.equal(phone('5511999990000:3@s.whatsapp.net'),'+5511999990000');assert.equal(phone('123456789012@lid'),null);assert.equal(phone('5511999990000@g.us'),null);
 assert.equal(authorized(undefined,'secret'),false);assert.equal(authorized('Bearer wrong','secret'),false);assert.equal(authorized('Bearer secret','secret'),true);
});

test('QR session restores from persisted identity even without phone-code registration',async()=>{
 const {hasLinkedSession}=await import('./store.mjs');
 assert.equal(hasLinkedSession({registered:false,me:{id:'5511999990000@s.whatsapp.net'}}),true);
 assert.equal(hasLinkedSession({registered:true}),true);
 assert.equal(hasLinkedSession({registered:false}),false);
 assert.equal(hasLinkedSession({me:{id:''}}),false);
});

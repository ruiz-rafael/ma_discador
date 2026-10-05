import {test} from 'node:test';
import assert from 'node:assert/strict';
import {buttons,interactiveContent,inboundContent,transmit} from './interactive.mjs';

const choices = [{type:'reply',id:'horario',label:'Combinar horário'}, {type:'url',id:'site',label:'Conhecer a Zyrex',url:'https://zyrex.ia.br'}, {type:'reply',id:'SAIR',label:'Não quero contato'}];
test('validates choices and rejects ambiguous IDs, unsafe links and excess buttons', () => {
  assert.equal(buttons(choices).length,3);
  for (const value of [[],[...choices,choices[0]],[choices[0],choices[0]],[{...choices[1],url:'javascript:alert(1)'}],[{...choices[1],url:'https://user:pass@example.com'}],[{...choices[0],label:'{nome}'}]]) assert.throws(()=>buttons(value));
});
test('reply and link actions retain their distinct payloads and personal text', () => {
  const m=interactiveContent('Olá, Gustavo!',choices).documentWithCaptionMessage.message.interactiveMessage;
  assert.equal(m.body.text,'Olá, Gustavo!');
  assert.equal(m.nativeFlowMessage.buttons[0].name,'quick_reply');
  assert.deepEqual(JSON.parse(m.nativeFlowMessage.buttons[0].buttonParamsJson),{display_text:'Combinar horário',id:'horario'});
  assert.equal(JSON.parse(m.nativeFlowMessage.buttons[1].buttonParamsJson).url,'https://zyrex.ia.br');
});
test('text transport stays unchanged; failed interactive relay cannot send a fallback', async () => {
  let texts=0,relays=0;
  const socket={user:{id:'me@s.whatsapp.net'},sendMessage:async(...args)=>{texts++;return args},relayMessage:async(jid,msg,opt)=>{
    relays++;assert.equal(opt.messageId,'reserved-id');
    assert.deepEqual(opt.additionalNodes,[{tag:'biz',attrs:{},content:[{tag:'interactive',attrs:{type:'native_flow',v:'1'},content:[{tag:'native_flow',attrs:{v:'9',name:'mixed'}}]}]}]);
    assert.equal(opt.AI,undefined);
    assert.equal(msg.documentWithCaptionMessage.message.interactiveMessage.nativeFlowMessage.buttons.length,3);
    throw Error('timeout');
  }};
  assert.deepEqual(await transmit(socket,'peer','Olá','text-id',null),['peer',{text:'Olá'},{messageId:'text-id'}]);
  const generate=(to,message,opts)=>{assert.equal(opts.messageId,'reserved-id');return {message}};
  await assert.rejects(transmit(socket,'peer','Olá','reserved-id',{mode:'experimental_buttons',buttons:choices},generate),/timeout/);
  assert.equal(texts,1);assert.equal(relays,1);
});
test('native and legacy replies preserve ID and original message context', () => {
  const parsed=inboundContent({ephemeralMessage:{message:{interactiveResponseMessage:{body:{text:'Mais tarde'},contextInfo:{stanzaId:'original-id'},nativeFlowResponseMessage:{paramsJson:'{"id":"horario","display_text":"Mais tarde"}'}}}}});
  assert.deepEqual(parsed,{body:'Mais tarde',reply:{id:'horario',label:'Mais tarde',context_id:'original-id'}});
  assert.equal(inboundContent({buttonsResponseMessage:{selectedButtonId:'SAIR',selectedDisplayText:'Não quero contato'}}).reply.id,'SAIR');
  assert.equal(inboundContent({templateButtonReplyMessage:{selectedId:'x',selectedDisplayText:'Sim'}}).body,'Sim');
});
test('malformed or oversized response does not break ingestion; media still interrupts', () => {
  for (const raw of ['{bad','null','x'.repeat(9000)]) assert.deepEqual(inboundContent({interactiveResponseMessage:{nativeFlowResponseMessage:{paramsJson:raw}}}),{body:''});
  assert.deepEqual(inboundContent({imageMessage:{caption:'Minha resposta'}}),{body:'Minha resposta'});
  assert.deepEqual(inboundContent({audioMessage:{}}),{body:''});
});

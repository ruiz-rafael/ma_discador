import {test} from 'node:test';
import assert from 'node:assert/strict';
import {generateWAMessageFromContent,proto} from '@whiskeysockets/baileys';
import {interactiveContent} from './interactive.mjs';
test('pinned Baileys encodes the interactive envelope and preserves the reserved ID',()=>{
 const content=interactiveContent('Olá, Gustavo!',[{type:'reply',id:'horario',label:'Combinar horário'},{type:'url',id:'site',label:'Site',url:'https://zyrex.ia.br'}]);
 const msg=generateWAMessageFromContent('5511999990001@s.whatsapp.net',content,{userJid:'5511999990000@s.whatsapp.net',messageId:'FIXED-RESERVATION-ID'});
 assert.equal(msg.key.id,'FIXED-RESERVATION-ID');
 const decoded=proto.Message.decode(proto.Message.encode(msg.message).finish());
 assert.equal(decoded.documentWithCaptionMessage.message.interactiveMessage.body.text,'Olá, Gustavo!');
 assert.equal(decoded.documentWithCaptionMessage.message.interactiveMessage.nativeFlowMessage.buttons.length,2);
});

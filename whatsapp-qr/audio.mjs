import {createHash} from 'node:crypto'
export function audioPayload(value){
 if(!value||typeof value.base64!=='string'||value.base64.length>1500000||/[^A-Za-z0-9+/=]/.test(value.base64))throw Error('Áudio inválido.')
 const buffer=Buffer.from(value.base64,'base64')
 if(buffer.toString('base64')!==value.base64||buffer.length<64||buffer.subarray(0,4).toString()!=='OggS'||!buffer.subarray(0,512).includes(Buffer.from('OpusHead')))throw Error('Use um áudio OGG/Opus gerado pelo MA.')
 return {buffer,hash:createHash('sha256').update(buffer).digest('hex')}
}
export async function transmitAudio(socket,jid,audio,id){return socket.sendMessage(jid,{audio:audio.buffer,mimetype:'audio/ogg; codecs=opus',ptt:true},{messageId:id})}

import http from 'node:http';
import {readFile} from 'node:fs/promises';
import {randomUUID} from 'node:crypto';
import makeWASocket, {BufferJSON, initAuthCreds, proto, DisconnectReason, generateWAMessageFromContent} from '@whiskeysockets/baileys';
import pino from 'pino';
import QRCode from 'qrcode';
import {Store,phone,authorized,hasLinkedSession} from './store.mjs';
import {buttons,inboundContent,transmit} from './interactive.mjs';
process.umask(0o077);
const cfg=JSON.parse(await readFile(process.env.CONFIG_PATH||'/run/qr-config.json','utf8'));
if(!/^[a-f0-9]{64}$/.test(cfg.key)||!cfg.token||cfg.token.length<32)throw new Error('Invalid private configuration');
const store=new Store(process.env.DATA_PATH||'/data',cfg.key,BufferJSON.replacer,BufferJSON.reviver);
const sessions=new Map(), logger=pino({level:'silent'});
let catalog=await store.read('catalog',{});
const validPhone=x=>typeof x==='string'&&/^\+[1-9][0-9]{7,14}$/.test(x);
async function load(id) {
 if(sessions.has(id))return sessions.get(id);
 const saved=await store.read(`session-${id}`,{creds:initAuthCreds(),keys:{},messages:{},events:[]});
 const s={id, saved, socket:null, status:'disconnected',number:null,qr:null,qrAt:0,stopped:false,connecting:false,reconnect:null,sendQueue:Promise.resolve(),eventQueue:Promise.resolve()}; sessions.set(id,s);return s;
}
const persist=s=>store.write(`session-${s.id}`,s.saved);
async function event(s,e) { const event_id=randomUUID();s.saved.events.push({...e,event_id});await persist(s); }
async function connect(s) {
 if(s.socket||s.connecting)return;
 if([...sessions.values()].filter(x=>x.socket).length>=5)throw new Error('Limite de cinco sessões atingido.');
 s.connecting=true;s.stopped=false;s.status='connecting';s.qr=null;
 try {
 const socket=makeWASocket({logger,auth:{creds:s.saved.creds,keys:{
  get:async(type,ids)=>Object.fromEntries(ids.map(id=>{let value=s.saved.keys[`${type}:${id}`];if(type==='app-state-sync-key'&&value)value=proto.Message.AppStateSyncKeyData.fromObject(value);return[id,value];})),
  set:async(data)=>{for(const[type,entries]of Object.entries(data))for(const[id,value]of Object.entries(entries)){if(value)s.saved.keys[`${type}:${id}`]=value;else delete s.saved.keys[`${type}:${id}`];}await persist(s);}
 }},syncFullHistory:false,shouldSyncHistoryMessage:()=>false,markOnlineOnConnect:false,browser:['Zyrex MA','Chrome','1.0.0']});
 s.socket=socket;
 socket.ev.on('creds.update',()=>{persist(s).catch(()=>{s.status='storage_error';socket.end(new Error('storage_error'));});});
 const safe=fn=>e=>{s.eventQueue=s.eventQueue.then(()=>fn(e)).catch(()=>{s.status='event_error';});};
 socket.ev.on('connection.update',safe(async u=>{
  if(s.socket!==socket)return;
  if(u.qr){s.status='qr';s.qr=await QRCode.toDataURL(u.qr,{width:300,margin:2});s.qrAt=Date.now();}
  if(u.connection==='open'){
   s.number=phone(socket.user?.id);s.qr=null;
   if(s.number!==catalog[s.id]?.number){s.status='number_mismatch';s.stopped=true;await socket.logout().catch(()=>{});s.saved.creds=initAuthCreds();s.saved.keys={};await persist(s);}
   else s.status='connected';
  }
  if(u.connection==='close'){
   s.socket=null;s.qr=null;s.connecting=false;
   const code=u.lastDisconnect?.error?.output?.statusCode;
   if(code===DisconnectReason.loggedOut){s.status='logged_out';s.saved.creds=initAuthCreds();s.saved.keys={};await persist(s);}
   else if(!s.stopped){s.status='disconnected';clearTimeout(s.reconnect);s.reconnect=setTimeout(()=>connect(s).catch(()=>{s.status='error';}),5000);}
  }
 }));
 socket.ev.on('messages.upsert',safe(async u=>{
  if(u.type!=='notify')return;
  for(const m of u.messages){
   if(m.key.fromMe||!m.key.id||s.status!=='connected')continue;
   let jid=m.key.remoteJidAlt||m.key.remoteJid;
   if(jid?.endsWith('@lid'))jid=await socket.signalRepository.lidMapping.getPNForLID(jid);
   const from=phone(jid); if(!from)continue;
   const content=m.message;
   const parsed=inboundContent(content);
   // Media replies also interrupt a cadence even without text. Do not import history.
   if(content)await event(s,{kind:'inbound',id:m.key.id,from,...parsed});
  }
 }));
 socket.ev.on('messages.update',safe(async updates=>{
  for(const {key,update} of updates){
   const found=Object.entries(s.saved.messages).find(([,m])=>m.reference===key.id);if(!found)continue;
   const [id,m]=found;const status=({2:'sent',3:'delivered',4:'read',5:'read'})[update.status];
   if(status&&({unknown:0,sending:0,sent:1,delivered:2,read:3})[status]>=({unknown:0,sending:0,sent:1,delivered:2,read:3})[m.status]){
    m.status=status;await event(s,{kind:'status',message_id:id,reference:m.reference,status});
   }
  }
 }));
 }finally{s.connecting=false;}
}
function info(s){return{capabilities:{experimental_buttons:true},status:s.status,number:s.number,qr:s.status==='qr'&&Date.now()-s.qrAt<55000?s.qr:null};}
async function send(s,b){
 if(!/^[a-f0-9-]{36}$/.test(b.id)||!validPhone(b.to)||typeof b.text!=='string'||!b.text.trim()||b.text.length>6000)throw new Error('Mensagem inválida.');
 const data=[b.to,b.text,b.number];
 if(b.interactive){if(b.interactive.mode!=='experimental_buttons')throw new Error('Invalid mode');b.interactive={mode:'experimental_buttons',buttons:buttons(b.interactive.buttons)};data.push(b.interactive);}
 const hash=JSON.stringify(data);
 const old=s.saved.messages[b.id];if(old){if(old.hash!==hash)throw new Error('Identificação já utilizada.');return old;}
 if(s.status!=='connected'||s.number!==b.number||s.number!==catalog[s.id]?.number)throw new Error('Número não conectado.');
 if(s.saved.events.some(e=>e.kind==='inbound'&&e.from===b.to))throw new Error('Resposta pendente de processamento.');
 if(!(cfg.allowed_recipients||[]).includes(b.to))throw new Error('Destino não autorizado para homologação.');
 const day=new Date().toISOString().slice(0,10);
 let total=0;for(const id of Object.keys(catalog)){const other=await load(id);total+=Object.values(other.saved.messages).filter(m=>m.day===day).length;}
 if(total>=(cfg.daily_limit||10))throw new Error('Limite diário atingido.');
 // Durable reservation before socket I/O. Restart or HTTP timeout cannot resend it.
 const m={status:'unknown',hash,day,reference:randomUUID().replaceAll('-','').toUpperCase()};s.saved.messages[b.id]=m;await persist(s);
 try{await transmit(s.socket,b.to.slice(1)+'@s.whatsapp.net',b.text,m.reference,b.interactive,generateWAMessageFromContent);m.status='sent';await event(s,{kind:'status',message_id:b.id,reference:m.reference,status:'sent'});}
 catch{await persist(s);}
 return m;
}
async function body(req){let value='';for await(const chunk of req){value+=chunk;if(value.length>32768)throw new Error('Corpo excessivo.');}return value?JSON.parse(value):{};}
const server=http.createServer(async(req,res)=>{
 res.setHeader('Content-Type','application/json');res.setHeader('Cache-Control','no-store');
 const reply=(code,data)=>{res.writeHead(code);res.end(JSON.stringify(data));};
 if(!authorized(req.headers.authorization,cfg.token))return reply(401,{message:'Unauthorized'});
 try{
  if(req.url==='/health'&&req.method==='GET')return reply(200,{ok:true});
  const match=/^\/sessions\/([1-9][0-9]{0,9})(?:\/(messages|events)(?:\/(ack))?)?$/.exec(req.url);if(!match)return reply(404,{});
  const [,id,resource,action]=match;const b=await body(req);const s=await load(id);
  if(!resource){
   if(req.method==='POST'){
    if(!validPhone(b.number))throw new Error('Número inválido.');
    if(catalog[id]&&catalog[id].number!==b.number)throw new Error('Número da sessão é imutável.');
    catalog[id]={number:b.number};await store.write('catalog',catalog);await connect(s);
   }else if(req.method==='DELETE'){
    s.stopped=true;clearTimeout(s.reconnect);if(s.socket)await s.socket.logout().catch(()=>{});s.socket=null;s.qr=null;s.number=null;s.status='disconnected';s.saved.creds=initAuthCreds();s.saved.keys={};await persist(s);delete catalog[id];await store.write('catalog',catalog);
   }else if(req.method!=='GET')return reply(405,{});
   return reply(200,info(s));
  }
  if(resource==='messages'&&req.method==='POST'){
   // Global serialization also protects the shared daily quota across sessions.
   const next=globalSend.then(()=>send(s,b));globalSend=next.catch(()=>{});const m=await next;
   return reply(200,{status:m.status,reference:m.reference});
  }
  if(resource==='events'&&req.method==='GET'&&!action)return reply(200,{events:s.saved.events.slice(0,100)});
  if(resource==='events'&&req.method==='POST'&&action==='ack'){
   if(!Array.isArray(b.ids)||b.ids.length>100)throw new Error('Ack inválido.');s.saved.events=s.saved.events.filter(e=>!b.ids.includes(e.event_id));await persist(s);return reply(200,{ok:true});
  }
  return reply(405,{});
 }catch(e){reply(409,{message:'Operação não confirmada.'});}
});
let globalSend=Promise.resolve();
server.listen(3000,'0.0.0.0');
for(const id of Object.keys(catalog)){const s=await load(id);if(hasLinkedSession(s.saved.creds))await connect(s).catch(()=>{s.status='error';});}

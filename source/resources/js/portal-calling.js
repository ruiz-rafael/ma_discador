import {checkMicrophone,microphoneMessage} from './voice/microphone'
import {callResult,activeAttempt,trustedGrant} from './voice/platform-result'
const $=id=>document.getElementById(id),dialog=$('admin-dialer'),audio=$('remote-audio')
let numbers=[],history=[],current=null,busy=false,polling=false,loading=false,device=null,twilioCall=null,ua=null,sipCall=null,deadline=null,generation=0,closing=false
function error(message=''){$('dial-error').textContent=message;$('dial-error').hidden=!message}
async function api(path='',method='GET',body){
 let response
 try{response=await fetch('/platform-calling'+path,{method,credentials:'same-origin',signal:AbortSignal.timeout(30000),headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},...(body?{body:JSON.stringify(body)}:{})})}catch{throw new Error('A conexão com a DiscAI foi interrompida. A tentativa será consultada antes de liberar outra ligação.')}
 if(response.redirected)throw new Error('Sua sessão expirou. Entre novamente na administração para consultar a chamada.');
 const data=await response.json().catch(()=>({}));if(!response.ok)throw Object.assign(new Error(response.status===429?'Muitas solicitações em pouco tempo. Aguarde um minuto antes de tentar novamente.':data.message||'Não foi possível concluir a operação. Atualize o resultado.'),{status:response.status});return data
}
function selected(){return numbers.find(n=>n.route===$('dial-origin').value)}
function render(){
 const active=current&&current.state!=='ended',result=callResult(current?.result)
 $('dial-state').textContent=busy&&!current?'Preparando microfone…':result.title;$('dial-detail').textContent=result.detail
 $('dial-state').parentElement.dataset.active=String(!!active)
 $('dial-fields').disabled=busy||!!active;$('dial-start').disabled=busy||!!active||!selected()?.ready;$('dial-start').hidden=!!active
 $('dial-stop').hidden=!active;$('dial-stop').disabled=busy||closing
 $('dial-check').hidden=!current;$('dial-reconcile').hidden=!active||current?.method!=='programmable_voice'
 $('open-dialer').textContent=active?'Acompanhar ligação':'Abrir discador'
 if(current){const idx=history.findIndex(h=>h.id===current.id);if(idx>=0)history[idx]=current;else history.unshift(current)}
 $('call-history').replaceChildren()
 if(!history.length){const p=document.createElement('p');p.textContent='As chamadas feitas por você neste discador aparecerão aqui.';$('call-history').append(p)}
 for(const item of history.slice(0,20)){
  const row=document.createElement('div');row.className='call-history-row'
  const a=document.createElement('div'),b=document.createElement('div'),title=document.createElement('strong'),from=document.createElement('p'),state=document.createElement('strong'),date=document.createElement('p'),button=document.createElement('button')
  title.textContent=item.destination;from.textContent='Saída: '+item.origin;state.textContent=callResult(item.result||{status:'pending'}).title
  date.textContent=item.created_at?new Date(item.created_at.replace(' ','T')+(/Z|[+-]\d\d:\d\d$/.test(item.created_at)?'':'Z')).toLocaleString('pt-BR'):''
  button.type='button';button.className='quiet';button.textContent='Ver resultado';button.disabled=!!active&&current.id!==item.id
  button.addEventListener('click',()=>{current=item;error();render();dialog.showModal();if(item.state!=='ended')refresh()})
  a.append(title,from);b.append(state,date);row.append(a,b,button);$('call-history').append(row)
 }
}
function originChanged(){
 const n=selected();$('origin-help').textContent=n?(n.ready?'Saída habilitada · '+n.company:n.reason||'Conexão indisponível.'):'Selecione a operadora e o número de saída.'
 $('test-destinations').replaceChildren();for(const phone of n?.destinations||[]){const o=document.createElement('option');o.value=phone;$('test-destinations').append(o)}render()
}
async function catalog(){
 if(loading)return;loading=true;$('refresh-numbers').disabled=true
 try{const data=await api('/catalog');const old=selected();numbers=data.numbers||[];history=data.calls||[];current=activeAttempt(history)||history.find(h=>h.id===current?.id)||current
  $('dial-origin').replaceChildren(new Option('Selecione um número',''));for(const n of numbers){const o=new Option(`${n.number} · ${n.operator} ${n.method==='sip_trunk'?'SIP':'API'} · ${n.company}${n.ready?'':' · indisponível'}`,n.route);o.disabled=!n.ready;$('dial-origin').append(o)}
  const chosen=current&&current.state!=='ended'?numbers.find(n=>n.number===current.origin&&n.method===current.method):numbers.find(n=>old&&n.number===old.number&&n.operator===old.operator&&n.method===old.method&&n.company===old.company);if(chosen)$('dial-origin').value=chosen.route;if(current&&current.state!=='ended')$('dial-destination').value=current.destination
  const count=numbers.filter(n=>n.ready).length;$('number-count').textContent=`${count} ${count===1?'número habilitado':'números habilitados'}`;$('catalog-message').textContent=(data.errors||[]).join(' ')||(!numbers.length?'Nenhum número configurado. Cadastre ou libere uma conexão em Telefonia e operadoras.':'')
  originChanged();if(current&&current.state!=='ended')await refresh()
 }catch(e){$('catalog-message').textContent=e.message}finally{loading=false;$('refresh-numbers').disabled=false;render()}
}
async function refresh(action='status'){
 if(!current||polling)return;polling=true;const id=current.id
 try{const d=await api('/calls/'+id+(action==='status'?'':'/'+action),action==='status'?'GET':'POST');if(current?.id!==id)return
  current.result=d.call;current.state=d.call.ended?'ended':'active';if(d.call.ended)await cleanup();render()
 }catch(e){error(e.message);if(e.status===404&&current?.id===id){current.state='ended';current.result={status:'failed',ended:true,message:'A tentativa não foi registrada. Nenhuma discagem foi iniciada pelo navegador.'};render()}}finally{polling=false}
}
async function cleanup(){
 if(closing)return;closing=true;generation++;clearTimeout(deadline)
 const oldDevice=device,oldTwilio=twilioCall,oldUa=ua,oldSip=sipCall;device=twilioCall=ua=sipCall=null
 try{oldTwilio?.disconnect();oldDevice?.destroy();if(oldSip?.state==='Established')await oldSip.bye();else if(oldSip&&['Initial','Establishing'].includes(oldSip.state))await oldSip.cancel()}catch{}
 const pc=oldSip?.sessionDescriptionHandler?.peerConnection;pc?.getSenders().forEach(s=>s.track?.stop());pc?.close();oldUa?.stop().catch(()=>{})
 audio.srcObject?.getTracks().forEach(t=>t.stop());audio.srcObject=null;$('dial-audio').hidden=true;closing=false
}
async function stop(){if(busy||closing)return;await cleanup();await refresh('cancel');render()}
async function ended(message){if(closing)return;if(message)error(message);await cleanup();await refresh('cancel');render()}
async function media(grant,epoch){
 const valid=()=>epoch===generation
 deadline=setTimeout(()=>{if(valid()){error('O limite de duração foi atingido. Aguarde a confirmação do resultado.');stop()}},(grant.max_seconds+grant.ring_seconds+20)*1000)
 if(grant.method==='programmable_voice'){
  const {Device}=await import('@twilio/voice-sdk');if(!valid())return
  device=new Device(grant.access_token,{edge:grant.edge,logLevel:'silent',closeProtection:true});device.on('incoming',i=>i.reject());device.on('error',()=>{if(valid())ended('Não foi possível manter o áudio com a operadora. Consulte o resultado abaixo.')})
  const call=await device.connect({params:grant.params});if(!valid()){call.disconnect();return}twilioCall=call
  call.on('accept',()=>{if(valid())refresh()});for(const event of ['disconnect','cancel'])call.on(event,()=>{if(valid())ended()});call.on('error',()=>{if(valid())ended('A conexão de áudio falhou. Aguardando o retorno da telefonia.')});return
 }
 const {UserAgent,Inviter,SessionState}=await import('sip.js');if(!valid())return
 ua=new UserAgent({uri:UserAgent.makeURI(grant.aor),authorizationUsername:grant.username,authorizationPassword:grant.password,transportOptions:{server:grant.server},logBuiltinEnabled:false,logConfiguration:false,sessionDescriptionHandlerFactoryOptions:{peerConnectionConfiguration:{iceServers:[]}},delegate:{onInvite:i=>i.reject(),onDisconnect:()=>{if(valid())ended('A conexão de áudio foi interrompida. Aguardando confirmação da telefonia.')}}})
 await ua.start();if(!valid())return
 sipCall=new Inviter(ua,UserAgent.makeURI(grant.destination),{sessionDescriptionHandlerOptions:{constraints:{audio:true,video:false}}});const call=sipCall
 call.stateChange.addListener(async state=>{
  if(!valid())return
  if(state===SessionState.Established){const stream=new MediaStream();call.sessionDescriptionHandler?.peerConnection?.getReceivers().forEach(r=>{if(r.track?.kind==='audio')stream.addTrack(r.track)});audio.srcObject=stream;try{await audio.play()}catch{$('dial-audio').hidden=false}refresh()}
  if(state===SessionState.Terminated)ended()
 });await call.invite()
}
async function begin(event){
 event.preventDefault();if(busy||current&&current.state!=='ended'||!selected()?.ready)return
 if(!$('dial-form').reportValidity())return
 busy=true;current=null;error();render();let id=null
 try{
  await checkMicrophone();const n=selected();id=crypto.randomUUID();current={id,method:n.method,origin:n.number,destination:$('dial-destination').value,state:'creating',created_at:new Date().toISOString(),result:{status:'pending',ended:false}};render()
  const d=await api('/calls','POST',{request_id:id,route:n.route,destination:current.destination,consent_confirmed:true,consent_evidence:$('dial-evidence').value});if(!trustedGrant(d.grant))throw new Error('A conexão retornou uma configuração de áudio inválida. Consulte a tentativa.')
  current.method=d.grant.method;current.state='active';busy=false;render();const epoch=++generation;await media(d.grant,epoch)
 }catch(e){const message=microphoneMessage(e);error(message);if(id){await cleanup();await refresh('cancel');error(message)}}finally{busy=false;render()}
}
$('open-dialer').addEventListener('click',()=>dialog.showModal());$('close-dialer').addEventListener('click',()=>dialog.close());dialog.addEventListener('cancel',e=>e.preventDefault())
$('refresh-numbers').addEventListener('click',catalog);$('dial-origin').addEventListener('change',originChanged);$('dial-form').addEventListener('submit',begin)
// Required evidence must be visible for native validation.
$('dial-evidence').addEventListener('invalid',()=>{$('dial-evidence').closest('details').open=true})
$('dial-stop').addEventListener('click',stop);$('dial-check').addEventListener('click',()=>refresh());$('dial-reconcile').addEventListener('click',()=>refresh('reconcile'))
$('dial-audio').addEventListener('click',async()=>{try{await audio.play();$('dial-audio').hidden=true}catch{error('Permita a reprodução de áudio nas configurações do navegador.')}})
window.addEventListener('beforeunload',e=>{if(busy||current&&current.state!=='ended'){e.preventDefault();e.returnValue=''}})
setInterval(()=>{if(!busy&&current&&current.state!=='ended')refresh()},4000)
catalog()

/** SIP receiver with an incoming-only PBX account. Never places outbound calls. */
export async function createSipReceiver(grant,{incoming,disconnected,audioBlocked}){
 const {UserAgent,Registerer,RegistererState,SessionState}=await import('sip.js')
 let stopped=false,registered=false,active=null,registerer=null,ringTimer=null
 const AudioContext=window.AudioContext||window.webkitAudioContext
 const ringer=AudioContext?new AudioContext():null
 const ring=()=>{if(!ringer||stopped||!active||active.state===SessionState.Established)return;if(ringer.state!=='running'){audioBlocked(true);return}const oscillator=ringer.createOscillator(),gain=ringer.createGain();oscillator.frequency.value=425;gain.gain.value=.045;oscillator.connect(gain);gain.connect(ringer.destination);oscillator.start();oscillator.stop(ringer.currentTime+.8)}
 const stopRing=()=>{clearInterval(ringTimer);ringTimer=null}
 const audio=new Audio();audio.autoplay=true
 const ua=new UserAgent({uri:UserAgent.makeURI(grant.aor),authorizationUsername:grant.username,authorizationPassword:grant.password,
  transportOptions:{server:grant.server},logBuiltinEnabled:false,logConfiguration:false,
  sessionDescriptionHandlerFactoryOptions:{peerConnectionConfiguration:{iceServers:[]}},
  delegate:{onDisconnect:()=>{registered=false;if(!stopped)disconnected()},onInvite:invite=>{
   if(stopped||active){invite.reject();return}
   active=invite;ring();ringTimer=setInterval(ring,4000);const listeners={};let ended=false
   const emit=e=>(listeners[e]||[]).forEach(fn=>fn())
   const call={parameters:{From:invite.remoteIdentity.uri.user||'Número não identificado'},on:(e,fn)=>{(listeners[e]??=[]).push(fn)},
    accept:()=>invite.accept({sessionDescriptionHandlerOptions:{constraints:{audio:true,video:false}}}).catch(()=>{emit('error');invite.dispose()}),
    reject:()=>invite.reject().catch(()=>{}),disconnect:()=>invite.state===SessionState.Established?invite.bye().catch(()=>{}):invite.reject().catch(()=>{})}
   invite.stateChange.addListener(async state=>{
    if(state===SessionState.Established){stopRing();const stream=new MediaStream();invite.sessionDescriptionHandler?.peerConnection?.getReceivers().forEach(r=>{if(r.track?.kind==='audio')stream.addTrack(r.track)});audio.srcObject=stream;emit('accept');try{await audio.play()}catch{audioBlocked(true)}}
    if(state===SessionState.Terminated&&!ended){ended=true;stopRing();active=null;audio.pause();audio.srcObject=null;audioBlocked(false);emit('disconnect')}
   })
   incoming(call)
  }}})
 return {get registered(){return registered},async start(){
  await ua.start();registerer=new Registerer(ua,{expires:90,refreshFrequency:75})
  await new Promise((resolve,reject)=>{const timeout=setTimeout(()=>reject(Error('Não foi possível registrar o receptivo SIP. Tente novamente em um minuto.')),15000)
   registerer.stateChange.addListener(state=>{if(state===RegistererState.Registered){registered=true;clearTimeout(timeout);resolve()}else if(state===RegistererState.Unregistered){registered=false;if(!stopped)disconnected()}})
   registerer.register({requestDelegate:{onReject:()=>{clearTimeout(timeout);reject(Error('O serviço de voz não confirmou o registro receptivo.'))}}}).catch(e=>{clearTimeout(timeout);reject(e)})
  })
 },async play(){await ringer?.resume();if(active?.state===SessionState.Established)await audio.play();else ring();audioBlocked(false)},async destroy(){stopped=true;registered=false;stopRing();await ringer?.close();audio.pause();audio.srcObject=null;try{await registerer?.unregister()}catch{}await ua.stop()}}
}

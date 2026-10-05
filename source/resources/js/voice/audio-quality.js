// Browser telemetry only. No microphone samples, addresses, SDP, or credentials are retained.
const number = v => typeof v === 'number' && Number.isFinite(v) ? v : null
const rounded = v => number(v) === null ? null : Math.round(v * 1000) / 1000
const percentile = values => values.length ? [...values].sort((a,b)=>a-b)[Math.ceil(values.length*.95)-1] : null
export const qualityLimits = {jitter_ms:30,rtt_ms:400,loss_percent:1}
export function qualityState(q) {
 if (!q || q.observed_seconds < 10 || q.sample_count < 5 || number(q.jitter_p95_ms) === null || number(q.rtt_p95_ms) === null || number(q.loss_percent) === null || !q.packets_received || !q.packets_sent) return 'insufficient'
 return q.jitter_p95_ms > qualityLimits.jitter_ms || q.rtt_p95_ms > qualityLimits.rtt_ms || q.loss_percent > qualityLimits.loss_percent ? 'attention' : 'good'
}
export class AudioQualityMonitor {
 constructor(){this.timeline=[];this.previous=null;this.started=null;this.latest=null}
 sample(report,now=performance.now()) {
  const rows=[...report.values()];const inbound=rows.find(r=>r.type==='inbound-rtp'&&(r.kind||r.mediaType)==='audio');const outbound=rows.find(r=>r.type==='outbound-rtp'&&(r.kind||r.mediaType)==='audio')
  if(!inbound&&!outbound)return this.summary()
  this.started??=now
  const transport=report.get(inbound?.transportId||outbound?.transportId);const pair=report.get(transport?.selectedCandidatePairId)||rows.find(r=>r.type==='candidate-pair'&&r.state==='succeeded'&&r.nominated)
  const remote=report.get(outbound?.remoteId)||rows.find(r=>r.type==='remote-inbound-rtp'&&(r.kind||r.mediaType)==='audio')
  const codec=report.get(inbound?.codecId||outbound?.codecId);const candidate=report.get(pair?.localCandidateId)
  const rawLost=number(inbound?.packetsLost),received=number(inbound?.packetsReceived),sent=number(outbound?.packetsSent);const lost=rawLost===null?null:Math.max(0,rawLost)
  const total=lost!==null&&received!==null?lost+received:null
  const seconds=this.previous?(now-this.previous.at)/1000:0
  const delta=(value,old)=>number(value)!==null&&number(old)!==null&&value>=old?value-old:null
  const receiveDelta=this.previous?.inboundId===inbound?.id?delta(inbound?.bytesReceived,this.previous?.received):null
  const sendDelta=this.previous?.outboundId===outbound?.id?delta(outbound?.bytesSent,this.previous?.sent):null
  const roundTrip=number(remote?.roundTripTime)??number(pair?.currentRoundTripTime)
  const metrics={seconds:rounded((now-this.started)/1000),jitter_ms:number(inbound?.jitter)===null?null:rounded(inbound.jitter*1000),rtt_ms:roundTrip===null?null:rounded(roundTrip*1000),loss_percent:total>0?rounded(100*lost/total):null,receive_kbps:seconds>0&&receiveDelta!==null?rounded(receiveDelta*8/seconds/1000):null,send_kbps:seconds>0&&sendDelta!==null?rounded(sendDelta*8/seconds/1000):null}
  this.timeline.push(metrics);if(this.timeline.length>90)this.timeline.shift()
  this.latest={...metrics,packets_sent:sent??0,packets_received:received??0,audio_energy:number(inbound?.totalAudioEnergy)??0,codec:codec?.mimeType||null,clock_rate:codec?.clockRate||null,channels:codec?.channels||null,transport:['udp','tcp'].includes(candidate?.protocol)?candidate.protocol:null,rtt_source:number(remote?.roundTripTime)!==null?'rtcp':roundTrip!==null?'ice':null,encrypted:transport?.dtlsState?transport.dtlsState==='connected':null,concealment_percent:number(inbound?.concealedSamples)!==null&&inbound?.totalSamplesReceived>0?rounded(100*inbound.concealedSamples/inbound.totalSamplesReceived):null,jitter_buffer_ms:number(inbound?.jitterBufferDelay)!==null&&inbound?.jitterBufferEmittedCount>0?rounded(1000*inbound.jitterBufferDelay/inbound.jitterBufferEmittedCount):null}
  this.previous={at:now,received:inbound?.bytesReceived,sent:outbound?.bytesSent,inboundId:inbound?.id,outboundId:outbound?.id}
  return this.summary()
 }
 summary(){const m=this.latest||{};return {source:'browser_webrtc',observed_seconds:m.seconds??0,sample_count:this.timeline.length,packets_sent:m.packets_sent??0,packets_received:m.packets_received??0,audio_energy:m.audio_energy??0,codec:m.codec??null,clock_rate:m.clock_rate??null,channels:m.channels??null,transport:m.transport??null,rtt_source:m.rtt_source??null,encrypted:m.encrypted??null,loss_percent:m.loss_percent??null,jitter_ms:m.jitter_ms??null,rtt_ms:m.rtt_ms??null,jitter_p95_ms:rounded(percentile(this.timeline.map(s=>s.jitter_ms).filter(v=>v!==null))),rtt_p95_ms:rounded(percentile(this.timeline.map(s=>s.rtt_ms).filter(v=>v!==null))),concealment_percent:m.concealment_percent??null,jitter_buffer_ms:m.jitter_buffer_ms??null,receive_kbps:m.receive_kbps??null,send_kbps:m.send_kbps??null,timeline:this.timeline.map(s=>({...s}))}}
 payload(){const q=this.summary();return {packets_sent:q.packets_sent,packets_received:q.packets_received,audio_energy:q.audio_energy,quality:q}}
}

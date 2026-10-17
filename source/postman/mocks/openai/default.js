// Contract subset from official Responses and Realtime documentation (2026-10-16).
const http=require('http');
http.createServer((req,res)=>{
 const url=new URL(req.url,'http://localhost');
 const send=(code,data,headers={})=>{res.writeHead(code,{'Content-Type':'application/json',...headers});res.end(typeof data==='string'?data:JSON.stringify(data));};
 if(req.method==='POST'&&url.pathname==='/v1/responses'){
  const b=req.body||{};
  if(b.model!=='gpt-4.1-mini'||b.store!==false||!b.instructions||b.text?.format?.type!=='json_schema'||b.text?.format?.strict!==true)return send(400,{error:{message:'Invalid contract'}});
  const last=b.input?.at(-1)?.content;
  if(last==='provider-rate-limit')return send(429,{error:{message:'Rate limit exceeded'}});
  if(last==='provider-html-error')return send(500,'<html>upstream unavailable</html>',{'Content-Type':'text/html'});
  if(last==='provider-malformed')return send(200,{status:'completed',output:[]});
  return send(200,{status:'completed',output:[{content:[{type:'output_text',text:JSON.stringify({reply:'Resposta interna fictícia. Qual é sua necessidade?',action:'reply',summary:'Simulação HTTP concluída.'})}]}],usage:{input_tokens:120,output_tokens:30}});
 }
 if(req.method==='POST'&&url.pathname==='/v1/realtime/calls')return send(201,'v=0\r\ns=fixture\r\n',{'Content-Type':'application/sdp',Location:'https://api.openai.com/v1/realtime/calls/rtc_fixture'});
 if(req.method==='POST'&&/^\/v1\/realtime\/calls\/[A-Za-z0-9_-]+\/(hangup|accept|reject|refer)$/.test(url.pathname))return send(200,'');
 send(501,{error:'Unimplemented provider operation'});
}).listen(process.env.PORT||19096);

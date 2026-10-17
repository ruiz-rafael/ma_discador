// Subset of Ollama's /api/tags and /api/chat contract. Synthetic data only.
const http=require('http');
http.createServer((req,res)=>{
 const send=(code,data)=>{res.writeHead(code,{'Content-Type':'application/json'});res.end(JSON.stringify(data));};
 if(req.headers.authorization!=='Bearer fictional-private-server-key')return send(401,{error:'Invalid fixture key'});
 if(req.method==='GET'&&req.url==='/tags')return send(200,{models:[{name:'qwen3:8b'}]});
 if(req.method==='POST'&&req.url==='/chat'){
  const b=req.body||{};
  if(b.model!=='qwen3:8b'||b.think!==false||b.stream!==false||b.messages?.[0]?.role!=='system'||!b.format?.properties?.action)return send(400,{error:'Contract mismatch'});
  const input=b.messages.at(-1)?.content;
  if(input==='rate-limit')return send(429,{error:'Busy'});
  if(input==='unavailable')return send(503,{error:'Unavailable'});
  if(input==='timeout')return setTimeout(()=>send(200,{}),15000);
  const content=input==='malformed'?'invalid JSON':JSON.stringify({reply:'Olá! Resposta fictícia do teste de transporte.',action:'reply',summary:'Teste interno, sem inferência.'});
  return send(200,{model:'qwen3:8b',done:true,done_reason:input==='truncated'?'length':'stop',message:{role:'assistant',content},prompt_eval_count:120,eval_count:30});
 }
 send(501,{error:'Operation outside tested contract'});
}).listen(process.env.PORT||19120);

#!/usr/bin/python3
"""Signed incoming-call events. All routing destinations are returned by the tenant API."""
import hashlib,hmac,json,re,sys,time,urllib.request,urllib.error
while sys.stdin.readline().strip():pass
values={'IN_ALLOWED':'0','IN_WAITING':'0'}
try:
 tenant,event,endpoint,channel=sys.argv[1:5]
 if not re.fullmatch(r'discai-browser-[a-f0-9]{16}',tenant):raise ValueError()
 if not re.fullmatch(r'discai-(?:vono|twilio)-[a-f0-9]{32}',endpoint):raise ValueError()
 if not re.fullmatch(r'[a-zA-Z0-9_.:-]{1,120}',channel):raise ValueError()
 if event not in ('start','dispatch','answer','result','end'):raise ValueError()
 config=json.load(open('/etc/asterisk/discai-sip-callbacks.json'))[tenant]
 if endpoint not in config['endpoints']:raise ValueError()
 payload={'event':'inbound_'+event,'endpoint':endpoint,'channel_id':channel}
 if event=='start':
  number,caller=sys.argv[5:7]
  if not re.fullmatch(r'\+[1-9][0-9]{7,14}',number):raise ValueError()
  if not re.fullmatch(r'\+?[0-9]{1,20}',caller):caller='anonymous'
  payload.update(number=number,**{'from':caller})
 elif event in ('answer','result'):
  if not re.fullmatch(r'[a-f0-9-]{36}',sys.argv[5]):raise ValueError()
  payload['offer_id']=sys.argv[5]
 elif event=='end':payload['bill_seconds']=max(0,min(int(sys.argv[5] or 0),86400))
 body=json.dumps(payload,separators=(',',':')).encode();stamp=str(int(time.time()))
 sig=hmac.new(config['secret'].encode(),stamp.encode()+b'.'+body,hashlib.sha256).hexdigest()
 req=urllib.request.Request(config['url'],data=body,headers={'Content-Type':'application/json','Accept':'application/json','X-Voice-Timestamp':stamp,'X-Voice-Signature':sig})
 class NoRedirect(urllib.request.HTTPRedirectHandler):
  def redirect_request(self,*args,**kwargs):return None
 with urllib.request.build_opener(NoRedirect).open(req,timeout=5) as response:result=json.load(response)
 if result.get('allowed') is True:
  if event=='start':
   wait=int(result['wait_seconds']);seconds=int(result['max_seconds'])
   if not 10<=wait<=600 or not 30<=seconds<=1800:raise ValueError()
   values.update(IN_WAIT=str(wait),IN_SECONDS=str(seconds))
  if event=='dispatch':
   if result.get('waiting') is True:values['IN_WAITING']='1'
   else:
    agent=result.get('agent','');offer=result.get('offer_id','');ring=int(result['ring_seconds'])
    if agent not in config.get('agents',[]) or not re.fullmatch(r'discai-in-[a-f0-9]{32}',agent):raise ValueError()
    if not re.fullmatch(r'[a-f0-9-]{36}',offer) or not 1<=ring<=60:raise ValueError()
    values.update(IN_AGENT=agent,IN_OFFER=offer,IN_RING=str(ring))
  values['IN_ALLOWED']='1'
except Exception as error:
 category='HTTP_'+str(error.code) if isinstance(error,urllib.error.HTTPError) else 'UNREACHABLE' if isinstance(error,urllib.error.URLError) else 'INVALID_RESPONSE'
 print('VERBOSE "DiscAI incoming callback failed: '+category+'" 1',flush=True);sys.stdin.readline()
 values={'IN_ALLOWED':'0','IN_WAITING':'0'}
for key in [k for k in values if k!='IN_ALLOWED']+['IN_ALLOWED']:
 print('SET VARIABLE '+key+' '+values[key],flush=True);sys.stdin.readline()

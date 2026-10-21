#!/usr/bin/python3
"""Tenant-scoped SIP AGI. The API supplies the endpoint and destination."""
import hashlib,hmac,json,re,sys,time,urllib.request,urllib.error
while sys.stdin.readline().strip():
    pass
values={'MA_ALLOWED':'0'}
try:
    tenant,event,token,channel=sys.argv[1:5]
    if not re.fullmatch(r'discai-browser-[a-f0-9]{16}',tenant):
        raise ValueError('Invalid tenant')
    if event not in ('start','answered','finish') or not re.fullmatch(r'[a-f0-9]{64}',token) or not re.fullmatch(r'[a-zA-Z0-9_.:-]{1,120}',channel):
        raise ValueError('Invalid event')
    config=json.load(open('/etc/asterisk/discai-sip-callbacks.json'))[tenant]
    payload={'event':event,'token':token,'channel_id':channel}
    if event=='finish':
        payload.update(bill_seconds=max(0,min(int(sys.argv[5] or 0),240)),cause=sys.argv[6] or None,dial_status=sys.argv[7] or None)
    body=json.dumps(payload,separators=(',',':')).encode();stamp=str(int(time.time()))
    sig=hmac.new(config['secret'].encode(),stamp.encode()+b'.'+body,hashlib.sha256).hexdigest()
    req=urllib.request.Request(config['url'],data=body,headers={'Content-Type':'application/json','Accept':'application/json','X-Voice-Timestamp':stamp,'X-Voice-Signature':sig})
    # Do not forward a signed request to an unexpected redirect target.
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self,*args,**kwargs):return None
    with urllib.request.build_opener(NoRedirect).open(req,timeout=5) as response:
        result=json.load(response)
    if result.get('allowed') is True:
        if event=='start':
            if not re.fullmatch(r'discai-vono-[a-f0-9]{32}',result.get('endpoint','')) or result['endpoint'] not in config['endpoints']:
                raise ValueError('Invalid endpoint')
            if not re.fullmatch(r'\+?[1-9][0-9]{7,14}',result.get('dial_number','')) or not re.fullmatch(r'\+[1-9][0-9]{7,14}',result.get('caller_id','')):
                raise ValueError('Invalid number')
            seconds=int(result['max_seconds']);ring=int(result['ring_seconds'])
            if not 30<=seconds<=180 or not 10<=ring<=45:raise ValueError('Invalid limits')
            values.update(MA_ENDPOINT=result['endpoint'],MA_NUMBER=result['dial_number'],MA_CALLER=result['caller_id'][1:],MA_SECONDS=str(seconds),MA_RING=str(ring))
        values['MA_ALLOWED']='1'
except Exception as error:
    # Fixed categories only: never log tokens, credentials, request bodies or destinations.
    category='HTTP_'+str(error.code) if isinstance(error,urllib.error.HTTPError) else 'UNREACHABLE' if isinstance(error,urllib.error.URLError) else 'INVALID_RESPONSE'
    print('VERBOSE "DiscAI SIP callback failed: '+category+'" 1',flush=True)
    sys.stdin.readline()
    values={'MA_ALLOWED':'0'}
for key in [k for k in values if k!='MA_ALLOWED']+['MA_ALLOWED']:
    print('SET VARIABLE '+key+' '+values[key],flush=True)
    sys.stdin.readline()

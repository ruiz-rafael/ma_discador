#!/usr/bin/python3
"""Fail-closed AGI. Output contains only validated channel variables; no arbitrary commands."""
import hashlib,hmac,json,re,sys,time,urllib.request
while sys.stdin.readline().strip():
    pass
values={'MA_ALLOWED':'0'}
try:
    event,token,channel=sys.argv[1:4]
    if event not in ('start','answered','finish') or not re.fullmatch('[a-f0-9]{64}',token) or not re.fullmatch('[a-zA-Z0-9_.:-]{1,120}',channel):
        raise ValueError('Invalid event')
    payload={'event':event,'token':token,'channel_id':channel}
    if event=='finish':
        payload.update(bill_seconds=max(0,min(int(sys.argv[4] or 0),240)),cause=sys.argv[5] or None,dial_status=sys.argv[6] or None)
    body=json.dumps(payload,separators=(',',':')).encode()
    secret=open('/etc/asterisk/ma-calling-secret').read().strip().encode();stamp=str(int(time.time()))
    sig=hmac.new(secret,stamp.encode()+b'.'+body,hashlib.sha256).hexdigest()
    req=urllib.request.Request('http://web/internal/voice/calling/event',data=body,headers={'Content-Type':'application/json','Accept':'application/json','X-Voice-Timestamp':stamp,'X-Voice-Signature':sig})
    with urllib.request.urlopen(req,timeout=3) as response:
        result=json.load(response)
    if result.get('allowed') is True:
        if event=='start':
            if not all(re.fullmatch(r'\+[1-9][0-9]{7,14}',result.get(k,'')) for k in ('number','caller_id')):
                raise ValueError('Invalid number')
            seconds=int(result['max_seconds']);ring=int(result['ring_seconds'])
            if not 30<=seconds<=180 or not 10<=ring<=45:raise ValueError('Invalid limits')
            values.update(MA_NUMBER=result['number'],MA_CALLER=result['caller_id'],MA_SECONDS=str(seconds),MA_RING=str(ring))
        values['MA_ALLOWED']='1'
except Exception:
    values={'MA_ALLOWED':'0'}
# Set ALLOWED last so partial AGI execution cannot authorize a call with missing variables.
for key in [k for k in values if k!='MA_ALLOWED']+['MA_ALLOWED']:
    print('SET VARIABLE '+key+' '+values[key],flush=True)
    sys.stdin.readline()

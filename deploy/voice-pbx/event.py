#!/usr/bin/python3
"""AGI for an authenticated, one-use internal echo grant; never dials a PSTN route."""
import hashlib,hmac,json,sys,time,urllib.request
while sys.stdin.readline().strip():
    pass
allowed=False
try:
    stage,token,channel=sys.argv[1:4]
    if len(token)!=64 or any(c not in '0123456789abcdef' for c in token):
        raise ValueError('Invalid grant')
    duration=int(sys.argv[4] or '0') if len(sys.argv)>4 else 0
    cause=str(sys.argv[5]) if len(sys.argv)>5 else ''
    payload=json.dumps({'event':stage,'token':token,'channel_id':channel,'duration':max(0,min(duration,90)),'cause':cause},separators=(',',':')).encode()
    secret=open('/etc/asterisk/event-secret').read().strip().encode()
    stamp=str(int(time.time()))
    signature=hmac.new(secret,stamp.encode()+b'.'+payload,hashlib.sha256).hexdigest()
    request=urllib.request.Request('http://web/internal/voice/audio/event',data=payload,headers={'Content-Type':'application/json','Accept':'application/json','X-Voice-Timestamp':stamp,'X-Voice-Signature':signature})
    with urllib.request.urlopen(request,timeout=3) as response:
        allowed=json.load(response).get('allowed',False)
except Exception:
    # Fail closed. Never print a grant, signature, credential, or upstream body.
    pass
print('SET VARIABLE VOICE_ALLOWED '+('1' if allowed else '0'),flush=True)
sys.stdin.readline()

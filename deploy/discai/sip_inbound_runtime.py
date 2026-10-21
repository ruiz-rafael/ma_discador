"""Incoming SIP config: carrier IP admission, exact DID routing, private agent accounts."""
import re
# https://www.twilio.com/docs/sip-trunking/ip-addresses (checked 2026-10-21)
TWILIO_NETWORKS=['54.172.60.0/30','54.244.51.0/30','54.171.127.192/30','35.156.191.128/30','54.65.63.192/30','54.169.127.128/30','54.252.254.64/30','177.71.206.192/30']

def agent_config(agent):
 name=agent['username'];secret=agent['password']
 if not re.fullmatch(r'discai-in-[a-f0-9]{32}',name) or not re.fullmatch(r'[a-f0-9]{64}',secret):raise RuntimeError('Invalid inbound agent')
 return f'''
[{name}]
type=auth
auth_type=userpass
username={name}
password={secret}
[{name}]
type=aor
max_contacts=1
remove_existing=yes
minimum_expiration=60
maximum_expiration=120
qualify_frequency=30
[{name}]
type=endpoint
transport=transport-ws
context=discai-sip-denied
from_domain=app.discai.ia.br
auth={name}
aors={name}
disallow=all
allow=ulaw,alaw
webrtc=yes
dtls_auto_generate_cert=yes
media_encryption=dtls
dtls_verify=fingerprint
dtls_setup=actpass
ice_support=yes
rtcp_mux=yes
use_avpf=yes
direct_media=no
force_rport=yes
rewrite_contact=yes
rtp_symmetric=yes
rtp_timeout=60
rtp_timeout_hold=120
allow_transfer=no
allow_subscribe=no
'''

def ingress_config(vono_addresses):
 permits='\n'.join('permit='+n for n in TWILIO_NETWORKS)
 matches='\n'.join('match='+n for n in TWILIO_NETWORKS)
 text=f'''
[discai-inbound-tls]
type=transport
protocol=tls
bind=0.0.0.0:15062
method=tlsv1_2
cert_file=/etc/asterisk/discai-inbound.crt
priv_key_file=/etc/asterisk/discai-inbound.key
external_signaling_address=217.216.65.237
external_signaling_port=5061
external_media_address=217.216.65.237
local_net=10.241.50.0/24
[discai-twilio-ingress]
type=endpoint
transport=discai-inbound-tls
context=discai-twilio-incoming
disallow=all
allow=ulaw,alaw
media_encryption=sdes
media_encryption_optimistic=no
direct_media=no
rtp_symmetric=no
allow_transfer=no
allow_subscribe=no
deny=0.0.0.0/0
{permits}
[discai-twilio-ingress]
type=identify
endpoint=discai-twilio-ingress
{matches}
'''
 if vono_addresses:
  for ip in vono_addresses:
   if not re.fullmatch(r'[0-9.]+',ip):raise RuntimeError('Invalid carrier address')
  permits='\n'.join('permit='+ip+'/32' for ip in vono_addresses)
  matches='\n'.join('match='+ip+'/32' for ip in vono_addresses)
  text+=f'''
[discai-vono-ingress]
type=endpoint
transport=discai-vono-udp
context=discai-vono-incoming
disallow=all
allow=alaw,ulaw
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
allow_transfer=no
allow_subscribe=no
deny=0.0.0.0/0
{permits}
[discai-vono-ingress]
type=identify
endpoint=discai-vono-ingress
{matches}
'''
 return text

def tenant_dialplan(identity):
 if not re.fullmatch(r'discai-browser-[a-f0-9]{16}',identity):raise RuntimeError('Invalid inbound tenant')
 return '''
[IDENTITY-incoming]
exten => s,1,NoOp(DiscAI incoming call)
 same => n,Set(IN_CHANNEL=${CHANNEL(uniqueid)})
 same => n,Set(SHARED(IN_ANSWER_EPOCH)=0)
 same => n,Set(IN_FROM=${FILTER(0-9+,${CALLERID(num)})})
 same => n,AGI(/etc/asterisk/discai-sip-inbound-event.py,IDENTITY,start,${IN_ENDPOINT},${IN_CHANNEL},${IN_NUMBER},${IN_FROM})
 same => n,GotoIf($["${IN_ALLOWED}" != "1"]?deny)
 same => n,Set(IN_ADMITTED=1)
 same => n,Set(TIMEOUT(absolute)=$[${IN_WAIT}+${IN_SECONDS}+15])
 same => n,Set(IN_WAIT_PLAYED=0)
 same => n,Ringing()
 same => n(next),AGI(/etc/asterisk/discai-sip-inbound-event.py,IDENTITY,dispatch,${IN_ENDPOINT},${IN_CHANNEL})
 same => n,GotoIf($["${IN_ALLOWED}" != "1"]?unavailable)
 same => n,GotoIf($["${IN_WAITING}" = "1"]?wait)
 same => n,Dial(PJSIP/${IN_AGENT},${IN_RING},gU(IDENTITY-incoming-answer^${IN_ENDPOINT}^${IN_CHANNEL}^${IN_OFFER}^${CHANNEL(name)})S(${IN_SECONDS}))
 same => n,AGI(/etc/asterisk/discai-sip-inbound-event.py,IDENTITY,result,${IN_ENDPOINT},${IN_CHANNEL},${IN_OFFER})
 same => n,GotoIf($["${IN_ALLOWED}" != "1"]?done)
 same => n,Goto(next)
 same => n(wait),GotoIf($["${IN_WAIT_PLAYED}" = "1"]?pause)
 same => n,Answer()
 same => n,Playback(/etc/asterisk/discai-wait)
 same => n,Set(IN_WAIT_PLAYED=1)
 same => n(pause),Wait(2)
 same => n,Goto(next)
 same => n(unavailable),Playback(/etc/asterisk/discai-unavailable)
 same => n(done),Hangup()
 same => n(deny),Busy(2)
 same => n,Hangup(21)
exten => h,1,GotoIf($["${IN_ADMITTED}" != "1"]?done)
 same => n,Set(IN_ANSWER_EPOCH=${SHARED(IN_ANSWER_EPOCH)})
 same => n,Set(IN_BILL=0)
 same => n,ExecIf($[${IN_ANSWER_EPOCH}>0]?Set(IN_BILL=$[${EPOCH}-${IN_ANSWER_EPOCH}]))
 same => n,AGI(/etc/asterisk/discai-sip-inbound-event.py,IDENTITY,end,${IN_ENDPOINT},${IN_CHANNEL},${IN_BILL})
 same => n(done),NoOp(Incoming call released)
[IDENTITY-incoming-answer]
exten => s,1,AGI(/etc/asterisk/discai-sip-inbound-event.py,IDENTITY,answer,${ARG1},${ARG2},${ARG3})
 same => n,ExecIf($["${IN_ALLOWED}" != "1"]?Set(GOSUB_RESULT=ABORT))
 same => n,ExecIf($["${IN_ALLOWED}" = "1"]?Set(SHARED(IN_ANSWER_EPOCH,${ARG4})=${EPOCH}))
 same => n,Return()
'''.replace('IDENTITY',identity)

def routes_dialplan(routes):
 groups={'twilio':{},'vono':{}}
 for r in routes:
  identity=r['tenant'];endpoint=r['endpoint'];number=r['number']
  if not re.fullmatch(r'discai-browser-[a-f0-9]{16}',identity) or not re.fullmatch(r'discai-(vono|twilio)-[a-f0-9]{32}',endpoint) or not re.fullmatch(r'\+[1-9][0-9]{7,14}',number):raise RuntimeError('Invalid inbound route')
  carrier='twilio' if endpoint.startswith('discai-twilio-') else 'vono'
  aliases=[number,number[1:]]
  if carrier=='vono':
   if number.startswith('+55'):aliases.append(number[3:])
   if r.get('username'):aliases.append(r['username'])
  for alias in aliases:
   if not re.fullmatch(r'[A-Za-z0-9_.+\-]{1,64}',alias):raise RuntimeError('Invalid inbound extension')
   if alias in groups[carrier]:raise RuntimeError('Duplicate inbound number')
   groups[carrier][alias]=f'exten => {alias},1,Goto({identity}-incoming,s,1)\n'
   # Set trusted routing before entering tenant context, not from SIP headers.
   groups[carrier][alias]=f'exten => {alias},1,Set(IN_ENDPOINT={endpoint})\n same => n,Set(IN_NUMBER={number})\n same => n,Goto({identity}-incoming,s,1)\n'
 text=''
 for carrier,mappings in groups.items():
  text+=f'\n[discai-{carrier}-incoming]\n'+''.join(mappings.values())+'exten => _.,1,Hangup(21)\n'
 return text

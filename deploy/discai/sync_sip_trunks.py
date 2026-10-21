#!/usr/bin/env python3
"""Apply authenticated front-end SIP settings to DiscAI's PBX. Never originates calls.
Only reads DiscAI containers; credentials remain in private storage and PBX files.
"""
from pathlib import Path
import fcntl, hashlib, json, os, re, shutil, subprocess, tempfile, time, socket
from sip_inbound_runtime import agent_config, ingress_config, tenant_dialplan, routes_dialplan
from sip_callback_relay import relay_network, relay_target, relay_config
os.umask(0o077)
ROOT=Path('/srv/zyrex-ma'); LIVE=ROOT/'voice-runtime/asterisk'
def run(args, data=None):
    p=subprocess.run(args,input=data,text=True,capture_output=True,timeout=60)
    if p.returncode:raise RuntimeError('Local command failed; inspect the private runtime')
    return p.stdout
def cli(command,container='zyrex-ma-pbx-1'):
    s=run(['docker','exec',container,'asterisk','-rx',command])
    if 'No such command' in s:raise RuntimeError('PBX command unavailable')
    return s
def write(p,content,mode=0o640):
    tmp=p.with_name('.'+p.name+'.next');tmp.write_text(content);os.chown(tmp,0,18080);tmp.chmod(mode);tmp.replace(p)
def main():
    lock=(ROOT/'voice-runtime/.sip-sync.lock').open('a')
    try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except BlockingIOError:return
    containers=['zyrex-ma-app-1']
    for p in sorted((ROOT/'discai/tenants').glob('*/compose.json')):
        if re.fullmatch('[a-z0-9-]+',p.parent.name):
            name='discai-tenant-'+p.parent.name+'-app-1'
            if run(['docker','inspect','--format','{{.State.Running}}',name]).strip()=='true':containers.append(name)
    configs={}
    vono_addresses=sorted({a[4][0] for a in socket.getaddrinfo('vono2.me',5060,socket.AF_INET,socket.SOCK_DGRAM)})
    for name in containers:
        configs[name]=json.loads(run(['docker','exec','-u','82:82',name,'php','artisan','voice:sip-runtime','export']))
    pjsip='''; Managed by DiscAI SIP reconciler. Do not edit manually.
[discai-vono-udp]
type=transport
protocol=udp
bind=0.0.0.0:15060
external_signaling_address=217.216.65.237
external_media_address=217.216.65.237
local_net=10.241.50.0/24
'''
    dialplan='[discai-sip-denied]\nexten => _.,1,Hangup(21)\n'
    pbx=json.loads(run(['docker','inspect','zyrex-ma-pbx-1']))[0]
    gateway,pbx_address=relay_network(pbx)
    callbacks={};expected=[];relay_targets={};tls=None;identities=set();incoming_routes=[];agent_names=[]
    for name,c in configs.items():
        for t in c['trunks']:
            if not re.fullmatch(r'discai-(?:vono|twilio)-[a-f0-9]{32}',t['endpoint']):raise RuntimeError('Invalid endpoint identity')
            if t['endpoint'] in identities:raise RuntimeError('Duplicate trunk identity')
            identities.add(t['endpoint'])
            if t.get('kind')=='twilio_tls':
                if tls is not None and tls!=t['transport_configuration']:raise RuntimeError('Conflicting TLS transports')
                tls=t['transport_configuration']
            if t['enabled']:
                rendered=t['configuration']
                if t['endpoint'].startswith('discai-vono-'):
                    rendered=rendered.replace('context=discai-sip-denied','context=discai-vono-incoming\ndeny=0.0.0.0/0\n'+'\n'.join('permit='+ip+'/32' for ip in vono_addresses))
                pjsip+='\n'+rendered;expected.append(t['endpoint'])
        if not c['policy'] or not any(t['enabled'] for t in c['trunks']):continue
        b=c['browser'];identity=b['username']
        if not re.fullmatch(r'discai-browser-[a-f0-9]{16}',identity) or not re.fullmatch(r'[a-f0-9]{64}',b['password']):raise RuntimeError('Invalid browser identity')
        if not re.fullmatch(r'https://[a-z0-9.-]+/internal/voice/calling/event',c['callback']):raise RuntimeError('Invalid tenant callback')
        if identity in callbacks:raise RuntimeError('Duplicate tenant browser identity')
        web_name=name.removesuffix('-app-1')+'-web-1'
        relay_targets[identity]=relay_target(name,c['callback'],json.loads(run(['docker','inspect',web_name]))[0])
        callbacks[identity]={'url':f'http://{gateway}:18489/{identity}','secret':c['policy']['event_secret'],'endpoints':[t['endpoint'] for t in c['trunks'] if t['enabled']],'agents':[a['username'] for a in c.get('inbound_agents',[])]}
        for a in c.get('inbound_agents',[]):
            if a['username'] in agent_names:raise RuntimeError('Duplicate agent identity')
            pjsip+=agent_config(a);agent_names.append(a['username'])
        for route in c.get('inbound_routes',[]):
            trunk=next((t for t in c['trunks'] if t['enabled'] and t['endpoint']==route['endpoint']),None)
            if trunk:incoming_routes.append(route|{'tenant':identity,'username':trunk.get('username')})
        dialplan+=tenant_dialplan(identity)
        pjsip+=f'''
[{identity}]
type=auth
auth_type=userpass
username={identity}
password={b['password']}
[{identity}]
type=endpoint
transport=transport-ws
context={identity}
auth={identity}
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
rtp_timeout=20
rtp_timeout_hold=30
allow_transfer=no
allow_subscribe=no
'''
        dialplan+='''
[IDENTITY]
exten => _[0-9a-f].,1,Set(MA_TOKEN=${EXTEN})
 same => n,Set(MA_CHANNEL=${CHANNEL(uniqueid)})
 same => n,Set(TIMEOUT(absolute)=30)
 same => n,AGI(/etc/asterisk/discai-sip-event.py,IDENTITY,start,${MA_TOKEN},${MA_CHANNEL})
 same => n,GotoIf($["${MA_ALLOWED}" != "1"]?deny)
 same => n,Set(TIMEOUT(absolute)=$[${MA_RING}+${MA_SECONDS}+5])
 same => n,Set(CALLERID(num)=${MA_CALLER})
 same => n,Set(CALLERID(name)=)
 same => n,Dial(PJSIP/${MA_NUMBER}@${MA_ENDPOINT},${MA_RING},giU(IDENTITY-answered^${MA_TOKEN}^${MA_CHANNEL})S(${MA_SECONDS}))
 same => n,Hangup()
 same => n(deny),Hangup(21)
exten => h,1,AGI(/etc/asterisk/discai-sip-event.py,IDENTITY,finish,${MA_TOKEN},${MA_CHANNEL},${CDR(billsec)},${HANGUPCAUSE},${DIALSTATUS})
[IDENTITY-answered]
exten => s,1,AGI(/etc/asterisk/discai-sip-event.py,IDENTITY,answered,${ARG1},${ARG2})
 same => n,ExecIf($["${MA_ALLOWED}" != "1"]?Set(GOSUB_RESULT=ABORT))
 same => n,Return()
'''.replace('IDENTITY',identity)
    pjsip+=ingress_config(vono_addresses)
    dialplan+=routes_dialplan(incoming_routes)
    files={'discai-sip-inbound-event.py':(ROOT/'source/resources/voice/sip-inbound-event.py').read_text(),'discai-sip-pjsip.conf':pjsip,'discai-sip-extensions.conf':dialplan,'discai-sip-callbacks.json':json.dumps(callbacks,sort_keys=True),'discai-sip-event.py':(ROOT/'source/resources/voice/sip-event.py').read_text()}
    files['discai-inbound.crt']=Path('/etc/letsencrypt/live/app.discai.ia.br/fullchain.pem').read_text()
    files['discai-inbound.key']=Path('/etc/letsencrypt/live/app.discai.ia.br/privkey.pem').read_text()
    if tls is not None:
        # Keep the existing TLS binding; retire the shared browser and trunk identities.
        files['ma-calling-pjsip.conf']=tls
        files['ma-calling-extensions.conf']='; Legacy shared calling context retired. Tenant contexts authorize all calls.\n'
    for base,include in [('pjsip.conf','discai-sip-pjsip.conf'),('extensions.conf','discai-sip-extensions.conf')]:
        text=(LIVE/base).read_text();line='#include '+include
        files[base]=text if line in text.splitlines() else text+'\n'+line+'\n'
        if tls is not None and base=='pjsip.conf' and '#include ma-calling-pjsip.conf' not in files[base].splitlines():
            files[base]+='\n#include ma-calling-pjsip.conf\n'
    relay_path=Path('/etc/nginx/conf.d/discai-sip-callbacks.conf')
    relay_text=relay_config(gateway,pbx_address,relay_targets)
    relay_before=relay_path.read_text() if relay_path.exists() else None
    relay_changed=relay_before!=relay_text
    changed=relay_changed or any(not(LIVE/f).exists() or (LIVE/f).read_text()!=v for f,v in files.items())
    if changed:
        if any(c['busy'] for c in configs.values()) or '0 active channels' not in cli('core show channels count'):
            print('SIP changes queued until current calls finish.');return
        # Parse configuration in an isolated Asterisk with no network and no exposed ports.
        stage=Path(tempfile.mkdtemp(prefix='sip-validate-',dir=ROOT/'releases'));check='discai-sip-validation'
        started=False
        try:
            for p in LIVE.iterdir():
                if p.is_file():shutil.copy2(p,stage/p.name)
            for f,v in files.items():(stage/f).write_text(v)
            os.chown(stage,0,18080);stage.chmod(0o750)
            for p in stage.iterdir():os.chown(p,0,18080);p.chmod(0o750 if p.suffix=='.py' else 0o640)
            host_args=[]
            for host in sorted(set(re.findall(r'contact=sip:([a-z0-9.-]+):',pjsip))):
                host_args+=['--add-host',host+':127.0.0.2']
            run(['docker','run','-d',*host_args,'--name',check,'--network','none','--memory=256m','--cpus=.25','--cap-drop=ALL','--security-opt=no-new-privileges','-v',str(stage)+':/etc/asterisk:ro','zyrex-ma-pbx:22.9.0-1']);started=True
            for attempt in range(60):
                try:
                    valid=all(('discai-vono-incoming' if e.startswith('discai-vono-') else 'discai-sip-denied') in cli('pjsip show endpoint '+e,check) for e in expected)
                    valid=valid and all('discai-sip-denied' in cli('pjsip show endpoint '+e,check) for e in agent_names)
                    valid=valid and all('incoming' in cli('dialplan show '+b+'-incoming',check) for b in callbacks)
                    valid=valid and all('Dial(' in cli('dialplan show '+b,check) for b in callbacks)
                    valid=valid and 'Echo(' in cli('dialplan show ma-audio-only',check)
                    if valid:break
                except RuntimeError:pass
                time.sleep(.5)
            else:raise RuntimeError('Isolated SIP validation failed')
            backup=ROOT/'backups'/('sip-'+time.strftime('%Y%m%dT%H%M%SZ'));backup.mkdir(mode=0o700)
            previous={f:(LIVE/f).read_bytes() if (LIVE/f).exists() else None for f in files}
            for f,v in previous.items():
                if v is not None:(backup/f).write_bytes(v)
            if relay_before is not None:(backup/'callback-relay.conf').write_text(relay_before)
            new_transport='discai-inbound-tls' not in cli('pjsip show transports') or any(previous.get(f)!=files[f].encode() for f in ['discai-inbound.crt','discai-inbound.key'])
            try:
                if relay_changed:
                    write(relay_path,relay_text,0o644)
                    run(['nginx','-t']);run(['systemctl','reload','nginx'])
                # Restrict the host firewall exception to this PBX and this private listener.
                if shutil.which('ufw') and 'Status: active' in run(['ufw','status']):
                    run(['ufw','allow','proto','tcp','from',pbx_address,'to',gateway,'port','18489','comment','DiscAI private PBX callbacks'])
                for f,v in files.items():write(LIVE/f,v,0o750 if f.endswith('.py') else 0o640)
                if new_transport:run(['docker','restart','zyrex-ma-pbx-1']);time.sleep(3)
                else:
                    cli('module reload res_pjsip.so');cli('module reload res_pjsip_outbound_registration.so');cli('dialplan reload')
                for e in expected:
                    if ('discai-vono-incoming' if e.startswith('discai-vono-') else 'discai-sip-denied') not in cli('pjsip show endpoint '+e):raise RuntimeError('Live endpoint validation failed')
                for b in callbacks:
                    if 'Dial(' not in cli('dialplan show '+b):raise RuntimeError('Live dialplan validation failed')
            except Exception:
                if relay_changed:
                    if relay_before is None:relay_path.unlink(missing_ok=True)
                    else:write(relay_path,relay_before,0o644)
                    run(['nginx','-t']);run(['systemctl','reload','nginx'])
                # Restrict the host firewall exception to this PBX and this private listener.
                if shutil.which('ufw') and 'Status: active' in run(['ufw','status']):
                    run(['ufw','allow','proto','tcp','from',pbx_address,'to',gateway,'port','18489','comment','DiscAI private PBX callbacks'])
                for f,v in previous.items():
                    if v is None:(LIVE/f).unlink(missing_ok=True)
                    else:write(LIVE/f,v.decode(),0o750 if f.endswith('.py') else 0o640)
                cli('module reload res_pjsip.so');cli('module reload res_pjsip_outbound_registration.so');cli('dialplan reload');raise
        finally:
            if started:run(['docker','rm','-f',check])
            shutil.rmtree(stage)
    output=cli('pjsip show registrations');summaries=[]
    for name,c in configs.items():
        channels=[]
        for line in cli('core show channels concise').splitlines():
            channel=line.split('!',1)[0]
            if re.fullmatch(r'[A-Za-z0-9_./-]+',channel):
                details=cli('core show channel '+channel)
                match=re.search(r'UniqueID:\s*([A-Za-z0-9_.:-]+)',details)
                if match:channels.append(match.group(1))
        states=[{'id':'inbound_channels','channels':channels}]
        for t in c['trunks']:
            if not t['enabled']:state='disabled'
            elif t.get('kind')=='twilio_tls':
                state='installed' if 'discai-sip-denied' in cli('pjsip show endpoint '+t['endpoint']) else 'error'
            else:
                line=next((l for l in output.splitlines() if t['endpoint']+'-registration/' in l),'')
                state='registered' if re.search(r'\bRegistered\b',line) else 'rejected' if re.search(r'\bRejected\b',line) else 'unregistered'
                if state=='registered' and not c['policy']:state='no_policy'
            states.append({'id':t['id'],'hash':t['hash'],'state':state})
            summaries.append({'container':name,'connection':t['id'],'state':state})
        run(['docker','exec','-i','-u','82:82',name,'php','artisan','voice:sip-runtime','status'],json.dumps(states))
    print(json.dumps({'changed':changed,'connections':summaries,'calls_originated':0}))
if __name__=='__main__':
    try:main()
    except Exception as error:
        # Only our fixed operational messages may be logged; subprocess output is private.
        safe=str(error) if type(error) is RuntimeError else type(error).__name__
        print('SIP synchronization incomplete: '+safe);raise SystemExit(1)

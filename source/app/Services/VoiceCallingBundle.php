<?php

namespace App\Services;

class VoiceCallingBundle
{
    public function generate(): void
    {
        $c = app(VoiceCallingConfig::class);
        $p = $c->read();
        $t = $c->trunk();
        abort_unless($p && $t, 422, 'Configure a política e o tronco antes de preparar o PBX.');
        $trunk = app(TwilioTrunk::class)->render($t);
        // Browser credentials can reach only the grant-controlled outbound context, never the echo context.
        $browser = <<<CONF
[ma-calling]
type=auth
auth_type=userpass
username=ma-calling
password={$p['sip_password']}
[ma-calling]
type=endpoint
transport=transport-ws
context=ma-calling-only
auth=ma-calling
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

CONF;
        $c->privateWrite('ma-calling-pjsip.conf', $trunk."\n".$browser);
        $dialplan = <<<'CONF'
[ma-twilio-denied]
exten => _.,1,Hangup(21)
[ma-calling-only]
; Token only. The API supplies the allowed phone number; EXTEN is never a phone number.
exten => _[0-9a-f].,1,Set(MA_TOKEN=${EXTEN})
 same => n,Set(MA_CHANNEL=${CHANNEL(uniqueid)})
 same => n,Set(TIMEOUT(absolute)=30)
 same => n,AGI(/etc/asterisk/ma-calling-event.py,start,${MA_TOKEN},${MA_CHANNEL})
 same => n,GotoIf($["${MA_ALLOWED}" != "1"]?deny)
 same => n,Set(TIMEOUT(absolute)=$[${MA_RING}+${MA_SECONDS}+5])
 same => n,Set(CALLERID(num)=${MA_CALLER})
 same => n,Set(CALLERID(name)=)
 same => n,Dial(PJSIP/${MA_NUMBER}@ma-twilio-out,${MA_RING},giU(ma-calling-answered^${MA_TOKEN}^${MA_CHANNEL})S(${MA_SECONDS}))
 same => n,Hangup()
 same => n(deny),Hangup(21)
exten => h,1,AGI(/etc/asterisk/ma-calling-event.py,finish,${MA_TOKEN},${MA_CHANNEL},${CDR(billsec)},${HANGUPCAUSE},${DIALSTATUS})
[ma-calling-answered]
exten => s,1,AGI(/etc/asterisk/ma-calling-event.py,answered,${ARG1},${ARG2})
 same => n,ExecIf($["${MA_ALLOWED}" != "1"]?Set(GOSUB_RESULT=ABORT))
 same => n,Return()
CONF;
        $c->privateWrite('ma-calling-extensions.conf', $dialplan."\n");
        $c->privateWrite('ma-calling-event.py', file_get_contents(resource_path('voice/calling-event.py')));
        $c->privateWrite('ma-calling-secret', $p['event_secret']);
        $names = ['ma-calling-pjsip.conf', 'ma-calling-extensions.conf', 'ma-calling-event.py', 'ma-calling-secret'];
        $files = [];
        foreach ($names as $name) {
            $files[$name] = hash_file('sha256', $c->directory().'/'.$name);
        }
        $c->privateWrite('bundle.json', json_encode(['fingerprint' => $c->fingerprint(), 'files' => $files], JSON_PRETTY_PRINT));
    }
}

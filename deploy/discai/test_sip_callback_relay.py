import io
import json
from pathlib import Path
import runpy
import unittest
from unittest.mock import patch
import urllib.error
from sip_callback_relay import relay_network, relay_target, relay_config

class RelayTest(unittest.TestCase):
    def web(self, name, port):
        return {'Name':'/'+name,'NetworkSettings':{'Ports':{'80/tcp':[{'HostIp':'127.0.0.1','HostPort':str(port)}]}}}
    def test_distinct_tenants_map_only_to_their_own_loopback_ports(self):
        main=relay_target('zyrex-ma-app-1','https://ma.zyrex.ia.br/internal/voice/calling/event',self.web('zyrex-ma-web-1',18280))
        other=relay_target('discai-tenant-polishop-app-1','https://polishop.discai.ia.br/internal/voice/calling/event',self.web('discai-tenant-polishop-web-1',19102))
        a='discai-browser-'+'a'*16;b='discai-browser-'+'b'*16
        text=relay_config('10.241.50.1','10.241.50.10',{a:main,b:other})
        self.assertIn('listen 10.241.50.1:18489;',text)
        self.assertIn('allow 10.241.50.10;\n deny all;',text)
        self.assertIn('location = /'+a,text);self.assertIn('127.0.0.1:18280/internal/voice/calling/event',text)
        self.assertIn('127.0.0.1:19102/internal/voice/calling/event',text)
        self.assertIn('limit_except POST { deny all; }',text)
        self.assertNotIn('listen 0.0.0.0',text)
    def test_wrong_tenant_or_public_upstream_is_rejected(self):
        web=self.web('zyrex-ma-web-1',18280)
        with self.assertRaises(RuntimeError):relay_target('discai-tenant-polishop-app-1','https://polishop.discai.ia.br/internal/voice/calling/event',web)
        web['NetworkSettings']['Ports']['80/tcp'][0]['HostIp']='0.0.0.0'
        with self.assertRaises(RuntimeError):relay_target('zyrex-ma-app-1','https://ma.zyrex.ia.br/internal/voice/calling/event',web)
    def test_no_public_listener_or_header_injection(self):
        with self.assertRaises(RuntimeError):relay_config('217.216.65.237','10.241.50.10',{})
        with self.assertRaises(RuntimeError):relay_config('10.241.50.1','10.241.50.10',{'x;':'bad'})

class AgiTest(unittest.TestCase):
    identity='discai-browser-'+'a'*16
    endpoint='discai-vono-'+'b'*32
    def execute(self, reply=None, error=None):
        script=Path(__file__).resolve().parents[2]/'source/resources/voice/sip-event.py'
        conf={self.identity:{'url':'http://10.241.50.1:18489/'+self.identity,'secret':'never-log-this','endpoints':[self.endpoint]}}
        output=io.StringIO()
        class Response(io.StringIO):
            pass
        with patch('sys.argv',[str(script),self.identity,'start','c'*64,'test-no-call']),patch('sys.stdin',io.StringIO('\n'+'200 result=1\n'*10)),patch('sys.stdout',output),patch('builtins.open',return_value=io.StringIO(json.dumps(conf))),patch('urllib.request.OpenerDirector.open',side_effect=error,return_value=Response(json.dumps(reply))) as request:
            runpy.run_path(str(script),run_name='__main__')
            req=request.call_args[0][0]
            self.assertIn('X-voice-signature',req.headers)
            self.assertNotIn('never-log-this',output.getvalue());self.assertNotIn('c'*64,output.getvalue())
        return output.getvalue()
    def test_signed_callback_authorizes_only_valid_endpoint_and_limits(self):
        reply={'allowed':True,'endpoint':self.endpoint,'dial_number':'5511999990001','caller_id':'+551130000001','max_seconds':60,'ring_seconds':30}
        output=self.execute(reply);self.assertTrue(output.endswith('SET VARIABLE MA_ALLOWED 1\n'))
        reply['endpoint']='discai-vono-'+'d'*32
        self.assertNotIn('MA_ALLOWED 1',self.execute(reply))
    def test_twilio_tls_endpoint_preserves_e164_caller_and_is_tenant_scoped(self):
        self.endpoint='discai-twilio-'+'b'*32
        reply={'allowed':True,'endpoint':self.endpoint,'dial_number':'+5511999990001','caller_id':'+551149330254','max_seconds':60,'ring_seconds':30}
        output=self.execute(reply)
        self.assertIn('MA_CALLER +551149330254',output)
        self.assertTrue(output.endswith('SET VARIABLE MA_ALLOWED 1\n'))
        reply['endpoint']='discai-twilio-'+'d'*32
        self.assertNotIn('MA_ALLOWED 1',self.execute(reply))
    def test_denial_and_network_failures_fail_closed_and_redact_secrets(self):
        self.assertEqual(self.execute({'allowed':False}),'SET VARIABLE MA_ALLOWED 0\n')
        for error in [urllib.error.HTTPError('http://secret',403,'secret',{},None),urllib.error.URLError('secret')]:
            output=self.execute(error=error)
            self.assertIn('DiscAI SIP callback failed:',output);self.assertTrue(output.endswith('SET VARIABLE MA_ALLOWED 0\n'));self.assertNotIn('http://secret',output)
    def test_redirect_is_not_followed(self):
        import urllib.request
        # Capture the handler configured by the actual AGI and exercise it directly.
        captured=[];original=urllib.request.build_opener
        def opener(*handlers):
            captured.extend(handlers);return original(*handlers)
        with patch('urllib.request.build_opener',side_effect=opener):self.execute({'allowed':False})
        self.assertIsNone(captured[0].redirect_request(None,None,302,'',{},'http://untrusted'))

if __name__=='__main__':unittest.main()

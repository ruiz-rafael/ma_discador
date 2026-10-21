import unittest
from sip_inbound_runtime import agent_config,routes_dialplan,tenant_dialplan,ingress_config,TWILIO_NETWORKS
class IncomingRuntimeTest(unittest.TestCase):
 def test_personal_agent_cannot_dial_out(self):
  text=agent_config({'username':'discai-in-'+'a'*32,'password':'b'*64})
  self.assertIn('context=discai-sip-denied',text);self.assertIn('max_contacts=1',text);self.assertIn('auth=discai-in-',text);self.assertIn('from_domain=app.discai.ia.br',text)
 def test_routing_is_exact_and_unique(self):
  route={'endpoint':'discai-twilio-'+'a'*32,'tenant':'discai-browser-'+'b'*16,'number':'+551149330254'}
  text=routes_dialplan([route]);self.assertIn('exten => +551149330254,1,Set(IN_ENDPOINT=',text);self.assertIn('exten => _.,1,Hangup(21)',text)
  with self.assertRaises(RuntimeError):routes_dialplan([route,route])
  with self.assertRaises(RuntimeError):routes_dialplan([route|{'number':'s,1,System(evil)'}])
 def test_incoming_dialplan_never_dials_carrier(self):
  text=tenant_dialplan('discai-browser-'+'a'*16);self.assertIn('Dial(PJSIP/${IN_AGENT}',text);self.assertNotIn('@${',text);self.assertIn('inbound-event.py',text);self.assertIn('IN_ADMITTED',text);self.assertIn('SHARED(IN_ANSWER_EPOCH,${ARG4})',text);self.assertNotIn('MASTER_CHANNEL',text)
 def test_available_agent_rings_before_answer_and_wait_announcement(self):
  text=tenant_dialplan('discai-browser-'+'a'*16)
  self.assertLess(text.index('Dial(PJSIP/'),text.index('Answer()'))
  self.assertLess(text.index('Ringing()'),text.index('Dial(PJSIP/'))
  self.assertIn('GotoIf($["${IN_WAIT_PLAYED}" = "1"]?pause)',text)
 def test_carriers_are_restricted_and_twilio_requires_srtp(self):
  text=ingress_config(['190.89.248.47']);self.assertIn('media_encryption=sdes',text);self.assertEqual(2,text.count('deny=0.0.0.0/0'));self.assertEqual(8,len(TWILIO_NETWORKS));self.assertIn('permit=190.89.248.47/32',text)
if __name__=='__main__':unittest.main()

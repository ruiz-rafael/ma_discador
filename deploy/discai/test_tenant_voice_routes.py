import unittest
from tenant_voice_routes import install_tenant_voice_routes
class RoutesTest(unittest.TestCase):
 def test_installs_only_on_https_with_tenant_origin_and_session_authorization(self):
  conf='server { listen 80; location / { return 301 https://bossanova.discai.ia.br$request_uri; } }\nserver { listen 443 ssl; location / { proxy_pass http://127.0.0.1:19103; } }'
  new=install_tenant_voice_routes(conf,'bossanova.discai.ia.br',19103)
  self.assertGreater(new.index('location = /voice/ws'),new.index('listen 443 ssl'))
  self.assertIn('auth_request /_discai_tenant_voice_auth',new)
  self.assertIn('$http_origin != "https://bossanova.discai.ia.br"',new)
  self.assertIn('127.0.0.1:19103/api/voice/audio/authorize',new)
  with self.assertRaises(ValueError):install_tenant_voice_routes(new,'bossanova.discai.ia.br',19103)
 def test_rejects_plaintext_and_header_injection(self):
  with self.assertRaises(ValueError):install_tenant_voice_routes('server { listen 80; location / { proxy_pass http://127.0.0.1:19103; } }','bossanova.discai.ia.br',19103)
  with self.assertRaises(ValueError):install_tenant_voice_routes('', 'bossanova.discai.ia.br; evil',19103)
if __name__=='__main__':unittest.main()

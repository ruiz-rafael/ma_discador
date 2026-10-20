import unittest
from portal_voice_routes import install_voice_routes


class PortalVoiceRoutesTest(unittest.TestCase):
    def test_http_redirect_precedes_https_without_capturing_voice_locations(self):
        http = 'server { listen 80; location / { return 301 https://app.discai.ia.br$request_uri; } }'
        https = 'server { listen 443 ssl; location / { proxy_pass http://127.0.0.1:19090; } }'
        result = install_voice_routes(http + '\n' + https, 'location = /platform-calling/voice/ws { proxy_pass http://pbx/ws; }')
        self.assertTrue(result.startswith(http))
        self.assertGreater(result.index('/platform-calling/voice/ws'), result.index('listen 443 ssl'))

    def test_missing_ambiguous_or_non_tls_upstream_is_rejected(self):
        invalid = [
            'server { listen 80; location / { return 301 https://app; } }',
            'server { listen 80; location / { proxy_pass http://127.0.0.1:19090; } }',
            'server { listen 443 ssl; location / { proxy_pass http://127.0.0.1:19090; } }' * 2,
        ]
        for config in invalid:
            with self.subTest(config=config), self.assertRaises(ValueError):
                install_voice_routes(config, 'voice locations')


if __name__ == '__main__':
    unittest.main()

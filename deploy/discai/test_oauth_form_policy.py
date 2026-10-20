"""Browser regression for OAuth redirects under the portal's deployed CSP."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from threading import Thread
import json, base64
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parents[2]
config = (root/'deploy/discai/configure_discai_domain.py').read_text()
assert "form-action \\'self\\' https://accounts.google.com;" in config
policy = "default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self' https://accounts.google.com; frame-ancestors 'none'; base-uri 'self'"

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def do_GET(self):
        self.send_response(200)
        self.send_header('Content-Type', 'text/html')
        self.send_header('Content-Security-Policy', policy)
        self.end_headers()
        self.wfile.write(('<form method="post" action="'+self.path+'"><button>Connect</button></form>').encode())
    def do_POST(self):
        self.send_response(302)
        self.send_header('Location', 'https://accounts.google.com/fixture' if self.path == '/allowed' else 'https://untrusted.example/fixture')
        self.end_headers()

server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
Thread(target=server.serve_forever, daemon=True).start()
results = []
try:
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        for scenario in ('allowed', 'blocked'):
            page = browser.new_page()
            violations = []
            page.on('console', lambda m: violations.append('form-action' in m.text))
            cdp = page.context.new_cdp_session(page)
            cdp.send('Fetch.enable', {'patterns':[{'urlPattern':'https://*','requestStage':'Request'}]})
            cdp.on('Fetch.requestPaused', lambda event: cdp.send('Fetch.fulfillRequest', {'requestId':event['requestId'],'responseCode':200,'responseHeaders':[{'name':'Content-Type','value':'text/html'}],'body':base64.b64encode(b'<h1>Destination</h1>').decode()}))
            page.goto('http://127.0.0.1:'+str(server.server_port)+'/'+scenario)
            page.get_by_role('button', name='Connect').click(no_wait_after=True)
            page.wait_for_timeout(600)
            arrived = page.get_by_role('heading', name='Destination').count() == 1
            assert arrived == (scenario == 'allowed'), {'scenario':scenario,'url':page.url,'violations':violations,'body':page.locator('body').inner_text()}
            assert any(violations) == (scenario == 'blocked')
            results.append({'scenario':scenario,'destination_reached':arrived,'passed':True})
            page.close()
        browser.close()
finally:
    server.shutdown()
    server.server_close()
evidence = root/'evidence/google-oauth-navigation-20261020'
evidence.mkdir(exist_ok=True)
(evidence/'policy-regression.json').write_text(json.dumps(results, indent=2))
print(json.dumps(results))

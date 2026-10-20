"""Create only app.discai.ia.br vhost with tested graceful reload and TLS."""
from pathlib import Path
import subprocess
host='app.discai.ia.br';base=Path('/srv/zyrex-ma/discai');acme=base/'acme';acme.mkdir(exist_ok=True);acme.chmod(0o755);base.chmod(0o711)
site=Path('/etc/nginx/sites-available')/host;link=Path('/etc/nginx/sites-enabled')/host
cert=Path('/etc/letsencrypt/live')/host
# acme parent needs traverse; only challenge directory is publicly served.
old=site.read_text()if site.exists()else None
http='server { listen 80; listen [::]:80; server_name '+host+'; location ^~ /.well-known/acme-challenge/ { root '+str(acme)+'; } location / { return 301 https://'+host+'$request_uri; } }\n'
if not cert.exists():
 site.write_text(http.replace('return 301 https://'+host+'$request_uri;','return 503;'));site.chmod(0o644)
 if not link.exists():link.symlink_to(site)
 subprocess.run(['nginx','-t'],check=True);subprocess.run(['systemctl','reload','nginx'],check=True)
 subprocess.run(['certbot','certonly','--webroot','-w',str(acme),'-d',host,'--non-interactive','--agree-tos','--register-unsafely-without-email'],check=True)
conf=http+'server { listen 443 ssl; listen [::]:443 ssl; server_name '+host+'; ssl_certificate '+str(cert/'fullchain.pem')+'; ssl_certificate_key '+str(cert/'privkey.pem')+'; ssl_protocols TLSv1.2 TLSv1.3; client_max_body_size 64k; access_log off; add_header X-Content-Type-Options nosniff always; add_header Referrer-Policy no-referrer always; add_header X-Frame-Options DENY always; add_header Content-Security-Policy "default-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\' https://*.twilio.com wss://*.twilio.com wss://*.discai.ia.br wss://ma.zyrex.ia.br; media-src \'self\' blob: https://sdk.twilio.com; worker-src \'self\' blob:; form-action \'self\' https://accounts.google.com; frame-ancestors \'none\'; base-uri \'self\'" always; location / { proxy_pass http://127.0.0.1:19090; proxy_set_header Host $host; proxy_set_header X-Forwarded-Proto https; proxy_set_header X-Real-IP $remote_addr; } }'
# Keep the WebSocket on the portal origin, protected by its own operator session.
voice_locations=(Path(__file__).with_name('platform_voice_nginx.conf')).read_text()
conf=conf.replace(' location / { proxy_pass http://127.0.0.1:19090;', '\n'+voice_locations+'\n location / { proxy_pass http://127.0.0.1:19090;')
site.write_text(conf);site.chmod(0o644)
if not link.exists():link.symlink_to(site)
try:subprocess.run(['nginx','-t'],check=True);subprocess.run(['systemctl','reload','nginx'],check=True)
except Exception:
 if old is not None:site.write_text(old)
 else:link.unlink(missing_ok=True)
 raise
subprocess.run(['curl','-fsS','--resolve',host+':443:127.0.0.1','https://'+host+'/login'],stdout=subprocess.DEVNULL,check=True)
print('HTTPS ready: https://'+host+'/login')

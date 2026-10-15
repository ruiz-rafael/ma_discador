"""Provision isolated DiscAI tenants. Only own files, containers, databases and vhosts.
Workers start only after migration and public HTTPS verification. No provider credentials are copied or campaigns enabled.
"""
from pathlib import Path
import base64,fcntl,json,os,re,secrets,shutil,socket,subprocess,time,ipaddress
os.umask(0o077)
ROOT=Path('/srv/zyrex-ma'); BASE=ROOT/'discai'; TENANTS=BASE/'tenants'; CODE=BASE/'release'
BOOT="require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
def run(args,**kw): return subprocess.run(args,check=True,**kw)
def php(code):return subprocess.check_output(['docker','exec','-u','82:82','discai-portal-app','php','-r',BOOT+code],text=True)
def update(t,values):
 data=base64.b64encode(json.dumps(values).encode()).decode()
 php("Illuminate\\Support\\Facades\\DB::table('portal_tenants')->where('id',"+str(t['id'])+")->update(json_decode(base64_decode('"+data+"'),true));")
def provision(t):
 slug=t['slug'];assert re.fullmatch('[a-z][a-z0-9-]{2,38}[a-z0-9]',slug);tid=int(t['id']);assert 1<=tid<=500
 host=slug+'.discai.ia.br';port=19100+tid;folder=TENANTS/slug;project='discai-tenant-'+slug
 # Preserve the established origin when resuming an existing tenant.
 if (folder/'.env').exists():
  origin=next((line.split('=',1)[1] for line in (folder/'.env').read_text().splitlines() if line.startswith('APP_URL=')), '')
  assert origin in ['https://'+slug+'.discai.ia.br','https://'+slug+'.app.discai.ia.br'],'Unexpected tenant origin'
  host=origin.removeprefix('https://')
 # No credentials, volumes or MA data are copied from the production workspace.
 if not (folder/'compose.json').exists():
  available=int(next(x.split()[1] for x in Path('/proc/meminfo').read_text().splitlines()if x.startswith('MemAvailable:')))
  if available<1400000:raise RuntimeError('Capacidade da VM insuficiente para preparar outro ambiente com segurança.')
  sock=socket.socket();sock.bind(('127.0.0.1',port));sock.close()
  folder.mkdir(parents=True,mode=0o700,exist_ok=True)
  for name in ['storage/app/private','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','cache']:
   p=folder/name;p.mkdir(parents=True,exist_ok=True)
  for p in (folder/'storage').rglob('*'):os.chown(p,82,82);p.chmod(0o750)
  os.chown(folder/'storage',82,82);os.chown(folder/'cache',82,82);(folder/'cache').chmod(0o750)
  secret=secrets.token_hex(32);password=secrets.token_hex(32);appkey=base64.b64encode(secrets.token_bytes(32)).decode()
  cfg={'slug':slug,'portal_url':'https://app.discai.ia.br','internal_url':'https://app.discai.ia.br','secret':secret,'required':True}
  p=folder/'storage/app/private/discai-portal.json';p.write_text(json.dumps(cfg));os.chown(p,82,82);p.chmod(0o600)
  env={'APP_ENV':'production','APP_DEBUG':'false','APP_KEY':'base64:'+appkey,'APP_URL':'https://'+host,'APP_NAME':'DiscAI','DB_CONNECTION':'pgsql','DB_HOST':'database','DB_PORT':'5432','DB_DATABASE':'discai','DB_USERNAME':'discai','DB_PASSWORD':password,'SESSION_DRIVER':'file','SESSION_COOKIE':'__Host-discai_'+slug.replace('-','_'),'SESSION_SECURE_COOKIE':'true','CACHE_STORE':'file','QUEUE_CONNECTION':'database','LOG_CHANNEL':'single','DISCAI_PORTAL':'false','DISCAI_PORTAL_URL':'https://app.discai.ia.br','MAIL_MAILER':'log'}
  (folder/'.env').write_text(''.join(k+'='+v+'\n'for k,v in env.items()))
  volumes=[str(CODE)+':/var/www/html:ro',str(folder/'storage')+':/var/www/html/storage',str(folder/'cache')+':/var/www/html/bootstrap/cache']
  compose={'name':project,'services':{'database':{'image':'postgres:17-alpine','environment':{'POSTGRES_DB':'discai','POSTGRES_USER':'discai','POSTGRES_PASSWORD':password},'volumes':['database:/var/lib/postgresql/data'],'mem_limit':'256m','cpus':.25,'restart':'unless-stopped'},'app':{'image':'zyrex-ma-php:1','working_dir':'/var/www/html','env_file':['.env'],'volumes':volumes,'mem_limit':'256m','cpus':.35,'restart':'unless-stopped'},'web':{'image':'nginx:1.28-alpine','ports':['127.0.0.1:'+str(port)+':80'],'volumes':[str(CODE/'public')+':/var/www/html/public:ro',str(folder/'nginx.conf')+':/etc/nginx/conf.d/default.conf:ro'],'mem_limit':'48m','cpus':.15,'restart':'unless-stopped'}},'volumes':{'database':{}}}
  subnet='10.242.'+str(tid//16)+'.'+str((tid%16)*16)+'/28'
  ids=subprocess.check_output(['docker','network','ls','-q'],text=True).split()
  for net in json.loads(subprocess.check_output(['docker','network','inspect',*ids])):
   for item in (net['IPAM'].get('Config')or[]):
    if item.get('Subnet')and ipaddress.ip_network(item['Subnet']).version==4:assert not ipaddress.ip_network(subnet).overlaps(ipaddress.ip_network(item['Subnet'])),'Private subnet already used'
  compose['networks']={'default':{'ipam':{'config':[{'subnet':subnet}]}}}
  for name,command in [('worker',['php','artisan','queue:work','database','--sleep=3','--tries=1','--timeout=60']),('scheduler',['php','artisan','schedule:work'])]:
   compose['services'][name]=dict(compose['services']['app'],command=command,user='82:82',mem_limit='160m',cpus=.15,profiles=['automation'])
  for service in compose['services'].values():service['logging']={'driver':'json-file','options':{'max-size':'5m','max-file':'2'}}
  (folder/'compose.json').write_text(json.dumps(compose))
  (folder/'nginx.conf').write_text('server { listen 80; root /var/www/html/public; index index.php; server_name _; client_max_body_size 8m; location / { try_files $uri $uri/ /index.php?$query_string; } location ~ \\.php$ { include fastcgi_params; fastcgi_pass app:9000; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_param HTTPS on; } location ~ /\\. { deny all; } }')
  (folder/'nginx.conf').chmod(0o644)
  sealed=base64.b64encode(secret.encode()).decode()
  php("Illuminate\\Support\\Facades\\DB::table('portal_tenants')->where('id',"+str(tid)+")->update(['secret'=>Illuminate\\Support\\Facades\\Crypt::encryptString(base64_decode('"+sealed+"'))]);")
 cmd=['docker','compose','-f',str(folder/'compose.json')]
 run(cmd+['up','-d','database','app','web'],cwd=folder,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 for _ in range(30):
  if subprocess.run(cmd+['exec','-T','database','pg_isready','-U','discai'],cwd=folder,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode==0:break
  time.sleep(1)
 run(cmd+['exec','-T','-u','82:82','app','php','artisan','migrate','--force'],cwd=folder,stdout=subprocess.DEVNULL)
 # Blank databases are migrated without demo seeding; nothing can dial or send.
 name=base64.b64encode(t['name'].encode()).decode()
 run(cmd+['exec','-T','-u','82:82','app','php','-r',BOOT+"Illuminate\\Support\\Facades\\DB::table('voice_workspaces')->where('id',1)->update(['name'=>base64_decode('"+name+"')]);"],cwd=folder)
 try:socket.getaddrinfo(host,443)
 except socket.gaierror:raise RuntimeError('Ambiente preparado. Aguardando DNS para '+host+'.')
 acme=BASE/'acme';acme.mkdir(exist_ok=True);acme.chmod(0o755)
 site=Path('/etc/nginx/sites-available')/('discai-tenant-'+slug);enabled=Path('/etc/nginx/sites-enabled')/site.name
 cert=Path('/etc/letsencrypt/live')/host
 if not cert.exists():
  site.write_text('server { listen 80; server_name '+host+'; location ^~ /.well-known/acme-challenge/ { root '+str(acme)+'; } location / { return 503; } }');site.chmod(0o644)
  if not enabled.exists():enabled.symlink_to(site)
  run(['nginx','-t'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);run(['systemctl','reload','nginx'])
  proc=subprocess.run(['certbot','certonly','--webroot','-w',str(acme),'-d',host,'--non-interactive','--agree-tos','--register-unsafely-without-email'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  if proc.returncode:raise RuntimeError('Ambiente preparado. Aguardando validação HTTPS do domínio.')
 conf='server { listen 80; server_name '+host+'; location ^~ /.well-known/acme-challenge/ { root '+str(acme)+'; } location / { return 301 https://'+host+'$request_uri; } }\nserver { listen 443 ssl; server_name '+host+'; ssl_certificate '+str(cert/'fullchain.pem')+'; ssl_certificate_key '+str(cert/'privkey.pem')+'; client_max_body_size 8m; access_log off; add_header Referrer-Policy no-referrer always; add_header X-Content-Type-Options nosniff always; location ^~ /internal/ { return 404; } location / { proxy_pass http://127.0.0.1:'+str(port)+'; proxy_set_header Host $host; proxy_set_header X-Forwarded-Proto https; proxy_set_header X-Real-IP $remote_addr; } }'
 old=site.read_text();site.write_text(conf);site.chmod(0o644)
 try:run(['nginx','-t'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);run(['systemctl','reload','nginx'])
 except Exception:site.write_text(old);raise
 # Nginx replaces workers asynchronously after reload; retry verified GETs, never disable TLS checks.
 run(['curl','-fsS','--retry','3','--retry-all-errors','--retry-delay','1','--max-time','5','--resolve',host+':443:127.0.0.1','https://'+host+'/up'],stdout=subprocess.DEVNULL)
 run(['curl','-fsS','--retry','3','--retry-all-errors','--retry-delay','1','--max-time','10','https://'+host+'/up'],stdout=subprocess.DEVNULL)
 run(cmd+['--profile','automation','up','-d'],cwd=folder,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 update(t,{'status':'active','url':'https://'+host,'provision_error':None,'checked_at':time.strftime('%Y-%m-%d %H:%M:%S',time.gmtime())})
 print('Provisioned '+slug+'; isolated workers ready, provider credentials absent.')
def main():
 BASE.mkdir(exist_ok=True);TENANTS.mkdir(exist_ok=True)
 with (BASE/'provision.lock').open('w')as lock:
  try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
  except BlockingIOError:return
  jobs=json.loads(php("echo Illuminate\\Support\\Facades\\DB::table('portal_tenants')->where('status','provisioning')->orderBy('id')->limit(1)->get(['id','slug','name'])->toJson();"))
  for t in jobs:
   try:provision(t)
   except Exception as e:
    message=str(e)if isinstance(e,RuntimeError)else'Não foi possível concluir a preparação. Revisão técnica necessária.'
    update(t,{'provision_error':message[:240]});print('Pending '+t['slug']+': '+message)
if __name__=='__main__':main()

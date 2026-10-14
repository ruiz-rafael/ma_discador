"""Deploy central portal on dedicated resources. Never copies MA provider secrets."""
from pathlib import Path
import base64,datetime,json,os,secrets,shutil,subprocess,ipaddress
os.umask(0o077)
ROOT=Path('/srv/zyrex-ma');BASE=ROOT/'discai';STAGE=ROOT/'releases/multitenant-20261014';CODE=BASE/'release';EVIDENCE=ROOT/'evidence/multitenant-20261014'
BOOT="require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
def run(args,**kw):return subprocess.run(args,check=True,**kw)
def php(container,code):return subprocess.check_output(['docker','exec','-u','82:82',container,'php','-r',BOOT+code],text=True)
def install():
 BASE.mkdir(exist_ok=True,mode=0o700)
 if not CODE.exists():
  shutil.copytree(STAGE,CODE,ignore=shutil.ignore_patterns('node_modules','.env','storage','.phpunit.result.cache'),symlinks=False)
  for p in [CODE,*CODE.rglob('*')]:
   if p.is_dir():p.chmod(0o755)
   elif p.is_file():p.chmod(0o644)
 (CODE/'storage').mkdir(exist_ok=True)
 for name in ['portal-storage/app/private','portal-storage/framework/cache/data','portal-storage/framework/sessions','portal-storage/framework/views','portal-storage/logs','portal-cache']:
  p=BASE/name;p.mkdir(parents=True,exist_ok=True);os.chown(p,82,82);p.chmod(0o750)
 for p in (BASE/'portal-storage').rglob('*'):os.chown(p,82,82)
 os.chown(BASE/'portal-storage',82,82)
 db=BASE/'portal-storage/portal.sqlite'
 if not db.exists():db.touch();db.chmod(0o600);os.chown(db,82,82)
 envfile=BASE/'portal.env'
 if not envfile.exists():
  env={'APP_ENV':'production','APP_DEBUG':'false','APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'APP_URL':'https://app.discai.ia.br','APP_NAME':'DiscAI','DISCAI_PORTAL':'true','DB_CONNECTION':'sqlite','DB_DATABASE':'/var/www/html/storage/portal.sqlite','SESSION_DRIVER':'file','SESSION_COOKIE':'__Host-discai_portal','SESSION_SECURE_COOKIE':'true','CACHE_STORE':'file','QUEUE_CONNECTION':'database','LOG_CHANNEL':'single','MAIL_MAILER':'log'}
  envfile.write_text(''.join(k+'='+v+'\n'for k,v in env.items()))
 nginx=BASE/'portal-nginx.conf';nginx.write_text('server { listen 80; server_name _; root /var/www/html/public; index index.php; client_max_body_size 64k; location / { try_files $uri $uri/ /index.php?$query_string; } location ~ \\.php$ { include fastcgi_params; fastcgi_pass portal-app:9000; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_param HTTPS on; } location ~ /\\. { deny all; } }');nginx.chmod(0o644)
 compose={'name':'discai-portal','services':{'portal-app':{'container_name':'discai-portal-app','image':'zyrex-ma-php:1','working_dir':'/var/www/html','env_file':['portal.env'],'volumes':[str(CODE)+':/var/www/html:ro',str(BASE/'portal-storage')+':/var/www/html/storage',str(BASE/'portal-cache')+':/var/www/html/bootstrap/cache'],'mem_limit':'256m','cpus':.35,'restart':'unless-stopped'},'portal-web':{'container_name':'discai-portal-web','image':'nginx:1.28-alpine','ports':['127.0.0.1:19090:80'],'volumes':[str(CODE/'public')+':/var/www/html/public:ro',str(nginx)+':/etc/nginx/conf.d/default.conf:ro'],'mem_limit':'48m','cpus':.15,'restart':'unless-stopped'}}}
 compose['networks']={'default':{'ipam':{'config':[{'subnet':'10.242.250.0/28'}]}}}
 ids=subprocess.check_output(['docker','network','ls','-q'],text=True).split()
 for net in json.loads(subprocess.check_output(['docker','network','inspect',*ids])):
  for item in (net['IPAM'].get('Config')or[]):
   if net['Name']!='discai-portal_default' and item.get('Subnet') and ipaddress.ip_network(item['Subnet']).version==4:assert not ipaddress.ip_network('10.242.250.0/28').overlaps(ipaddress.ip_network(item['Subnet'])),'Choose a different private subnet'
 for s in compose['services'].values():s['logging']={'driver':'json-file','options':{'max-size':'5m','max-file':'2'}}
 (BASE/'compose.json').write_text(json.dumps(compose))
 run(['docker','compose','-f',str(BASE/'compose.json'),'up','-d'],cwd=BASE,stdout=subprocess.DEVNULL)
 run(['docker','exec','-u','82:82','discai-portal-app','php','artisan','migrate','--force'],stdout=subprocess.DEVNULL)
 run(['docker','exec','-u','82:82','discai-portal-app','php','artisan','migrate','--force','--path=database/portal-migrations'],stdout=subprocess.DEVNULL)
 if php('discai-portal-app',"echo Illuminate\\Support\\Facades\\DB::table('portal_operators')->count();").strip()=='0':
  # Keep hashes private: import existing authorized accounts without printing credentials.
  users=php('zyrex-ma-app-1',"echo Illuminate\\Support\\Facades\\DB::table('users')->where('voice_enabled',true)->orderBy('id')->get(['name','email','password','voice_role'])->toJson();")
  data=base64.b64encode(users.encode()).decode();secret=secrets.token_hex(32)
  script="$db=Illuminate\\Support\\Facades\\DB::class;$id=$db::table('portal_tenants')->insertGetId(['slug'=>'zyrex','name'=>'Zyrex Workspace','status'=>'active','url'=>'https://ma.zyrex.ia.br','secret'=>Illuminate\\Support\\Facades\\Crypt::encryptString('"+secret+"'),'plan'=>'Operação existente','seats'=>20,'channels'=>1,'storage_mb'=>10240,'created_at'=>now(),'updated_at'=>now()]);$operator=false;foreach(json_decode(base64_decode('"+data+"'),true)as $d){$role=$d['voice_role']??'agent';$u=App\\Models\\User::create(['name'=>$d['name'],'email'=>$d['email'],'password'=>$d['password']]);$db::table('portal_memberships')->insert(['tenant_id'=>$id,'user_id'=>$u->id,'role'=>$role,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);if(!$operator&&$role==='admin'){$db::table('portal_operators')->insert(['user_id'=>$u->id]);$operator=true;}}if(!$operator)throw new RuntimeException('Administrator missing');"
  php('discai-portal-app',script)
  bridge={'slug':'zyrex','portal_url':'https://app.discai.ia.br','internal_url':'https://app.discai.ia.br','secret':secret,'required':False}
  p=ROOT/'source/storage/app/private/discai-portal.json';assert not p.exists();p.write_text(json.dumps(bridge));p.chmod(0o600);os.chown(p,82,82)
 (EVIDENCE/'portal-installed.json').write_text(json.dumps({'url':'https://app.discai.ia.br','resources':'isolated containers and database','ma_data_migrated':False,'provider_credentials_copied':False}))
 print('Portal installed; existing access imported privately.')
if __name__=='__main__':install()

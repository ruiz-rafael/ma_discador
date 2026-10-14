"""Read-only usage snapshots; no provider requests or operational mutations."""
from pathlib import Path
import base64,json,subprocess,time
root=Path('/srv/zyrex-ma');base=root/'discai';boot="require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
def php(container,code):return subprocess.check_output(['docker','exec','-u','82:82',container,'php','-r',boot+code],text=True)
tenants=json.loads(php('discai-portal-app',"echo Illuminate\\Support\\Facades\\DB::table('portal_tenants')->whereIn('status',['active','suspended'])->get(['id','slug'])->toJson();"))
for tenant in tenants:
 if tenant['slug']=='zyrex':container='zyrex-ma-app-1';private=root/'source/storage/app/private'
 else:
  folder=base/'tenants'/tenant['slug']
  if not(folder/'compose.json').exists():continue
  ids=subprocess.check_output(['docker','compose','-f',str(folder/'compose.json'),'ps','-q','app'],cwd=folder,text=True).strip()
  if not ids:continue
  container=ids;private=folder/'storage/app/private'
 try:
  usage=json.loads(php(container,"$d=Illuminate\\Support\\Facades\\DB::class;echo json_encode(['contacts'=>$d::table('contacts')->count(),'calls'=>$d::table('voice_outbound_calls')->count(),'messages'=>$d::table('wa_messages')->where('direction','outbound')->count(),'active_calls'=>$d::table('voice_outbound_calls')->whereNull('capacity_released_at')->count(),'agents'=>$d::table('users')->where('voice_enabled',true)->count()]);"))
  usage['storage_mb']=round(sum(p.stat().st_size for p in private.rglob('*')if p.is_file()and not p.is_symlink())/1048576,2)if private.exists()else 0
  data=base64.b64encode(json.dumps(usage).encode()).decode()
  php('discai-portal-app',"Illuminate\\Support\\Facades\\DB::table('portal_tenants')->where('id',"+str(int(tenant['id']))+")->update(['usage'=>base64_decode('"+data+"'),'checked_at'=>now()]);")
 except Exception:print('Usage pending for tenant '+str(tenant['id']))
print('Usage snapshots updated without contacting providers.')

<?php
namespace App\Http\Controllers;
use App\Services\Portal\{Access,NumberOrders,NumberProvider};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class PortalNumbersController extends Controller {
 private function manager(Request $r,int $tenant):void {abort_unless(app(Access::class)->manages($tenant,$r->user()->id),403);app(NumberOrders::class)->requestingTenant($tenant);}
 private function operator(Request $r):void {abort_unless(app(Access::class)->operator($r->user()->id),403);}
 public function index(Request $r,int $tenant){$this->manager($r,$tenant);$t=app(NumberOrders::class)->requestingTenant($tenant);return view('portal.numbers', ['tenant'=>$t,'operator'=>app(Access::class)->operator($r->user()->id),'orders'=>DB::table('portal_number_requests')->where('tenant_id',$tenant)->latest()->paginate(20),'labels'=>NumberOrders::LABELS,'ddds'=>NumberOrders::DDDS]);}
 public function store(Request $r,int $tenant){$this->manager($r,$tenant);$d=$r->validate(['id'=>'required|uuid','ddd'=>['required',Rule::in(NumberOrders::DDDS)],'purpose'=>'required|in:inbound,outbound,mixed','notes'=>'nullable|string|max:500']);app(NumberOrders::class)->request($tenant,$r->user()->id,$d);return back()->with('notice','Solicitação enviada à administração DiscAI. Você pode acompanhar o andamento aqui.');}
 public function administration(Request $r){$this->operator($r);$r->validate(['status'=>['nullable',Rule::in(array_keys(NumberOrders::LABELS))]]);return view('portal.number-admin',['orders'=>DB::table('portal_number_requests as n')->join('portal_tenants as t','t.id','=','n.tenant_id')->select('n.*','t.name as tenant_name')->when($r->status,fn($q)=>$q->where('n.status',$r->status))->orderByDesc('n.created_at')->paginate(25)->withQueryString(),'labels'=>NumberOrders::LABELS,'configured'=>(bool)app(NumberProvider::class)->config()]);}
 public function show(Request $r,string $id){$this->operator($r);$order=app(NumberOrders::class)->order($id);$tenant=DB::table('portal_tenants')->find($order->tenant_id);$registrations=['bundles'=>[],'addresses'=>[]];$providerError=null;
  if($order->status==='quoted')try{$registrations=app(NumberProvider::class)->registrations();}catch(\Throwable){$providerError='Não foi possível consultar os cadastros da operadora. Atualize a página para tentar novamente.';}
  return view('portal.number-order',compact('order','tenant','registrations','providerError')+['labels'=>NumberOrders::LABELS,'quote'=>json_decode($order->quote??'{}',true)]);
 }
 public function assignment(Request $r){$this->operator($r);return view('portal.number-assign',['tenants'=>DB::table('portal_tenants')->where('status','active')->orderBy('name')->get(['id','name']),'configured'=>(bool)app(NumberProvider::class)->config(),'inventory'=>app(\App\Services\Portal\NumberInventory::class)->catalog()]);}
 public function assign(Request $r){
  $this->operator($r);
  $d=$r->validate(['id'=>'required|uuid','tenant_id'=>'required|integer|exists:portal_tenants,id','number'=>['required','regex:/^\+[1-9][0-9]{7,14}$/D'],'purpose'=>'required|in:inbound,outbound,mixed','notes'=>'nullable|string|max:500','confirm'=>'required|accepted']);
  try{app(NumberOrders::class)->assign($r->user()->id,$d);}
  catch(\Illuminate\Validation\ValidationException $e){throw $e;}
  catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){return back()->withInput()->withErrors(['operation'=>$e->getMessage()?:'Não foi possível vincular o número. Confira a empresa e tente novamente.']);}
  catch(\Illuminate\Database\UniqueConstraintViolationException){return back()->withInput()->withErrors(['operation'=>'Este número já está reservado ou vinculado. Atualize o painel para conferir.']);}
  catch(\Throwable){return back()->withInput()->withErrors(['operation'=>'A conferência não foi concluída. Nenhuma compra foi realizada. Tente novamente para consultar o vínculo.']);}
  return redirect('/number-administration/'.$d['id'])->with('notice','Número reservado para a empresa. Confira os dados e clique em Liberar para concluir a configuração.');
 }
 public function action(Request $r,string $id,string $action){$this->operator($r);$s=app(NumberOrders::class);$actor=$r->user()->id;
  try{
   if($action==='quote')$s->quote($id,$actor);
   elseif($action==='buy'){$d=$r->validate(['number'=>['required','regex:/^\+55[1-9][0-9]{9}$/D'],'quote_token'=>'required|string|size:48','bundle_sid'=>['required','regex:/^BU[a-fA-F0-9]{32}$/D'],'address_sid'=>['nullable','regex:/^AD[a-fA-F0-9]{32}$/D'],'confirm'=>'required|accepted']);$s->buy($id,$actor,$d);}
   elseif($action==='adopt'){$d=$r->validate(['number'=>['required','regex:/^\\+55[1-9][0-9]{9}$/D'],'confirm'=>'required|accepted']);$s->adopt($id,$actor,$d['number']);}
   elseif($action==='reconcile')$s->reconcile($id,$actor);
   elseif($action==='release'){$r->validate(['confirm'=>'required|accepted']);$s->release($id,$actor);}
   elseif($action==='reject'){$d=$r->validate(['message'=>'required|string|max:500']);$s->reject($id,$actor,$d['message']);}
   else abort(404);
  }catch(\Illuminate\Validation\ValidationException $e){throw $e;}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){if($e->getStatusCode()===404)throw $e;return back()->withErrors(['operation'=>$e->getMessage()?:'O pedido foi alterado. Atualize a página.']);}catch(\Throwable){return back()->withErrors(['operation'=>'A operação não foi confirmada. Atualize o pedido e confira o estado antes de repetir.']);}
  return redirect('/number-administration/'.$id)->with('notice','Pedido atualizado. Confira o estado abaixo.');
 }
}

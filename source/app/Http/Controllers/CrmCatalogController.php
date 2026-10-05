<?php
namespace App\Http\Controllers;
use App\Contracts\CrmCatalog;
use App\Models\CrmResource;
use App\Services\{NodeCatalog,NodeConfiguration,GraphValidator};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\Rule;
class CrmCatalogController extends Controller {
 public const KINDS=['segment','campaign','list','template','field','tag','stage','user','form','goal','pipeline','store','sender'];
 public function index(Request $r,CrmCatalog $catalog){$d=$r->validate(['kind'=>['required',Rule::in(self::KINDS)],'q'=>'nullable|string|max:160','channel'=>'nullable|in:email,sms,whatsapp','page'=>'nullable|integer|min:1|max:10000','id'=>'nullable|string|max:160']);if(isset($d['id'])){$item=$catalog->find($d['kind'],$d['id']);return ['data'=>$item?[$item]:[],'has_more'=>false,'page'=>1];}return $catalog->search($d['kind'],$d['q']??'',$d['channel']??null,$d['page']??1);}
 public static function rules():array {return ['kind'=>['required',Rule::in(self::KINDS)],'external_id'=>'required|string|max:160','name'=>'required|string|max:200','channel'=>'nullable|in:email,sms,whatsapp','active'=>'sometimes|boolean','metadata'=>'nullable|array:description,data_type,campaign_id,parameters,buttons,subject,language','metadata.description'=>'nullable|string|max:1000','metadata.data_type'=>'nullable|in:text,number,date,boolean','metadata.campaign_id'=>'nullable|string|max:160','metadata.parameters'=>'sometimes|array|max:20','metadata.parameters.*'=>'string|max:80','metadata.buttons'=>'sometimes|array|max:8','metadata.buttons.*'=>'string|max:60','metadata.subject'=>'nullable|string|max:200','metadata.language'=>'nullable|string|max:20'];}
 public function store(Request $r){$d=$r->validate(self::rules());$this->validateChannel($d);return DB::transaction(function()use($d){$item=CrmResource::where('kind',$d['kind'])->where('external_id',$d['external_id'])->lockForUpdate()->first();abort_if($item&&$item->origin==='crm',409,'Esta referência é gerenciada pelo CRM. Atualize-a pela sincronização.');return CrmResource::updateOrCreate(['kind'=>$d['kind'],'external_id'=>$d['external_id']],$d+['origin'=>'preparation']);});}
 public function sync(Request $r){
  $secret=config('marketing.catalog_secret');$stamp=$r->header('X-MA-Timestamp','');abort_unless($secret&&ctype_digit($stamp)&&abs(time()-(int)$stamp)<300&&hash_equals(hash_hmac('sha256',$stamp.'.'.$r->getContent(),$secret),$r->header('X-MA-Signature','')),401);
  $d=$r->validate(['resources'=>'required|array|min:1|max:200']);$items=[];$keys=[];foreach($d['resources']as $i=>$item){$v=Validator::make(is_array($item)?$item:[],self::rules());if($v->fails())throw \Illuminate\Validation\ValidationException::withMessages(["resources.$i"=>$v->errors()->all()]);$data=$v->validated();$this->validateChannel($data);$key=$data['kind'].':'.$data['external_id'];abort_if(isset($keys[$key]),422,'Referência duplicada no lote.');$keys[$key]=true;$items[]=$data;}
  DB::transaction(function()use($items){foreach($items as $item)CrmResource::updateOrCreate(['kind'=>$item['kind'],'external_id'=>$item['external_id']],array_merge($item,['origin'=>'crm']));});return ['updated'=>count($items),'contract_version'=>1];
 }
 private function validateChannel(array $d):void {if(in_array($d['kind'],['campaign','template','sender'])&&empty($d['channel']))throw \Illuminate\Validation\ValidationException::withMessages(['channel'=>'Selecione o canal da campanha, template ou remetente.']);}
 public function validateNode(Request $r,NodeConfiguration $config){$d=$r->validate(['key'=>['required',Rule::in(array_keys(NodeCatalog::all()))],'settings'=>'present|array']);$issues=$config->errors($d['key'],$d['settings'],true,false);if($issues)throw \Illuminate\Validation\ValidationException::withMessages(['settings'=>$issues]);return ['valid'=>true,'activation_pending'=>$config->activationPending($d['key'],$d['settings'])];}
 public function validateGraph(Request $r,GraphValidator $v){$d=$r->validate(['graph'=>'required|array','graph.nodes'=>'required|array|max:150','graph.edges'=>'present|array|max:300']);$v->validate($d['graph'],true,false);$pending=[];foreach($d['graph']['nodes']as $n)foreach(app(NodeConfiguration::class)->activationPending($n['data']['key'],$n['data']['settings']??[])as $p)$pending[]=$p;return ['valid'=>true,'activation_pending'=>array_values(array_unique($pending))];}
}

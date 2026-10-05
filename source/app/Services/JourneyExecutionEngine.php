<?php
namespace App\Services;
use App\Models\{Journey,Contact,JourneySubscriber,JourneyVersion,JourneyToken,JourneyNodeLog};
use App\Jobs\ExecuteJourneyNodeJob;
use Illuminate\Support\Facades\DB;
class JourneyExecutionEngine {
 public function enroll(Journey $journey,Contact $contact,string $eventKey,string $trigger='open_trigger',array $attributes=[]):?JourneySubscriber {
  return DB::transaction(function()use($journey,$contact,$eventKey,$trigger,$attributes){
   $journey=Journey::lockForUpdate()->findOrFail($journey->id);
   if($journey->status!=='active'||($journey->start_date&&now()->lt($journey->start_date))||($journey->end_date&&now()->gt($journey->end_date)))return null;
   $graph=JourneyVersion::where('journey_id',$journey->id)->where('version',$journey->published_version)->firstOrFail()->graph;
   $triggers=collect($graph['nodes'])->filter(fn($n)=>$n['data']['key']===$trigger)->filter(fn($n)=>app(TriggerMatcher::class)->matches($n,$contact,$attributes));
   if($triggers->isEmpty())return null;
   $sub=JourneySubscriber::firstOrCreate(['journey_id'=>$journey->id,'contact_id'=>$contact->id,'event_key'=>$eventKey],['version'=>$journey->published_version,'status'=>'active','entered_at'=>now()]);
   if(!$sub->wasRecentlyCreated)return $sub;
   foreach($triggers as $node)$this->token($sub,$node['id'],'start:'.$sub->id.':'.$node['id']);return $sub;
  });
 }
 public function token(JourneySubscriber $sub,string $node,string $path):void {
  $t=JourneyToken::firstOrCreate(['path_key'=>hash('sha256',$path)],['journey_subscriber_id'=>$sub->id,'node_uuid'=>$node]);if($t->wasRecentlyCreated)ExecuteJourneyNodeJob::dispatch($t->id)->afterCommit();
 }
 public function execute(int $id):void {
  DB::transaction(function()use($id){
   $reference=JourneyToken::find($id);if(!$reference)return;JourneySubscriber::lockForUpdate()->findOrFail($reference->journey_subscriber_id);$token=JourneyToken::lockForUpdate()->find($id);if(!$token||!in_array($token->status,['pending','waiting']))return;
   $sub=$token->subscriber;$journey=$sub->journey;
   if($journey->status!=='active')return;
   if($journey->end_date&&now()->gt($journey->end_date)){$token->update(['status'=>'cancelled']);$this->settle($sub);return;}
   if($token->resume_at&&now()->lt($token->resume_at))return;
   $graph=JourneyVersion::where('journey_id',$journey->id)->where('version',$sub->version)->firstOrFail()->graph;$node=collect($graph['nodes'])->firstWhere('id',$token->node_uuid);
   try{
    $result=DB::transaction(function()use($token,$node){Contact::lockForUpdate()->findOrFail($token->subscriber->contact_id);return app(NodeHandlers::class)->execute($token,$node);});
    if($result['wait']??false){$token->update(['status'=>'waiting','resume_at'=>now()->addMinutes($result['minutes']),'context'=>array_merge($token->context??[],['wait_started'=>now()->toIso8601String()])]);$sub->update(['status'=>'waiting']);return;}
    $token->update(['status'=>'completed','resume_at'=>null]);
    JourneyNodeLog::create(['journey_id'=>$journey->id,'contact_id'=>$sub->contact_id,'journey_token_id'=>$token->id,'node_uuid'=>$token->node_uuid,'status'=>'success','response'=>['port'=>$result['port']??null,'http_status'=>$result['response']['http_status']??null],'executed_at'=>now()]);
    if($result['stop']??false){$sub->tokens()->whereIn('status',['pending','waiting'])->update(['status'=>'cancelled']);}
    else foreach($graph['edges'] as $edge)if($edge['source']===$token->node_uuid&&($edge['sourceHandle']??'success')===$result['port'])$this->token($sub,$edge['target'],$token->id.':'.$edge['target'].':'.$result['port']);
   }catch(\Throwable $e){$token->update(['status'=>'failed']);JourneyNodeLog::create(['journey_id'=>$journey->id,'contact_id'=>$sub->contact_id,'journey_token_id'=>$token->id,'node_uuid'=>$token->node_uuid,'status'=>'failed','response'=>['error'=>mb_substr($e->getMessage(),0,400)],'executed_at'=>now()]);}
   $this->settle($sub);
  });
 }
 public function settle(JourneySubscriber $sub):void {$status=$sub->tokens()->where('status','pending')->exists()?'active':($sub->tokens()->where('status','waiting')->exists()?'waiting':($sub->tokens()->where('status','failed')->exists()?'failed':'completed'));$sub->update(['status'=>$status]);}
}

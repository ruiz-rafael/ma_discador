<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class QueueRouting {
 public static function campaigns(object $q):array{return collect([$q->campaign_id])->merge(DB::table('voice_queue_campaigns')->where('queue_id',$q->id)->pluck('campaign_id'))->filter()->unique()->map(fn($id)=>(int)$id)->values()->all();}
 public static function forCampaign(int $w,int $id){return DB::table('voice_live_queues')->where('workspace_id',$w)->where(fn($q)=>$q->where('campaign_id',$id)->orWhereIn('id',DB::table('voice_queue_campaigns')->where('campaign_id',$id)->select('queue_id')));}
 public static function incoming(?object $q):bool{return $q&&in_array($q->direction,['inbound','mixed'],true)&&(bool)$q->inbound_enabled;}
 public function configure(int $w,int $u,array $d,?int $id):object{return DB::transaction(function()use($w,$u,$d,$id){
  DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();app(VoiceLiveQueue::class)->settle();
  $old=$id?DB::table('voice_live_queues')->where('workspace_id',$w)->where('id',$id)->firstOrFail():null;
  $direction=$d['direction']??$old?->direction??'outbound';$campaigns=$d['campaign_ids']??(isset($d['campaign_id'])?[$d['campaign_id']]:[]);$campaigns=array_values(array_unique(array_map('intval',$campaigns)));
  abort_if($direction==='inbound'&&$campaigns,422,'Filas receptivas recebem números, não campanhas de saída.');
  abort_unless(DB::table('voice_campaigns')->where('workspace_id',$w)->whereIn('id',$campaigns)->count()===count($campaigns),422,'Selecione campanhas deste workspace.');
  abort_unless(DB::table('users')->where('voice_workspace_id',$w)->where('voice_enabled',true)->whereIn('id',$d['agent_ids'])->count()===count($d['agent_ids']),422,'Selecione atendentes ativos deste workspace.');
  if($old){abort_unless($old->status==='paused'&&$old->revision===($d['revision']??-1),409,'Pause a saída da fila e atualize os dados antes de editar.');abort_if(DB::table('voice_live_reservations')->where('queue_id',$id)->whereIn('status',VoiceLiveQueue::ACTIVE)->exists()||DB::table('voice_inbound_calls')->where('queue_id',$id)->where(fn($q)=>$q->whereNull('capacity_released_at')->orWhere('status','tabulation'))->exists(),409,'Conclua os atendimentos e as tabulações desta fila.');abort_if($direction==='outbound'&&DB::table('voice_inbound_routes')->where('queue_id',$id)->exists(),422,'Transfira as rotas receptivas antes de tornar esta fila somente de saída.');}
  foreach($campaigns as $campaign)abort_if(self::forCampaign($w,$campaign)->when($id,fn($q)=>$q->where('id','!=',$id))->exists(),409,'Uma campanha selecionada já pertence a outra fila de saída. Remova o vínculo anterior primeiro.');
  $values=['allow_landline'=>$d['allow_landline']??$old?->allow_landline??true,'allow_mobile'=>$d['allow_mobile']??$old?->allow_mobile??true,'manual_enabled'=>$d['manual_enabled']??$old?->manual_enabled??true,'name'=>$d['name'],'direction'=>$direction,'campaign_id'=>$campaigns[0]??null,'mode'=>$d['mode'],'calling_method'=>$d['calling_method']??$old?->calling_method??'programmable_voice','strategy'=>$d['strategy'],'wrapup_seconds'=>$d['wrapup_seconds'],'agent_ids'=>json_encode($d['agent_ids']),'updated_at'=>now()];
  if($old)DB::table('voice_live_queues')->where('id',$id)->update($values+['revision'=>$old->revision+1,'inbound_enabled'=>$direction==='outbound'?false:$old->inbound_enabled]);
  else $id=DB::table('voice_live_queues')->insertGetId($values+['workspace_id'=>$w,'status'=>'paused','inbound_enabled'=>false,'created_at'=>now()]);
  DB::table('voice_queue_campaigns')->where('queue_id',$id)->delete();foreach($campaigns as $campaign)DB::table('voice_queue_campaigns')->insert(['queue_id'=>$id,'campaign_id'=>$campaign]);
  app(VoiceLab::class)->audit($w,$u,'live_queue.configured',$id,['direction'=>$direction,'campaign_ids'=>$campaigns]);return DB::table('voice_live_queues')->find($id);
 });}
}

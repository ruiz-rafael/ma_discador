<?php

namespace App\Http\Controllers;

use App\Services\{VoiceSupervision,VoiceLab};
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VoiceSupervisionController extends Controller
{
    private function workspace(Request $r): int
    {
        abort_unless($r->user()?->voice_workspace_id===1 && $r->user()->voice_enabled && in_array($r->user()->voice_role,['admin','supervisor'],true),403);
        return (int)$r->user()->voice_workspace_id;
    }

    private function filters(Request $r,int $w): array
    {
        $f=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from',
            'queue_id'=>'nullable|integer|min:1','campaign_id'=>'nullable|integer|min:1','user_id'=>'nullable|integer|min:1',
            'direction'=>'nullable|in:inbound,outbound','result'=>['nullable',Rule::in(array_keys(VoiceSupervision::RESULTS))],
            'q'=>'nullable|string|max:100','page'=>'sometimes|integer|min:1|max:100000','live'=>'sometimes|boolean','attempted'=>'sometimes|boolean']);
        $today=now('America/Sao_Paulo')->toDateString();$f['from']=$f['from']??$today;$f['to']=$f['to']??$today;
        abort_if($f['to']<$f['from']||CarbonImmutable::parse($f['from'])->diffInDays(CarbonImmutable::parse($f['to']))>30,422,'Selecione um período de até 31 dias.');
        foreach(['queue_id'=>'voice_live_queues','campaign_id'=>'voice_campaigns','user_id'=>'users'] as $key=>$table)if(!empty($f[$key])){
            $f[$key]=(int)$f[$key];abort_unless(DB::table($table)->where($key==='user_id'?'voice_workspace_id':'workspace_id',$w)->where('id',$f[$key])->exists(),404);
        }
        return $f;
    }

    public function index(Request $r) { $w=$this->workspace($r);return response()->json(app(VoiceSupervision::class)->snapshot($w,$this->filters($r,$w)))->header('Cache-Control','no-store, private'); }
    public function calls(Request $r) { $w=$this->workspace($r);return response()->json(app(VoiceSupervision::class)->calls($w,$this->filters($r,$w)))->header('Cache-Control','no-store, private'); }
    public function pause(Request $r,int $id)
    {
        $w=$this->workspace($r);$d=$r->validate(['channel'=>'required|in:inbound,outbound','revision'=>'required|integer|min:0']);
        DB::transaction(function()use($w,$r,$id,$d){
            DB::table('voice_runtime')->where('id',1)->lockForUpdate()->firstOrFail();
            $q=DB::table('voice_live_queues')->where('workspace_id',$w)->where('id',$id)->firstOrFail();
            abort_unless($q->revision===$d['revision'],409,'A fila mudou. Atualize antes de confirmar a pausa.');
            abort_if($d['channel']==='inbound'&&$q->direction==='outbound'||$d['channel']==='outbound'&&$q->direction==='inbound',422,'Canal incompatível com a fila.');
            $values=$d['channel']==='inbound'?['inbound_enabled'=>false]:['status'=>'paused'];
            DB::table('voice_live_queues')->where('id',$id)->update($values+['revision'=>$q->revision+1,'updated_at'=>now()]);
            app(VoiceLab::class)->audit($w,$r->user()->id,'supervision.queue_paused',$id,['channel'=>$d['channel']]);
        });
        return response()->json(['saved'=>true]);
    }
}

<?php
namespace App\Http\Controllers;

use App\Services\AiSupervision;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiSupervisionController extends Controller
{
    private function workspace(Request $r): int
    {
        abort_unless($r->user()?->voice_enabled&&$r->user()->voice_workspace_id&&in_array($r->user()->voice_role,['admin','supervisor'],true),403);
        return (int)$r->user()->voice_workspace_id;
    }

    public function index(Request $r,AiSupervision $service)
    {
        $w=$this->workspace($r);
        $f=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d','scope'=>'sometimes|in:production,tests','agent_id'=>'nullable|uuid','channel'=>'nullable|in:whatsapp,phone,text,voice','outcome'=>'nullable|in:active,handoff,failure,stop,closed,expired','page'=>'sometimes|integer|min:1|max:100000']);
        $today=CarbonImmutable::now(AiSupervision::TIMEZONE);
        $f['from']=$f['from']??$today->subDays(6)->toDateString();$f['to']=$f['to']??$today->toDateString();$f['scope']=$f['scope']??'production';
        abort_if($f['to']<$f['from']||CarbonImmutable::parse($f['from'])->diffInDays(CarbonImmutable::parse($f['to']))>30,422,'Selecione um período de até 31 dias.');
        if(!empty($f['agent_id']))DB::table('ai_agents')->where('workspace_id',$w)->where('id',$f['agent_id'])->firstOrFail();
        if(!empty($f['channel']))abort_unless(in_array($f['channel'],$f['scope']==='production'?['whatsapp','phone']:['text','voice'],true),422,'Escolha um canal compatível com atendimento real ou testes.');
        return response()->json($service->snapshot($w,$f))->header('Cache-Control','no-store, private');
    }

    public function show(Request $r,AiSupervision $service,string $id)
    {
        $w=$this->workspace($r);$d=$r->validate(['page'=>'sometimes|integer|min:1|max:100000']);
        return response()->json($service->detail($w,$id,$d['page']??1))->header('Cache-Control','no-store, private');
    }
}

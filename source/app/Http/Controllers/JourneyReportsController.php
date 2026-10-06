<?php
namespace App\Http\Controllers;
use App\Services\JourneyReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JourneyReportsController extends Controller
{
    private function workspace(Request $r): int
    {
        abort_unless($r->user()->voice_workspace_id && in_array($r->user()->voice_role,['admin','supervisor'],true),403);
        return (int)$r->user()->voice_workspace_id;
    }
    private function filters(Request $r,int $w,bool $details=false): array
    {
        $f=$r->validate(['run_id'=>'nullable|uuid','campaign_id'=>'nullable|integer|min:1','from'=>'nullable|date_format:Y-m-d|after_or_equal:2020-01-01','to'=>'nullable|date_format:Y-m-d|after_or_equal:2020-01-01',
            'day'=>'nullable|date_format:Y-m-d','page'=>'sometimes|integer|min:1|max:100000',
            'metric'=>[$details?'required':'sometimes',Rule::in(JourneyReports::METRICS)],
            'button_id'=>'nullable|string|max:64','button_label'=>'nullable|string|max:100','phone'=>['nullable','regex:/^[+0-9]{2,20}$/D']]);
        $today=CarbonImmutable::now('America/Sao_Paulo');$f['from']=$f['from']??$today->subDays(29)->toDateString();$f['to']=$f['to']??$today->toDateString();
        abort_unless($f['to']>=$f['from'] && CarbonImmutable::parse($f['from'])->diffInDays(CarbonImmutable::parse($f['to']))<=365,422,'Selecione um período de até 366 dias, com início anterior ao fim.');
        abort_if(!empty($f['day'])&&($f['day']<$f['from']||$f['day']>$f['to']),422,'Dia fora do período selecionado.');
        abort_if((!empty($f['button_id'])||isset($f['button_label']))&&($f['metric']??'')!=='button_clicks',422,'Filtro de botão exige o indicador de cliques.');
        if(!empty($f['campaign_id']))DB::table('voice_campaigns')->where('workspace_id',$w)->where('id',$f['campaign_id'])->firstOrFail();
        if(!empty($f['run_id']))DB::table('voice_cadence_runs')->where('workspace_id',$w)->when(!empty($f['campaign_id']),fn($q)=>$q->where('campaign_id',$f['campaign_id']))->where('id',$f['run_id'])->firstOrFail();
        return $f;
    }
    public function index(Request $r,JourneyReports $reports)
    {
        $w=$this->workspace($r);return response()->json($reports->dashboard($w,$this->filters($r,$w)))->header('Cache-Control','no-store, private');
    }
    public function details(Request $r,JourneyReports $reports)
    {
        $w=$this->workspace($r);return response()->json($reports->details($w,$this->filters($r,$w,true)))->header('Cache-Control','no-store, private');
    }
    public function record(Request $r,JourneyReports $reports,string $kind,string $id)
    {
        return response()->json($reports->record($this->workspace($r),$kind,$id))->header('Cache-Control','no-store, private');
    }
}

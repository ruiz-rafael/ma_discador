<?php

namespace App\Http\Controllers;

use App\Services\VoiceEligibility;
use App\Services\VoiceLists;
use App\Services\VoiceLiveQueue;
use App\Services\VoiceProviderCatalog;
use App\Services\VoiceReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VoiceOperationsController extends Controller
{
    private function workspace(Request $r, bool $manage = false): int
    {
        $w = (int) $r->user()->voice_workspace_id;
        abort_unless($w === 1, 403);
        if ($manage) {
            abort_unless(in_array($r->user()->voice_role, ['admin', 'supervisor']), 403, 'Ação reservada à supervisão.');
        }

return $w;
    }

    public function catalog(Request $r)
    {
        $w = $this->workspace($r);
        $manage = in_array($r->user()->voice_role, ['admin', 'supervisor']);

        if (! $manage) {
            $queues = app(VoiceLiveQueue::class)->snapshot($w, $r->user()->id)['queues'];
            return response()->json(['can_manage'=>false,'user_id'=>$r->user()->id,
                'campaigns'=>DB::table('voice_campaigns')->where('workspace_id',$w)->whereIn('id',$queues->flatMap(fn($q)=>$q->campaign_ids)->unique())->get(['id','name','status']),
                'agents'=>[['id'=>$r->user()->id,'name'=>$r->user()->name]],
                'codes'=>DB::table('voice_disposition_codes')->where('workspace_id',$w)->where('active',true)->get(),
                'lists'=>[],'policies'=>[],'origins'=>[]])->header('Cache-Control','no-store, private');
        }
        return response()->json(['queue_channels'=>app(\App\Services\QueueChannels::class)->catalog($w),'can_manage' => $manage, 'user_id' => $r->user()->id, 'campaigns' => DB::table('voice_campaigns')->where('workspace_id', $w)->get(['id', 'name', 'status']), 'agents' => DB::table('users')->where('voice_workspace_id', $w)->get(['id', 'name']), 'codes' => DB::table('voice_disposition_codes')->where('workspace_id', $w)->get(), 'lists' => DB::table('voice_lists')->where('workspace_id', $w)->get(), 'policies' => DB::table('voice_campaign_policies')->whereIn('campaign_id', DB::table('voice_campaigns')->where('workspace_id', $w)->select('id'))->get()->map(function ($p) {
            $p->retry_minutes = json_decode($p->retry_minutes, true);
            $p->default_retry_minutes = json_decode(DB::table('voice_campaigns')->where('id', $p->campaign_id)->value('settings'), true)['retry_minutes'];

            return $p;
        }), 'origins' => $manage ? DB::table('voice_origins')->where('workspace_id', $w)->get() : []])->header('Cache-Control', 'no-store, private');
    }

    private function filters(Request $r): array
    {
        return $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'timezone' => 'sometimes|timezone', 'campaign_id' => 'nullable|integer', 'list_id' => 'nullable|integer', 'queue_id' => 'nullable|integer', 'user_id' => 'nullable|integer', 'method' => 'nullable|in:sip_trunk,programmable_voice', 'status' => 'nullable|in:pending,dialing,answered,completed,no_answer,busy,failed,cancelled,unknown', 'environment' => 'nullable|in:homologation,production', 'disposition_code' => 'nullable|string|max:40', 'phone' => ['nullable', 'regex:/^[+0-9]{2,20}$/D'], 'caller_id' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/D'], 'pending_disposition' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1|max:100000']);
    }

    public function reports(Request $r)
    {
        return response()->json(app(VoiceReports::class)->snapshot($this->workspace($r), $r->user(), $this->filters($r)))->header('Cache-Control', 'no-store, private');
    }

    public function export(Request $r)
    {
        $w = $this->workspace($r);
        $f = $this->filters($r);
        $service = app(VoiceReports::class);
        $q = $service->rows($service->query($w, $r->user(), $f));
        abort_if((clone $q)->count() > 10000, 422, 'Restrinja os filtros para exportar até 10.000 chamadas.');

        return response()->streamDownload(function () use ($q, $f) {
            $fp = fopen('php://output', 'w');
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, ['Gerado em UTC', now()->toIso8601String(), 'Filtros', json_encode($f, JSON_UNESCAPED_UNICODE)], ';', '"', '');
            fputcsv($fp, ['ID', 'Ambiente', 'Campanha', 'Atendente', 'Contato', 'Destino', 'Origem', 'Método', 'Resultado técnico', 'Tabulação', 'Humano confirmado', 'Início UTC', 'Término UTC', 'Segundos de conversa', 'Retorno UTC', 'Notas'], ';', '"', '');
            foreach ($q->cursor() as $c) {
                $snapshot = json_decode($c->context_snapshot ?? '{}', true);
                foreach (['contact_name', 'campaign_name', 'agent_name'] as $key) {
                    if (array_key_exists($key, $snapshot)) {
                        $c->$key = $snapshot[$key];
                    }
                }fputcsv($fp, array_map([VoiceReports::class, 'csvCell'], [$c->id, $c->environment, $c->campaign_name, $c->agent_name, $c->contact_name, $c->destination, $c->caller_id, $c->method, $c->status, $c->disposition_label, $c->human_confirmed === null ? 'desconhecido' : ($c->human_confirmed ? 'sim' : 'não'), $c->started_at, $c->ended_at, $c->bill_seconds, $c->callback_at, $c->disposition_notes]), ';', '"', '');
            }fclose($fp);
        }, 'chamadas-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    public function disposition(Request $r, string $id)
    {
        $d = $r->validate(['revision' => 'required|integer|min:0', 'idempotency_key' => 'required|uuid', 'code' => 'required|string|max:40', 'notes' => 'nullable|string|max:2000', 'callback_at' => 'nullable|date|after:now', 'correction_reason' => 'nullable|string|min:8|max:500']);

        return app(VoiceReports::class)->tabulate($this->workspace($r), $r->user(), $id, $d);
    }

    public function history(Request $r, string $id)
    {
        $w = $this->workspace($r);
        $c = DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
        abort_unless($c->user_id === $r->user()->id || in_array($r->user()->voice_role, ['admin', 'supervisor']), 403);

        return DB::table('voice_dispositions')->where('call_id', $id)->orderBy('revision')->get();
    }

    public function code(Request $r)
    {
        $w = $this->workspace($r, true);
        $d = $r->validate(['code' => ['required', 'regex:/^[a-z][a-z0-9_]{2,39}$/D'], 'label' => 'required|string|max:100', 'human' => 'required|boolean', 'notes_required' => 'required|boolean', 'active' => 'required|boolean']);
        abort_unless(! in_array($d['code'], ['converted', 'callback', 'opt_out']) || $d['human'], 422, 'Esta tabulação pressupõe contato humano.');
        DB::table('voice_disposition_codes')->updateOrInsert(['workspace_id' => $w, 'code' => $d['code']], $d + ['updated_at' => now()]);

        return response()->json(['saved' => true]);
    }

    public function costs(Request $r, string $id)
    {
        return app(VoiceProviderCatalog::class)->costs($this->workspace($r, true), $id);
    }

    public function originsSync(Request $r)
    {
        return app(VoiceProviderCatalog::class)->sync($this->workspace($r, true));
    }

    public function origin(Request $r, int $id)
    {
        $w = $this->workspace($r, true);
        $d = $r->validate(['enabled' => 'required|boolean']);
        $s = DB::table('voice_origins')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
        abort_if($d['enabled'] && ! $s->verified_at, 422, 'Sincronize a origem antes de habilitar.');
        DB::table('voice_origins')->where('id', $id)->update($d + ['updated_at' => now()]);

        return response()->json(['saved' => true]);
    }

    public function createList(Request $r)
    {
        $w = $this->workspace($r, true);
        $d = $r->validate(['name' => 'required|string|max:160', 'source' => 'required|string|max:300']);
        $id = DB::table('voice_lists')->insertGetId($d + ['workspace_id' => $w, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('voice_lists')->find($id);
    }

    public function members(Request $r, int $id)
    {
        $w = $this->workspace($r);
        DB::table('voice_lists')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
        $d = $r->validate(['page' => 'sometimes|integer|min:1', 'q' => 'nullable|string|max:100']);
        $q = DB::table('voice_list_members as m')->join('voice_contacts as c', 'c.id', '=', 'm.contact_id')->where('m.list_id', $id);
        if (! empty($d['q'])) {
            $q->where(fn ($q) => $q->where('c.name', 'like', '%'.$d['q'].'%')->orWhere('c.phone', 'like', '%'.$d['q'].'%'));
        }$rows = $q->select(['m.*', 'c.name', 'c.phone', 'c.suppressed_at', 'c.replied_at', 'c.consent'])->orderBy('m.id')->paginate(30);
        $campaigns = DB::table('voice_campaigns')->where('workspace_id', $w)->whereIn('id', DB::table('voice_campaign_policies')->where('list_id', $id)->select('campaign_id'))->get();
        foreach ($rows as $row) {
            $row->attempts = DB::table('voice_outbound_calls')->where('workspace_id', $w)->where('contact_id', $row->contact_id)->whereNotNull('started_at')->count();
            $contact = DB::table('voice_contacts')->find($row->contact_id);
            $row->eligibility = $campaigns->map(fn ($campaign) => ['campaign' => $campaign->name, 'reason' => app(VoiceEligibility::class)->reason($w, $campaign, $contact)]);
        }

return $rows;
    }

    public function member(Request $r, int $id)
    {
        $w = $this->workspace($r, true);
        $d = $r->validate(['status' => 'required|in:active,removed', 'reason' => 'required|string|min:5|max:300']);
        app(VoiceLists::class)->member($w, $r->user()->id, $id, $d['status'], $d['reason']);

        return response()->json(['saved' => true]);
    }

    public function preview(Request $r, int $id)
    {
        $w = $this->workspace($r, true);
        $d = $r->validate(['file' => 'required|file|max:2048|extensions:csv,txt,xlsx', 'delimiter' => ['required', Rule::in([';', ','])], 'name_column' => 'required|string|max:80', 'phone_column' => 'required|string|max:80', 'source' => 'required|string|max:300', 'consent' => 'required|boolean', 'consent_evidence' => 'nullable|required_if:consent,true|string|min:8|max:2000']);
        unset($d['file']);

        return app(VoiceLists::class)->preview($w, $r->user()->id, $id, $r->file('file'), $d);
    }

    public function importErrors(Request $r, string $id)
    {
        $w = $this->workspace($r, true);
        $b = DB::table('voice_imports')->where('workspace_id', $w)->where('id', $id)->firstOrFail();

        return response()->streamDownload(function () use ($b) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['Linha', 'Erro'], ';', '"', '');
            foreach (json_decode($b->errors, true) as $error) {
                fputcsv($f, array_map([VoiceReports::class, 'csvCell'], [$error['line'], $error['message']]), ';', '"', '');
            }fclose($f);
        }, 'erros-importacao.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function commit(Request $r, string $id)
    {
        return app(VoiceLists::class)->commit($this->workspace($r, true), $r->user()->id, $id);
    }

    public function policy(Request $r, int $id)
    {
        $w = $this->workspace($r, true);
        $d = $r->validate(['revision' => 'required|integer|min:0', 'list_id' => 'nullable|integer', 'daily_per_contact' => 'required|integer|between:1,20', 'global_daily' => 'required|integer|between:1,100', 'global_total' => 'required|integer|between:1,1000', 'technical_limit' => 'required|integer|between:1,10', 'expires_at' => 'nullable|date|after:now', 'retry_minutes' => 'required|array:no_answer,busy,failed,cancelled', 'retry_minutes.*' => 'required|integer|between:1,10080', 'origin_mode' => 'required|in:configured,same_ddd']);

        return app(VoiceLists::class)->policy($w, $r->user()->id, $id, $d);
    }

    public function queues(Request $r)
    {
        return response()->json(app(VoiceLiveQueue::class)->snapshot($this->workspace($r), $r->user()->id))->header('Cache-Control', 'no-store, private');
    }

    public function queue(Request $r, ?int $id = null)
    {
        $w = $this->workspace($r, true);
        $d = $r->validate(['incoming_number'=>['sometimes','nullable','regex:/^\+[1-9][0-9]{7,14}$/D'],'voice_number'=>['sometimes','nullable','regex:/^\+[1-9][0-9]{7,14}$/D'],'whatsapp_sender_id'=>'sometimes|nullable|integer|min:1','number_mode'=>'sometimes|in:single,separate','name' => 'required|string|max:160', 'campaign_id' => 'nullable|integer|min:1', 'campaign_ids'=>'sometimes|array|max:100', 'campaign_ids.*'=>'integer|min:1|distinct', 'direction'=>'sometimes|in:inbound,outbound,mixed','calling_method'=>'sometimes|in:programmable_voice,sip_trunk','manual_enabled'=>'sometimes|boolean','allow_landline'=>'sometimes|boolean','allow_mobile'=>'sometimes|boolean', 'mode' => 'required|in:preview,progressive', 'strategy' => 'required|in:fifo', 'wrapup_seconds' => 'required|integer|between:0,120', 'agent_ids' => 'required|array|min:1|max:100', 'agent_ids.*' => 'required|integer|distinct', 'revision' => 'sometimes|integer|min:0']);

        return app(VoiceLiveQueue::class)->configure($w, $r->user()->id, $d, $id);
    }

    public function queueStatus(Request $r, int $id)
    {
        $w = $this->workspace($r, true);
        $d = $r->validate(['status' => 'required|in:running,paused','channel'=>'sometimes|in:inbound,outbound']);
        DB::transaction(function () use ($w, $id, $d) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $q = DB::table('voice_live_queues')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
            $channel=$d['channel']??'outbound';abort_if($channel==='inbound'&&$q->direction==='outbound'||$channel==='outbound'&&$q->direction==='inbound',422,'Canal incompatível com o tipo da fila.');
            abort_if($channel==='outbound'&&$d['status']==='running'&&!$q->manual_enabled&&!\App\Services\QueueRouting::campaigns($q),422,'Vincule uma campanha ou permita a discagem manual antes de habilitar a saída.');
            $values=$channel==='inbound'?['inbound_enabled'=>$d['status']==='running']:['status'=>$d['status']];
            DB::table('voice_live_queues')->where('id', $id)->update($values + ['revision' => $q->revision + 1, 'updated_at' => now()]);
        });

        return response()->json(['saved' => true]);
    }

    public function claim(Request $r,int $id)
    {
        $d = $r->validate(['idempotency_key' => 'required|uuid']);

        return app(VoiceLiveQueue::class)->claim($this->workspace($r),$r->user()->id,$id,$d['idempotency_key']);
    }

    public function manual(Request $r) { $d=$r->validate(['queue_id'=>'required|integer|min:1','number'=>'required|string|max:40','idempotency_key'=>'required|uuid']);return app(\App\Services\ManualDial::class)->reserve($this->workspace($r),$r->user()->id,$d); }

    public function cancelReservation(Request $r,string $id)
    {
        app(VoiceLiveQueue::class)->cancel($this->workspace($r),$r->user()->id,$id);

        return response()->json(['saved' => true]);
    }
}

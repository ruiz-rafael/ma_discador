<?php

namespace App\Http\Controllers;

use App\Services\VoiceJourneyOverview;
use App\Services\VoiceLab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Edits the existing dialer rules. Canvas positions never determine execution. */
class VoiceJourneyEditorController extends Controller
{
    private const FIELDS = [
        'entry' => ['list_kind', 'list_id', 'contact_ids', 'crm_campaign_id', 'segment_id'],
        'voice' => ['mode', 'max_attempts', 'whatsapp_after', 'timezone', 'days', 'start_time', 'end_time', 'concurrency', 'script'],
        'wait' => ['retry_minutes'],
        'decision' => ['whatsapp_after'],
        'message' => ['whatsapp_enabled', 'whatsapp_delivery', 'number_mode', 'whatsapp_number', 'whatsapp_sender_id', 'whatsapp_after', 'whatsapp_delay', 'whatsapp_text', 'whatsapp_qr_template_id', 'whatsapp_qr_buttons_confirmed', 'whatsapp_real_template_id', 'whatsapp_template_id', 'whatsapp_variables'],
    ];

    private function workspace(Request $r): int
    {
        abort_unless($r->user()->voice_workspace_id && in_array($r->user()->voice_role, ['admin', 'supervisor']), 403, 'A edição é reservada à supervisão.');
        return (int) $r->user()->voice_workspace_id;
    }

    public function node(Request $r, int $id, string $node): array
    {
        $w = $this->workspace($r);
        abort_unless(isset(self::FIELDS[$node]), 422, 'Esta etapa tem uma regra fixa de encerramento.');
        $d = $r->validate(['revision' => 'required|integer|min:0', 'config' => 'required|array:'.implode(',', self::FIELDS[$node]), 'config.list_kind' => 'sometimes|in:voice,automation', 'config.list_id' => 'sometimes|nullable|integer|min:1', 'config.contact_ids' => 'sometimes|array|max:500', 'config.contact_ids.*' => 'integer|distinct']);
        return DB::transaction(function () use ($r, $w, $id, $node, $d) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            $c = DB::table('voice_campaigns')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
            abort_unless($c->revision === $d['revision'], 409, 'A jornada mudou. Atualize antes de salvar esta etapa.');
            $s = json_decode($c->settings, true);
            $config = $d['config'];
            $ids = DB::table('voice_members')->where('campaign_id', $id)->pluck('contact_id')->all();
            $policy = DB::table('voice_campaign_policies')->where('campaign_id', $id)->first();
            if ($node === 'entry') {
                $kind = $config['list_kind'] ?? (array_key_exists('list_id', $config) ? 'voice' : ($policy?->audience_id ? 'automation' : 'voice'));
                $sourceId = array_key_exists('list_id', $config) ? $config['list_id'] : ($policy?->audience_id ?? $policy?->list_id);
                $audienceId = $kind === 'automation' ? $sourceId : null;
                $listId = $kind === 'voice' ? $sourceId : null;
                if ($audienceId) {
                    $ids = app(\App\Services\VoiceAudience::class)->prepare($w, $audienceId);
                } elseif ($listId) {
                    DB::table('voice_lists')->where('workspace_id', $w)->where('id', $listId)->firstOrFail();
                    $ids = DB::table('voice_list_members')->where('list_id', $listId)->where('status', 'active')->pluck('contact_id')->all();
                    abort_if(count($ids) > 500, 422, 'Selecione uma lista com até 500 contatos nesta versão.');
                } else {
                    $ids = $config['contact_ids'] ?? $ids;
                }
                unset($config['list_kind'], $config['list_id'], $config['contact_ids']);
            }
            $s = array_replace($s, $config);
            if ($node === 'message' && ($s['number_mode'] ?? 'single') === 'single') {
                $s['whatsapp_number'] = $s['business_number'] ?? null;
            }
            // Reuse all campaign validation, workspace checks, optimistic locking,
            // pending-message cancellation and paused/in-flight restrictions.
            $request = Request::create('/', 'PUT', ['name' => $c->name, 'revision' => $d['revision'], 'contact_ids' => $ids, 'settings' => $s]);
            $request->setUserResolver(fn () => $r->user());
            app(VoiceLabController::class)->campaign($request, $id);
            if ($node === 'entry') {
                if (($policy?->list_id ?? null) !== $listId || ($policy?->audience_id ?? null) !== $audienceId) {
                    DB::table('voice_campaigns')->where('id', $id)->increment('followup_revision');
                    DB::table('voice_followups')->where('campaign_id', $id)->whereIn('status', ['pending','blocked'])->update(['status'=>'cancelled','reason'=>'Origem do público alterada.','updated_at'=>now()]);
                }
                if ($policy) {
                    DB::table('voice_campaign_policies')->where('campaign_id', $id)->update(['list_id' => $listId, 'audience_id' => $audienceId, 'revision' => $policy->revision + 1, 'updated_at' => now()]);
                } else {
                    DB::table('voice_campaign_policies')->insert(['campaign_id' => $id, 'list_id' => $listId, 'audience_id' => $audienceId, 'retry_minutes' => '{}', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            if ($node === 'wait' && $policy) {
                $retry = json_decode($policy->retry_minutes, true);
                unset($retry['no_answer']);
                DB::table('voice_campaign_policies')->where('campaign_id', $id)->update(['retry_minutes' => json_encode((object) $retry), 'revision' => $policy->revision + 1, 'updated_at' => now()]);
            }
            if ($node === 'voice' && isset($config['mode'])) {
                $q = DB::table('voice_live_queues')->where('campaign_id', $id);
                abort_if((clone $q)->where('status', 'running')->exists(), 409, 'Pause a fila antes de alterar o modo de discagem.');
                $q->update(['mode' => $config['mode'], 'updated_at' => now()]);
            }
            app(VoiceLab::class)->audit($w, $r->user()->id, 'journey.node.configured', $id, ['node' => $node]);
            return ['journey' => collect(app(VoiceJourneyOverview::class)->get($w))->firstWhere('id', $id)];
        });
    }

    public function layout(Request $r, int $id): array
    {
        $w = $this->workspace($r);
        $d = $r->validate([
            'revision' => 'required|integer|min:0',
            'positions' => 'required|array|size:6', 'positions.*' => 'required|array:id,x,y',
            'positions.*.id' => ['required', 'distinct', Rule::in(['entry', 'voice', 'decision', 'wait', 'message', 'exit'])],
            'positions.*.x' => 'required|numeric|between:-5000,5000', 'positions.*.y' => 'required|numeric|between:-5000,5000',
        ]);
        return DB::transaction(function () use ($r, $w, $id, $d) {
            DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail();
            DB::table('voice_campaigns')->where('workspace_id', $w)->where('id', $id)->firstOrFail();
            $old = DB::table('voice_journey_layouts')->where('campaign_id', $id)->first();
            abort_unless(($old->revision ?? 0) === $d['revision'], 409, 'A organização do mapa mudou. Atualize a página.');
            $revision = ($old->revision ?? 0) + 1;
            DB::table('voice_journey_layouts')->updateOrInsert(['campaign_id' => $id], ['positions' => json_encode($d['positions']), 'revision' => $revision, 'created_at' => $old->created_at ?? now(), 'updated_at' => now()]);
            app(VoiceLab::class)->audit($w, $r->user()->id, 'journey.layout.saved', $id);
            return ['revision' => $revision, 'positions' => $d['positions']];
        });
    }
}

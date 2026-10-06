<?php

namespace App\Services;

use App\Models\Audience;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Membership management only: no provider requests or automatic journey enrollment. */
class ListContacts
{
    public const BASE = ['name', 'email', 'phone', 'source', 'crm_contact_id', 'consent', 'consent_evidence'];
    public function lock(): void { DB::table('voice_runtime')->where('id', 1)->lockForUpdate()->firstOrFail(); }
    public function list(int $w, string $kind, int $id): object
    {
        abort_unless($w === 1 && in_array($kind, ['automation', 'voice']), 404);
        $q = DB::table($kind === 'voice' ? 'voice_lists' : 'audiences')->where('id', $id);
        if ($kind === 'voice') $q->where('workspace_id', $w);
        return $q->firstOrFail();
    }
    public function settings(int $w, string $kind, int $id): array
    {
        $s = DB::table('ma_list_settings')->where('workspace_id', $w)->where('kind', $kind)->where('list_id', $id)->first();
        return ['mode'=>$s?->mode??'manual','rule_match'=>$s?->rule_match??'all','rules'=>$s?->rules?json_decode($s->rules,true):[], 'revision' => $s?->revision ?? 0, 'fields' => $s ? json_decode($s->fields, true) : []];
    }
    public function targets(array $schema): array
    {
        return array_merge(self::BASE, array_map(fn ($f) => 'fields.'.$f['key'], $schema));
    }
    public function configure(int $w, string $kind, int $id, array $d): array
    {
        return DB::transaction(function () use ($w, $kind, $id, $d) {
            $this->lock(); $this->list($w, $kind, $id); $s = $this->settings($w, $kind, $id);
            abort_unless($s['revision'] === $d['revision'], 409, 'A lista mudou. Atualize antes de salvar.');
            foreach ($d['fields'] as $field) abort_if(in_array($field['key'], array_merge(self::BASE, ['constructor', 'prototype', '__proto__'])), 422, 'Nome de campo reservado.');
            $segment=app(Segments::class)->validate($w,['mode'=>$d['mode']??$s['mode'],'rule_match'=>$d['rule_match']??$s['rule_match'],'rules'=>$d['rules']??$s['rules']],$d['fields']);
            $targets = $this->targets($d['fields']);
            foreach (DB::table('ma_list_webhooks')->where('workspace_id', $w)->where('kind', $kind)->where('list_id', $id)->where('active', true)->get() as $hook) {
                foreach (json_decode($hook->mapping, true) as $m) abort_unless(in_array($m['target'], $targets), 422, 'Desative ou remapeie o webhook antes de remover um campo utilizado.');
            }
            DB::table($kind === 'voice' ? 'voice_lists' : 'audiences')->where('id', $id)->update(['name' => $d['name'], 'updated_at' => now()]);
            DB::table('ma_list_settings')->updateOrInsert(['workspace_id' => $w, 'kind' => $kind, 'list_id' => $id], ['mode'=>$segment['mode'],'rule_match'=>$segment['rule_match'],'rules'=>json_encode($segment['rules']), 'fields' => json_encode($d['fields']), 'revision' => $s['revision'] + 1, 'updated_at' => now(), 'created_at' => now()]);
            app(Segments::class)->refresh($w,$kind,$id);
            return $this->settings($w, $kind, $id);
        });
    }
    public function mapping(array $mapping, array $schema, bool $paths = false): array
    {
        Validator::make(['mapping' => $mapping], ['mapping' => 'required|array|min:1|max:27', 'mapping.*' => 'required|array:source,target', 'mapping.*.source' => 'required|string|max:160', 'mapping.*.target' => ['required', 'distinct', Rule::in($this->targets($schema))]])->validate();
        if ($paths) foreach ($mapping as $m) {
            abort_unless(preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.(?:[A-Za-z_][A-Za-z0-9_]*|[0-9]+)){0,9}$/D', $m['source']), 422, 'Use caminhos como cliente.nome, sem expressões ou curingas.');
            foreach (explode('.', $m['source']) as $part) abort_if(in_array($part, ['__proto__', 'prototype', 'constructor']), 422, 'Caminho reservado.');
        }
        abort_unless(in_array('name', array_column($mapping, 'target')), 422, 'Mapeie o nome do contato.');
        return $mapping;
    }
    public function map(array $payload, array $mapping, bool $paths = false): array
    {
        $data = ['fields' => []];
        foreach ($mapping as $m) {
            $value = $paths ? data_get($payload, $m['source']) : ($payload[$m['source']] ?? null);
            if (str_starts_with($m['target'], 'fields.')) $data['fields'][substr($m['target'], 7)] = $value;
            else $data[$m['target']] = $value;
        }
        return $data;
    }
    public function normalize(string $kind, array $schema, array $data): array
    {
        foreach (['name', 'email', 'phone', 'source', 'crm_contact_id', 'consent_evidence'] as $key) {
            if (isset($data[$key])) { abort_unless(is_string($data[$key]), 422, 'O campo '.$key.' deve ser texto.'); $data[$key] = trim($data[$key]); }
            if (($data[$key] ?? null) === '') $data[$key] = null;
        }
        $data['email'] = isset($data['email']) ? mb_strtolower($data['email']) : null;
        $data['consent'] = $this->boolean($data['consent'] ?? false, 'consent');
        if (! empty($data['phone'])) {
            abort_unless(preg_match('/^\+?[0-9 ()\.\-]{8,60}$/D', $data['phone']), 422, 'Telefone inválido. Use DDI/DDD e número, sem fórmulas ou notação científica.');
            $data['phone'] = VoiceLab::phone($data['phone']);
        }
        $keys = array_column($schema, 'key'); $fields = $data['fields'] ?? [];
        abort_unless(is_array($fields) && ! array_diff(array_keys($fields), $keys), 422, 'Cadastre os campos adicionais na lista antes de utilizá-los.');
        foreach ($schema as $field) {
            $value = $fields[$field['key']] ?? null;
            if ($value === '') $value = null;
            if ($field['required'] && $value === null) throw ValidationException::withMessages(['fields.'.$field['key'] => 'Preencha '.$field['label'].'.']);
            if ($value === null) { unset($fields[$field['key']]); continue; }
            if ($field['type'] === 'boolean') $value = $this->boolean($value, $field['label']);
            $rule = match ($field['type']) { 'number' => 'numeric|between:-1000000000000,1000000000000', 'boolean' => 'boolean', 'date' => 'date_format:Y-m-d', default => 'string|max:2000' };
            Validator::make(['value' => $value], ['value' => $rule], [], ['value' => $field['label']])->validate();
            $fields[$field['key']] = $field['type'] === 'number' ? (float) $value : $value;
        }
        $data['fields'] = $fields; $data['source'] = $data['source'] ?? 'Cadastro na lista';
        return Validator::make($data, ['name' => 'required|string|max:160', 'email' => 'nullable|email|max:200', 'phone' => ($kind === 'voice' ? 'required' : 'nullable').'|string|max:30', 'source' => 'required|string|max:300', 'crm_contact_id' => 'nullable|string|max:160', 'consent' => 'required|boolean', 'consent_evidence' => 'nullable|required_if:consent,true|string|min:5|max:1000', 'fields' => 'array'])->after(function ($v) use ($data) {
            if (empty($data['email']) && empty($data['phone'])) $v->errors()->add('contact', 'Informe e-mail ou telefone para identificar o contato.');
        })->validate();
    }
    private function boolean(mixed $v, string $label): bool
    {
        if (is_bool($v)) return $v;
        if (is_string($v)) $v = mb_strtolower(trim($v));
        if (in_array($v, [1, '1', 'true', 'sim'], true)) return true;
        if (in_array($v, [0, '0', 'false', 'não', 'nao', '', null], true)) return false;
        throw ValidationException::withMessages([$label => 'Informe verdadeiro/falso ou 1/0 para '.$label.'.']);
    }
    public function ingest(int $w, string $kind, int $list, array $data, bool $explicit = true): array
    {
        // Caller holds the runtime lock. Existing identities, consent, opt-outs and
        // metadata are preserved; ingestion links duplicates rather than overwriting them.
        $this->list($w, $kind, $list);
        if ($kind === 'voice') {
            $old = DB::table('voice_contacts')->where('workspace_id', $w)->where('phone', $data['phone'])->first();
        } else {
            $q = DB::table('contacts')->where(function ($q) use ($data) {
                $q->whereRaw('1=0');
                if (! empty($data['email'])) $q->orWhereRaw('LOWER(email) = ?', [$data['email']]);
                if (! empty($data['phone'])) {
                    $digits = substr($data['phone'], 1); $options = [$digits];
                    if (str_starts_with($digits, '55')) $options[] = substr($digits, 2);
                    $expr = 'phone'; foreach ([' ', '-', '(', ')', '+', '.'] as $char) $expr = "REPLACE($expr, '$char', '')";
                    $q->orWhereIn(DB::raw($expr), $options);
                }
            })->get();
            abort_if($q->count() > 1, 422, 'E-mail e telefone identificam cadastros diferentes. Revise os contatos antes de importar.');
            $old = $q->first();
        }
        $id = $old?->id;
        if (! $id) {
            $fields = $data['fields'] + ['source' => $data['source'], 'crm_contact_id' => $data['crm_contact_id'] ?? null];
            if ($kind === 'voice') {
                $fields['email'] = $data['email'] ?? null;
                $id = DB::table('voice_contacts')->insertGetId(['workspace_id' => $w, 'name' => $data['name'], 'phone' => $data['phone'], 'original_phone' => $data['phone'], 'source' => $data['source'], 'crm_contact_id' => $data['crm_contact_id'] ?? null, 'consent' => $data['consent'], 'consent_evidence' => $data['consent'] ? $data['consent_evidence'] : null, 'consented_at' => $data['consent'] ? now() : null, 'fields' => json_encode($fields), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $fields['consent_evidence'] = $data['consent'] ? $data['consent_evidence'] : null;
                $id = DB::table('contacts')->insertGetId(['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'subscribed' => $data['consent'], 'fields' => json_encode($fields), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if($old&&!$explicit&&$this->settings($w,$kind,$list)['mode']==='rules'){
            $fields=array_replace(json_decode($old->fields??'{}',true)??[],$data['fields']);
            DB::table($kind==='voice'?'voice_contacts':'contacts')->where('id',$id)->update(['fields'=>json_encode($fields),'updated_at'=>now()]);
        }
        if($explicit)app(Segments::class)->pin($w,$kind,$list,$id);
        $c=DB::table($kind==='voice'?'voice_contacts':'contacts')->where('id',$id)->first();
        if(!app(Segments::class)->eligible($w,$kind,$list,$c))return ['contact_id'=>$id,'created'=>!$old,'preserved'=>(bool)$old,'added'=>false,'membership_removed'=>false,'outside_rules'=>true];
        return $this->link($w, $kind, $list, $id) + ['created' => ! $old, 'preserved' => (bool) $old];
    }
    /** Caller holds runtime lock; linking never edits a contact or restores an exclusion. */
    public function link(int $w, string $kind, int $list, int $id): array
    {
        if ($kind === 'voice') {
            $member = DB::table('voice_list_members')->where('list_id', $list)->where('contact_id', $id)->first();
            if (! $member) DB::table('voice_list_members')->insert(['list_id' => $list, 'contact_id' => $id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            if($member&&$member->status==='outside_rules')DB::table('voice_list_members')->where('id',$member->id)->update(['status'=>'active','reason'=>null,'updated_at'=>now()]);
            $added = ! $member || $member->status==='outside_rules';
            if (! $member || in_array($member->status,['active','outside_rules'])) {
                foreach (DB::table('voice_campaign_policies')->where('list_id', $list)->pluck('campaign_id') as $campaign) DB::table('voice_members')->insertOrIgnore(['campaign_id' => $campaign, 'contact_id' => $id]);
            }
            $removed = $member && $member->status === 'removed';
        } else {
            $removed = DB::table('ma_list_membership_exclusions')->where('audience_id', $list)->where('contact_id', $id)->exists();
            $added = ! $removed && DB::table('audience_contact')->insertOrIgnore(['audience_id' => $list, 'contact_id' => $id]);
        }
        if($added)app(CadenceReentry::class)->membershipChanged($kind,$list,[$id],true);
        return ['contact_id' => $id, 'added' => (bool) $added, 'membership_removed' => (bool) $removed];
    }
    public function existing(int $w, string $kind, int $list, array $ids): array
    {
        return DB::transaction(function () use ($w, $kind, $list, $ids) {
            $this->lock(); $this->list($w, $kind, $list);
            $query = DB::table($kind === 'voice' ? 'voice_contacts' : 'contacts')->whereIn('id', $ids);
            if ($kind === 'voice') $query->where('workspace_id', $w);
            abort_unless($query->count() === count($ids), 422, 'Um contato selecionado não está mais disponível neste cadastro. Atualize a busca.');
            $results = array_map(function($id)use($w,$kind,$list){$contact=DB::table($kind==='voice'?'voice_contacts':'contacts')->where('id',$id)->first();if(!app(Segments::class)->eligible($w,$kind,$list,$contact))return ['added'=>false,'membership_removed'=>false,'outside_rules'=>true];app(Segments::class)->pin($w,$kind,$list,$id);return $this->link($w,$kind,$list,$id);}, $ids);
            return ['outside_rules'=>count(array_filter($results,fn($r)=>$r['outside_rules']??false)),'selected' => count($ids), 'added' => count(array_filter($results, fn ($r) => $r['added'])), 'removed_preserved' => count(array_filter($results, fn ($r) => $r['membership_removed']))];
        });
    }
    public function remove(int $w, string $kind, int $list, int $contact): void
    {
        DB::transaction(function () use ($w, $kind, $list, $contact) {
            $this->lock(); $this->list($w, $kind, $list);
            app(CadenceReentry::class)->membershipChanged($kind,$list,[$contact],false);
            if ($kind === 'voice') {
                $m = DB::table('voice_list_members')->where('list_id', $list)->where('contact_id', $contact)->firstOrFail();
                DB::table('voice_list_members')->where('id', $m->id)->update(['status' => 'removed', 'reason' => 'Retirado no gerenciamento de listas', 'updated_at' => now()]);
                $campaigns = DB::table('voice_campaign_policies')->where('list_id', $list)->pluck('campaign_id');
                DB::table('voice_followups')->whereIn('campaign_id', $campaigns)->where('contact_id', $contact)->whereIn('status', ['pending', 'blocked'])->update(['status' => 'cancelled', 'reason' => 'Contato retirado da lista.', 'updated_at' => now()]);
            } else {
                DB::table('audience_contact')->where('audience_id', $list)->where('contact_id', $contact)->firstOrFail();
                DB::table('ma_list_membership_exclusions')->insertOrIgnore(['audience_id' => $list, 'contact_id' => $contact, 'created_at' => now()]);
                DB::table('audience_contact')->where('audience_id', $list)->where('contact_id', $contact)->delete();
                app(VoiceAudience::class)->removed($list, $contact);
            }
        });
    }
}

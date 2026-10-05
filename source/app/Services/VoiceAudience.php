<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** A live MA audience can supply voice campaigns without changing its source records. */
class VoiceAudience
{
    public function catalog(int $w): array
    {
        $voice = DB::table('voice_lists')->where('workspace_id', $w)->get(['id', 'name'])->map(fn ($l) => (array) $l + ['kind' => 'voice']);
        $ma = $w === 1 ? DB::table('audiences')->get(['id', 'name'])->map(fn ($l) => (array) $l + ['kind' => 'automation']) : collect();
        return $voice->concat($ma)->sortBy('name')->values()->all();
    }

    private function phone(?string $phone): ?string
    {
        if (! $phone || ! preg_match('/^\+?[0-9 ()\.\-]{8,60}$/D', trim($phone))) return null;
        try { return VoiceLab::phone(trim($phone)); } catch (\Illuminate\Validation\ValidationException) { return null; }
    }

    private function rows(int $audience)
    {
        app(Segments::class)->refresh(1,'automation',$audience);
        return DB::table('contacts as c')->join('audience_contact as m', 'c.id', '=', 'm.contact_id')->where('m.audience_id', $audience)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ma_list_membership_exclusions as x')->where('x.audience_id', $audience)->whereColumn('x.contact_id', 'c.id'))->select('c.*');
    }

    private function consent(object $c): bool
    {
        $f = json_decode($c->fields ?? '{}', true) ?? [];
        return (bool) $c->subscribed && is_string($f['consent_evidence'] ?? null) && mb_strlen(trim($f['consent_evidence'])) >= 5;
    }

    /** Called only under the voice_runtime lock, inside a transaction. No external I/O. */
    public function prepare(int $w, int $audience): array
    {
        abort_unless($w === 1, 404);
        DB::table('audiences')->where('id', $audience)->firstOrFail();
        $rows = $this->rows($audience)->orderBy('c.id')->limit(501)->get();
        abort_if($rows->count() > 500, 422, 'Esta jornada aceita uma lista com até 500 contatos. Reduza o público antes de continuar.');
        $groups = []; $ids = [];
        foreach ($rows as $c) {
            $phone = $this->phone($c->phone);
            if ($phone && ! Validator::make(['name' => $c->name], ['name' => 'required|string|max:160'])->fails()) $groups[$phone][] = $c;
        }
        foreach ($groups as $phone => $contacts) {
            $old = DB::table('voice_contacts')->where('workspace_id', $w)->where('phone', $phone)->first();
            $id = $old?->id;
            if (! $id) {
                $c = $contacts[0]; $fields = json_decode($c->fields ?? '{}', true) ?? [];
                $consent = collect($contacts)->every(fn ($c) => $this->consent($c));
                $fields['email'] = $c->email;
                $id = DB::table('voice_contacts')->insertGetId(['workspace_id' => $w, 'name' => $c->name, 'phone' => $phone, 'original_phone' => $c->phone,
                    'source' => 'Lista do MA #'.$audience, 'crm_contact_id' => is_string($fields['crm_contact_id'] ?? null) ? mb_substr($fields['crm_contact_id'], 0, 160) : null,
                    'consent' => $consent, 'consent_evidence' => $consent ? mb_substr($fields['consent_evidence'], 0, 1000) : null, 'consented_at' => $consent ? now() : null,
                    'fields' => json_encode($fields), 'created_at' => now(), 'updated_at' => now()]);
            }
            // Existing voice identity, authorization, opt-outs, reply state and history remain intact.
            foreach ($contacts as $c) DB::table('voice_audience_contacts')->updateOrInsert(['audience_id' => $audience, 'contact_id' => $c->id], ['voice_contact_id' => $id]);
            $ids[] = $id;
        }
        return $ids;
    }

    public function sync(int $w, int $campaign): void
    {
        $policy = DB::table('voice_campaign_policies')->where('campaign_id', $campaign)->first();
        if (! $policy?->audience_id) return;
        DB::table('voice_campaigns')->where('workspace_id', $w)->where('id', $campaign)->firstOrFail();
        $ids = $this->prepare($w, $policy->audience_id);
        DB::table('voice_members')->where('campaign_id', $campaign)->whereNotIn('contact_id', $ids)->delete();
        foreach ($ids as $id) DB::table('voice_members')->insertOrIgnore(['campaign_id' => $campaign, 'contact_id' => $id]);
    }

    /** Recheck the source immediately before dialing and before a pending WhatsApp. */
    public function reason(int $w, ?object $policy, object $contact): ?string
    {
        if (! $policy?->audience_id) return null;
        if ($w !== 1 || $policy->list_id) return 'Origem de público incompatível com esta jornada.';
        $rows = $this->rows($policy->audience_id)->limit(501)->get();
        if ($rows->count() > 500) return 'Lista do MA acima do limite de 500 contatos desta jornada.';
        $matches = $rows->filter(fn ($c) => $this->phone($c->phone) === $contact->phone);
        if ($matches->isEmpty()) return 'Contato retirado da lista do MA ou com telefone alterado.';
        if ($matches->contains(fn ($c) => ! $this->consent($c))) return 'Contato sem autorização documentada na lista do MA.';
        return null;
    }

    /** Campaign-local current profile. Never overwrite identity, consent, phone or history. */
    public function personalize(int $w, int $campaign, object $contact): object
    {
        $policy = DB::table('voice_campaign_policies')->where('campaign_id', $campaign)->first();
        if ($w !== 1 || ! $policy?->audience_id) return $contact;
        $source = $this->rows($policy->audience_id)->orderBy('c.id')->limit(501)->get()
            ->first(fn ($row) => $this->phone($row->phone) === $contact->phone);
        if (! $source) return $contact;
        $profile = clone $contact;
        if (trim($source->name ?? '') !== '') $profile->name = $source->name;
        $fields = json_decode($source->fields ?? '{}', true) ?? [];
        if (is_string($fields['crm_contact_id'] ?? null)) $profile->crm_contact_id = $fields['crm_contact_id'];
        $profile->source = is_string($fields['source'] ?? null) ? $fields['source'] : 'Lista do MA #'.$policy->audience_id;
        return $profile;
    }

    public function removed(int $audience, int $contact): void
    {
        $ids = DB::table('voice_audience_contacts')->where('audience_id', $audience)->where('contact_id', $contact)->pluck('voice_contact_id');
        DB::table('voice_followups')->whereIn('campaign_id', DB::table('voice_campaign_policies')->where('audience_id', $audience)->select('campaign_id'))->whereIn('contact_id', $ids)->whereIn('status', ['pending', 'blocked'])->update(['status' => 'cancelled', 'reason' => 'Contato retirado da lista do MA.', 'updated_at' => now()]);
    }
}

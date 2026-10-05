<?php
namespace App\Http\Controllers;

use App\Services\{ListContacts,ListImports,ListWebhooks};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ListManagerController extends Controller
{
    private function workspace(Request $r, bool $write = false): int
    {
        abort_unless((int) $r->user()->voice_workspace_id === 1, 403, 'Estas listas pertencem ao workspace principal do MA.');
        if ($write) abort_unless(in_array($r->user()->voice_role, ['admin', 'supervisor']), 403, 'A edição é reservada à supervisão.');
        return 1;
    }
    private function safe($data) { return response()->json($data)->header('Cache-Control', 'no-store, private'); }
    public function index(Request $r)
    {
        $w = $this->workspace($r);
        app(\App\Services\Segments::class)->refreshAll();
        $ma = DB::table('audiences')->get()->map(fn ($l) => ['id' => $l->id, 'kind' => 'automation', 'name' => $l->name, 'count' => DB::table('audience_contact')->where('audience_id', $l->id)->count()]);
        $voice = DB::table('voice_lists')->where('workspace_id', $w)->get()->map(fn ($l) => ['id' => $l->id, 'kind' => 'voice', 'name' => $l->name, 'count' => DB::table('voice_list_members')->where('list_id', $l->id)->where('status', 'active')->count()]);
        return $this->safe(['lists' => $ma->concat($voice)->sortBy('name')->values(), 'can_manage' => in_array($r->user()->voice_role, ['admin', 'supervisor'])]);
    }
    public function create(Request $r)
    {
        $w = $this->workspace($r, true); $d = $r->validate(['kind' => 'required|in:automation,voice', 'name' => 'required|string|max:160']);
        $id = DB::table($d['kind'] === 'voice' ? 'voice_lists' : 'audiences')->insertGetId(['name' => $d['name'], 'created_at' => now(), 'updated_at' => now()] + ($d['kind'] === 'voice' ? ['workspace_id' => $w, 'source' => 'Gerenciamento de listas'] : []));
        return $this->safe(['id' => $id, 'kind' => $d['kind']]);
    }
    public function show(Request $r, string $kind, int $id)
    {
        $w = $this->workspace($r); $service = app(ListContacts::class); $list = $service->list($w, $kind, $id);app(\App\Services\Segments::class)->refresh($w,$kind,$id);
        $d = $r->validate(['search' => 'nullable|string|max:160', 'page' => 'sometimes|integer|between:1,100000']);
        if ($kind === 'voice') {
            $q = DB::table('voice_contacts as c')->join('voice_list_members as m', 'c.id', '=', 'm.contact_id')->where('m.list_id', $id)->where('c.workspace_id', $w)->select('c.id', 'c.name', 'c.phone', 'c.fields', 'c.source', 'c.crm_contact_id', 'c.consent', 'c.suppressed_at', 'c.replied_at', 'm.status');
        } else {
            $q = DB::table('contacts as c')->join('audience_contact as m', 'c.id', '=', 'm.contact_id')->where('m.audience_id', $id)->select('c.id', 'c.name', 'c.email', 'c.phone', 'c.fields', 'c.subscribed as consent');
        }
        if (! empty($d['search'])) $q->where(fn ($q) => $q->where('c.name', 'like', '%'.$d['search'].'%')->orWhere('c.phone', 'like', '%'.$d['search'].'%'));
        $members = $q->orderByDesc('c.id')->paginate(30);
        $members->through(function ($c) use ($kind) { $c->fields = json_decode($c->fields ?? '{}', true); $c->status ??= 'active'; if ($kind === 'voice') $c->email = $c->fields['email'] ?? null; return $c; });
        $hooks = DB::table('ma_list_webhooks')->where('workspace_id', $w)->where('kind', $kind)->where('list_id', $id)->orderBy('created_at')->get()->map(fn ($h) => app(ListWebhooks::class)->public($h));
        $removed = $kind === 'automation' ? DB::table('ma_list_membership_exclusions as x')->join('contacts as c', 'c.id', '=', 'x.contact_id')->where('x.audience_id', $id)->limit(100)->get(['c.id', 'c.name', 'c.phone', 'c.email']) : [];
        return $this->safe(['list' => ['id' => $id, 'kind' => $kind, 'name' => $list->name] + $service->settings($w, $kind, $id), 'members' => $members, 'removed' => $removed, 'webhooks' => $hooks,'campaigns'=>DB::table('voice_campaigns')->where('workspace_id',$w)->get(['id','name'])]);
    }
    public function segmentPreview(Request $r,string $kind,int $id) {
        $w=$this->workspace($r,true);$d=$r->validate(['mode'=>'required|in:manual,rules','rule_match'=>'required|in:all,any','rules'=>'present|array|max:20']);$result=app(\App\Services\Segments::class)->preview($w,$kind,$id,$d);unset($result['ids']);return $this->safe($result+['saved'=>false]);
    }
    public function configure(Request $r, string $kind, int $id)
    {
        $w = $this->workspace($r, true);
        $d = $r->validate(['mode'=>'sometimes|in:manual,rules','rule_match'=>'sometimes|in:all,any','rules'=>'sometimes|array|max:20','revision' => 'required|integer|min:0', 'name' => 'required|string|max:160', 'fields' => 'present|array|max:20', 'fields.*' => 'required|array:key,label,type,required', 'fields.*.key' => ['required', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,39}$/D'], 'fields.*.label' => 'required|string|max:100', 'fields.*.type' => 'required|in:text,number,boolean,date', 'fields.*.required' => 'required|boolean']);
        return $this->safe(app(ListContacts::class)->configure($w, $kind, $id, $d));
    }
    public function contact(Request $r, string $kind, int $id)
    {
        $w = $this->workspace($r, true); $d = $r->validate(['data' => 'required|array:name,email,phone,source,crm_contact_id,consent,consent_evidence,fields']);
        return $this->safe(DB::transaction(function () use ($w, $kind, $id, $d) { $s = app(ListContacts::class); $s->lock(); $s->list($w, $kind, $id); return $s->ingest($w, $kind, $id, $s->normalize($kind, $s->settings($w, $kind, $id)['fields'], $d['data'])); }));
    }
    public function candidates(Request $r, string $kind, int $id)
    {
        $w = $this->workspace($r, true); app(ListContacts::class)->list($w, $kind, $id);
        $d = $r->validate(['search' => 'nullable|string|max:160', 'page' => 'sometimes|integer|between:1,100000']);
        $q = DB::table($kind === 'voice' ? 'voice_contacts' : 'contacts');
        if ($kind === 'voice') $q->where('workspace_id', $w);
        if (! empty($d['search'])) $q->where(function ($q) use ($d, $kind) {
            $q->where('name', 'like', '%'.$d['search'].'%')->orWhere('phone', 'like', '%'.$d['search'].'%');
            if ($kind === 'automation') $q->orWhere('email', 'like', '%'.$d['search'].'%');
        });
        $rows = $q->orderBy('name')->orderBy('id')->paginate(20, $kind === 'voice' ? ['id','name','phone','consent','suppressed_at'] : ['id','name','phone','email','subscribed as consent']);
        $ids = $rows->pluck('id');
        $members = $kind === 'voice' ? DB::table('voice_list_members')->where('list_id', $id)->whereIn('contact_id', $ids)->pluck('status','contact_id') : DB::table('audience_contact')->where('audience_id', $id)->whereIn('contact_id', $ids)->pluck('contact_id');
        $removed = $kind === 'automation' ? DB::table('ma_list_membership_exclusions')->where('audience_id', $id)->whereIn('contact_id', $ids)->pluck('contact_id') : collect();
        $rows->through(function ($c) use ($kind, $members, $removed) {
            $c->membership = $kind === 'voice' ? ($members[$c->id] ?? null) : ($removed->contains($c->id) ? 'removed' : ($members->contains($c->id) ? 'active' : null)); return $c;
        });
        return $this->safe(['contacts' => $rows]);
    }
    public function attach(Request $r, string $kind, int $id)
    {
        $w = $this->workspace($r, true); $d = $r->validate(['contact_ids' => 'required|array|min:1|max:100', 'contact_ids.*' => 'required|integer|distinct|min:1']);
        return $this->safe(app(ListContacts::class)->existing($w, $kind, $id, $d['contact_ids']));
    }
    public function remove(Request $r, string $kind, int $id, int $contact)
    {
        app(ListContacts::class)->remove($this->workspace($r, true), $kind, $id, $contact); return $this->safe(['removed' => true]);
    }
    public function restore(Request $r, string $kind, int $id, int $contact)
    {
        $w = $this->workspace($r, true);
        DB::transaction(function () use ($w, $kind, $id, $contact) {
            $s = app(ListContacts::class); $s->lock(); $s->list($w, $kind, $id);$row=DB::table($kind==='voice'?'voice_contacts':'contacts')->where('id',$contact)->firstOrFail();abort_unless(app(\App\Services\Segments::class)->eligible($w,$kind,$id,$row),422,'O contato não corresponde às regras atuais do segmento.');app(\App\Services\Segments::class)->pin($w,$kind,$id,$contact);
            if ($kind === 'voice') {
                $m = DB::table('voice_list_members')->where('list_id', $id)->where('contact_id', $contact)->firstOrFail();
                DB::table('voice_list_members')->where('id', $m->id)->update(['status' => 'active', 'reason' => 'Reinclusão manual no gerenciamento de listas', 'updated_at' => now()]);
                foreach (DB::table('voice_campaign_policies')->where('list_id', $id)->pluck('campaign_id') as $c) DB::table('voice_members')->insertOrIgnore(['campaign_id' => $c, 'contact_id' => $contact]);
            } else {
                DB::table('ma_list_membership_exclusions')->where('audience_id', $id)->where('contact_id', $contact)->firstOrFail();
                DB::table('ma_list_membership_exclusions')->where('audience_id', $id)->where('contact_id', $contact)->delete();
                DB::table('audience_contact')->insertOrIgnore(['audience_id' => $id, 'contact_id' => $contact]);
            }
        });
        return $this->safe(['restored' => true]);
    }
    public function inspect(Request $r, string $kind, int $id)
    {
        app(ListContacts::class)->list($this->workspace($r, true), $kind, $id);
        $d = $r->validate(['file' => 'required|file|max:2048', 'delimiter' => 'required|string|size:1']);
        $csv = app(ListImports::class)->read($d['file'], $d['delimiter']);
        return $this->safe(['headers' => $csv['headers'], 'sample' => array_slice($csv['rows'], 0, 5), 'row_count' => count($csv['rows'])]);
    }
    public function preview(Request $r, string $kind, int $id)
    {
        $w = $this->workspace($r, true);
        $d = $r->validate(['file' => 'required|file|max:2048', 'delimiter' => 'required|string|size:1', 'mapping' => 'required|json', 'source' => 'required|string|max:300', 'consent' => 'required|boolean', 'consent_evidence' => 'nullable|string|max:1000']);
        $d['mapping'] = json_decode($d['mapping'], true); abort_unless(is_array($d['mapping']), 422, 'Mapeamento inválido.');
        return $this->safe(app(ListImports::class)->preview($w, $r->user()->id, $kind, $id, $d['file'], $d));
    }
    public function commit(Request $r, string $kind, int $id, string $batch)
    {
        return $this->safe(app(ListImports::class)->commit($this->workspace($r, true), $kind, $id, $batch));
    }
    public function webhook(Request $r, string $kind, int $id, ?string $hook = null)
    {
        $w = $this->workspace($r, true); $d = $r->validate(['name' => 'required|string|max:160', 'active' => 'required|boolean', 'revision' => 'required|integer|min:0', 'mapping' => 'required|array|max:27', 'rotate' => 'sometimes|boolean']);
        return $this->safe(app(ListWebhooks::class)->save($w, $kind, $id, $d, $hook));
    }
    public function webhookPreview(Request $r, string $kind, int $id)
    {
        $w = $this->workspace($r, true); $d = $r->validate(['mapping' => 'required|array|max:27', 'payload' => 'required|array']);
        abort_if(strlen($r->getContent()) > 65536, 413);
        return $this->safe(['contact' => app(ListWebhooks::class)->preview($w, $kind, $id, $d['mapping'], $d['payload']), 'saved' => false]);
    }
    public function receive(Request $r, string $hook)
    {
        abort_unless($r->isJson(), 415, 'Use Content-Type: application/json.');
        return $this->safe(app(ListWebhooks::class)->receive($hook, $r->bearerToken() ?? '', $r->header('Idempotency-Key', ''), $r->getContent()));
    }
}

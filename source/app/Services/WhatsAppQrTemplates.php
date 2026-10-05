<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WhatsAppQrTemplates
{
    public function catalog(int $workspace): array
    {
        return DB::table('wa_qr_templates')->where('workspace_id', $workspace)->orderByDesc('created_at')->orderBy('id')->limit(200)->get()->map(function ($t) {
            $t->buttons = json_decode($t->buttons, true);
            return $t;
        })->all();
    }

    public function get(int $workspace, string $id): object
    {
        return DB::table('wa_qr_templates')->where('workspace_id', $workspace)->where('id', $id)->firstOrFail();
    }

    public function create(int $workspace, array $data): object
    {
        $d = Validator::make($data, [
            'name' => 'required|string|max:160', 'body' => 'required|string|max:3000',
            'buttons' => 'required|array|min:1|max:3',
            'buttons.*' => 'required|array:type,id,label,url',
            'buttons.*.type' => 'required|in:reply,url',
            'buttons.*.id' => ['required', 'distinct', 'regex:/^[a-zA-Z0-9_-]{1,64}$/D'],
            'buttons.*.label' => 'required|string|max:20',
            'buttons.*.url' => 'nullable|string|max:1000',
        ])->validate();
        app(VoicePersonalization::class)->validate($d['body']);
        foreach ($d['buttons'] as &$button) {
            abort_unless(trim($button['label']) !== '' && !preg_match('/[\x00-\x1f{}]/u', $button['label']), 422, 'O botão precisa de um título fixo, sem quebras de linha ou variáveis.');
            if ($button['type'] === 'url') {
                $url = $button['url'] ?? '';
                $parsed = parse_url($url);
                abort_unless(filter_var($url, FILTER_VALIDATE_URL) && ($parsed['scheme'] ?? '') === 'https' && empty($parsed['user']) && empty($parsed['pass']) && !preg_match('/[\s{}]/u', $url), 422, 'O botão de link exige uma URL HTTPS fixa, sem credenciais ou variáveis.');
            } else {
                abort_if(!empty($button['url']), 422, 'Botão de resposta não utiliza URL.');
                unset($button['url']);
            }
            $button['label'] = trim($button['label']);
        }
        unset($button);
        $id = (string) Str::uuid();
        // Immutable versions: editing creates a new ID, never mutates an active cadence.
        DB::table('wa_qr_templates')->insert(['id' => $id, 'workspace_id' => $workspace, 'name' => trim($d['name']), 'body' => $d['body'], 'buttons' => json_encode($d['buttons']), 'created_at' => now(), 'updated_at' => now()]);
        return $this->get($workspace, $id);
    }

    public function render(int $workspace, string $id, int $contactId, ?int $campaignId): array
    {
        $t = $this->get($workspace, $id);
        $contact = DB::table('voice_contacts')->where('workspace_id', $workspace)->where('id', $contactId)->firstOrFail();
        $campaign = $campaignId ? DB::table('voice_campaigns')->where('workspace_id', $workspace)->where('id', $campaignId)->firstOrFail() : (object) ['name' => ''];
        $body = app(VoicePersonalization::class)->render($t->body, $contact, $campaign);
        abort_if(mb_strlen($body) > 4000, 422, 'A mensagem personalizada excede 4.000 caracteres.');
        return ['body' => $body, 'buttons' => json_decode($t->buttons, true)];
    }
}

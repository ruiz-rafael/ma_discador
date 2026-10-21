<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class WhatsAppPublicProfile
{
    private const BASE = 'https://messaging.twilio.com/v2/Channels/Senders/';

    private function sender(int $workspace, int $id): object
    {
        $s = app(WhatsAppMessages::class)->sender($workspace, $id);
        abort_unless($s->provider === 'twilio', 422, 'Este perfil é para números da API oficial.');
        return $s;
    }

    private function change(int $workspace, int $id, callable $work): array
    {
        $this->sender($workspace, $id);
        $lock = Cache::lock('wa-public-profile:'.$workspace.':'.$id, 45);
        abort_unless($lock->get(), 409, 'Uma atualização está em andamento. Aguarde e consulte o perfil.');
        try { return $work(); } finally { $lock->release(); }
    }

    public function read(int $workspace, int $id): array
    {
        $s = $this->sender($workspace, $id);
        $row = DB::table('wa_public_profiles')->where('sender_id', $id)->where('workspace_id', $workspace)->first();
        return ['draft' => json_decode($row?->draft ?? '{}', true) + ['name' => '', 'about' => '', 'logo_url' => ''],
            'confirmed' => json_decode($row?->confirmed ?? '{}', true), 'submission_state' => $row?->submission_state ?? 'draft',
            'synced_at' => $row?->synced_at, 'submitted_at' => $row?->submitted_at,
            'can_publish' => (bool) $s->provider_sid, 'number' => $s->number];
    }

    private function saveDraft(int $workspace, int $id, array $draft, int $user): array
    {
        $old = DB::table('wa_public_profiles')->where('sender_id', $id)->first();
        DB::table('wa_public_profiles')->updateOrInsert(['sender_id' => $id], ['workspace_id' => $workspace,
            'draft' => json_encode($draft), 'updated_by' => $user, 'updated_at' => now(), 'created_at' => $old?->created_at ?? now()]);
        return $this->read($workspace, $id);
    }

    public function draft(int $workspace, int $id, array $data, int $user): array
    {
        return $this->change($workspace, $id, fn () => $this->saveDraft($workspace, $id,
            array_replace($this->read($workspace, $id)['draft'], array_intersect_key($data, array_flip(['name', 'about']))), $user));
    }

    public function photo(int $workspace, int $id, UploadedFile $file, int $user): array
    {
        return $this->change($workspace, $id, function () use ($workspace, $id, $file, $user) {
            $bytes = file_get_contents($file->getRealPath());
            $info = @getimagesizefromstring($bytes);
            abort_unless($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true), 422, 'Escolha uma foto JPG ou PNG válida.');
            abort_unless($info[0] === $info[1] && $info[0] >= 640 && $info[0] <= 4096, 422, 'A foto deve ser quadrada, entre 640 e 4096 pixels.');
            if ($info[2] === IMAGETYPE_PNG) {
                // PNG alpha channels and tRNS transparency are not accepted by the provider.
                $transparent = in_array(ord($bytes[25]), [4, 6], true);
                for ($offset = 8; $offset + 12 <= strlen($bytes);) {
                    $size = unpack('N', substr($bytes, $offset, 4))[1];
                    $type = substr($bytes, $offset + 4, 4);
                    if ($type === 'tRNS') $transparent = true;
                    if ($size > strlen($bytes) - $offset - 12) abort(422, 'Arquivo PNG inválido.');
                    $offset += $size + 12;
                }
                abort_if($transparent, 422, 'Use uma imagem com fundo sólido. Exporte como JPG ou PNG sem transparência.');
            }
            $name = Str::uuid().($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
            $dir = storage_path('app/private/wa-profile-images');
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            abort_if(file_put_contents($dir.'/'.$name, $bytes, LOCK_EX) === false, 500, 'Não foi possível guardar a foto.');
            chmod($dir.'/'.$name, 0600);
            $draft = $this->read($workspace, $id)['draft'];
            $draft['logo_url'] = \App\Services\Portal\TenantUrls::origin().'/media/whatsapp-profile/'.$name;
            return $this->saveDraft($workspace, $id, $draft, $user);
        });
    }

    private function remote(object $s): array
    {
        abort_unless($s->provider_sid && preg_match('/^XE[0-9a-fA-F]{32}$/D', $s->provider_sid), 422, 'Conecte o número ao WhatsApp antes de publicar o perfil.');
        // Reuse onboarding's account and WABA ownership checks before every provider operation.
        app(WhatsAppOnboarding::class)->status($s->workspace_id, $s->id);
        $r = app(TwilioWhatsAppApi::class)->sender($s->provider_sid);
        $account = app(TwilioWhatsAppConnection::class)->require()['account_sid'];
        abort_unless(($r['sid'] ?? '') === $s->provider_sid && ($r['sender_id'] ?? '') === 'whatsapp:'.$s->number, 409, 'O remetente não corresponde a este número.');
        abort_if(isset($r['account_sid']) && $r['account_sid'] !== $account, 409, 'O remetente pertence a outra conta.');
        $waba = DB::table('wa_onboardings')->where('sender_id', $s->id)->value('waba_id');
        abort_if($waba && ($r['configuration']['waba_id'] ?? null) !== $waba, 409, 'O remetente pertence a outra conta WhatsApp.');
        return $r;
    }

    private function snapshot(int $workspace, int $id, array $remote): void
    {
        $profile = array_intersect_key($remote['profile'] ?? [], array_flip(['name', 'about', 'logo_url', 'pending_display_name', 'pending_display_name_status', 'pending_display_name_status_date']));
        if (isset($profile['logo_url']) && !str_starts_with($profile['logo_url'], 'https://')) unset($profile['logo_url']);
        $old = DB::table('wa_public_profiles')->where('sender_id', $id)->first();
        DB::table('wa_public_profiles')->updateOrInsert(['sender_id' => $id], ['workspace_id' => $workspace,
            'confirmed' => json_encode($profile), 'synced_at' => now(), 'updated_at' => now(), 'created_at' => $old?->created_at ?? now()]);
    }

    public function sync(int $workspace, int $id): array
    {
        return $this->change($workspace, $id, function () use ($workspace, $id) {
            $this->snapshot($workspace, $id, $this->remote($this->sender($workspace, $id)));
            DB::table('wa_public_profiles')->where('sender_id', $id)->update(['submission_state' => 'consulted']);
            return $this->read($workspace, $id);
        });
    }

    public function publish(int $workspace, int $id, int $user): array
    {
        return $this->change($workspace, $id, function () use ($workspace, $id, $user) {
            $s = $this->sender($workspace, $id); $state = $this->read($workspace, $id);
            abort_if(in_array($state['submission_state'], ['submitting', 'unknown']), 409, 'Consulte o perfil antes de enviar outra atualização.');
            $draft = $state['draft'];
            abort_unless(mb_strlen(trim($draft['name'])) >= 2, 422, 'Salve o nome comercial antes de publicar.');
            $remote = $this->remote($s);
            abort_unless(in_array($remote['status'] ?? '', ['ONLINE', 'ONLINE:UPDATING']), 422, 'Aguarde a ativação do WhatsApp antes de publicar o perfil.');
            $this->snapshot($workspace, $id, $remote);
            $payload = ['name' => $draft['name']];
            if ($draft['about'] !== '') $payload['about'] = $draft['about'];
            if ($draft['logo_url'] !== '') $payload['logo_url'] = $draft['logo_url'];
            DB::table('wa_public_profiles')->where('sender_id', $id)->update(['submission_state' => 'submitting', 'submitted_at' => now(), 'updated_by' => $user]);
            try {
                // A 202 can be partial: it is acceptance, never proof that the public name changed.
                $r = app(TwilioWhatsAppApi::class)->request('POST', self::BASE.$s->provider_sid, ['profile' => $payload]);
                abort_unless(($r['sid'] ?? '') === $s->provider_sid, 502, 'Consulte o perfil para confirmar o resultado.');
                $result = ($r['profile']['display_name_status'] ?? $r['display_name_status'] ?? '') === 'error' ? 'partial_error' : 'submitted';
            } catch (\Throwable $e) {
                $result = str_starts_with($e->getMessage(), 'twilio_rejected_') ? 'rejected' : 'unknown';
            }
            DB::table('wa_public_profiles')->where('sender_id', $id)->update(['submission_state' => $result, 'updated_at' => now()]);
            return $this->read($workspace, $id);
        });
    }
}

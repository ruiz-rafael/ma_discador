<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppPublicProfile;
use Illuminate\Http\Request;

class WhatsAppPublicProfileController extends Controller
{
    public function handle(Request $r, int $id, string $action = 'draft')
    {
        abort_unless((int) $r->user()->voice_workspace_id === 1 && $r->user()->voice_role === 'admin', 403);
        $service = app(WhatsAppPublicProfile::class); $user = $r->user()->id;
        try {
            if ($r->isMethod('get')) $data = $service->read(1, $id);
            elseif ($action === 'draft') {
                $d = $r->validate(['name' => 'required|string|min:2|max:255', 'about' => 'present|nullable|string|max:139']);
                $data = $service->draft(1, $id, ['name' => trim($d['name']), 'about' => trim($d['about'] ?? '')], $user);
            } elseif ($action === 'photo') {
                $r->validate(['photo' => 'required|file|mimes:jpg,jpeg,png|max:5120']);
                $data = $service->photo(1, $id, $r->file('photo'), $user);
            } elseif ($action === 'sync') $data = $service->sync(1, $id);
            elseif ($action === 'publish') {
                $r->validate(['confirmed' => 'required|accepted']);
                $data = $service->publish(1, $id, $user);
            } else abort(404);
        } catch (\RuntimeException $e) {
            if (!str_starts_with($e->getMessage(), 'twilio_')) throw $e;
            abort(502, 'Não foi possível consultar o perfil na Twilio. Seu rascunho está preservado.');
        }
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }

    public function image(string $file)
    {
        $path = storage_path('app/private/wa-profile-images/'.$file);
        abort_unless(is_file($path), 404);
        return response()->file($path, ['Content-Type' => str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=86400']);
    }
}

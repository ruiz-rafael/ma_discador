<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class WhatsAppNumbers
{
    public function normalize(int $workspace, array $s): array
    {
        $s['number_mode'] = $s['number_mode'] ?? 'single';
        if ($s['number_mode'] === 'single') {
            abort_if(! empty($s['whatsapp_number']) && $s['whatsapp_number'] !== ($s['business_number'] ?? null), 422, 'No modo número único, voz e WhatsApp devem usar o mesmo número.');
            $s['whatsapp_number'] = $s['business_number'] ?? null;
        } else {
            abort_if($s['whatsapp_enabled'] && empty($s['whatsapp_number']), 422, 'Informe o número separado de WhatsApp.');
        }
        if (! empty($s['whatsapp_sender_id'])) {
            $sender = DB::table('wa_senders')->where('workspace_id', $workspace)->where('id', $s['whatsapp_sender_id'])->first();
            abort_unless($sender && $sender->number === ($s['whatsapp_number'] ?? null), 422, 'O remetente precisa pertencer ao workspace e corresponder ao número de WhatsApp.');
        }

        if (!empty($s['whatsapp_qr_template_id'])) {
            app(WhatsAppQrTemplates::class)->get($workspace, $s['whatsapp_qr_template_id']);
            abort_unless(isset($sender) && $sender->provider === 'qr', 422, 'Template QR exige remetente conectado por QR Code.');
            abort_unless(($s['whatsapp_qr_buttons_confirmed'] ?? false) === true, 422, 'Confirme o uso experimental dos botões nesta cadência.');
        }

        return $s;
    }
}

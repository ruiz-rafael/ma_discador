<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class WhatsAppMessagePresenter
{
    private array $templates = [];

    public function body(object $message): string
    {
        if (trim((string) ($message->body ?? '')) !== '') {
            return (string) $message->body;
        }
        if (! ($message->template_id ?? null)) {
            return '';
        }
        $template = $this->templates[$message->template_id] ??= DB::table('wa_templates')->where('id', $message->template_id)->first(['id', 'body']);
        if (! $template) {
            return '';
        }
        try {
            $values = json_decode($message->variables ?? '{}', true, flags: JSON_THROW_ON_ERROR);

            return app(WhatsAppTemplates::class)->renderBody($template->body, is_array($values) ? $values : []);
        } catch (\Throwable) {
            return (string) $template->body;
        }
    }

    public function present(object $message): object
    {
        $message->body = $this->body($message);

        return $message;
    }
}

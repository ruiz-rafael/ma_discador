<?php

namespace App\Services;

class VoicePersonalization
{
    public const FIELDS = [
        'nome' => 'Nome completo', 'primeiro_nome' => 'Primeiro nome',
        'telefone' => 'Telefone do contato', 'campanha' => 'Nome da campanha',
        'id_crm' => 'Identificador no CRM', 'origem' => 'Origem do contato',
    ];

    public function validate(string $text): void
    {
        preg_match_all('/\{([^{}]+)\}/u', $text, $matches);
        foreach ($matches[1] as $field) {
            abort_unless(array_key_exists($field, self::FIELDS), 422, 'Variável desconhecida: {'.$field.'}.');
        }
        $rest = preg_replace('/\{(?:'.implode('|', array_keys(self::FIELDS)).')\}/u', '', $text);
        abort_if(str_contains($rest, '{') || str_contains($rest, '}'), 422, 'Use variáveis no formato {nome}; expressões e variáveis incompletas não são aceitas.');
    }

    public function render(string $text, object $contact, object $campaign): string
    {
        $this->validate($text);
        if (isset($campaign->id, $campaign->workspace_id)) $contact = app(VoiceAudience::class)->personalize($campaign->workspace_id, $campaign->id, $contact);
        $name = trim($contact->name ?? '');
        $values = ['nome' => $name, 'primeiro_nome' => preg_split('/\s+/u', $name)[0] ?? '',
            'telefone' => $contact->phone ?? '', 'campanha' => $campaign->name ?? '',
            'id_crm' => $contact->crm_contact_id ?? '', 'origem' => $contact->source ?? ''];

        // One pass: contact data is never interpreted again as another template.
        return preg_replace_callback('/\{([^{}]+)\}/u', function ($match) use ($values) {
            $value = trim((string) $values[$match[1]]);
            abort_if($value === '', 422, 'O contato não possui o dado necessário: '.self::FIELDS[$match[1]].'.');

            return $value;
        }, $text);
    }
}

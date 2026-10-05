<?php

namespace App\Console\Commands;

use App\Services\VoiceCallingBundle;
use App\Services\VoiceCallingConfig;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ConfigureVoiceCalling extends Command
{
    protected $signature = 'voice:calling:configure {--method=sip_trunk : sip_trunk ou programmable_voice} {--disable : Bloqueia novas ligações sem interromper uma chamada em andamento}';

    protected $description = 'Prepara política de homologação e arquivos privados de saída Twilio, sem alterar o PBX.';

    public function handle(VoiceCallingConfig $c, VoiceCallingBundle $bundle): int
    {
        $method = $this->option('method');
        if (! in_array($method, ['sip_trunk', 'programmable_voice'], true)) {
            $this->error('Método inválido. Use sip_trunk ou programmable_voice.');
            return self::FAILURE;
        }
        $api = app(\App\Services\TwilioVoiceConnection::class);
        if ($this->option('disable')) {
            $method === 'programmable_voice' ? $api->disable() : $c->disable();
            $this->info('Novas ligações bloqueadas.');

            return self::SUCCESS;
        }
        try {
            if (! $c->connection($method)) {
                $this->error($method === 'programmable_voice' ? 'Execute voice:twilio-api:configure primeiro.' : 'Execute voice:twilio:configure primeiro.');

                return self::FAILURE;
            }
            $data = ['allowed_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) $this->ask('Destinos autorizados em E.164, separados por vírgula'))))), 'daily_limit' => (int) $this->ask('Máximo diário de tentativas', '20'), 'max_seconds' => (int) $this->ask('Duração máxima da conversa em segundos', '120'), 'ring_seconds' => (int) $this->ask('Tempo máximo de toque em segundos', '30'), 'caller_id_confirmed' => $this->confirm('O número de origem foi autorizado pela Twilio para a rota contratada?', false)];
            $c->save($data);
            if ($method === 'programmable_voice') {
                $api->save(array_replace($api->read(), ['enabled' => true]));
                $this->info('API habilitada para homologação manual. Confira a TwiML App e os destinos na Twilio. Nenhuma chamada foi realizada.');
            } else {
                $bundle->generate();
                $this->info('Preparação privada criada. A instalação validada no PBX é necessária antes de liberar a tela de chamadas.');
            }

            return self::SUCCESS;
        } catch (ValidationException $e) {
            foreach (array_keys($e->errors()) as $key) {
                $this->error('Campo inválido: '.$key);
            }

return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Preparação não concluída. Verifique dados, permissões e chamadas pendentes.');

            return self::FAILURE;
        }
    }
}

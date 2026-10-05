<?php

namespace App\Console\Commands;

use App\Services\TwilioVoiceConnection;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ConfigureTwilioVoice extends Command
{
    protected $signature = 'voice:twilio-api:configure';
    protected $description = 'Cadastra Programmable Voice em arquivo criptografado; não realiza ligações.';

    public function handle(TwilioVoiceConnection $connection): int
    {
        $this->info('Use uma API Key Standard da região US1 e uma TwiML App exclusiva para o MA.');
        $this->info('Voice Request URL (POST): '.TwilioVoiceConnection::BASE.'/dial');
        $data = [
            'account_sid' => $this->ask('Account SID (AC...)'),
            'api_key' => $this->ask('API Key SID (SK...)'),
            'api_secret' => $this->secret('API Key Secret'),
            'auth_token' => $this->secret('Auth Token atual da conta, para validar webhooks'),
            'application_sid' => $this->ask('TwiML Application SID (AP...)'),
            'caller_id' => $this->ask('Número Twilio habilitado e autorizado para voz, em E.164 (+...)'),
            'edge' => $this->choice('Edge de mídia do navegador', ['sao-paulo', 'ashburn', 'umatilla', 'dublin', 'frankfurt', 'singapore', 'tokyo', 'sydney'], 0),
            'enabled' => false,
        ];
        try {
            $connection->save($data);
        } catch (ValidationException $e) {
            foreach (array_keys($e->errors()) as $field) {
                $this->error('Campo inválido: '.$field);
            }
            return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Configuração não salva. Verifique permissões e chamadas pendentes.');
            return self::FAILURE;
        }
        $this->info('Credenciais cadastradas. Execute voice:calling:configure --method=programmable_voice para definir destinos e habilitar testes.');
        return self::SUCCESS;
    }
}

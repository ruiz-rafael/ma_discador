<?php

namespace App\Console\Commands;

use App\Services\TwilioWhatsAppConnection;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ConfigureTwilioWhatsApp extends Command
{
    protected $signature = 'voice:whatsapp:configure';

    protected $description = 'Configura API Twilio/WhatsApp privada, destinos de homologação e limite diário.';

    public function handle(TwilioWhatsAppConnection $service): int
    {
        $this->info('Credenciais de API separadas do tronco SIP. O cadastro não envia mensagens.');
        $d = ['account_sid' => $this->ask('Account SID (AC...)'), 'api_key' => $this->ask('API Key SID (SK...)'), 'api_secret' => $this->secret('API Key Secret'), 'auth_token' => $this->secret('Auth Token da mesma conta, para validar webhooks'), 'allowed_recipients' => array_values(array_filter(array_map('trim', explode(',', $this->ask('Destinos autorizados em E.164, separados por vírgula'))))), 'daily_limit' => (int) $this->ask('Máximo diário de tentativas de envio na homologação', '20')];
        try {
            $service->save($d);
        } catch (ValidationException $e) {
            foreach (array_keys($e->errors()) as $k) {
                $this->error('Campo inválido: '.$k);
            }

return self::FAILURE;
        } catch (\Throwable) {
            $this->error('Falha ao gravar configuração privada.');

            return self::FAILURE;
        }
        $this->info('Credenciais salvas. Cadastre o Sender SID e sincronize o remetente no MA antes de enviar.');

        return self::SUCCESS;
    }
}

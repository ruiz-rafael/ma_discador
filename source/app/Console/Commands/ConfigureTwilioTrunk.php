<?php

namespace App\Console\Commands;

use App\Services\TwilioTrunk;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ConfigureTwilioTrunk extends Command
{
    protected $signature = 'voice:twilio:configure';
    protected $description = 'Cadastra o tronco Twilio em arquivo privado e gera configuração ainda inativa.';

    public function handle(TwilioTrunk $trunk): int
    {
        $this->info('Preparação do Twilio Elastic SIP Trunking para o MA. Não ativa chamadas.');
        $data = [
            'account_sid' => $this->ask('Account SID (AC...)'),
            'trunk_sid' => $this->ask('Trunk SID (TK...)'),
            'termination_host' => $this->ask('Termination SIP URI, sem sip: (nome.pstn.twilio.com)'),
            'sip_username' => $this->ask('Usuário da Credential List SIP'),
            'sip_password' => $this->secret('Senha SIP da Credential List (não é o Auth Token da conta)'),
            'caller_id' => $this->ask('Número de origem Twilio em E.164 (+...)'),
            'edge' => $this->choice('Edge inicial; escolher conforme latência da VM', ['frankfurt','dublin','sao-paulo','ashburn','umatilla','singapore','tokyo','sydney'], 0),
            'planned_concurrency' => $this->ask('Chamadas simultâneas planejadas; não altera limite ativo', '2'),
            'planned_cps' => $this->ask('Novas chamadas por segundo planejadas; confirmar na Twilio', '1'),
        ];
        try { $trunk->save($data); $trunk->stage(); }
        catch (ValidationException $e) {
            foreach (array_keys($e->errors()) as $field) $this->error('Campo inválido: '.$field);
            return self::FAILURE;
        }
        catch (\Throwable) { $this->error('Não foi possível preparar o tronco. Verifique permissões e chave da aplicação. Nenhuma rota foi ativada.'); return self::FAILURE; }
        $this->info('Configuração privada salva e arquivo PJSIP preparado em storage/app/private/voice/twilio. Falta homologação; o PBX ativo não foi alterado.');
        return self::SUCCESS;
    }
}

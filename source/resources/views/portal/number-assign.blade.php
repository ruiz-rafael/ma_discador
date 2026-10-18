@extends('portal.layout')
@section('title','Vincular número existente')
@section('content')
<a href="/number-administration">← Números e solicitações</a>
<div class="heading"><div><span class="eyebrow">TELEFONIA CENTRAL</span><h1>Vincular número existente</h1><p>Escolha a empresa que receberá um número já contratado pela DiscAI.</p></div></div>
<section class="card number-assignment"><form action="/number-administration/assign" method="post">@csrf
<input type="hidden" name="id" value="{{old('id', (string) Illuminate\Support\Str::uuid())}}">
<span class="eyebrow">1 · EMPRESA E NÚMERO</span>
<label>Cliente<select name="tenant_id" aria-label="Cliente" required><option value="">Selecione a empresa</option>@foreach($tenants as $tenant)<option value="{{$tenant->id}}" @selected((string)old('tenant_id',request('tenant'))===(string)$tenant->id)>{{$tenant->name}}</option>@endforeach</select></label>
<label>Número já contratado<input type="tel" name="number" value="{{old('number')}}" placeholder="Ex.: +551130001234" pattern="\+[1-9][0-9]{7,14}" maxlength="16" required aria-describedby="number-format"></label><p class="small" id="number-format">Informe +, código do país, DDD e número, sem espaços. Aceita números brasileiros e internacionais da conta Twilio da DiscAI.</p>
<label>Uso do número<select name="purpose" aria-label="Uso do número" required>@foreach(['mixed'=>'Entrada e saída','inbound'=>'Receber chamadas','outbound'=>'Fazer chamadas'] as $key=>$label)<option value="{{$key}}" @selected(old('purpose','mixed')===$key)>{{$label}}</option>@endforeach</select></label>
<label>Observação para o cliente (opcional)<textarea name="notes" rows="2" maxlength="500" placeholder="Ex.: Número incluído na contratação inicial.">{{old('notes')}}</textarea></label>
<div class="notice"><strong>2 · CONFERÊNCIA E LIBERAÇÃO</strong><p>Vamos conferir se o número pertence à conta da DiscAI e está livre para vínculo. Depois, você confirma a liberação para configurar a telefonia da empresa.</p></div>
<label class="number-consent"><input type="checkbox" name="confirm" value="1" required><span>Confirmo que este número será reservado para a empresa selecionada.</span></label>
@unless($configured)<p class="alert">Configure a conexão da operadora na administração antes de vincular um número.</p>@endunless
@if($tenants->isEmpty())<p class="notice">Cadastre e ative uma empresa para disponibilizar números.</p>@endif
<div class="tenant-actions"><a class="button" href="/number-administration">Cancelar</a><button class="primary" @disabled(!$configured||$tenants->isEmpty())>Conferir e vincular</button></div><p class="small">Esta etapa não compra números e não inicia chamadas. A liberação e a escolha da fila vêm a seguir.</p>
</form></section>
@endsection

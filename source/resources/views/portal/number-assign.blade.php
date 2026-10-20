@extends('portal.layout')
@section('title','Vincular número existente')
@section('content')
<a href="/number-administration">← Números e solicitações</a>
<div class="heading"><div><span class="eyebrow">TELEFONIA CENTRAL</span><h1>Vincular número existente</h1><p>Escolha a empresa que receberá um número já contratado pela DiscAI.</p></div></div>
<section class="card number-assignment"><form action="/number-administration/assign" method="post">@csrf
<input type="hidden" name="id" value="{{old('id', (string) Illuminate\Support\Str::uuid())}}">
<span class="eyebrow">1 · EMPRESA E NÚMERO</span>
<label>Cliente<select name="tenant_id" aria-label="Cliente" required><option value="">Selecione a empresa</option>@foreach($tenants as $tenant)<option value="{{$tenant->id}}" @selected((string)old('tenant_id',request('tenant'))===(string)$tenant->id)>{{$tenant->name}}</option>@endforeach</select></label>
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:20px"><label for="available-number" style="margin:0">Número e operadora</label><a class="small" href="{{url('/number-administration/assign')}}?tenant={{old('tenant_id',request('tenant'))}}">Atualizar lista ↻</a></div>
<select id="available-number" name="number" required aria-describedby="number-format" style="width:100%;margin-top:8px">
<option value="">{{$inventory['available']?'Selecione um número disponível':($inventory['errors']?'Disponibilidade não confirmada':'Nenhum número livre para vínculo')}}</option>
@foreach([true=>'Disponíveis',false=>'Já utilizados ou reservados'] as $available=>$group)
<optgroup label="{{$group}}">
@foreach($inventory['numbers'] as $item)
@if((bool)$item['available']===(bool)$available)
<option value="{{$item['number']}}" @disabled(!$item['available']) @selected($item['available']&&old('number')===$item['number'])>{{\App\Services\Portal\NumberInventory::display($item['number'])}} · {{$item['operator']}}{{$item['reason']?' · '.$item['reason']:''}}</option>
@endif
@endforeach
</optgroup>
@endforeach
</select>
<p class="small" id="number-format">Números já contratados e cadastrados na DiscAI. A operadora aparece ao lado do telefone; números com vínculo ficam indisponíveis para uma nova atribuição.</p>
@foreach($inventory['errors'] as $error)<p class="alert" role="alert">{{$error}}</p>@endforeach
@if(!$inventory['available'])
<div class="notice">
@if($inventory['errors'])<strong>Não foi possível confirmar a disponibilidade.</strong><p>Confira os avisos acima e atualize a lista para tentar novamente.</p>
@else<strong>Nenhum número livre no momento.</strong><p>Os números já vinculados continuam com a empresa indicada na lista. Para um novo número, contrate com a operadora e atualize o cadastro na DiscAI.</p>@endif
</div>
@endif

<label>Uso do número<select name="purpose" aria-label="Uso do número" required>@foreach(['mixed'=>'Entrada e saída','inbound'=>'Receber chamadas','outbound'=>'Fazer chamadas'] as $key=>$label)<option value="{{$key}}" @selected(old('purpose','mixed')===$key)>{{$label}}</option>@endforeach</select></label>
<label>Observação para o cliente (opcional)<textarea name="notes" rows="2" maxlength="500" placeholder="Ex.: Número incluído na contratação inicial.">{{old('notes')}}</textarea></label>
<div class="notice"><strong>2 · CONFERÊNCIA E LIBERAÇÃO</strong><p>Vamos conferir se o número pertence à conta da DiscAI e está livre para vínculo. Depois, você confirma a liberação para configurar a telefonia da empresa.</p></div>
<label class="number-consent"><input type="checkbox" name="confirm" value="1" required><span>Confirmo que este número será reservado para a empresa selecionada.</span></label>
@unless($configured)<p class="alert">Configure a conexão da operadora na administração antes de vincular um número.</p>@endunless
@if($tenants->isEmpty())<p class="notice">Cadastre e ative uma empresa para disponibilizar números.</p>@endif
<div class="tenant-actions"><a class="button" href="/number-administration">Cancelar</a><button class="primary" @disabled(!$configured||!$inventory['available']||$tenants->isEmpty())>Conferir e vincular</button></div><p class="small">Esta etapa não compra números e não inicia chamadas. A liberação e a escolha da fila vêm a seguir.</p>
</form></section>
@endsection

@extends('portal.layout')
@section('title','Meus números · '.$tenant->name)
@section('content')
<a href="/t/{{$tenant->slug}}?section=inbound">← Voltar à operação</a>
<div class="heading"><div><span class="eyebrow">TELEFONIA · {{$tenant->name}}</span><h1>Meus números</h1><p>Peça um número e acompanhe a liberação pela equipe DiscAI.</p></div>@if($operator)<a class="button" href="/number-administration">Gerenciar solicitações</a>@endif</div>
<details class="card number-request" @if($orders->isEmpty()) open @endif><summary>+ Solicitar novo número</summary>
<form method="post" action="/companies/{{$tenant->id}}/numbers" class="form-grid">@csrf<input type="hidden" name="id" value="{{(string)Illuminate\Support\Str::uuid()}}">
<label>País<input value="Brasil (+55)" readonly></label><label>DDD desejado<select name="ddd" required><option value="">Selecione o DDD</option>@foreach($ddds as $ddd)<option value="{{$ddd}}">{{$ddd}}</option>@endforeach</select></label>
<label>Como pretende usar?<select name="purpose"><option value="mixed">Receber e fazer chamadas</option><option value="inbound">Receber chamadas</option><option value="outbound">Fazer chamadas</option></select></label>
<label class="full">Observações <span class="small">opcional</span><textarea name="notes" maxlength="500" rows="2" placeholder="Conte à equipe alguma necessidade da sua operação."></textarea></label>
<p class="small full">Uma solicitação por número. A disponibilidade depende da operadora. O pedido será analisado pela administração e não gera contratação automática.</p><button class="primary">Enviar solicitação</button></form></details>
<div class="grid number-grid">@forelse($orders as $o)<article class="card number-card"><div class="company-top"><span class="eyebrow">{{$o->ddd?'BRASIL · DDD '.$o->ddd:'NÚMERO INTERNACIONAL'}}</span><span class="badge {{$o->status==='available'?'available':''}}">{{$labels[$o->status]??'Em análise'}}</span></div><h2>{{$o->number??'Seu próximo número'}}</h2><p>{{['inbound'=>'Recebimento de chamadas','outbound'=>'Chamadas de saída','mixed'=>'Entrada e saída'][$o->purpose]}}</p><p class="small">{{$o->source==='admin_assignment'?'Atribuído pela administração em':'Solicitado em'}} {{\Carbon\Carbon::parse($o->created_at)->format('d/m/Y H:i')}}</p>@if($o->source==='admin_assignment'&&$o->notes)<p class="small">{{$o->notes}}</p>@endif
@if($o->message)<p class="small">{{$o->message}}</p>@endif
@if($o->status==='available')<a class="button primary" href="/t/{{$tenant->slug}}?section=inbound">Escolher fila de atendimento →</a>@else<p class="small">{{$o->status==='rejected'?'Você pode enviar uma nova solicitação.':'A administração cuida da contratação e da configuração. Acompanhe o andamento por aqui.'}}</p>@endif
@if($operator)<a class="button" href="/number-administration/{{$o->id}}">Gerenciar pedido</a>@endif</article>@empty<section class="card number-empty"><h2>Um número para sua próxima conversa</h2><p>Solicite o DDD desejado. Assim que o número for liberado, você poderá vinculá-lo a uma fila.</p></section>@endforelse</div>
<div class="number-pagination">{{$orders->links()}}</div>
@endsection

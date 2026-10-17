@extends('portal.layout')
@section('title','Inteligência artificial')
@section('content')
<div class="heading"><div><span class="eyebrow">ADMINISTRAÇÃO DISCAI</span><h1>Inteligência artificial</h1><p>Escolha a tecnologia de cada empresa. Seus clientes cuidam da conversa; você cuida da infraestrutura.</p></div><a class="button" href="/administration">Voltar à administração</a></div>
<div class="grid companies-grid">@foreach($tenants as $t)<article class="card"><span class="badge">Configuração exclusiva</span><h2>{{$t->name}}</h2><p>Atendimento escrito, voz e limites de uso.</p><div class="tenant-actions">@if($t->status==='active')<a class="button primary" href="/ai-administration/{{$t->id}}">Configurar IA →</a>@else<span>Ambiente indisponível</span>@endif</div></article>@endforeach</div>
<div class="card"><h2>Uma experiência simples para o cliente</h2><p>Endereços, modelos, credenciais e limites são administrados aqui. O cliente continua configurando a identidade, o conhecimento e os canais do seu agente.</p><p class="small">Qwen atende texto nesta entrega. A voz ao vivo continua com OpenAI. Kokoro e Chatterbox permanecem no Estúdio de voz para gerar áudios.</p></div>
@endsection

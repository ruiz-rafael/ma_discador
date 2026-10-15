<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><link rel="icon" type="image/svg+xml" href="/brand/discai-mark.svg"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>@yield('title','Sua operação') · DiscAI</title><link rel="stylesheet" href="/portal.css?v={{ substr(hash_file('sha256', public_path('portal.css')), 0, 16) }}"></head><body>
<header><a class="brand" href="/companies"><img src="/brand/discai-mark.svg" alt="" width="44" height="44"> Disc<span>AI</span></a><nav aria-label="Principal">@auth<a href="/companies">Minhas empresas</a><form action="/logout" method="post">@csrf<button class="quiet" title="Encerrar as sessões abertas pelo portal">Sair</button></form>@else<a href="https://discai.ia.br">Voltar ao site ↗</a>@endauth</nav></header>
<main>@if($errors->any())<div class="alert" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(session('notice'))<div class="notice" role="status">{{session('notice')}}</div>@endif
@yield('content')</main><footer>DiscAI · Voz, relacionamento e próximas conversas.</footer>
</body></html>

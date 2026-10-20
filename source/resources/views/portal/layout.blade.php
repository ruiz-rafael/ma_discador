<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="csrf-token" content="{{ csrf_token() }}"><link rel="icon" type="image/svg+xml" href="/brand/discai-mark.svg"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow"><title>@yield('title','Sua operação') · DiscAI</title><link rel="stylesheet" href="/portal.css?v={{ substr(hash_file('sha256', public_path('portal.css')), 0, 16) }}"></head><body>
<header class="portal-header @auth portal-header-auth @endauth">
 <a class="brand" href="/companies"><img src="/brand/discai-mark.svg" alt="" width="44" height="44"> Disc<span>AI</span></a>
 <nav aria-label="Principal">
 @auth
 @php($isPlatformOperator=app(\App\Services\Portal\Access::class)->operator(auth()->id()))
 <a href="/companies" @if(request()->is('companies','companies/*','administration/*')) aria-current="page" @endif>{{$isPlatformOperator?'Meus clientes':'Minhas empresas'}}</a>
 @if($isPlatformOperator)<a href="/administration" @if(request()->is('administration','number-administration*','email-administration*','ai-administration*','signup-invitations*','platform-calling*')) aria-current="page" @endif>Console administrativo</a>@endif
 @else<a href="https://discai.ia.br">Voltar ao site ↗</a>@endauth
 </nav>
 @auth<form action="/logout" method="post" class="portal-signout">@csrf<button class="quiet" title="Encerrar as sessões abertas pelo portal">Sair</button></form>@endauth
</header>
<main>@if($errors->any())<div class="alert" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if(session('notice'))<div class="notice" role="status">{{session('notice')}}</div>@endif
@yield('content')</main><footer>DiscAI · Voz, relacionamento e próximas conversas.</footer>
@stack('scripts')
</body></html>

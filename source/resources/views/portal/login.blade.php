@extends('portal.layout')
@section('title','Entrar')
@section('content')<div class="login-layout"><section><span class="eyebrow">SUA OPERAÇÃO, CONECTADA</span><h1>Boas conversas<br>começam aqui.</h1><p class="lead">Entre para acessar sua empresa, organizar sua equipe e acompanhar cada relacionamento.</p><div class="pills"><span>Voz</span><span>WhatsApp</span><span>Cadências</span></div></section><section class="card login-card"><h2>Bem-vindo de volta</h2><p>Use seu acesso da DiscAI.</p><form id="portal-login-form" method="post" action="/login">@csrf<label>E-mail<input type="email" name="email" autocomplete="username" value="{{old('email')}}" required autofocus></label><label>Senha<input type="password" name="password" autocomplete="current-password" required></label><p id="login-refresh-message" class="alert" role="alert" hidden></p><button type="submit" class="primary wide">Entrar na plataforma →</button></form><p class="small">Ainda não tem acesso? Peça um convite ao administrador da sua empresa.</p><a href="https://discai.ia.br">Conhecer a DiscAI ↗</a></section></div>@endsection

@push('scripts')
<script src="/portal-login.js?v={{ substr(hash_file('sha256', public_path('portal-login.js')), 0, 16) }}" defer></script>
@endpush

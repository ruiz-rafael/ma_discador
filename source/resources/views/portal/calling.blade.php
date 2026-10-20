@extends('portal.layout')
@section('title','Discador administrativo')
@section('content')
<a class="back-link" href="/administration">← Console administrativo</a>
<div class="heading"><div><span class="eyebrow">TELEFONIA · ADMINISTRAÇÃO GERAL</span><h1>Discador administrativo</h1><p>Use os números habilitados da DiscAI sem entrar no ambiente de um cliente.</p></div><button class="primary" id="open-dialer">Abrir discador</button></div>
<div class="dial-overview"><article class="card"><span class="eyebrow">SEUS NÚMEROS</span><h2 id="number-count">Consultando conexões…</h2><p>Número, operadora e empresa juntos na seleção de saída.</p><button type="button" id="refresh-numbers" class="quiet">Atualizar números</button></article><article class="card"><span class="eyebrow">TESTE MANUAL</span><h2>Você no controle</h2><p>A ligação usa o áudio do navegador. O administrador não precisa ficar online em uma fila. O teste não inicia jornadas ou mensagens.</p></article></div>
<p id="catalog-message" role="status"></p>
<section class="card"><span class="eyebrow">SEU HISTÓRICO ADMINISTRATIVO</span><h2>Últimas tentativas</h2><div id="call-history"><p>Nenhuma chamada carregada.</p></div></section>
<dialog id="admin-dialer" aria-labelledby="dialer-title"><div class="dial-shell"><div class="dial-heading"><div><span class="eyebrow">DISCADOR DA DISCAI</span><h2 id="dialer-title">Fazer uma ligação</h2></div><button id="close-dialer" type="button" class="quiet" aria-label="Minimizar discador" title="Minimizar; a ligação continua">✕</button></div>
<form id="dial-form"><fieldset id="dial-fields"><label>Número de saída<select id="dial-origin" required><option value="">Carregando números…</option></select></label><p class="small" id="origin-help"></p>
<label>Para quem vamos ligar?<input id="dial-destination" type="tel" inputmode="tel" list="test-destinations" placeholder="+55 11 99999-0000" maxlength="30" required autocomplete="off"></label><datalist id="test-destinations"></datalist>
<label class="dial-authorization">Registro da autorização<input id="dial-evidence" minlength="8" maxlength="1000" placeholder="Ex.: meu telefone, teste solicitado por mim" required></label>
<label class="dial-check"><input id="dial-consent" type="checkbox" required>Tenho autorização do destinatário para esta ligação.</label></fieldset>
<div class="dial-actions"><button class="primary" id="dial-start" disabled>Ligar agora</button></div></form>
<section id="dial-feedback" aria-label="Acompanhamento da ligação" hidden>
<p id="dial-party" class="small"></p>
<div class="dial-result" role="status" aria-live="polite"><span id="dial-state">Pronto para ligar</span><p id="dial-detail">Escolha um número de saída e informe o destino.</p></div>
<p id="dial-error" class="alert" role="alert" hidden></p>
<div class="dial-actions"><button type="button" id="dial-stop" class="dial-danger" hidden>Encerrar ligação</button><button type="button" id="dial-retry" class="primary" hidden>Voltar ao discador</button></div>
<div class="dial-actions"><button type="button" class="quiet" id="dial-check" hidden>Atualizar resultado</button><button type="button" class="quiet" id="dial-reconcile" hidden>Consultar na operadora</button><button type="button" class="quiet" id="dial-audio" hidden>Ouvir áudio</button></div>
</section><audio id="remote-audio" autoplay playsinline></audio><p class="small dial-footnote">Use um headset. Fechar este modal não encerra a ligação.<br>Ligações reais estão sujeitas à cobrança da operadora.</p></div></dialog>
@endsection
@push('scripts')@vite('resources/js/portal-calling.js')@endpush

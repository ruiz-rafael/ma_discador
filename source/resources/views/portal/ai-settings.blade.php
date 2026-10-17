@extends('portal.layout')
@section('title','Parâmetros de IA')
@section('content')
@php($q=array_replace($settings['local'], array_intersect_key(old('local',[]), array_flip(['text_provider','endpoint','model','enabled','daily_requests','context_tokens','parallel_requests','timeout_seconds']))))
@php($o=array_replace($settings['openai'], array_intersect_key(old('openai',[]), array_flip(['enabled','daily_requests','project_id']))))
<div class="heading"><div><span class="eyebrow">INTELIGÊNCIA ARTIFICIAL · ADMINISTRAÇÃO</span><h1>{{$tenant->name}}</h1><p>Uma escolha para o atendimento escrito. Voz configurada separadamente.</p></div><a class="button" href="/ai-administration">Todas as empresas</a></div>
<form method="post" action="/ai-administration/{{$tenant->id}}" class="card ai-admin-form">@csrf<input type="hidden" name="action" value="save"><input type="hidden" name="local[revision]" value="{{$q['revision']}}">
<span class="eyebrow">01 · ATENDIMENTO ESCRITO</span><h2>Quem responde no WhatsApp?</h2>
<label>Tecnologia<select name="local[text_provider]"><option value="openai" @selected($q['text_provider']==='openai')>GPT · OpenAI</option><option value="qwen" @selected($q['text_provider']==='qwen')>Qwen3-8B · infraestrutura própria</option></select></label>
<p class="small">A escolha vale para novos testes de texto e respostas automáticas. Nunca há troca automática para um provedor pago.</p>
<details id="qwen-settings" class="settings" @if($q['text_provider']==='qwen') open @endif><summary>Qwen · servidor e capacidade</summary><div class="form-grid">
<label>Servidor Ollama<input name="local[endpoint]" type="url" value="{{$q['endpoint']}}" maxlength="250" placeholder="https://ia.suaempresa.com.br"><span class="small">Origem HTTPS protegida por chave. Sem /api no final.</span></label>
<label>Chave do servidor<input name="local[api_key]" type="password" autocomplete="new-password" maxlength="500" placeholder="{{$q['key_configured']?'Deixe vazio para manter':'Chave do proxy de autenticação'}}"></label>
<label>Modelo instalado<select name="local[model]"><option value="qwen3:8b" @selected($q['model']==='qwen3:8b')>qwen3:8b</option><option value="qwen3:8b-q4_K_M" @selected($q['model']==='qwen3:8b-q4_K_M')>qwen3:8b-q4_K_M · 4 bits</option></select></label>
<label>Solicitações por dia<input type="number" name="local[daily_requests]" min="1" max="1000" value="{{$q['daily_requests']}}" required></label>
<label>Memória da conversa<select name="local[context_tokens]"><option value="8192" @selected($q['context_tokens']==8192)>8 mil tokens · menor consumo</option><option value="16384" @selected($q['context_tokens']==16384)>16 mil tokens · mais contexto</option></select></label>
<label>Respostas simultâneas nesta empresa<input type="number" name="local[parallel_requests]" min="1" max="4" value="{{$q['parallel_requests']}}" required></label>
<label>Espera máxima por resposta (segundos)<input type="number" name="local[timeout_seconds]" min="10" max="60" value="{{$q['timeout_seconds']}}" required></label>
</div><input type="hidden" name="local[enabled]" value="0"><label class="check"><input name="local[enabled]" type="checkbox" value="1" @checked($q['enabled'])>Liberar Qwen para esta empresa</label>
<p class="small">Ao liberar, verificamos se o modelo está instalado. Não instalamos nem baixamos modelos automaticamente. Sem tarifa de API por token; servidor e manutenção têm custo. O limite de simultaneidade é por empresa: dimensione também o total no servidor.</p></details>
<details class="settings"><summary>OpenAI · texto e voz ao vivo</summary><div class="form-grid">
<label>Chave OpenAI<input type="password" name="openai[api_key]" autocomplete="new-password" maxlength="300" placeholder="{{$o['configured']?'Deixe vazio para manter':'sk-…'}}"></label>
<label>Solicitações por dia<input type="number" name="openai[daily_requests]" min="1" max="1000" value="{{$o['daily_requests']}}" required></label>
<label>Projeto SIP (opcional)<input name="openai[project_id]" value="{{$o['project_id']}}" maxlength="120" placeholder="proj_…"></label>
<label>Assinatura SIP (opcional)<input name="openai[sip_secret]" type="password" autocomplete="new-password" maxlength="300" placeholder="whsec_… · vazio mantém a atual"></label>
</div><p class="small">GPT-4.1 mini: US$ 0,40 entrada / US$ 1,60 saída por milhão de tokens. GPT-Realtime-2.1: áudio US$ 32 / US$ 64; texto US$ 4 / US$ 24 por milhão de tokens. Referência 16/10/2026, sem atualização automática. <a href="https://developers.openai.com/api/docs/pricing" target="_blank" rel="noopener">Tabela do provedor ↗</a></p>
<input type="hidden" name="openai[enabled]" value="0"><label class="check"><input name="openai[enabled]" type="checkbox" value="1" @checked($o['enabled'])>Liberar consumo OpenAI</label><label class="check"><input name="openai[cost_acknowledged]" type="checkbox" value="1">Conferi os custos e autorizo o consumo OpenAI nesta empresa.</label><p class="small">Limite de solicitações não é teto financeiro. Voz e telefonia podem ter custos adicionais. Escolher Qwen para texto não desativa a voz OpenAI.</p></details>
<div class="tenant-actions"><button class="primary">Salvar parâmetros</button></div>
</form>
<form method="post" action="/ai-administration/{{$tenant->id}}" class="card">@csrf<input type="hidden" name="action" value="probe"><h2>Conferir a conexão Qwen</h2><p>Consulta o servidor salvo e confirma o modelo instalado. Não gera texto, áudio nem chamadas.</p><button @disabled(!$q['endpoint'])>Verificar servidor</button><p class="small">Depois, use o laboratório do agente para avaliar respostas em português, inglês e espanhol. Voz com Qwen ainda não está disponível.</p></form>
<script>document.querySelector('[name="local[text_provider]"]').addEventListener('change', function(){document.getElementById('qwen-settings').open=this.value==='qwen';});</script>
@endsection

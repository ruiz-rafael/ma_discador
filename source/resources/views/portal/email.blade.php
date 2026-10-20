@extends('portal.layout')
@section('title',$company?'E-mail de '.$company->name:'E-mail da DiscAI')
@section('content')
<a href="{{$company?'/administration/'.$company->id:'/administration'}}">← {{$company?'Voltar à empresa':'Console administrativo'}}</a>
<div class="heading"><div><span class="eyebrow">{{$company?'CONEXÃO DA EMPRESA':'COMUNICAÇÕES DA DISCAI'}}</span><h1>{{$company?'E-mail de '.$company->name:'E-mail da DiscAI'}}</h1><p>{{$company?'A conta usada nas jornadas desta empresa.':'A conta administrativa usada para enviar convites da plataforma.'}}</p></div><span class="badge">Google Workspace / Gmail</span></div>
<div class="mail-grid">
<section class="card mail-connection">
    <span class="eyebrow">01 · CONTA CONECTADA</span>
    <h2>{{$senderDetails['account']??'Conecte sua conta Google'}}</h2>
    <p>{{$connection['status']==='connected'?'Conta autorizada. Escolha abaixo como sua empresa aparecerá nos e-mails.':($connection['status']==='reconnect'?'Renove a autorização para continuar.':'Autorize a conta que enviará suas mensagens.')}}</p>
    <p class="small">Esta configuração pertence exclusivamente a {{$company?->name??'DiscAI (administração geral)'}}. Conectar não envia e-mails. Não lemos sua caixa de entrada.</p>
    @if($oauthReady)
    <form method="post" action="{{$path}}/connect">@csrf
        <input type="hidden" name="sender_access" value="{{$senderDetails['access']?'1':'0'}}">
        <button class="primary">{{$senderDetails['account']?'Reconectar ou trocar conta':'Conectar com Google'}} →</button>
    </form>
    @else<p class="notice">A conexão ficará disponível após a configuração do aplicativo Google pela DiscAI.</p>@endif
    @if(in_array($connection['status'],['connected','reconnect']))
    <form method="post" action="{{$path}}/disconnect" class="mail-disconnect">@csrf
        <input type="hidden" name="revision" value="{{$connection['revision']}}"><button class="quiet">Desconectar conta</button>
    </form>
    @endif
</section>
<section class="card">
    <span class="eyebrow">02 · COMO VOCÊ APARECE</span>
    <h2>Remetente dos e-mails</h2>
    @if($connection['status']==='connected')
        @if(!$senderDetails['access'])
        <p>Quer enviar por um endereço comercial, alias ou grupo? Autorize a consulta dos remetentes cadastrados no seu Gmail.</p>
        <form method="post" action="{{$path}}/connect">@csrf<input type="hidden" name="sender_access" value="1"><button>Autorizar remetentes do Gmail →</button></form>
        <p class="small">O Google solicita acesso às configurações básicas do Gmail. A DiscAI usa essa permissão para consultar remetentes; não altera filtros ou configurações do Gmail.</p>
        @else
        <form method="post" action="{{$path}}/senders">@csrf<input type="hidden" name="revision" value="{{$connection['revision']}}"><button>Atualizar remetentes do Google</button></form>
        @endif
        <form method="post" action="{{$path}}/senders" class="mail-sender-form">@csrf
            <input type="hidden" name="revision" value="{{$connection['revision']}}">
            <label>Enviar como<select name="sender" required><option value="">Selecione um remetente autorizado</option>
            @foreach($senderDetails['options'] as $option)
                <option value="{{$option['email']}}" @selected($connection['from']===$option['email'])>{{$option['email']}}{{$option['primary']?' · Conta principal':' · Endereço autorizado'}}</option>
            @endforeach
            </select></label>
            <label>Nome exibido ao destinatário<input name="sender_name" maxlength="120" value="{{$connection['sender_name']?:($company?->name??'DiscAI')}}" placeholder="Nome da sua empresa" required></label>
            <p class="small">As respostas serão direcionadas ao endereço escolhido. Salvar o remetente pausa os envios até você conferir e liberar novamente.</p>
            <button class="primary">Salvar remetente</button>
        </form>
        <details class="settings"><summary>Meu endereço comercial não aparece</summary>
            <p>No Gmail da conta conectada, abra Configurações → Contas → Enviar e-mail como. Adicione o endereço comercial e conclua a verificação. Depois, atualize os remetentes aqui.</p>
            <p class="small">Para grupos, confirme que a mensagem de verificação pode chegar aos membros responsáveis. Apenas participar do grupo não autoriza o envio por ele.</p>
            <a href="https://mail.google.com/mail/u/0/#settings/accounts" target="_blank" rel="noopener noreferrer">Abrir configurações do Gmail ↗</a>
        </details>
        <p class="small">Alguns serviços podem identificar a conta conectada nos cabeçalhos ou como “em nome de”. Para não usar seu endereço pessoal na origem, conecte uma caixa comercial própria.</p>
    @else<p class="notice">Conecte a conta Google para escolher o remetente.</p>@endif
</section>
</div>
<section class="card">
    <span class="eyebrow">03 · CONFERIR E LIBERAR</span>
    <h2>{{$connection['enabled']?'Envios liberados':'Envios pausados'}}</h2>
    @if($connection['from'])
        <p class="notice">Remetente escolhido: <strong>{{$connection['sender_name']}} &lt;{{$connection['from']}}&gt;</strong><br>Respostas para: {{$connection['reply_to']}}</p>
    @else<p class="notice">Escolha e salve um remetente antes de liberar os envios.</p>@endif
    @if($connection['status']==='connected'&&$connection['from'])
    <form method="post" action="{{$path}}" class="form-grid">@csrf<input type="hidden" name="revision" value="{{$connection['revision']}}">
        <label>Permissão de envio<select name="enabled"><option value="0" @selected(!$connection['enabled'])>Pausado</option><option value="1" @selected($connection['enabled'])>Liberado</option></select></label>
        <label>Limite diário de tentativas<input name="daily_limit" type="number" min="1" max="{{config('google_mail.daily_limit_max')}}" value="{{$connection['daily_limit']}}" required></label>
        <button class="primary">Salvar preferências</button>
    </form>
    @endif
    <p class="small">Os limites do Google também se aplicam. Se o remetente escolhido perder a autorização, os envios serão bloqueados. Não usamos outra conta ou remetente como alternativa.</p>
</section>
@if($operator)<details class="card settings"><summary>Preparação do aplicativo Google</summary><p>Aplicativo Web · público externo · identidade da conta, gmail.send e, para consultar remetentes, gmail.settings.basic. A permissão de configurações é restrita e amplia os requisitos de verificação do aplicativo pelo Google.</p><label>URI de redirecionamento autorizado<input readonly value="{{$callback}}"></label><p class="small">O Client Secret é instalado no servidor; nunca aparece nesta página. A publicação do aplicativo e a verificação do Google são etapas separadas.</p></details>@endif
<section class="card"><h2>Últimos envios desta conta</h2><p class="small">Aceitação pelo Google não comprova entrega ou leitura. Resultados incertos não são reenviados automaticamente. Horários em UTC.</p><div class="table-scroll"><table><thead><tr><th>Destinatário</th><th>Resultado</th><th>Quando</th></tr></thead><tbody>@forelse($history as $h)<tr><td>{{$h->destination}}</td><td>{{['accepted'=>'Aceito pelo Google','submitting'=>'Iniciado · aguarde ou confira o provedor','blocked'=>'Impedido antes do envio','unknown'=>'Não confirmado'][$h->status]??$h->status}}<p class="small">{{$h->reason}}</p></td><td>{{$h->created_at}}</td></tr>@empty<tr><td colspan="3">Nenhum envio registrado nesta conta.</td></tr>@endforelse</tbody></table></div></section>
@endsection

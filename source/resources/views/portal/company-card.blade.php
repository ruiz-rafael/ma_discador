@php($usage=json_decode($t->usage??'null',true))
<article class="card company-card">
 <div class="company-top"><span class="company-avatar">{{mb_substr($t->name,0,1)}}</span><span class="badge {{ $t->status==='active'?'available':'' }}">{{['active'=>'Disponível','suspended'=>'Suspensa','provisioning'=>'Preparando','failed'=>'Revisar preparação'][$t->status]??$t->status}}</span></div>
 <h2>{{$t->name}}</h2><p class="small">{{$operator?'Seu acesso: administrador geral':('Seu perfil: '.(['admin'=>'Administrador','supervisor'=>'Supervisor','agent'=>'Atendente'][$t->role]??$t->role))}}</p>
 @if($operator||in_array($t->role??null,['admin','supervisor']))
 <div class="company-metrics"><div><strong>{{$usage['queues']??'—'}}</strong><span>filas</span></div><div><strong>{{$usage['campaigns']??'—'}}</strong><span>cadências</span></div><div><strong>{{$usage['online_agents']??'—'}}</strong><span>online</span></div><div><strong>{{$usage['active_calls']??'—'}}</strong><span>chamadas em curso</span></div></div>
 <p class="small snapshot-note">{{$t->checked_at?'Última coleta: '.$t->checked_at.' UTC':'Indicadores disponíveis após a primeira coleta.'}}</p>
 @endif
 @if($t->status==='active')
 <a class="button primary" href="/t/{{$t->slug}}">Entrar na empresa →</a>
 @if($operator||in_array($t->role??null,['admin','supervisor']))<div class="company-shortcuts"><a href="/t/{{$t->slug}}?section=supervision">Supervisão</a>@if($operator||($t->role??null)==='admin')<a href="/t/{{$t->slug}}?section=queues">Filas</a><a href="/t/{{$t->slug}}?section=journeys">Cadências</a>@endif</div>@endif
 @else<p class="small">{{$t->status==='suspended'?'O acesso operacional está suspenso.':'Estamos preparando o ambiente exclusivo desta empresa.'}}</p>@endif
 @if($operator||($t->role??null)==='admin')<div class="company-foot"><a href="/administration/{{$t->id}}">{{$operator?'Gerenciar cliente e equipe':'Equipe e convites'}} →</a></div>@endif
</article>

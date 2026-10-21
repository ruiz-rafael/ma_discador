# Reconexão do receptivo

O registro de 18:59 UTC foi atendido pela espera da fila, sem atendimento humano. Às 19:01:16 UTC, outra ligação foi atendida pelo usuário 2 e teve 12 segundos de conversa registrados. Isso confirma entrega e aceite no navegador, não a qualidade do áudio nos dois sentidos.

A conexão receptiva mantém uma concessão de 60 segundos para impedir disputa entre abas. O frontend gerava um novo UUID em cada tentativa e não protegia todo o processo de registro contra falhas ou chamadas concorrentes. Agora:

- Cliques simultâneos compartilham uma única conexão; a identificação permanece igual durante as tentativas da mesma instância.
- Falhas liberam a concessão desta instância, inclusive antes de criar os clientes SIP/Twilio.
- O servidor distingue concessão temporária de chamada/tabulação ativa e informa o prazo restante.
- Quando o registro antigo ainda está expirando, uma contagem regressiva aparece também no diálogo de status. Há uma única nova tentativa automática, sem tomar uma sessão ativa. Sair da página cancela a tentativa.
- Eventos tardios de um receptor SIP anterior não desconectam sua substituição.

Validação: 51 testes PHP / 314 assertions, 4 testes JavaScript, build Vite. Publicação sem reinício do PBX. Backup: `/srv/zyrex-ma/backups/receiver-reconnect-20261021T190533Z`.

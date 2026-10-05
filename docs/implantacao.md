# Implantação — 07/09/2026

**Atualização dos nós:** consulte `configuracao-nos-crm.md` para os novos formulários, catálogo de referências e contrato de sincronização. As limitações abaixo descrevem a primeira publicação, salvo as atualizações indicadas.

## Endereço e acesso

URL: https://ma.zyrex.ia.br. Usuário inicial: `admin@zyrex.ia.br`. Senha aleatória exclusiva entregue no arquivo local `.secrets/admin-access.txt`; cópia remota `/srv/zyrex-ma/admin-access.txt`. A senha SSH da VM não foi gravada no código ou usada como senha da aplicação.

O workspace inicial é administrativo e de uma única organização. Não existe cadastro público. Novos administradores e mudanças de senha são operados pelo administrador via Laravel; a tela de gerenciamento de usuários não integra esta entrega.

## Isolamento

Compose `zyrex-ma`, diretório `/srv/zyrex-ma`, sub-rede própria `10.241.50.0/24` verificada contra redes e rotas existentes. Os pools automáticos do Docker estavam esgotados; nenhuma rede anterior foi removida ou alterada.

Seis serviços exclusivos: `app`, `web`, `worker`, `scheduler`, `postgres`, `redis`. Banco `zyrex_ma`, volumes `zyrex-ma_pgdata` e `zyrex-ma_redisdata`. Apenas web publica porta, em `127.0.0.1:18280`. PHP/app e worker têm limites próprios; Horizon limitado a dois processos em produção. Docker logs têm rotação de 10 MB, três arquivos por serviço.

O proxy compartilha somente o Nginx do host, adicionando o virtual host `ma.zyrex.ia.br`. Foi validado com `nginx -t` e carregado por reload gracioso. Configurações anteriores tiveram hashes preservados. Certificado Let's Encrypt emitido, válido até 06/12/2026 na coleta. Renovação por webroot `/srv/zyrex-ma/acme`, com hook exclusivo condicionado ao domínio.

Arquivos acrescentados no host:

- `/etc/nginx/sites-available/ma.zyrex.ia.br` e link em `sites-enabled`.
- Certificado e configuração de renovação de `ma.zyrex.ia.br` em `/etc/letsencrypt`.
- `/etc/letsencrypt/renewal-hooks/deploy/zyrex-ma.sh`.
- `/etc/cron.d/zyrex-ma-backup`.

Os 26 containers que estavam ativos no início mantiveram seus IDs. O processo principal do Nginx permaneceu o mesmo. Os códigos HTTP dos seis endpoints anteriores verificados permaneceram iguais. Outros containers de CRM apareceram durante o intervalo; esta implantação não os criou nem os modificou. Comparação: `evidence/comparison.json` e `evidence/nginx-comparison.txt`.

## Validação

- PHPUnit: 11 testes, 30 assertions, sem falhas na execução direta. Testes em SQLite temporário em memória, com variáveis explícitas para não usar PostgreSQL da aplicação.
- Build Vite de produção aprovado.
- Chromium: login, lista, contato, criação de jornada, configuração e salvamento de nós, reabertura, publicação, admissão, execução via Redis/Horizon, relatório e pausa. Sem erros JavaScript. Uma segunda validação conectou as portas por arraste real do mouse, salvou e reabriu o fluxo com a conexão preservada.
- HTTPS público e health check local retornaram 200.
- Backup PostgreSQL restaurado em banco temporário próprio; contagens de jornadas/logs verificadas. Banco temporário removido após verificação.

## Operação

Sempre executar dentro de `/srv/zyrex-ma`. Não aplicar comandos globais do Docker.

```sh
cd /srv/zyrex-ma
docker compose ps
docker compose logs --tail=100 app worker scheduler
docker compose exec -T app php artisan horizon:status
./backup.sh
```

Backup diário às 03:17 no timezone do host, arquivos em `backups/`, com SHA-256. Os backups ficam na mesma VM; cópia externa e política de retenção ainda precisam ser definidas. Para restauração, validar o arquivo em banco temporário antes de qualquer substituição do banco principal. Nunca apontar `pg_restore` para bancos de outros projetos.

Para retirar exclusivamente esta aplicação do ar, parar seus serviços pelo Compose deste diretório e remover apenas seu virtual host após `nginx -t`. Preservar volumes e backups. Não usar `docker system prune` ou `compose down -v`.

## Integração de eventos

`POST /events` aceita JSON com `event_key`, `contact_id`, `type` e, para callbacks, `token_id` e `status`.

Headers:

- `X-MA-Timestamp`: Unix timestamp em segundos, tolerância de cinco minutos.
- `X-MA-Signature`: HMAC-SHA256 hexadecimal de `timestamp + "." + corpo JSON exato`, usando `MA_WEBHOOK_SECRET`.

O emissor preserva `event_key` ao repetir um evento. O banco deduplica esse identificador. Eventos sem token admitem contatos em jornadas ativas e vigentes cujo público contenha o contato. `type` corresponde à chave do catálogo, por exemplo `open_trigger`, `form_submitted`, `email_event`, `whatsapp_event`, `cart_abandoned`, `purchase`.

Callbacks devem correlacionar o ID do token devolvido ao gateway. O segredo de webhook e tokens de provedor ficam fora do navegador. Não colocar segredos nas configurações JSON dos nós.

## Gateway de envio

Configurar exclusivamente no `.env` remoto:

- `MA_GATEWAY_URL`: endpoint HTTPS do adaptador de provedores.
- `MA_GATEWAY_TOKEN`: token Bearer.
- `MA_ALLOWED_HOSTS`: hosts exatos permitidos, separados por vírgula; inclui o gateway e destinos autorizados de nós Webhook.

A aplicação envia `action`, `contact`, `settings`, `token_id`, com `Idempotency-Key: ma-token-ID`. O gateway precisa honrar essa chave e devolver confirmação real de aceitação pelo provedor. Não deve devolver sucesso para simulações. Entrega/leitura/respostas são eventos posteriores, não inferidos do HTTP 2xx.

Antes de ativar canais: implementar/configurar o adaptador do provedor escolhido, mapear templates e callbacks, testar credenciais e idempotência. Esta entrega não inclui conexão nativa já autenticada com Meta, um provedor de SMS ou CRM externo. Sem gateway, a publicação desses nós é bloqueada. Ações Webhook exigem HTTPS e allowlist; destinos privados/reservados e redirects são bloqueados, com resolução IP fixada durante a requisição.

## Limites funcionais desta versão

- Listas locais são implementadas; segmentos dinâmicos e rastreamento de páginas/formulários/carrinhos dependem de eventos do sistema de origem. Não há construtor de segmentos, pixel ou conector de e-commerce nesta versão.
- Gatilho de data compara campo com a data UTC atual, sem offsets de aniversário. O cíclico usa intervalos de minutos alinhados ao relógio UTC.
- Payloads de Webhook/Sync usam JSON; botões, parâmetros de template e regras múltiplas agora possuem formulários. Divisão múltipla possui dois caminhos de regra e um fallback.
- Merge continua cada ramo ao chegar; não implementa uma barreira que aguarda todos os ramos. Ciclos no grafo são bloqueados; recorrência ocorre pelo gatilho cíclico.
- Wait Until verifica condição a cada minuto; delays têm precisão de scheduler, não de segundos. Callback de atividade precisa indicar o token da espera; o adaptador deve manter a correlação com a mensagem.
- Os grafos publicados são imutáveis para execuções já admitidas. A pausa impede novos avanços na verificação do worker, mas não cancela uma chamada externa já iniciada.
- Operações externas têm chave de idempotência, mas a garantia depende do gateway. Falhas dos handlers ficam registradas; não há botão de retry manual. Não se declara entrega exatamente uma vez em provedores externos.
- Tarefas e negócios ficam no CRM local da aplicação; sincronização com CRM externo depende do gateway.
- Relatórios mostram execuções de nós; sucesso de ação não é confirmação de entrega de mensagem. Listagens atuais limitam contatos/entradas a 500 e logs a 100.
- O cadastro inicial da jornada e o canvas estão disponíveis. Edição de metadados da jornada, gestão avançada de usuários, importação em massa e construtor de templates não integram esta versão.

As credenciais e a escolha dos provedores são as próximas dependências para envios reais. Essas limitações não afetam os serviços preexistentes da VM.

## Laboratório de voz em 01/10/2026

Foi adicionado o menu Voz e cadências ao mesmo projeto, sem novos containers ou portas. O documento balizador, capacidades simuladas, testes e pendências de integração estão em [voz-whatsapp-ia.md](voz-whatsapp-ia.md). A implantação inicial e suas limitações continuam aplicáveis às funcionalidades que não foram alteradas.

## Áudio interno — marco 2

A atualização seguinte adicionou um container PBX exclusivo, WSS autenticado e portas UDP para o teste real de eco. Consulte [marco-2-telefonia.md](marco-2-telefonia.md) para uso, contrato, portas, validação e reversão. A ausência de operadora mantém ligações externas pendentes.

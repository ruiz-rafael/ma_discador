# Listas: edição, cadastro, CSV e webhooks

Publicado no MA em 04/10/2026. Acesse **Listas de contatos → Editar lista** (ou clique no nome). A tela reúne listas de **Automação MA** e **Voz e WhatsApp**, com identificação do uso; os cadastros dos dois motores continuam separados. Nenhum dado foi migrado na publicação. Uma lista do MA pode ser vinculada a uma jornada de voz pelo cartão Público da jornada, conforme descrito abaixo.

## Organização da tela — 05/10/2026

Lista é um público escolhido explicitamente. Segmento dinâmico seleciona pessoas por regras; o gerenciador atual organiza listas e não executa filtros dinâmicos de segmentação.

A lista mantém seus contatos visíveis na tela principal. Quatro cartões abrem painéis laterais sobrepostos: **Cadastro manual**, **Contatos existentes**, **Importar planilha** e **Receber por webhook**. **Lista e campos** também abre um painel. Os painéis têm navegação por teclado, fechamento por Escape e confirmação de alterações não salvas. O token do webhook exige confirmação antes de fechar.

Em **Contatos existentes**, busque e selecione até 100 cadastros por inclusão, com páginas de 20 resultados. A busca usa o cadastro do mesmo tipo da lista: listas MA consultam os contatos do MA; listas de voz consultam os contatos do discador. Use uma lista MA como público da cadência quando quiser aproveitar o cadastro central de contatos. Participações existentes ficam identificadas; retiradas exigem a ação explícita **Reincluir**. A inclusão é atômica e idempotente, preserva dados e autorizações e não aciona chamadas/mensagens.

O teste do webhook valida o mapeamento e os dados do JSON no servidor, exibindo o contato normalizado. Ele não cria contatos, não grava recebimentos e não testa a conectividade de um sistema externo. Para testar a entrega externa, o integrador pode enviar uma requisição autenticada à URL; esse envio real cadastra/vincula o contato conforme as regras da lista.

## Contatos

- Cadastre nome, telefone/e-mail, origem, ID no CRM e campos adicionais no cartão **Cadastro manual**.
- Nas listas de voz o telefone é obrigatório. Na automação, informe telefone ou e-mail.
- Informe autorização e sua evidência quando disponíveis; o padrão é sem autorização.
- Um contato existente é vinculado sem sobrescrever nome, dados, autorização, bloqueios ou histórico. Conflitos em que e-mail e telefone identificam pessoas diferentes são rejeitados.
- **Retirar** remove somente a participação na lista. Nova importação/webhook não desfaz a retirada; **Reincluir** é uma ação manual explícita. Reincluir não zera tentativas nem remove bloqueios.
- Em listas de voz, retirar cancela WhatsApps pendentes das campanhas vinculadas; um envio já aceito pelo provedor não pode ser recolhido.

O gerenciamento de listas não inicia jornadas de automação automaticamente. Seus contatos ficam disponíveis para as regras de entrada do MA. Na voz, membros ativos são vinculados às campanhas que usam a lista, preservando pausas, autorizações, limites e histórico. Nenhuma configuração de ativação foi alterada nesta entrega.

## Usar uma lista do MA na jornada de voz

Em **Jornadas → abra a jornada → Público da jornada → Origem do público**, o seletor reúne listas MA e Voz e WhatsApp. Selecione, por exemplo, **Lista Teste · MA** e clique em **Salvar etapa**, com a campanha pausada. O botão **Importar e gerenciar listas** abre o gerenciador unificado.

O vínculo é persistente: ao salvar e antes de reservar o próximo contato na fila real, os contatos atuais da lista MA com nome e telefone válidos são preparados para o discador. Inclusões manuais, CSV e webhook passam a compor o público nessa atualização. Telefones repetidos são consolidados; cadastros sem telefone válido ficam fora do discador. O limite atual é 500 registros por lista; acima disso a seleção/reserva é recusada, sem truncar o público.

A origem permanece no MA. Contatos de voz existentes são reutilizados pelo telefone, preservando autorização, bloqueios, respostas e histórico. Novos cadastros recebem os dados e campos adicionais da origem. Autorização exige inscrição ativa e evidência documentada; selecionar a lista não concede autorização. Em duplicidades de telefone na mesma lista, qualquer registro sem autorização impede a abordagem.

Antes de discar e de enviar o WhatsApp pendente, o sistema revalida a presença, telefone e autorização do contato na lista MA. Retiradas pelo gerenciador cancelam os WhatsApps pendentes vinculados. Reinclusão não reinicia tentativas nem elimina histórico ou bloqueios. Alterações do público não iniciam chamadas: a fila continua exigindo campanha habilitada e atendente disponível.

As listas MA são acessíveis somente ao workspace principal. A identificação inclui o tipo da lista, evitando confundir uma lista MA e uma lista de voz com o mesmo ID. As regras avançadas preservam o vínculo MA; a troca de público é feita pelo cartão da jornada. Os relatórios existentes continuam disponíveis por campanha; o filtro legado de lista permanece específico às listas de voz.

## Campos adicionais e nome da lista

Em **Lista e campos**, altere o nome ou cadastre até 20 campos: nome exibido, chave (`empresa`, `cidade` etc.), tipo (texto, número, sim/não ou data) e obrigatoriedade. Datas usam `AAAA-MM-DD`; documentos e códigos com zeros à esquerda devem ser texto. Campos obrigatórios são validados em novos cadastros/importações/eventos; cadastros existentes não são reescritos.

Os campos ficam armazenados no contato. Isso não adiciona automaticamente novas variáveis ao catálogo de templates de voz/WhatsApp. Webhooks ativos que usam um campo precisam ser remapeados ou desativados antes de removê-lo da definição da lista.

## CSV

1. Abra **Importar planilha**. **Baixar modelo CSV** gera o cabeçalho com nome, telefone, e-mail e os campos adicionais da lista.
2. Envie CSV UTF-8 de até 2 MB, com até 1.000 contatos e 50 colunas. Escolha ponto e vírgula, vírgula ou tabulação.
3. Relacione as colunas aos campos. Nomes diferentes de cabeçalho são aceitos pelo mapeamento.
4. Informe origem e, quando aplicável, autorização/evidência comum. Colunas de autorização/evidência mapeadas prevalecem sobre os valores comuns.
5. Clique em **Validar e gerar prévia**. Confira válidos, inválidos e os números das linhas com erro.
6. **Importar contatos válidos** confirma a entrada. O resumo distingue novos, existentes preservados, vínculos criados e retiradas preservadas.

Prévia não cria contatos. Mostra até 20 registros e 100 erros. Confirmação é idempotente e transacional; prévias expiram em 24 horas e são recusadas se a configuração da lista mudar. Telefones em fórmula/notação científica são rejeitados. Números brasileiros com DDD sem DDI são normalizados para `+55`.

## Webhook de entrada

1. Em **Receber por webhook → Novo webhook** (ou o formulário aberto na primeira configuração), dê um nome e configure o recebimento ativo/desativado.
2. Marque os campos que farão parte do payload. Mapeie **Caminho no JSON** para **Salvar em**. Exemplo: `cliente.nome` → Nome, `cliente.telefone` → Telefone, `cliente.empresa` → Empresa.
3. Clique em **Gerar exemplo com estes campos**, ou cole um exemplo de JSON e clique em **Testar payload sem cadastrar**. Essa ação mostra os valores normalizados e não grava contatos.
4. **Gerar webhook** gera a URL e um token. Copie o token nessa ocasião; o servidor guarda apenas seu hash, e não o exibe novamente. Gerar novo token ao salvar invalida o anterior.
5. Envie um objeto JSON por requisição `POST` para a URL gerada, com:
   - `Authorization: Bearer SEU_TOKEN`
   - `Content-Type: application/json`
   - `Idempotency-Key: ID_UNICO_DO_EVENTO`

Exemplo de corpo:

```json
{
  "cliente": {
    "nome": "Ana Souza",
    "telefone": "+5511999990001",
    "email": "ana@example.test",
    "empresa": "Empresa exemplo"
  }
}
```

Autorização não é presumida: para recebê-la, mapeie também os campos de autorização (`true`/`false`, `1`/`0`, sim/não) e sua evidência. O endpoint recebe até 64 KB por evento, com limite de 60 requisições/minuto por IP. São permitidos até 10 webhooks por lista.

A mesma chave com o mesmo corpo retorna o resultado anterior; chave repetida com conteúdo diferente retorna `409`. Não há segundo cadastro nesse reenvio. Tokens inválidos retornam `401`, webhook desativado `410`, mapeamento/dados inválidos `422`. A tela lista os dez últimos recebimentos aceitos, sem armazenar o corpo original ou o token no histórico. Erros de validação são devolvidos ao sistema remetente.

## API e implementação

- Gerenciamento autenticado: `/api/lists`, `/api/lists/{automation|voice}/{id}`; edição reservada a administrador/supervisor do workspace principal.
- Seleção existente: `GET .../available-contacts?search=...&page=1`; `POST .../existing-contacts` com `contact_ids` (até 100 IDs distintos).
- Contatos: `POST .../contacts`; retirada `DELETE .../contacts/{id}`; reinclusão `POST .../contacts/{id}/restore`.
- CSV: `POST .../csv/inspect`, `.../csv/preview`, `.../imports/{uuid}/commit`.
- Webhooks: `POST .../webhooks`, `PUT .../webhooks/{uuid}`, `POST .../webhook-preview`.
- Recepção externa: `POST /hooks/lists/{uuid}`, autenticada por token, sem sessão/CSRF. Os endpoints administrativos preservam autenticação e CSRF.
- Alterações de configuração usam revisão para recusar sobrescritas de outra sessão. Importação e recebimento serializam a resolução de identidades e preservam exclusões.

## Validação e publicação

179 testes / 1.402 asserções aprovados em SQLite e PostgreSQL isolados, requisições externas impedidas/simuladas e filas falsas nos testes de ingestão. Build Vite aprovado. Testes de navegador usam dados reais apenas para leitura e interceptam mutações para testar cadastro, campos, CSV e webhook sem gerar contatos ou tokens em produção.

Validação HTTP pelo domínio público com `curl`: token inválido devolveu `401`, conforme esperado, sem exigir sessão ou CSRF. A configuração existente da Cloudflare bloqueou o User-Agent padrão de Python urllib com erro 1010; remetentes Python precisam identificar sua aplicação no User-Agent ou ter sua regra revisada pelo administrador da Cloudflare. Nenhuma regra de infraestrutura foi alterada nesta entrega.

Backup de código e banco antes da migração aditiva. Sem chamadas, mensagens, alterações de campanhas ou reinícios de serviços. Evidências em `evidence/list-manager-20261004/`.

Correção do seletor de público em 04/10/2026: 187 testes / 1.457 asserções aprovados em SQLite e PostgreSQL isolados. Testes incluem vínculo MA, IDs coincidentes entre tipos, sincronização na reserva, exclusões, autorização, telefone alterado, bloqueio de WhatsApp e isolamento de workspace. Evidências em `evidence/audience-link-20261004/`.

Atualização de 05/10/2026: painel lateral e seleção de contatos existentes validados com **289 testes / 2.343 asserções** em SQLite e PostgreSQL isolados. A conferência de navegador cobre cadastro, seleção, CSV, campos do payload, exemplo gerado, validação válida/inválida e geração de URL/token com respostas simuladas. Sem importações, criação de webhooks ou contatos, chamadas ou mensagens em produção durante os testes. Evidências privadas em `evidence/list-intake-20261005/`.

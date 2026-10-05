# Consultas para integração futura com o CRM — marco 6A

Incremento de 05/10/2026. A API ganha leitura de chamadas receptivas, conversas/mensagens WhatsApp e custos por canal. O CRM existente não é alterado. Nenhuma chamada, mensagem, nova credencial ou habilitação de fila é necessária para validar esta entrega.

## Acesso e permissões

O administrador cria credenciais em **Integrações → Nova integração**. O **Guia de integração** explica os recursos com exemplos de backend; o contrato em `/integrations/openapi.json` passa a versão 1.1.0 e mantém os endpoints anteriores.

| Recurso | Escopo | Consulta |
| --- | --- | --- |
| Receptivo | `calls:read` | `GET /api/v1/calls/inbound?from=2026-10-01&to=2026-10-05` |
| Distribuição/transferências | `calls:read` | `GET /api/v1/calls/inbound/{id}` |
| Conversas | **`conversations:read`** | `GET /api/v1/conversations?status=open&page=1` |
| Mensagens e respostas | **`conversations:read`** | `GET /api/v1/conversations/{id}/messages?page=1` |
| Custos locais | **`costs:read`** | `GET /api/v1/reports/costs?from=2026-10-01&to=2026-10-05` |

Os dois escopos novos são explícitos e não são adicionados a credenciais existentes. `reports:read` continua destinado às jornadas e não concede acesso às novas consultas de conversas ou custos. `calls:read` agora inclui também as chamadas receptivas. Esses escopos de leitura abrangem todo o workspace, independentemente das listas permitidas; listas selecionadas limitam apenas operações de listas. O ambiente continua restrito ao workspace 1, sem anunciar isolamento SaaS multi-organização pronto.

Tokens `mai_` ficam exclusivamente no servidor integrador. O navegador que incorpora o discador recebe apenas a sessão curta delegada. A revogação impede novas consultas. Limite de 120 requisições/minuto por credencial, sujeito ao limite global por IP. Os dados de atendimento são privados e as respostas autenticadas usam `Cache-Control: no-store, private`.

## Contratos de consulta

**Receptivo:** período inclusivo, até 90 dias, usando datas de America/Sao_Paulo. Filtros opcionais por `queue_id` e `user_id`, validados no workspace. Até 50 chamadas por página em `rows`; detalhe com `call` e `offers`. Cada chamada é contada uma vez, mesmo com transferência. `outcome` distingue `answered`, `active`, `unavailable` e `abandoned`. Não expõe SID, identidade do dispositivo, credenciais ou notas privadas.

**Conversas:** até 50 por página em `data`; filtros opcionais `status`, `sender_id` e `assigned_user_id`. Mensagens retornam `conversation` e `messages`, também com até 50 por página. Incluem texto, direção, estado, campanha e campos `button_id`, `button_label`, `reply_to_message_id` e `reply_attribution`. Botão ausente permanece nulo; resposta em texto não é transformada em clique. Consultar não marca leitura, não distribui atendimento e não envia mensagem.

**Custos:** usa o ledger já armazenado; não acessa Twilio. Totais em `known` e `channels`, sem misturar moedas; `rows` contém 25 registros por página. `channel` filtra apenas o detalhe, preservando totais de todos os canais do período. Não informado não é zero, QR não é classificado como gratuito e custo parcial não representa a fatura. Não há endpoint público nesta entrega para sincronizar custos, comprar números ou alterar credenciais.

Os horários dos registros são strings UTC `YYYY-MM-DD HH:MM:SS`, com nulo para valores desconhecidos. As listas são o estado atual, sem snapshot entre páginas. Empates têm ordenação por ID. Novos eventos podem mover registros entre páginas: o integrador deduplica pelos IDs e reconcilia periodicamente. Eventos assinados e a consulta com cursor ajudam a acompanhar mudanças, mas não representam cada alteração de cada entidade.

## Validação

Testes isolados cobrem escopos existentes, novos escopos, revogação, acesso entre workspaces, filtros estrangeiros, telefone/estado de mensagem, botão nulo e identificado, paginação de 51 mensagens, limites de período, fronteira de fuso, transferência sem contagem duplicada, moedas/custos desconhecidos e ausência de chamadas ao provedor. O contrato verifica que cada rota nova exige o escopo documentado e que as referências de esquema existem.

Homologação de áudio incorporado e uso efetivo pelo CRM continuam pendentes. O guia de consulta não habilita automaticamente uma integração, atendente ou campanha.

Validação concluída: **264 testes e 2.095 asserções em cada banco isolado (SQLite e PostgreSQL)**; build aprovado e interface/guia conferidos em desktop e celular, com mutações bloqueadas.

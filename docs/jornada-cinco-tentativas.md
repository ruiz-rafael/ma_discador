# Zyrex — jornada de cinco tentativas

Navegação atual: **Cadências → abra a cadência → cartões do fluxo**. Voz e automação compartilham o catálogo. Números, WhatsApp por QR/API e templates ficam em **Cadências → Canais**; filas e atendentes ficam em **Filas e atendimento**. Relatórios de voz/WhatsApp ficam em **Relatórios**; resultados da automação também ficam na aba Relatórios do próprio editor.

Entrega de 04/10/2026, no MA. Nenhuma alteração no CRM ou nos demais projetos da VM.

## Lista única de jornadas

Todas as jornadas aparecem na mesma tabela, com nome, canais, status, público, contatos/entradas e atualização. A busca consulta os dois tipos e o contador de ativas/habilitadas inclui a voz. Não há destaque automático. Cada linha abre seu editor correspondente, preservando os motores de execução e dados atuais. Evidências desta unificação: `evidence/unified-journeys-20261004/` (12 testes existentes, build e navegação em navegador com escritas bloqueadas).

## Jornada criada

**Nome:** Zyrex | 5 tentativas → WhatsApp personalizado

**Link direto:** https://ma.zyrex.ia.br/#/voice/journey/4

**Estado:** pausada, lista vazia, fila progressiva pausada; nenhuma chamada ou mensagem iniciada.

A tela **Jornadas → Todas as jornadas → Zyrex | 5 tentativas → WhatsApp personalizado** abre o construtor em Vue Flow, a mesma biblioteca do editor MA. Clique em cada cartão para editar seus campos no painel lateral e use **Salvar etapa**. Os atalhos continuam disponíveis para importação, fila, relatórios e conexões.

- **Público:** escolher uma lista ou contatos individuais; referências de campanha/segmento para futura integração. Ao salvar uma lista, seus membros ativos substituem o público anterior. Exclusões e histórico não são apagados.
- **Ligação:** modo, máximo de tentativas, horário, dias, fuso e roteiro; o modo acompanha a fila pausada.
- **Resultado:** quantidade de não atendimentos necessária para o WhatsApp.
- **Intervalo:** espera efetiva após não atendimento; substitui a exceção desse resultado e mantém as demais.
- **WhatsApp:** mesmo número ou outro, conexão Twilio/QR, template, variáveis e prévia por pessoa.
- **Encerramento:** regra fixa que interrompe a abordagem quando o cliente atende.

Arraste os cartões, use zoom, minimapa e **Enquadrar jornada**. **Organizar** restaura a disposição inicial; **Salvar posições** persiste a organização no servidor. O salvamento visual tem revisão própria e não altera tentativas, regras nem mensagens pendentes. Alterações de regras exigem campanha pausada e supervisão, reutilizam as validações do discador e cancelam passos pendentes conforme o comportamento existente.

As seis etapas e suas conexões representam o percurso executado pelo discador, incluindo o retorno pelo intervalo. A disposição é livre; as conexões desta jornada são controladas pelas regras, sem permitir criar caminhos que o motor não executa. Novos tipos de etapa e conexões arbitrárias não estão incluídos nesta entrega.

Configuração inicial:

- Até cinco tentativas por contato, com intervalo inicial de 60 minutos. O padrão acompanha as alterações da campanha; intervalos diferentes podem ser definidos em **Editar regras por resultado**. O mapa exibe o intervalo efetivo de não atendimento, incluindo eventual exceção.
- Horário 09h–18h, todos os dias, no fuso America/Sao_Paulo; tudo editável.
- Uma chamada por vez; limites privados de homologação preservados.
- Cinco resultados confirmados como **Não atendeu** criam um único passo de WhatsApp, sem espera adicional, respeitando horário e demais verificações. Ocupado, cancelamento, erro técnico ou resultado desconhecido não contam como não atendimento. Todos os inícios reais contam para o limite de cinco tentativas; se houver esses outros resultados, a mensagem não é disparada automaticamente como se fossem cinco não atendimentos.
- Atendimento em qualquer tentativa encerra a abordagem e cancela o WhatsApp pendente. Uma mensagem já aceita pelo provedor não pode ser recolhida.
- Lista **Zyrex | Público da jornada 5 tentativas** e fila **Zyrex | Fila da jornada 5 tentativas** vinculadas à campanha. O público está vazio para seleção/importação pelo usuário, sem presumir autorização de clientes.
- Mesmo número de voz/WhatsApp como configuração inicial. O cadastro do remetente é local e está sem verificação; não equivale a um número conectado na Meta/Twilio. É possível escolher número separado e conexão QR nas configurações existentes.

O template local **zyrex_retorno_apos_5_tentativas**, em português, permanece em rascunho; não foi publicado nem enviado à aprovação. Antes do uso real, conectar o remetente e aprovar o template, ou escolher um WhatsApp QR conectado e a mensagem correspondente. Habilitar a campanha e iniciar a fila/atendente são ações explícitas posteriores.

## Mensagem e variáveis

Mensagem inicial:

> Olá, {nome}! Aqui é a equipe Zyrex. Tentamos falar com você por telefone. Qual é o melhor horário para conversarmos?

Na API Twilio, o texto usa `{{1}}` e o mapeamento da campanha preenche a variável `1` com `{nome}`. O exemplo “Ana Souza” serve à prévia e aos dados de exemplo do template; o envio usa o nome do destinatário real. No QR, o texto contém diretamente `{nome}`.

| Variável | Dado usado |
| --- | --- |
| `{nome}` | Nome completo do contato de voz |
| `{primeiro_nome}` | Primeiro nome extraído do nome cadastrado |
| `{telefone}` | Telefone normalizado do contato |
| `{campanha}` | Nome da campanha da execução |
| `{id_crm}` | Identificador do contato no CRM, quando preenchido |
| `{origem}` | Origem registrada no contato |

A edição oferece botões de inserção e prévia com dados de exemplo ou de um contato cadastrado. A substituição acontece no servidor para cada execução, tanto no QR quanto nas variáveis da API Twilio. Variáveis desconhecidas ou malformadas são rejeitadas ao salvar. Se um dado exigido estiver ausente, o passo fica bloqueado antes de chamar o provedor; não envia marcadores literais nem reaproveita os dados de outro cliente. Texto de dados do contato não é reinterpretado como outra variável. Não há integração ou sincronização nova com o CRM nesta entrega.

## Identidade visual

Paleta conferida no site https://zyrex.ia.br em 04/10/2026: grafite `#151616`, dourado `#c89b3c`, azul `#3e67c7` e fundo claro `#f5f4f1`. Símbolo SVG copiado do próprio site da marca. O tema abrange login, navegação, cartões de jornadas, formulários, tabelas e editor, com adaptação para dispositivos móveis. Nenhuma alteração foi feita no site institucional.

## Evidências

168 testes / 1.295 asserções aprovados em SQLite e PostgreSQL isolados, com requisições externas impedidas/simuladas. Build Vite aprovado. Testes de navegador validam leitura autenticada da jornada persistida, acesso por link direto, mapa, prévia de personalização e edição por requisição interceptada, sem alterações de teste em produção. Evidências do tema inicial: `evidence/design-journey-20261004/`; editor Vue Flow e salvamento por cartão: `evidence/canvas-editor-20261004/`. Os testes de navegador interceptam as alterações para validar formulários, arraste, conflito de revisão e prévia, sem aplicar mudanças de teste à campanha real.

Publicação com backup de código e banco, sem reiniciar containers. Contadores antes/depois: 10 chamadas históricas, zero chamadas ativas, zero mensagens. A homologação real continua reservada para a manhã, conforme solicitação do usuário.

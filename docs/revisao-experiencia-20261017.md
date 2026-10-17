# Revisão da experiência e dos formulários — 17/10/2026

## Escopo

Revisão dos fluxos existentes de administrador, supervisor e atendente no DiscAI, com foco em formulários, navegação, permissões, tratamento de falhas e apresentação em desktop/celular. O site institucional não faz parte deste repositório e não foi alterado. Telefonia, provedores, campanhas e credenciais não são ativados pela revisão.

## Padrão de configuração

`DialogShell.vue` concentra título, orientação, área de conteúdo rolável e ações fixas. Filas, equipe, contatos, cadências de voz/automação, vínculo de número receptivo, conexão de WhatsApp e template oficial usam o padrão. Filas mantêm os cinco assuntos separados: visão geral, números/horários, equipe, distribuição/permissões e gravação. Cadências separam fila/referências, público, horário/tentativas e conversa/continuidade.

O rascunho é preservado quando o servidor recusa a operação. Ao fechar um formulário alterado, a confirmação permite continuar editando ou descartar. Cliques fora do modal e Escape não descartam estes formulários. Durante o salvamento, campos e fechamento ficam indisponíveis; submeter novamente não cria uma segunda requisição. A confirmação de fechamento dos segmentos substitui o alerta nativo, incluindo o aviso sobre token de webhook exibido uma única vez.

`ui/dialog-layer.js` compartilha uma pilha entre os modais: isola o conteúdo de fundo, bloqueia rolagem, mantém Tab/Shift+Tab dentro do diálogo ativo e restaura foco/rolagem ao fechar. Inclui modais de supervisão, IA, segmentos, catálogo e headset. A discagem manual continua exigindo fechamento explícito; abrir um modal não muda a disponibilidade do agente.

`dialogs.css` padroniza campos, espaçamento, cartões, seletores, mensagens de erro e foco visível. O portal de administração central/cliente recebeu ajustes equivalentes em formulários, agrupamentos e telas pequenas.

## Correções funcionais

- Fechamento de formulários de criação/configuração sem confirmação perdia dados preenchidos.
- Modais com gerenciamento independente de foco/rolagem não coordenavam corretamente sobreposições.
- Formulários principais não apresentavam um retorno compreensível quando a rede falhava ou o servidor retornava conteúdo não JSON; o usuário agora recebe orientação para conferir os dados antes de repetir a ação.
- Ações de abertura durante carregamento devem refletir o estado indisponível, evitando cliques ignorados.
- Criação de jornada e abertura do editor usam uma única operação protegida, evitando submissão duplicada sem impedir a navegação após salvar.
- Mensagens de orientação de contatos/segmentos e equipe foram atualizadas para as telas atuais.

## Limites da verificação

Testes automatizados e revisão no navegador procuram regressões nos cenários cobertos; não demonstram ausência de todas as falhas possíveis. Áudio com vários atendentes, AMD, entrega de mensagens, cobrança e disponibilidade de operadoras continuam dependendo de homologação própria. Esta revisão não faz chamadas, não envia mensagens, não compra números e não altera os limites operacionais.

## Evidência de validação

- Suíte geral: 532 testes e 4.065 asserções aprovados.
- Regressão em PostgreSQL: 81 testes e 746 asserções aprovados.
- JavaScript: 11 testes aprovados; build de produção concluído.
- Navegador real em bases isoladas: cadastro de contato e atendente, criação de fila pausada, falha 503 com preservação do rascunho, confirmação aninhada, foco/rolagem, validação em aba oculta e abertura dos modais de cadência, WhatsApp, receptivo, segmentos, IA e discador.
- Verificação adicional: uma única requisição durante cliques repetidos em salvar, bloqueio dos campos/fechamento enquanto a requisição está pendente e abertura do editor após criar um rascunho de automação.
- Perfis: administração e atendente, além do portal central. Larguras de 1.440, 390 e 320 pixels, sem transbordamento horizontal dos diálogos verificados.

A validação do campo de origem da fila foi corrigida: a opção vazia agora impede salvar e abre o assunto “Números e horários” para mostrar o campo obrigatório.

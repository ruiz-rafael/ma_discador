# Revisão visual da DiscAI — 17/10/2026

A revisão reforça a identidade verde e dourada da aplicação e a hierarquia das telas de administrador, cliente e atendente. Cards usam bordas mais claras, sombras discretas e espaçamento consistente. Ações interativas têm estados de foco e seleção distintos; preferências de movimento reduzido são respeitadas.

## Entregas

- Visão geral com destaque inicial, indicadores e progresso calculado a partir dos três cadastros existentes. O indicador representa cadastro, sem afirmar homologação. Cards de filas levam à gestão de filas.
- Navegação móvel por menu com nomes completos, agrupamento por assunto, acesso à conta e troca de empresa. Reutiliza as permissões de cada perfil e a proteção existente de navegação com alterações pendentes.
- Atendimento com dois cards explicativos para status/filas e discagem manual. Abrir um card não altera disponibilidade nem inicia chamadas.
- Modais compartilhados com cabeçalho destacado, seções demarcadas, campos legíveis e rodapé fixo. Proteção de rascunhos, foco e fechamento explícito preservados.
- Acabamento unificado de filas, tabelas, conversas e estados vazios.
- Portal central com cards de empresas, indicadores separados, atalhos organizados, formulários e seções expansíveis mais claros.

## Validação

- PHPUnit: 538 testes, 4.129 assertions.
- JavaScript: 11 testes aprovados. Build Vite concluído; permanecem os avisos existentes de tamanho/importação de chunks.
- Chromium em 1440, 390 e 320 pixels: páginas principais, configuração de fila em cinco seções, contatos, equipe, cadência, conexão WhatsApp, laboratório de IA, discador, portal e navegação móvel.
- Em bases isoladas: salvamento de cadastros, erro de API sem perda do rascunho, confirmação de descarte, foco de teclado, perfil de agente sem menus administrativos e discador que permanece aberto ao usar Escape ou clicar fora.
- Nenhuma chamada, mensagem, contratação ou ativação de provedor faz parte desta revisão. A homologação de telefonia e áudio continua separada dos testes de interface.

Evidências internas: `evidence/visual-refinement-20261017/` (capturas e relatórios). Esta entrega não altera regras de distribuição, permissões ou configuração dos clientes.

## Publicação

Publicado no portal central, no workspace DiscAI e no tenant Polishop. Conferência autenticada após a publicação: visão geral, navegação móvel e modais de contatos, filas e WhatsApp, sem erros JavaScript e sem gravações operacionais. Arquivos conferidos contra a versão revisada. Contagens de chamadas e mensagens, filas, campanhas e configurações de IA permaneceram inalteradas. Backup da publicação: `visual-refinement-20261018T024448Z`.

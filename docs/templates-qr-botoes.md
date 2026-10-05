# Templates com botões por QR Code — experimental

Implementação de 04/10/2026, autorizada como recurso experimental, sem ativação nas campanhas atuais. O CRM permanece fora deste trabalho.

## Usar no MA

1. Abra **Cadências → Canais → WhatsApp → Templates com botões**.
2. Clique em **Novo template QR**, dê um nome e escreva a mensagem. Estão disponíveis `{nome}`, `{primeiro_nome}`, `{telefone}`, `{campanha}`, `{id_crm}` e `{origem}`. O dado exigido precisa existir; não são aceitas expressões ou variáveis desconhecidas.
3. Configure de um a três botões: **Enviar resposta** ou **Abrir link HTTPS**. Os títulos têm até 20 caracteres; identificadores são únicos no modelo. Títulos e URLs são fixos; as variáveis ficam no texto.
4. Salve. **Duplicar e editar** cria uma nova versão; modelos já vinculados não mudam. Nenhum modelo é ativado automaticamente.
5. Para usar na cadência pausada, abra o cartão **Enviar WhatsApp**, escolha um remetente QR e selecione **Modelo da mensagem QR**. Marque **Usar botões experimentais nesta cadência** e salve a etapa. Sem modelo selecionado, o envio continua como texto.
6. O formulário **Teste de mensagem por QR Code** permite escolher modelo, contato e, opcionalmente, campanha para personalização pelo público atual do MA. **Conferir prévia personalizada** não envia mensagem. O envio real exige confirmação experimental e autorização do contato.

O modelo de exemplo **Zyrex | Retorno com botões (QR experimental)** fica disponível no catálogo após a publicação, sem vínculo com campanhas. Ele oferece “Podemos falar agora”, “Combinar horário” e “Não quero contato”.

## Respostas e histórico

Botões de resposta registram o identificador, rótulo e referência à mensagem original quando o protocolo fornece esse contexto. O vínculo só é confirmado se remetente, destinatário e botão coincidirem com o envio registrado. Mensagens recebidas são deduplicadas; qualquer resposta interrompe a abordagem como já ocorria com texto e mídia.

O identificador `SAIR` registra supressão do contato, mesmo que o rótulo seja “Não quero contato”. Também são reconhecidos `STOP`, `PARAR` e `CANCELAR`. O recebimento é conciliado pelo agendador do MA; a interrupção não é uma confirmação de leitura do supervisor.

Abrir um link não gera automaticamente uma resposta, não encerra a cadência e não produz relatório de clique. Não foram implementados agendamento automático de retorno, ramificações por botão nem uma caixa de atendimento nesta entrega. Esses fluxos precisam de regras próprias nos próximos marcos.

O histórico conserva o texto personalizado, a versão do modelo, os botões enviados e a resposta. Um estado “enviada” não comprova exibição de botões. Falha, timeout ou resultado desconhecido não dispara uma segunda mensagem de texto; a mesma chave de envio retorna a tentativa já reservada.

## Compatibilidade

O conector permanece em Baileys **7.0.0-rc14**, sem troca por fork ou instalação de um pacote de botões. Um adaptador pequeno usa os campos `interactiveMessage/nativeFlowMessage` e a operação `relayMessage`, com os nós de protocolo necessários para resposta e URL. O caminho de texto anterior permanece separado.

Isso não equivale aos templates aprovados da WhatsApp Business Platform e não transfere aprovações da Twilio. A compatibilidade visual depende do cliente e da conta WhatsApp. Precisam ser testados em Android/iOS: exibição, resposta, opção SAIR, link e conciliação da entrega. Nenhuma homologação visual em aparelho foi alegada nesta entrega.

Referências: [tipos da versão instalada](https://github.com/WhiskeySockets/Baileys/blob/v7.0.0-rc14/src/Types/Message.ts), [protocolo](https://github.com/WhiskeySockets/Baileys/blob/v7.0.0-rc14/WAProto/WAProto.proto) e [referência de nós interativos](https://github.com/zqdevelopers/zq_baileys_helper). O adaptador externo foi consultado como referência de protocolo; não foi instalado.

## Validação

- 203 testes / 1.572 asserções em SQLite e PostgreSQL isolados, com provedores simulados.
- 9 testes do conector: armazenamento, validação, mensagem codificada pela versão instalada, resposta nativa/legada e ausência de fallback após timeout e retomada de sessão QR a partir da identidade persistida.
- Navegador com mutações interceptadas: criação, duplicação, prévia, confirmação experimental, seleção na etapa e tela móvel.
- A publicação exige backup, migração aditiva e recriação apenas do conector QR exclusivo do MA para carregar o código. Preserva volume de sessão, credenciais, limites, campanhas, filas e histórico. Nenhuma chamada ou mensagem é autorizada pela publicação.

As configurações por QR foram mantidas em texto nas campanhas existentes. Evidências em `evidence/qr-buttons-20261004/`.

A sessão QR reconectou com as chaves existentes. A atualização revelou e corrigiu a retomada de pareamentos cujo campo `registered` continua falso apesar de existir identidade salva; não foi necessário ler outro QR. A conferência final confirmou campanhas/filas inalteradas, 19 chamadas históricas e uma mensagem enviada, sem novos disparos.


## Ajuste do selo de IA — 05/10/2026

O teste de dois contatos de homologação confirmou os três botões no Android e duas respostas pelo botão `agora`, vinculadas à mensagem original no dashboard. O print também mostrou um selo de IA e um aviso da Meta. O conector enviava um nó adicional `bot` com `biz_bot=1`; foi removido dos próximos envios, preservando o envelope `biz/interactive/native_flow`, texto, IDs e leitura das respostas.

A implementação instalada não solicitava explicitamente IA ao gerar a mensagem. A relação do marcador com o selo é sustentada por [esta referência de implementação](https://github.com/onxlmao/Baileys-Premod#-ai-message-icon-sparkle-badge); a [referência original de botões](https://github.com/zqdevelopers/zq_baileys_helper) também usa esse marcador para compatibilidade em conversas privadas. Por isso, o comportamento visual dos botões sem o marcador ainda exige conferência em aparelho; o teste automatizado confirma o protocolo e a ausência da marcação, não a renderização pelo WhatsApp.

Imagem do conector: `zyrex-ma-whatsapp-qr:buttons-no-ai-20261005`. Os nove testes do conector passaram. Nenhuma mensagem ou chamada foi disparada por esta correção. Mensagens já entregues e avisos já mostrados no histórico não são editados pelo ajuste. Evidências: `evidence/qr-no-ai-20261005/`.

# WhatsApp após X não atendimentos — API e QR Code

Entrega de 03/10/2026 no MA: configuração por campanha, contagem de chamadas reais e envio por Twilio ou por dispositivo WhatsApp conectado por QR Code. Complementa o marco 4B; a fila de discagem do marco 3 continua simulada. O CRM não foi alterado.

## Configurar o remetente

Em **Voz e cadências → WhatsApp → Cadastrar número**:

1. Informar nome, número completo com `+55` e origem do número.
2. Escolher **API oficial Twilio** ou **QR Code · WhatsApp Web**.
3. Salvar o número.

Para Twilio, vincular o Sender SID `XE…`, verificar o remetente e criar/publicar um template. O envio verifica novamente se o remetente está ONLINE e o template aprovado. As credenciais privadas já configuradas para a conta foram aproveitadas, sem expor tokens no navegador; conectar a conta não cadastra automaticamente um número no WhatsApp. A Twilio exige templates aprovados para iniciar conversas fora da janela de atendimento: [documentação oficial](https://www.twilio.com/docs/whatsapp/tutorial/send-whatsapp-notification-messages-templates).

Para QR Code, clicar **Conectar / gerar QR Code**. No celular, abrir **WhatsApp → Aparelhos conectados → Conectar um aparelho** e ler o código. O número do celular precisa corresponder ao número cadastrado. O painel permite consultar a conexão e desconectar. A sessão permanece no serviço exclusivo do MA, criptografada em disco; pode exigir nova leitura quando o WhatsApp encerrar o vínculo.

O QR Code usa um conector de WhatsApp Web baseado em Baileys, independente da Twilio e sem vínculo oficial com o WhatsApp. Não representa a API oficial nem herda a aprovação de templates da Twilio. Ver [projeto do conector](https://github.com/WhiskeySockets/Baileys). O ambiente permanece restrito aos contatos autorizados de homologação, com limite diário de 10 tentativas para QR. A configuração Twilio mantém sua própria lista e limite.

## Configurar a campanha

Em **Voz e cadências → Campanhas → Nova campanha de voz**, ou **Configurar** em uma campanha pausada:

1. Definir o número de voz. Manter **Usar um único número para voz e WhatsApp** ou desmarcar e informar outro número de WhatsApp.
2. Selecionar **Remetente WhatsApp cadastrado**. O sistema mostra se usa Twilio ou QR Code.
3. Definir contatos, dias, horários, intervalo entre ligações e máximo de tentativas.
4. Marcar **Enviar mensagem quando o cliente não atender** e escolher **Enviar após chamadas reais da campanha**.
5. Definir **Quantidade de não atendimentos (X)**, de 1 a 20, sem exceder o máximo de tentativas, e **Espera antes da mensagem**, de 0 a 1.440 minutos.
6. Para QR, escrever o texto; para Twilio, selecionar o template e preencher suas variáveis. Ambos aceitam substituições de `{nome}` e `{telefone}` nos campos apropriados.
7. Salvar e clicar **Habilitar campanha**.

Exemplo: máximo de quatro tentativas, X = 3 e espera de dez minutos. Após o terceiro resultado confirmado como “Não atendeu”, uma mensagem fica programada, usando o remetente escolhido. Se o horário permitido terminar nesse intervalo, aguarda a próxima janela.

Na aba **Chamadas**, vincular cada ligação à campanha. Uma ligação avulsa não aciona a mensagem. O usuário ainda inicia as ligações manualmente; esta entrega automatiza o passo de WhatsApp, não a discagem das filas.

## Regras entregues

- Contagem por contato e campanha, somente para chamadas reais iniciadas e encerradas com `no_answer`, nos métodos API e SIP. Não há soma de chamadas simuladas, falhas, ocupado, cancelamento ou chamadas avulsas.
- Apenas chamadas da configuração atual entram no limiar. Testes antigos não são reaproveitados. Pausar e retomar preserva o contador; editar configurações cria uma nova revisão de regras, preserva o limite total de chamadas e cancela passos ainda pendentes.
- Um único passo por contato/campanha. O contato deixa de ser elegível a novas chamadas nessa campanha após a criação do passo. Para outra abordagem, criar outra campanha, respeitando consentimento e exclusões existentes.
- Atendimento por voz, resposta no WhatsApp, interrupção, retirada da campanha ou alteração de telefone impedem o envio. Pausar a campanha segura o passo; encerrar cancela o que está pendente.
- O número único é comparado também com a origem efetiva da chamada. Informar um número no formulário não o conecta automaticamente ao WhatsApp.
- Antes de enviar, revalidar contato, horário, revisão, participação, remetente e política de homologação. Número QR conectado diferente do cadastrado não autoriza envio.
- Reservar uma tentativa antes de chamar o provedor. Timeout ou resultado desconhecido não causa novo envio automático. O conector QR também mantém reserva durável para não duplicar mensagens após reinício.
- O processamento ocorre a cada minuto. Espera de zero significa envio no próximo processamento elegível, não garantia de envio no mesmo segundo.

## Acompanhar e testar

Em **Cadência e histórico → WhatsApp após chamadas reais**, consultar campanha, contato, previsão, estado e motivo. Um passo impedido antes de qualquer tentativa permite **Reavaliar após corrigir**. Havendo tentativa de envio, especialmente sem confirmação, essa ação não repete a mensagem.

Na aba **WhatsApp**, o histórico mostra mensagens e estados de entrega. Existem formulários separados para teste manual com template Twilio e para teste por QR Code. Aceitação/envio não equivale a entrega ou leitura. Respostas e pedidos de saída interrompem a abordagem do contato.

Nenhuma campanha existente foi habilitada automaticamente nesta publicação. Nenhum celular foi vinculado e nenhuma mensagem ou ligação real foi enviada durante a validação da entrega. Para homologar entrega no telefone, o usuário precisa conectar seu remetente e usar um destinatário autorizado.

## Implementação e validação

- Migração aditiva `2026_10_03_000001_create_voice_followups.php`: provedor nos remetentes/mensagens, revisão de regras e tabela de passos reais `voice_followups`, independente de `voice_actions` simuladas.
- `VoiceFollowups` recebe os resultados terminais confirmados da API e do PBX e executa os passos elegíveis. A rotina `voice:followups:run` também concilia recebimentos e estados do QR.
- Serviço `whatsapp-qr` exclusivo do projeto Docker Compose `zyrex-ma`, sem porta publicada, com autenticação interna, volume privado, limite de memória/CPU e configuração fora do código.
- Baileys `7.0.0-rc14` e dependências fixadas no lockfile. A sessão é criptografada com AES-256-GCM; segredos e QR não são registrados em logs nem retornados nos catálogos gerais.
- 132 testes / 1.033 assertions aprovados em SQLite e PostgreSQL; testes do armazenamento criptografado e identificação do número; build Vue aprovado; geração real de QR em instância isolada, sem pareamento.
- Verificação autenticada no navegador aprovada, com cenários de configuração QR/Twilio, número único/separado e visualização móvel. Os cenários de interface usam dados controlados, sem criar campanhas ou enviar mensagens em produção. Comunicação do MA com o conector e agenda de execução verificadas no ambiente publicado.
- Publicação com backup de código e banco. Nenhum container existente foi reiniciado; somente o novo conector foi iniciado. Sem alteração de PBX, CRM ou serviços alheios ao MA.

Evidências em `evidence/cadence-qr-20261003/` e, na VM, `/srv/zyrex-ma/evidence/cadence-qr-20261003/`. A homologação de sessão pareada e entrega ao celular permanece dependente do número que o usuário conectar.

## Organização da configuração — 05/10/2026

Em **Cadências → Canais → WhatsApp**, a configuração passou a ter quatro áreas:

- **Números conectados:** cartões com status e painel do número selecionado; um número conectado é selecionado inicialmente. O cadastro orienta a escolha entre QR Code e API oficial antes de pedir os dados. O QR tem instruções para o celular, consulta de conexão e confirmação explícita para desconectar. A Twilio mostra as etapas de credenciais, vínculo e verificação; webhook e detalhes técnicos ficam recolhidos.
- **Templates:** modelos QR e oficiais separados por canal. Os oficiais têm campos para exemplos das variáveis e prévia, sem exigir edição de JSON. Publicação, aprovação e conciliação continuam disponíveis. Os modelos QR mantêm variáveis, botões experimentais e criação de nova versão.
- **Teste de envio:** escolha de canal, indicação dos pré-requisitos, autorização e acompanhamento da tentativa. Mantém os mesmos endpoints e chaves de idempotência. Trocar entre as quatro áreas não apaga os formulários em andamento.
- **Histórico:** busca por texto ou telefone e filtros por número e direção, com status e identificação das respostas de botão. Horários exibidos no fuso do navegador.

A seleção do remetente de cada cadência continua no cartão **Enviar WhatsApp**. A reorganização não muda vínculos, sessões, políticas de envio ou regras da jornada. Foram publicados somente componentes Vue e assets compilados, com backup anterior e sem reinício de serviços.

Validação: 27 testes existentes de WhatsApp / 129 asserções aprovados em ambiente isolado; compilação e fluxos no navegador com operações simuladas, incluindo cadastro, conexão QR, vínculo Twilio, variáveis, publicação/aprovação, contratos dos envios, filtros, estados vazios e telas móveis. Evidências em `evidence/whatsapp-ux-20261005/`. Não foram enviados testes reais nesta atualização.

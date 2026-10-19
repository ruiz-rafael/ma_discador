# Preparar o teste do discador com WhatsApp e e-mail

A continuidade de WhatsApp já existia. Esta entrega acrescenta um passo durável de e-mail após os não atendimentos e uma conferência local da operação em **Cadências → abrir cadência de voz → Conferir teste e mensagens**.

## Preparação

1. Confira o público autorizado, a fila, o número de origem, os horários e as tentativas. Para teste curto, configure explicitamente uma tentativa e o limite de WhatsApp compatível. O teste automático exige atendente disponível, fila e cadência habilitadas; discagem manual avulsa não inicia mensagens de cadência.
2. No cartão **Enviar WhatsApp**, escolha o remetente e o envio automático. API oficial exige template aprovado; QR depende de sessão conectada e das regras de seu conector. A conferência mostra registros locais e não comprova conexão atual, áudio ou entrega ao celular.
3. Na aba **E-mail**, defina assunto, mensagem em texto simples e espera. A etapa é independente do sucesso do WhatsApp e utiliza o mesmo limite de não atendimentos quando WhatsApp está habilitado; sem WhatsApp, utiliza o máximo de tentativas da cadência. Caixa postal obedece à política AMD existente. Ocupado, falha e resultados inconclusivos não são classificados como não atendimento.
4. O e-mail do destinatário vem do campo `email` do contato de voz. Importações e cadastros de segmentos já aceitam esse campo. O contato precisa de autorização registrada e participação válida.
5. O serviço SMTP é configurado pela infraestrutura em cada ambiente de cliente. Parâmetros `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` e, quando necessário, `MAIL_EHLO_DOMAIN`. A etapa utiliza explicitamente SMTP e timeout de 15 segundos, nunca o mailer `log`. A ativação exige `DISCAI_EMAIL_ENABLED=true`, `DISCAI_EMAIL_FROM`, `DISCAI_EMAIL_REPLY_TO` e `DISCAI_EMAIL_DAILY_LIMIT` positivo (padrão 20). `DISCAI_EMAIL_NAME` é opcional. Configurar o nome/host de um provedor não autentica o domínio: o administrador deve validar remetente e DNS no serviço contratado.

Nenhum serviço é contratado nem credencial é descoberta automaticamente. Nesta entrega o envio permanece desativado na infraestrutura e nas cadências existentes. A configuração de e-mail só considera tentativas posteriores ao salvamento; não envia mensagens por chamadas históricas.

## Execução e proteção

- Um passo por contato/cadência/participação; callbacks repetidos não duplicam mensagens.
- Processamento por minuto, com espera, horário da cadência, pausa geral e limite diário compartilhado de e-mail por workspace. Agentes online são pré-requisito para iniciar chamadas automáticas; a continuidade de uma chamada já concluída não exige manter o agente online.
- Antes da submissão: revalida participação, revisão da configuração, consentimento, descadastro, segmento, resposta WhatsApp e atendimento humano. Alteração de e-mail impede um passo já preparado. Encerramento de participação cancela passos pendentes.
- Reserva durável antes do SMTP. Timeout, desconexão ou interrupção após submissão geram resultado não confirmado, sem reenvio automático. Reavaliar só é permitido quando nenhuma submissão aconteceu.
- Personalização validada com os campos existentes (`{nome}`, `{primeiro_nome}`, `{campanha}` etc.); assunto sem quebras de linha. Sem HTML arbitrário.
- Link de descadastro assinado, com confirmação explícita para evitar cancelamento por scanners. Bloqueia aquele endereço nos e-mails de cadência da empresa; a leitura do link não muda preferências.
- Configuração e histórico são exclusivos do administrador do tenant. Acesso de outra empresa não encontra a cadência. Segredos SMTP não entram no payload de consulta nem no histórico de falhas.

## O que o histórico comprova

Estados: aguardando, impedido antes do envio, em submissão, aceito pelo SMTP, não confirmado e cancelado. Até 50 registros recentes, com totais por estado de toda a cadência.

**Aceito pelo SMTP não comprova entrega, leitura, clique ou resposta.** Esses eventos dependem da integração específica do provedor de e-mail, ainda pendente. As respostas chegam ao endereço `Reply-To` e não encerram automaticamente a cadência nesta versão. O motivo genérico de término `no_response` representa expiração da espera observada pela plataforma; não comprova ausência de resposta na caixa de e-mail externa. WhatsApp mantém seus relatórios de entrega, leitura, respostas e botões.

## Contrato administrativo

- `GET /api/voice/campaigns/{id}/impact`: diagnóstico local, configuração, estado do SMTP e histórico. Sem consulta externa ou alteração operacional; `Cache-Control: no-store, private`.
- `PUT /api/voice/campaigns/{id}/impact`: `enabled`, `revision`, `delay_minutes`, `subject`, `body`. Exige cadência pausada/rascunho e revisão atual. Retorna o mesmo contrato da consulta. Erros 403/404/409/422 preservam o formulário.
- `POST /api/voice/campaigns/{id}/impact/email/{message}/retry`: somente passo impedido antes de submissão; revalida condições e volta à espera.
- `GET/POST /email/preferences/{id}`: URL assinada de descadastro, GET confirma visualmente e POST aplica com proteção CSRF.

## Validação

Testes locais e PostgreSQL de limiar, idempotência, autorização, pausa, limite, reentrada, cancelamento, descadastro e falha ambígua. O cliente SMTP real foi exercitado contra `tests/Fixtures/cadence-smtp.php`, servidor restrito a loopback, com aceitação e desconexão após DATA. A simulação usa protocolo SMTP; os mocks HTTP do Postman não substituem esse transporte. Não utiliza serviço público, credenciais ou destinatários reais. O teste prova a semântica do transporte e não entregabilidade ou DNS do futuro provedor.

Publicação verificada no workspace DiscAI e na Polishop, sem gravações operacionais. Suíte geral: 550 testes aprovados antes dos dois reforços finais; suíte focada final de e-mail: 13 testes; PostgreSQL: 66 testes de e-mail, reentrada, WhatsApp e distribuição; JavaScript: 11 testes. Navegador em 1440, 390 e 320 pixels: leitura do diagnóstico, proteção de rascunho, erro de salvamento, persistência e histórico vazio. Testes finais reforçam a mudança de telefone e o corte estritamente posterior à configuração, inclusive quando registros antigos compartilham o segundo do salvamento.

Na conferência de produção: as duas cadências DiscAI referenciam remetente WhatsApp registrado ONLINE; as filas de saída estão pausadas e sem agentes disponíveis. Nenhum contato dessas cadências possui e-mail válido cadastrado. SMTP e configuração de e-mail de ambas continuam desativados. Polishop ainda não possui cadências. Estas são observações do momento da publicação, não condições fixas do produto.

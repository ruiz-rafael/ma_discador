# Twilio no MA: dois métodos de chamadas

Os dois métodos estão implementados. A homologação de cada ambiente depende das credenciais, das rotas e de testes autorizados; consulte o [roteiro vigente](roteiro-proximos-marcos.md).

A aba **Voz e cadências → Chamadas** oferece dois métodos selecionáveis para cada chamada manual:

| Método | Caminho do áudio | Configuração necessária |
| --- | --- | --- |
| Programmable Voice · API | Navegador → Twilio → telefone | Account SID, API Key/Secret, Auth Token, TwiML App e origem autorizada |
| Elastic SIP Trunking · Asterisk | Navegador → Asterisk do MA → Twilio → telefone | Account SID, Trunk SID, Termination URI, credenciais SIP, origem e instalação da rota |

A seleção inicial da tela é API, mas só o método escolhido pode ser usado. Não há fallback automático nem repetição de uma ligação pelo outro método. Ambos continuam disponíveis para configuração independente. O histórico registra o método de cada tentativa. O CRM não participa desta entrega.

## Configurar Programmable Voice

No console Twilio:

1. Confirme conta, saldo, permissões geográficas e um número de origem habilitado e autorizado para a rota desejada. Números de teste e destinos precisam respeitar as limitações da conta. A elegibilidade para WhatsApp não confirma elegibilidade para voz.
2. Crie uma **API Key Standard na região US1**. Guarde SID `SK…` e Secret. A região de controle usada pela integração é US1; o edge de mídia do navegador é configurável e não muda a região da chave.
3. Crie uma **TwiML App exclusiva para o MA** e guarde seu SID `AP…`. Configure **Voice Request URL**, método **POST**:

   `https://ma.zyrex.ia.br/callbacks/twilio/voice/dial`

4. Não é preciso cadastrar manualmente URLs por contato: o MA inclui as URLs de progresso e encerramento no TwiML de cada ligação. Não apontar a TwiML App para uma URL genérica que aceite um telefone arbitrário do navegador.
5. Cadastre os dados no terminal privado da VM:

```sh
cd /srv/zyrex-ma
docker compose exec --user 82:82 app php artisan voice:twilio-api:configure
docker compose exec --user 82:82 app php artisan voice:calling:configure --method=programmable_voice
```

O primeiro comando solicita Account SID `AC…`, API Key/Secret, Auth Token atual, TwiML Application SID, origem E.164 e edge. Os segredos usam entrada oculta, não devem ser colocados em argumentos do shell, repositório ou chat. Ele salva a conexão desabilitada em `storage/app/private/voice/calling/programmable.enc`, cifrada com APP_KEY, arquivo 0600/diretório 0700.

O segundo comando define lista de destinos, limites e confirmação da elegibilidade da origem, e habilita homologação manual. Não realiza ligação nem altera o PBX. Habilitado para teste indica configuração local; autenticação real, áudio, origem e retorno da Twilio ainda dependem do teste com a conta.

Para bloquear novas chamadas por API, mantendo os callbacks das que já começaram:

```sh
docker compose exec --user 82:82 app php artisan voice:calling:configure --method=programmable_voice --disable
```

## Configurar Elastic SIP Trunking

A preparação anterior continua disponível:

```sh
cd /srv/zyrex-ma
docker compose exec --user 82:82 app php artisan voice:twilio:configure
docker compose exec --user 82:82 app php artisan voice:calling:configure --method=sip_trunk
python3 ops/install_calling_pbx.py
# Depois da validação isolada e da conferência de ausência de canais ativos:
python3 ops/install_calling_pbx.py --apply
# Na primeira inclusão do transporte TLS, com PBX do MA sem chamadas:
# python3 ops/install_calling_pbx.py --apply --initialize-tls
```

Consulte [o roteiro do PBX](chamadas-twilio.md). Credenciais SIP são diferentes da API Key e do Auth Token. A instalação controlada da rota só pertence ao método SIP. Usar API não depende do serviço de áudio do PBX e não exige instalar esse bundle.

## Limites compartilhados

A política de homologação é única: destinos permitidos, limite diário, duração e evidência de autorização. Os dois métodos compartilham uma chamada externa por vez, até uma nova tentativa por segundo e a exclusão com o eco interno. Esses controles de teste não representam a capacidade contratada da Twilio ou de produção.

Alterar a política invalida a instalação SIP anterior. Ao configurar ambos pela primeira vez, cadastre as duas conexões, defina/habilite a política API e depois gere/instale o bundle SIP com os mesmos limites desejados. Se mudar os limites posteriormente, regenere/instale o bundle SIP. Credenciais API e política não podem ser substituídas enquanto houver reserva/chamada com capacidade pendente; desabilitar novas chamadas é permitido.

Campanhas mantêm a flag de número único/separado para WhatsApp. Quando uma chamada é vinculada a campanha, o contato deve pertencer a ela e o número de voz da campanha deve coincidir com a origem do método escolhido.

## Autorização e confirmação na API

- Reserva autenticada por usuário/workspace, idempotente, válida por 45 segundos. Trocar método altera a identidade da requisição e não reutiliza outra tentativa.
- JWT de voz válido por cinco minutos, identidade específica da reserva, restrito à TwiML App e sem permissão de chamadas recebidas. O JWT não dispensa a reserva de 45 segundos. API Secret e Auth Token nunca são enviados ao navegador.
- O navegador transmite apenas identificadores opacos. O servidor decide origem/destino e revalida consentimento, bloqueio, lista permitida e configuração antes de emitir `<Dial>`.
- Um único `<Dial>` por reserva. Retry do webhook de início retorna `<Hangup>` e não produz outra tentativa; em uma falha ambígua, prioriza-se não duplicar chamadas.
- Webhooks POST assinados com o Auth Token, URL canônica fixa, Account SID e correlação entre chamada do navegador e chamada do destinatário. Parâmetros de formulário são preservados sem trim/conversão antes de validar a assinatura.
- Duração limitada pelo `timeLimit` da Twilio; timeout de toque pode ter o buffer de aproximadamente cinco segundos documentado pelo fornecedor. Não há gravação.
- Progresso/encerramento confirmado pelo servidor, sem regredir por eventos atrasados. O SDK fornece o áudio, mas não declara faturamento ou encerramento no banco.
- Sem confirmação final, a reserva fica `unknown` e mantém a capacidade ocupada. O botão **Consultar na Twilio** consulta apenas as chamadas daquela reserva, sem criar ligação. Resposta indisponível, lista vazia ou dados incoerentes não liberam capacidade. Casos sem evidência recuperável exigem conferência operacional; não usar a liberação manual de PBX para uma chamada API.

## Escopo e validação

Esta etapa prepara chamadas manuais de saída. Recebimento, distribuição de chamadas reais entre atendentes, discagem automática/progressiva, gravações, limites monetários e operação multiempresa ainda não foram implementados. Filas de contatos em simulação estão no [marco 3](marco-3-filas.md). As chamadas reais são tarifadas conforme conta, produto e rota. O cadastro local não verifica saldo nem garante disponibilidade de números.

A entrega não usa credenciais reais Twilio nem realiza chamadas. A validação automatizada cobre reservas, JWT, TwiML, assinatura/correlação, consentimento, limites, isolamento entre métodos, callbacks duplicados/atrasados e reconciliação. Evidências de execução ficam em `evidence/voice-methods-20261001/`.

Referências técnicas: [Voice SDK JavaScript](https://www.twilio.com/docs/voice/sdks/javascript), [Access Tokens](https://www.twilio.com/docs/iam/access-tokens), [Dial](https://www.twilio.com/docs/voice/twiml/dial), [Number e callbacks](https://www.twilio.com/docs/voice/twiml/number), [diretrizes brasileiras de voz](https://www.twilio.com/en-us/guidelines/br/voice).

## Evidências da publicação

- 99 testes / 744 assertions, aprovados em SQLite e em um PostgreSQL de teste separado do banco do MA.
- SDK `@twilio/voice-sdk` fixado em 2.18.5 no lockfile; build Vite aprovado. Instalação npm reportou zero vulnerabilidades na árvore resolvida.
- Chromium autenticado: seleção API/SIP, estados sem credenciais, HTTP 503 para reservas sem configuração, acesso anônimo HTTP 401, telas desktop/celular e ausência de erros JavaScript.
- Adaptador do SDK exercitado com fixture somente no navegador: conectar, exibir áudio/pacotes, encerrar, limpar após erro. Essa simulação não valida mídia ou autenticação na infraestrutura real da Twilio.
- Diagnóstico de áudio interno disponível e WhatsApp preservado. Zero registros de chamadas externas após os testes.
- Publicação com backup `20261002T021124Z`, migração aditiva e limpeza do cache de rotas. Nenhum container mudou ID/horário de início durante a comparação; hashes dos arquivos do PBX preservados. Nenhuma credencial real Twilio foi cadastrada ou usada.

Para reverter a aplicação, restaure os arquivos e o manifesto do backup `backups/voice-methods-code-before-20261002T021124Z.tar.gz`; remova as classes/rotas exclusivas desta entrega se necessário e limpe o cache de rotas. Preserve os campos aditivos e os registros do banco. A entrega não requer reversão de configuração do PBX.

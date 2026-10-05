# Twilio no módulo de voz do MA

Decisão de 01/10/2026: usar **Twilio Elastic SIP Trunking** como provedor de telefonia do MA. O projeto continua em `/srv/zyrex-ma`, no domínio `ma.zyrex.ia.br`. O projeto de CRM permanece fora desta implantação.

## Entregue nesta preparação

- Aba **Voz e cadências → Conexão Twilio** com estado da preparação, etapas de cadastro e capacidades planejadas.
- Endpoint autenticado `GET /api/voice/provider/twilio`, restrito ao workspace original do MA, com resposta `no-store`. Não devolve credenciais, SIDs ou número de origem.
- Comando interativo para cadastrar dados e gerar uma configuração PJSIP ainda inativa. A senha SIP é solicitada sem eco, não como argumento de shell.
- Dados cifrados com a chave Laravel em `source/storage/app/private/voice/twilio/connection.enc`. Diretório 0700 e arquivo 0600; preservar APP_KEY e backups com acesso restrito.
- Arquivo `pjsip-staged.conf` privado, 0600, contendo credenciais necessárias ao Asterisk. **Não está incluído no PBX ativo**. Sua geração não prova conectividade nem faz chamadas.
- Teste interno WebRTC preservado. Campanhas continuam simuladas. Não foi contratada conta, comprado número, criado tronco na Twilio ou habilitado recebimento externo.

## Dados que faltam

1. Conta Twilio com acesso ao Elastic SIP Trunking. A página atual de limites informa que o serviço exige upgrade do trial; confirmar a disponibilidade na conta concreta antes de adicionar saldo.
2. Trunk SID (`TK…`) e Account SID (`AC…`). Esses identificadores não substituem a senha SIP.
3. Termination SIP URI base, como `nome.pstn.twilio.com`.
4. Usuário e senha de uma **Credential List SIP exclusiva do MA**, associada ao tronco. Não é o Auth Token da conta Twilio.
5. Número de origem elegível e confirmação da rota Brasil; destinatários de homologação autorizados.
6. Limites efetivos da conta: chamadas simultâneas e novas chamadas por segundo (CPS).

No console Twilio, criar o tronco em Elastic SIP Trunking e associar a Credential List em Termination. Uma IP ACL pode restringir a origem pública do MA (`217.216.65.237`); quando ACL e credenciais estão configuradas, ambas são exigidas. O Elastic SIP Trunking não usa SIP REGISTER.

Para cadastrar os dados no MA, executar em terminal privado da VM:

```sh
cd /srv/zyrex-ma
docker compose exec --user 82:82 app php artisan voice:twilio:configure
```

O comando valida host Twilio, números E.164, SIDs e caracteres permitidos; não imprime a senha. Para evitar interpretação em arquivos Asterisk, a senha aceita 12–128 caracteres entre letras, números e `!@$%^&*()_+-={}:.?/`, sem espaços, ponto e vírgula, aspas, barras invertidas ou quebras de linha. Criar a Credential List com uma senha forte compatível. Não enviar senhas ou Auth Token no chat.

O cadastro pode ser repetido para substituir os dados. A aba passará para **Dados cadastrados · homologação pendente**, nunca para conectado apenas porque os campos foram preenchidos. O comando não altera `.env`, PJSIP ativo, portas ou containers.

## Número brasileiro e WhatsApp

A documentação geral aceita números Twilio ou caller IDs verificados, mas a diretriz específica do Brasil restringe a origem com números brasileiros não pertencentes à Twilio e proíbe origem por número brasileiro toll-free. Ela também não garante preservação da identificação em todas as rotas. Por isso, a validação genérica de um celular próprio não basta para este projeto.

Solicitar à Twilio confirmação do número que poderá ser usado na saída, no retorno e no WhatsApp oficial. A decisão posterior do usuário permite número único ou números separados por campanha; ver [WhatsApp no MA](whatsapp-twilio.md). Um número externo no WhatsApp não comprova elegibilidade como origem de voz. A disponibilidade e documentação exigida para um número brasileiro precisam ser verificadas na conta. A escolha deste provedor não habilita mensagens nem chamadas dentro do WhatsApp.

A página comercial de diretrizes do Brasil ainda cita regras de 0303 de 2022. Não tratá-la como parecer atualizado sobre toda a regulamentação: confirmar a política comercial aplicável com o fornecedor e as exigências vigentes para a operação concreta.

## Capacidade e implantação seguinte

Simultaneidade é quantidade de chamadas em andamento; CPS é quantidade de novas chamadas iniciadas por segundo. A Twilio documenta 1 CPS inicial e ampliação sujeita ao perfil da conta e operadoras. Contas novas sem Business Profile aprovado podem ter limites adicionais. Não há comprovação de capacidade ilimitada nesta VM.

Os campos `planned_concurrency` e `planned_cps` são **planejamento**, não cotas aplicadas nem capacidade contratada. O eco atual mantém duas chamadas; fila distribuída, orçamento, controle real de CPS e múltiplos PBXs ainda não estão implementados. A conexão foi separada do laboratório para permitir essa evolução sem mudar de provedor ou confundir os limites.

Após receber os dados válidos:

1. Conferir acesso, destino autorizado, tarifas, limite de gasto e elegibilidade do número. Não comprar ou aumentar CPS automaticamente.
2. Validar o arquivo PJSIP em ambiente isolado com Asterisk. A preparação usa TLS, validação de certificado e SRTP/SDES; o navegador continua usando DTLS-SRTP. Conferir o bundle CA e as rotas de mídia.
3. Escolher o edge conforme latência **da VM**, não apenas pela localização do destinatário; testar redundância por DNS antes de operação contínua.
4. Instalar e homologar a [rota de saída preparada](chamadas-twilio.md), com autorização por tentativa, destinos restritos, limites de duração/tentativas, reserva de capacidade e CPS. O teto monetário depende de uma etapa adicional. O endpoint compartilhado de eco não deve receber permissão de discagem externa.
5. Conferir na rota real a correlação implementada de atendimento, ocupado, não atendimento, falha e encerramento com eventos do PBX. Tratar encerramento incerto e eventos repetidos.
6. Homologar uma chamada com áudio bidirecional e identificação correta. Preparar recebimento separadamente: Origination URI, autenticação/ACL do provedor, portas exclusivas e roteamento para atendente do MA.
7. Conectar campanhas preview/progressivo somente após essa homologação. Não há mudanças previstas no projeto CRM.

## Referências verificadas

- [Elastic SIP Trunking — configuração, autenticação e transporte](https://www.twilio.com/docs/sip-trunking).
- [Limites e disponibilidade no trial](https://www.twilio.com/docs/sip-trunking/scale-and-limits).
- [CPS e condições de ampliação](https://www.twilio.com/docs/sip-trunking/cps-trunk-termination).
- [Diretrizes de voz da Twilio para o Brasil](https://www.twilio.com/en-us/guidelines/br/voice).

## Validação da preparação

- 52 testes / 459 assertions em suíte isolada, sem acesso à rede: autenticação, escopo do workspace, cifragem, ausência de credenciais na API, configuração corrompida, rejeição de injeção em PJSIP e cadastro interativo.
- Chromium autenticado no domínio público: aba Twilio em desktop/móvel, estado pendente, resposta sem credenciais, acesso anônimo bloqueado e diagnóstico do áudio existente disponível. Sem erros JavaScript.
- Build Vite concluído. O comando também foi localizado no runtime de produção executando com o usuário 82:82.
- Implantação sem migrações, reinício de containers ou alterações no PBX: IDs/horários dos containers e hashes dos arquivos do PBX foram comparados antes e depois.
- Evidências em `evidence/twilio-20261001/`. Não há teste de chamada pela Twilio, pois os dados da conta e do tronco ainda não foram fornecidos.

## Reversão

Esta entrega não possui migração de banco nem ativa configuração de telefonia. O backup `backups/twilio-code-before-*.tar.gz` preserva os arquivos anteriores e o build. Restaurar somente esses arquivos se necessário, mantendo assets de versões anteriores para abas abertas. Os novos arquivos PHP/Vue podem permanecer sem uso após restaurar as rotas e o manifest. Eventuais credenciais privadas devem ser retiradas separadamente pelo responsável; a reversão da interface não revoga uma Credential List na Twilio.

## Atualização: chamadas manuais de saída

A aba **Voz e cadências → Chamadas** e a rota autorizada de saída estão preparadas para homologação. Incluem contato autorizado, limites, histórico e eventos do PBX. Faltam credenciais reais, instalação do bundle no PBX e homologação com a operadora. Recebimento e cadências automáticas permanecem pendentes. Consulte [chamadas Twilio](chamadas-twilio.md).

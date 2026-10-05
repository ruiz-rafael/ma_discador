# WhatsApp no MA: números flexíveis e API Twilio

Atualização de 03/10/2026: entregue a [configuração de WhatsApp após X não atendimentos reais, por API Twilio ou QR Code](cadencia-whatsapp-api-qr.md). Esta entrega antecipa parte do marco 4B; filas de discagem reais, inbox completo e integração com o CRM continuam pendentes. A nova opção QR complementa a escolha anterior de API oficial.

Decisão de 01/10/2026: cada campanha escolhe `number_mode=single|separate`. Essa escolha atualiza a diretriz original do PDF de usar sempre o mesmo número; o documento original foi preservado. Toda a implementação pertence ao MA, sem alterações no projeto CRM.

## Uso na interface

Em **Voz e cadências → Campanhas → Configurar**, a opção **Usar um único número para voz e WhatsApp** vem marcada, inclusive para campanhas antigas. Nesse modo, o número WhatsApp deriva do número de voz. Desmarcando, surge um campo independente. O remetente vinculado precisa pertencer ao mesmo workspace e corresponder ao número escolhido. A opção pode ser preparada antes de contratar os serviços; não comprova conexão ou disponibilidade do número nos dois canais.

Em **Voz e cadências → WhatsApp**:

1. Cadastre nome, número em E.164 e origem: outra operadora ou Twilio. Esse cadastro é local e começa como não verificado.
2. Cadastre/verifique o número na Twilio e informe o Sender SID `XE…`. **Verificar na Twilio** consulta o remetente real e exige correspondência exata do número.
3. Crie um rascunho de template de texto, com idioma, categoria e exemplos das variáveis. Use `{{1}}`, `{{2}}` no texto e `{"1":"Ana","2":"123"}` nos exemplos. Sem variáveis, use `{}`.
4. **Publicar na Twilio** cria o Content SID `HX…`. **Solicitar aprovação** submete o conteúdo para análise; **Consultar aprovação** atualiza o resultado. Aprovação é decidida pela Meta, não pelo MA. Conteúdo publicado não equivale a aprovado.
5. Com remetente ONLINE, template aprovado e contato autorizado na lista de homologação, faça um envio manual. O sistema consulta novamente o provedor antes do envio. O histórico distingue fila, envio, entrega e leitura, além de falha ou resultado incerto.

Templates desta etapa são de **texto**, nas categorias Marketing e Utilidade, em pt_BR/en/es. Mídia, botões, autenticação/OTP, edição e importação de templates existentes não fazem parte desta entrega. Um conteúdo alterado deve ser criado como novo rascunho, com outro nome.

Campanhas e cadências continuam simuladas. A API Twilio implementada serve aos testes manuais deste módulo; os nós legados de jornadas MA continuam dependentes do gateway de integração. Publicar um template aqui não cria automaticamente uma referência de template no CRM ou no catálogo legado. A execução automática das jornadas/cadências será conectada depois da homologação real, com regras de janela, orçamento e reconciliação por execução.

## Número de outra operadora

A Twilio será o provedor de API WhatsApp para esse número: cadastro do remetente, Content API, solicitação de aprovação, envio e callbacks. Não é uma ponte genérica para uma API WhatsApp já operada por outro fornecedor. Um número externo pode permanecer com a sua operadora de voz; será necessário comprovar controle do número e concluir o cadastro da conta WhatsApp Business/Meta.

Se já existir WhatsApp nesse número, validar com a Twilio o procedimento aplicável de migração/coexistência antes de mudar o serviço. Não fazemos portabilidade de voz, exclusão de conta WhatsApp ou migração automática. A elegibilidade como caller ID de voz na Twilio deve ser confirmada separadamente, especialmente para números brasileiros externos. Números separados permitem homologar os canais de forma independente.

## Conectar a conta

Na VM, em terminal privado:

```sh
cd /srv/zyrex-ma
docker compose exec --user 82:82 app php artisan voice:whatsapp:configure
```

Informar Account SID `AC…`, API Key SID `SK…` e secret dessa chave, Auth Token **da mesma conta** para validar webhooks, destinos autorizados em E.164 e limite diário (1–100, padrão 20 tentativas). A chave precisa de acesso a Senders, Content e Messages na conta que possui o remetente. Credenciais de API são distintas da Credential List SIP. Não colocar segredos no navegador, chat, argumentos de shell ou documentação.

O comando grava dados cifrados com APP_KEY em `storage/app/private/voice/whatsapp/connection.enc`, diretório 0700 e arquivo 0600. Executar como usuário 82:82 permite leitura pelo PHP. Preservar APP_KEY e arquivo cifrado em backup restrito. O comando não registra números nem envia mensagens. A tela não expõe os segredos. Acesso de gestão é restrito ao workspace original (1), como na preparação SIP; permissões por papel e multiempresa completa continuam fora desta homologação.

No remetente Twilio, configurar **Incoming message webhook**, método POST:

```
https://ma.zyrex.ia.br/callbacks/twilio/whatsapp/inbound
```

O callback de status é enviado em cada requisição de mensagem, contendo o UUID local da tentativa. Ambos validam assinatura com o SDK oficial Twilio e o Auth Token, URL pública canônica, parâmetros integrais e Account SID. Não aceitam query string arbitrária. Corpo de formulário é preservado sem trim/conversão de valores vazios para manter a assinatura correta. Não há resposta automática paga: o recebimento retorna `<Response/>`.

## API e garantias da homologação

Rotas autenticadas, com CSRF e limite de frequência, sob `/api/voice/whatsapp`:

| Operação | Método e caminho |
|---|---|
| Painel | GET `/` |
| Cadastro local do número | POST `/senders` |
| Vincular Sender SID | PUT `/senders/{id}` |
| Consultar remetente | POST `/senders/{id}/sync` |
| Criar rascunho | POST `/templates` |
| Publicar conteúdo | POST `/templates/{uuid}/publish` |
| Solicitar/consultar aprovação | POST `/templates/{uuid}/approval`, `submit: true/false` |
| Conciliar publicação incerta | POST `/templates/{uuid}/reconcile`, `content_sid` |
| Enviar teste | POST `/messages` |
| Consultar mensagem já correlacionada | POST `/messages/{uuid}/reconcile`, `message_sid` |

Envio exige `sender_id`, `contact_id`, `template_id`, `variables`, `idempotency_key` UUID, `consent_confirmed=true` e `consent_evidence`. `campaign_id` é opcional; quando presente, exige contato membro da campanha, WhatsApp habilitado e remetente correspondente à configuração de números. Não executa a cadência nem valida horário como um disparo automático; trata-se de ação manual de homologação.

A reserva de envio é confirmada no banco antes do POST à Twilio. Reutilizar a mesma chave e os mesmos dados devolve a tentativa anterior; trocar dados com a mesma chave é rejeitado. Não há retry automático de POST. Timeout, queda ou resposta ambígua mantém a tentativa `unknown`/`sending`, que também consome o limite diário UTC. Consultar o histórico antes de criar outra tentativa. Aceitação HTTP não confirma entrega.

Callbacks são deduplicados e não regridem estado de entregue/lida quando chegam fora de ordem. Um callback pode confirmar a tentativa antes da resposta do POST. Uma consulta de mensagem sem SID já associado só é aceita quando houver correlação exata pela URL de callback; não se associa uma mensagem apenas por número ou horário. Se a API não devolver essa correlação, aguardar o callback assinado e investigar no console, sem repetir o envio.

Publicação incerta também não pode ser repetida automaticamente. Para conciliar, localizar o HX no console e informar na interface; o sistema confere conta, nome, idioma, texto e variáveis. Se a solicitação de aprovação ficar incerta, consultar seu estado antes de qualquer nova ação.

Recebimento identifica o contato pelo número dentro do workspace do remetente. Resposta registra `replied_at`; STOP/SAIR/PARAR/CANCELAR ou `OptOutType=STOP` registra exclusão. Ambos cancelam passos pendentes do contato no laboratório. Não há alteração do CRM, reativação automática por START, chatbot ou caixa de atendimento multiagente nesta etapa.

## Homologação que depende da conta

Sem credenciais e remetente real não se comprova cadastro, aprovação, tarifas, entrega ou retorno de mensagens. Após configurar a conta: verificar um remetente, publicar um template, solicitar e aguardar aprovação, registrar um contato com autorização/allowlist e enviar um teste escolhido pelo responsável. Conferir recebimento no aparelho, status assinado, resposta e opt-out. Manter os limites de teste até concluir essa etapa.

## Referências oficiais consultadas

- [Cadastro WhatsApp na Twilio](https://www.twilio.com/docs/whatsapp/self-sign-up).
- [Senders API — v2](https://www.twilio.com/docs/whatsapp/api/senders): esta implementação usa v2, exigida para novos cadastros desde setembro de 2026.
- [Content API e aprovação](https://www.twilio.com/docs/content/content-api-resources).
- [Messages API e callbacks](https://www.twilio.com/docs/messaging/api/message-resource).
- [Validação de requisições Twilio](https://www.twilio.com/docs/usage/security).

## Validação e implantação

- 71 testes / 539 assertions em SQLite e novamente em PostgreSQL isolado. Cobrem escopo, números, publicação/aprovação, autorização, limites, idempotência, timeout, assinatura, eventos fora de ordem, retorno antecipado e opt-out. Chamadas HTTP do provedor usam respostas simuladas nesses testes.
- Build Vue/Vite concluído. SDK oficial `twilio/sdk` 8.12.2 adicionado para validação de assinatura. A auditoria da instalação identificou duas correções pendentes no `league/commonmark` já existente; atualização pontual 2.10.1 → 2.10.3, sem outras atualizações de pacotes. Composer terminou sem avisos de vulnerabilidade conhecidos.
- Quatro tabelas aditivas (`wa_senders`, `wa_templates`, `wa_messages`, `wa_events`). Implantação restrita a `/srv/zyrex-ma`, sem alteração de PBX, Nginx, portas, projeto CRM ou reinício solicitado de containers.
- Evidências: `evidence/whatsapp-20261001/`. Backups privados: `backups/whatsapp-code-before-*.tar.gz` e `backups/whatsapp-db-before-*.dump` na VM.

Para reverter a interface/API, restaurar os arquivos e autoload do backup de código correspondente, limpar apenas o cache de rotas do MA e manter os assets antigos disponíveis. As novas tabelas podem permanecer sem uso: não removê-las ou restaurar o banco indiscriminadamente se já houver dados novos. A reversão local não exclui conteúdos publicados no provedor nem revoga credenciais.

A conferência Chromium no domínio público passou em desktop e celular: remetente e template em rascunho, botões externos bloqueados sem credenciais, persistência dos dois modos de número, acesso anônimo bloqueado e diagnóstico do áudio existente disponível. Sem erros JavaScript. Os registros fictícios criados pela conferência foram removidos, com auditoria arquivada. A comparação anterior/posterior à publicação mostrou os mesmos IDs e horários de início de todos os containers e os mesmos hashes de configuração do PBX.

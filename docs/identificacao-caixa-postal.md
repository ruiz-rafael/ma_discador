# Identificação de atendimento e recado — 12/10/2026

A cadência pode distinguir o retorno técnico de atendimento da classificação pessoa, máquina, fax ou inconclusivo. A configuração começa **desativada**. Nenhuma campanha existente é habilitada ou reconfigurada pela publicação.

## Configurar

1. Pause a cadência e abra o cartão **Ligar para o cliente**.
2. Selecione uma fila **Twilio API** e abra **Identificação e caixa postal**.
3. Escolha apenas identificar ou identificar e deixar recado após o sinal.
4. Para recado, gere e ouça um áudio no **Estúdio de voz**, com Kokoro ou Chatterbox. Selecione um áudio pronto de até 60 segundos e confirme que o texto pode ser igual para todos os contatos.
5. Defina se caixa postal também conta para o limite de não atendimentos do WhatsApp. Salve a etapa.

O áudio selecionado é fixo, previamente gerado e privado. Esta etapa não gera um áudio por contato durante a chamada. Não selecione uma prévia contendo nome/dados de uma pessoa específica para uma campanha com vários destinatários. Arquivos expiram conforme a retenção do estúdio; é preciso substituí-los antes disso. A seleção exige mais de 15 minutos de validade. Não são gerados áudios automaticamente no momento de atender.

## Continuidade

| Resultado | Com identificação ativada |
| --- | --- |
| Pessoa identificada ou confirmada na tabulação | Encerra participação e cancela WhatsApp pendente |
| Caixa postal | Consome uma tentativa; pode contar para o limite WhatsApp se a opção estiver marcada |
| Fax | Consome uma tentativa, sem contar como pessoa ou não atendimento para WhatsApp |
| Pendente/inconclusivo em chamada conectada | Bloqueia continuação automática; conferir a tabulação |
| Sem atendimento | Mantém a regra de tentativas/WhatsApp existente |

Sem identificação, permanece a regra anterior: atendimento técnico encerra a abordagem. O histórico não é reclassificado. Sair da participação não exclui o contato do segmento. Uma participação com identificação inconclusiva continua ativa e não abre uma reentrada duplicada.

A confirmação manual de pessoa ou caixa postal prevalece sobre callbacks tardios. Correções ficam na tabulação; uma participação já encerrada não é reaberta automaticamente por uma correção posterior.

## Funcionamento e limites

A classificação é AMD da Twilio em `<Dial><Number>`, assíncrona. O agente pode ouvir a saudação antes de chegar o resultado; não é triagem garantida antes de conectar. Pode haver erros e cobrança específica da operadora. A síntese é própria do MA; a identificação desta implementação é específica da Twilio API. SIP/Asterisk ainda não tem o adaptador de detecção/recado.

O recado só é solicitado após `machine_end_beep`. Silêncio, fim de saudação sem sinal, fax e resultado inconclusivo não autorizam reprodução. A solicitação atualiza exclusivamente o CallSid filho já existente; não cria outra ligação. Consentimento, telefone, participação, pausa, vínculo e revisão da campanha são conferidos novamente antes da reprodução.

Callbacks exigem assinatura, conta e vínculo com o CallSid filho comprovado. Eventos antecipados aguardam confirmação desse vínculo; duplicados não repetem o recado. Uma solicitação HTTP sem confirmação não é repetida automaticamente. O encerramento do trecho do navegador não libera a capacidade enquanto o trecho do destinatário ainda não terminou; a conciliação continua disponível.

**Recado reproduzido** significa que a Twilio solicitou a continuação após executar `<Play>`. Não comprova que a caixa postal armazenou a mensagem ou que alguém a ouviu. Encerramento sem essa confirmação é registrado como inconclusivo.

## Relatórios

No dashboard da jornada, **Conectadas pela operadora** mantém o total técnico. **Pessoas e caixa postal** oferece indicadores clicáveis de pessoas identificadas, caixa postal, identificação pendente/inconclusiva, chamadas sem identificação e recados reproduzidos. O detalhe mostra classificação, retorno bruto e situação do recado. Pessoas podem ser identificadas automaticamente ou confirmadas por tabulação; o indicador antigo de contatos humanos tabulados permanece específico da tabulação.

## Homologação pendente

A validação automatizada usa callbacks e HTTP simulados, bancos descartáveis e navegador com escritas interceptadas. Não substitui testes com pessoas, caixas postais e operadora. A identificação, a reprodução após o sinal, a liberação de capacidade após redirecionamento e a acurácia por operadora precisam de homologação real autorizada. A detecção continua desativada nas campanhas atuais.

Referências técnicas: [Number e AMD](https://www.twilio.com/docs/voice/twiml/number), [Answering Machine Detection](https://www.twilio.com/docs/voice/answering-machine-detection), [exemplo de redirecionamento assíncrono](https://www.twilio.com/en-us/blog/async-answering-machine-detection-tutorial).

## Verificação da entrega

Regressão: 421 testes / 3.243 asserções em SQLite e PostgreSQL, 11 testes JavaScript e compilação Vite. Navegador desktop e celular verificou seleção de áudio, confirmação de recado genérico, salvamento sem alteração pendente e drill down das classificações, com escritas interceptadas. Publicação aditiva com backup: nenhum contêiner reiniciado, nenhuma alteração em campanhas/filas/agentes e contadores preservados de 25 chamadas e 3 mensagens enviadas. Não houve chamada ou envio real nesta entrega.

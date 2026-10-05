# Marcos e roteiro de homologação

Atualizado em 05/10/2026. Este é o roteiro vigente; documentos de testes anteriores preservam o estado da época. O projeto permanece no MA e não altera o CRM nem os outros sistemas da VM.

## Situação das entregas

| Marco | Implementado | O que falta comprovar ou concluir |
| --- | --- | --- |
| **1–3 — Base e canais** | Cadências visuais, listas, configuração de Twilio API/SIP e laboratório | Homologar conversação real nos métodos utilizados; configuração não equivale a áudio validado |
| **4A–4C — Operação** | Tabulação, relatórios por jornada, importação, tentativas, saída após atendimento/resposta, filas preview/progressivas e origens autorizadas por DDD | Validar sequência contínua após correções; números brasileiros habilitados para a regra de mesmo DDD |
| **4D — Botões e homologação** | Templates personalizados, botões QR experimentais, registro de respostas e opção escolhida. O usuário confirmou os três botões no celular | Conferir novamente após retirada do selo de IA; validar outros aparelhos e continuidade completa. Nenhum novo envio nesta rodada |
| **4E — Equipe** | Tela de atendentes, perfis, filas, disponibilidade, desativação e acesso restrito | Exercício com atendentes reais, seus navegadores e headsets |
| **5 — Receptivo** | Entrada Twilio API, distribuição, capacidade compartilhada, transferência e tabulação. Incremento: relatório por período/fila/atendente, gráfico diário e detalhe das ofertas | Homologar áudio recebido e transferência com pessoas em horário adequado. Recepção SIP permanece fora da implementação atual |
| **6A — Integrações** | API v1 documentada, escopos, idempotência, eventos assinados e discador incorporável; cliente de referência | Homologar áudio incorporado no domínio consumidor; integração efetiva ao CRM será posterior. Operação atual restrita ao workspace 1 |
| **6B — Conversas** | Caixa WhatsApp, responsáveis, distribuição e recebimento de leads pela API. Incremento: triagem com responsável, estado, notas, controle de versão e evento `lead.reviewed` | Integração direta de formulários Meta depende de aplicação/permissões; qualificação não autoriza contato nem inicia cadência |
| **6C — Operação e escala** | Supervisão, limites, métricas de áudio interno e teste de disputa por capacidade. Incremento: custos por canal, histórico e recuperação protegida de eventos, retenção configurável com arquivamento/restauração | Homologar operação e mídia com pessoas antes de elevar concorrência; conferir custos reais com a fatura e definir se a política de arquivamento deve ser habilitada |

Detalhes da base publicada: [marcos 4E–6C](marcos-4e-6c.md), [dashboard por jornada](dashboard-jornadas.md), [operação e supervisão](operacao-supervisao.md), [listas e webhooks](listas-contatos-webhooks.md).

## Incremento sem chamadas externas

O relatório receptivo conta uma chamada por registro, mesmo quando transferida. Distingue atendidas (inclusive em andamento), aguardando atendimento, abandonadas e encerradas sem disponibilidade. O detalhe registra as ofertas aos atendentes e a tabulação; a agregação por responsável usa o responsável final. Filtros usam America/Sao_Paulo, até 90 dias. Recusas da operadora/MA antes da admissão não constam nesses totais. Médias sem amostra ficam indisponíveis.

A triagem fica em **Conversas → Leads de integrações**. Supervisor e administrador podem atribuir responsáveis, qualificar, descartar com motivo e reabrir revisão. Atualizações concorrentes exigem recarregar. A revisão preserva consentimento, supressão e histórico; não envia mensagens, não telefona e não inclui automaticamente o lead em listas. O evento `lead.reviewed` é versionado e pode ser consultado/recebido pelos consumidores autorizados.

O diagnóstico fica em **Cadências → Testes → Áudio** e na **Minha operação** fora do discador incorporado. **Saúde e limites** reúne as últimas medições da equipe. Sessões de diagnóstico e chamadas externas não podem disputar os recursos ao mesmo tempo; o diagnóstico recusa início durante atendimento/reserva/tabulação. Ele não habilita agentes ou filas.

**Saúde e limites** inclui custos por canal, histórico de entregas e retenção reversível. Os prazos são configuráveis; o arquivamento automático começa desativado. A restauração preserva estados e não reenvia eventos. Chamadas, mensagens, custos, auditorias e consentimentos não são arquivados. Detalhes: [custos, recuperação e retenção](custos-recuperacao-retencao.md).

## Qualidade de voz — alcance da verificação

Foi executado eco interno real do navegador automatizado até o Asterisk do MA, com tom sintético de 660 Hz, sem destino telefônico externo. Na medição inicial de 25 segundos: **0% de perda, jitter p95 de 3 ms e RTT p95 de 137 ms**. Na conferência após publicação, foram 26 segundos, **0% de perda, jitter p95 de 5 ms e RTT ICE p95 de 146 ms** (RTCP p95 de 141,45 ms exibido no painel), com diagnóstico persistido no painel. O retorno do tom foi detectado em aproximadamente 656 Hz. O codec negociado foi PCMU/G.711, 8 kHz mono. Isso comprova mídia no percurso medido; não comprova microfone/headset do atendente nem qualidade Twilio/PSTN até o cliente. Não é uma medição perceptual MOS.

O painel guarda somente estatísticas, sem áudio, SDP, endereços IP ou credenciais. Métricas ausentes continuam desconhecidas. As referências do produto para atenção são jitter acima de 30 ms, RTT acima de 400 ms e perda acima de 1%, com pelo menos 10 segundos e cinco amostras para classificação. Valores informados pelo navegador são diagnósticos, não comprovação para cobrança.

Referências técnicas: [semântica das estatísticas WebRTC](https://www.w3.org/TR/webrtc-stats/) e [eventos de qualidade do SDK Twilio](https://www.twilio.com/docs/voice/voice-insights/api/call/details-sdk-call-quality-events). Referências de SDK não constituem garantia de qualidade do trecho da operadora.

## Próxima homologação com pessoas

1. Atendente conecta seu headset e executa eco interno, ouvindo clareza, volume e retorno.
2. Em horário combinado, chamada externa autorizada para validar os dois sentidos, identificação e encerramento, separadamente por método API/SIP utilizado.
3. Chamada receptiva e transferência entre dois agentes; conferir disponibilidade, abandono e tabulação sem duplicidade.
4. Cadência controlada: atendimento interrompe jornada; não atendimento chega ao limite configurado e produz uma mensagem; resposta/botão interrompe abordagem futura. Conferir dashboard e opção escolhida.
5. Validar discador incorporado em domínio de homologação, com permissões de microfone e eventos idempotentes, antes da integração ao CRM.
6. Medir carga de mídia e consumo antes de aumentar a concorrência, que permanece em 1 na política externa atual.

Nenhuma chamada externa ou mensagem é necessária para validar permissões, relatórios, revisão de leads e conflitos de versão. A autorização desta rodada é para configuração e testes internos; o roteiro acima não inicia contato com pessoas.

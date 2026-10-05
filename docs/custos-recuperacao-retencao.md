# Custos, recuperação de eventos e retenção técnica

Incremento do marco 6C, de 05/10/2026. Acesso em **Saúde e limites → Custos, recuperação e retenção**. Administradores podem configurar e executar os controles; supervisores consultam. Atendentes não têm acesso. O escopo atual é o workspace 1.

## Custos por canal

O painel separa voz de saída, voz receptiva, WhatsApp API e WhatsApp QR. O período usa America/Sao_Paulo, com até 90 dias por consulta. Os totais abrangem todos os canais do período; o filtro de canal restringe o detalhamento. O resumo diário superior continua em UTC e agora agrega os valores conhecidos dos canais.

**Consultar custo** consulta recursos já identificados na Twilio, por GET, e não origina chamadas nem envia mensagens. Para voz, o encerramento precisa estar confirmado. Receptivo inclui o recebimento e as pernas de atendentes conhecidas pelo MA, inclusive transferências. WhatsApp API consulta a mensagem pelo identificador confirmado. Credenciais, conta, números e relação entre as pernas são conferidos antes de persistir. Uma consulta posterior sem preço não apaga o último preço conhecido.

Valores ficam separados por moeda, sem conversão cambial. Há distinção entre recursos consultados, custos parciais e não informados. Zero só aparece quando informado pelo provedor. QR não fornece custo por mensagem e não é apresentado como gratuito. Mensalidades, impostos, infraestrutura, recursos não correlacionados e serviços adicionais não informados pela API estão fora do total. O painel não substitui a fatura; o alerta de custo não é bloqueio de cobrança.

A Twilio informa `price` e `price_unit` nos recursos; o preço de voz pode aparecer depois do encerramento e não cobre todos os serviços adicionais. Referências: [Call Resource](https://www.twilio.com/docs/voice/api/call-resource) e [Message Resource](https://www.twilio.com/docs/messaging/api/message-resource).

## Recuperação de eventos

A aba lista entregas às integrações e seu histórico: horário, tentativa, resultado HTTP e categoria de falha, sem armazenar corpos de resposta ou segredos. Históricos anteriores à atualização não são reconstruídos.

Cada ciclo tem até seis execuções, com espera crescente. A execução reserva a entrega por 60 segundos; uma resposta de execução antiga não pode substituir o resultado da atual. Uma sexta execução abandonada encerra o ciclo sem produzir uma sétima. Integrações desativadas ou sem permissão para eventos não são reprocessadas.

**Reagendar** exige confirmação e a revisão atual do registro. Preserva o evento e todas as execuções anteriores, zera o contador do novo ciclo e deixa a entrega para o agendador. Não repete a chamada ou a mensagem que originou o evento. O consumidor deve validar a assinatura e deduplicar por `X-MA-Event-Id`: uma falha de rede pode ocorrer depois de o destino receber o evento, portanto a entrega não é garantia de execução única no sistema consumidor.

## Retenção reversível

A política tem prazos independentes para testes de áudio encerrados, sessões incorporadas expiradas e entregas de eventos concluídas/canceladas. Mínimo de 30 dias e máximo de 3.650. Padrões: 90, 30 e 90 dias, respectivamente.

O automático começa **desativado**. A tela mostra a prévia com a política salva e permite arquivar até 500 registros de cada categoria por lote. A execução automática, se habilitada pelo administrador, ocorre às 03:30 UTC (00:30 de São Paulo). A prévia pode ser maior que o lote; o restante fica para outra execução.

O arquivamento apenas oculta esses registros dos históricos operacionais: não exclui linhas e não libera espaço físico no banco. Chamadas, mensagens, custos, consentimentos e auditorias permanecem. Entregas pendentes, com falha ou reservadas não são arquivadas. O histórico de tentativas e a identidade dos eventos são preservados.

O pedido manual é idempotente; repetir a mesma chave não cria outro lote. **Restaurar lote** traz os registros de volta, mantendo seus estados e sem reenviar eventos concluídos. Se o automático continuar ativo, os registros restaurados podem voltar a ser arquivados na próxima execução. Exclusão física e retenção jurídica de dados pessoais não fazem parte desta entrega.

## Validação e publicação

A validação utiliza provedores simulados e bancos descartáveis. Confere correlação de custos, moeda, preço ausente, permissões, resposta tardia de webhook, limite de execuções, conflito de revisão, arquivamento/restauração e preservação dos registros ativos. A interface é exercitada em desktop e celular com mutações interceptadas; a conferência publicada é somente leitura.

A publicação não habilita retenção automática, não arquiva registros existentes, não reagenda eventos e não ativa atendentes ou filas. Homologação telefônica com pessoas e aumento de concorrência continuam pendentes.

Resultado: **256 testes e 1.987 asserções em cada banco (SQLite e PostgreSQL isolados)**; três testes das métricas WebRTC; build concluído; navegação desktop/celular aprovada. Disputa de capacidade: 40 solicitações, uma aceita, 39 recusadas por capacidade e nenhuma reserva duplicada. Conferência publicada somente leitura aprovada. Nenhuma chamada ou mensagem nova foi disparada.

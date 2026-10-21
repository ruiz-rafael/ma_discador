# Telefonia sem cotas diárias da plataforma

A DiscAI não deve impor cotas diárias ao recebimento nem à saída de chamadas. A implementação anterior somava chamadas recebidas e de saída e aplicava o menor limite entre uma política de operação e uma configuração privada de homologação. Isso recusou chamadas reais após testes internos. A elevação temporária de 10 para 50 foi substituída pela remoção dessa regra.

- `OperationPolicy` preserva a pausa explícita, mas não aplica cota diária de voz. Valores antigos em banco e na configuração privada não bloqueiam chamadas.
- Admissão SIP (Twilio e Vono), recebimento Twilio API e recebimento SIP por IA usam a regra de entrada sem cota diária de telefonia.
- A reserva de chamadas de saída não verifica mais o teto privado diário. Twilio API e SIP compartilham essa reserva.
- A API de saúde informa limites diários de voz como nulos e separa estatísticas de entrada e saída. A interface remove o campo de cota de voz e mostra “Sem limite diário”.
- Permanecem capacidade simultânea, pausa explícita, horários, autorização dos destinos e regras configuradas de tentativas nas campanhas. Limites de WhatsApp, e-mail e consumo da API de IA não foram alterados.

Validação: 49 testes / 305 assertions, incluindo mais de 50 chamadas recebidas, independência de configurações legadas e nova discagem após exceder o antigo teto privado. Build Vite concluído. Publicação sem migração de banco ou reinício de PBX. Backup: `/srv/zyrex-ma/backups/voice-no-daily-20261021T185147Z`.

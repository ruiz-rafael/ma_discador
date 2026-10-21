# Validação do recebimento SIP — 21/10/2026

- 79 testes PHP aprovados, 672 verificações, incluindo isolamento, capacidade compartilhada, assinatura dos eventos e tabulação.
- 11 testes Python aprovados para geração e proteção da configuração SIP.
- Compilação de produção concluída.
- Navegador real automatizado: registro, oferta, atendimento, mídia bidirecional, encerramento e tabulação nas rotas Vono e Twilio. Originação por canal Local do Asterisk; nenhuma chamada pela rede pública.
- Vono: 180 pacotes enviados e 150 recebidos, conversa de 5 segundos. Twilio: 113 enviados e 79 recebidos, conversa de 3 segundos. Nenhum erro JavaScript.
- Twilio: URI de originação configurada; Vono: registro confirmado. Ambas as linhas brasileiras vinculadas à fila LANÇAMENTOS de entrada da Bossa Nova. Linha Twilio internacional permanece na fila original.

A entrega de chamadas pelas redes das operadoras ainda requer homologação externa, ligando para cada DID. Estes testes não comprovam o percurso PSTN.

A medição usa uma variável compartilhada no canal exato de entrada, permitindo medir também chamadas com canais Local intermediários. As duas chamadas finais terminaram como concluídas, tabuladas e com capacidade liberada.

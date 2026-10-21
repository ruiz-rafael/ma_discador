# Validação do recebimento SIP — 21/10/2026

- 79 testes PHP aprovados, 672 verificações, incluindo isolamento, capacidade compartilhada, assinatura dos eventos e tabulação.
- 11 testes Python aprovados para geração e proteção da configuração SIP.
- Compilação de produção concluída.
- Navegador real automatizado: registro, oferta, atendimento, mídia bidirecional, encerramento e tabulação nas rotas Vono e Twilio. Originação por canal Local do Asterisk; nenhuma chamada pela rede pública.
- Vono: 179 pacotes enviados e 145 recebidos. Twilio: 175 enviados e 115 recebidos. Nenhum erro JavaScript.
- Twilio: URI de originação configurada; Vono: registro confirmado. Ambas as linhas brasileiras vinculadas à fila LANÇAMENTOS de entrada da Bossa Nova. Linha Twilio internacional permanece na fila original.

A entrega de chamadas pelas redes das operadoras ainda requer homologação externa, ligando para cada DID. Estes testes não comprovam o percurso PSTN.

# DiscAI: voz multilíngue e evolução para SDR

## Entrega de 15/10/2026

O Estúdio de voz ganha idioma/região, vozes compatíveis, exemplo de abordagem e ajustes opcionais de pronúncia por template. As opções são português brasileiro, inglês americano e espanhol latino-americano. O motor Chatterbox usa o checkpoint especializado PT-BR, o V3 multilíngue para inglês e o checkpoint especializado es-MX/LatAm. Kokoro mantém suas vozes brasileiras, acrescenta Heart/Bella/Michael e vozes de espanhol geral; o fonemizador usa `pt-br`, `en-us` e `es-419`. A interface explica que o espanhol Kokoro não é um modelo regional dedicado.

A comparação aplica o mesmo texto, variáveis, idioma, velocidade e dicionário nos dois motores. Ajustes são literais, sem SSML nem expressões regulares fornecidas pelo usuário; respeitam limites de palavras, aplicam termos mais longos primeiro e não reaplicam substituições recursivamente. Texto original e texto pronunciado ficam registrados separadamente. Os limites são conferidos depois da expansão. Idioma, voz e texto efetivo participam da deduplicação. Templates antigos permanecem PT-BR.

Esta entrega gera mensagens e recados. Não escuta o cliente nem decide respostas. A CPU atual carrega os modelos Chatterbox por trabalho; a latência deve ser medida e não é adequada, por si só, para um SDR de voz ao vivo. Nenhuma campanha recebe novos áudios automaticamente.

## Próximas entregas para um SDR conversacional

1. **Perfil do SDR por empresa:** propósito, idiomas, tom, identificação como assistente virtual, perguntas de qualificação, informações aprovadas, limites de atuação e fila de transferência. Versões publicadas e rascunhos separados. Configuração exclusiva do tenant.
2. **Conversa de teste no navegador:** transcrição, modelo de linguagem já existente com ferramentas restritas, memória por conversa, respostas apoiadas na base da empresa e saída estruturada (interesse, dados coletados, próximo passo). Ferramentas de agenda, registro e transferência devem ter autorização, validação e idempotência no backend. MCP é opcional; não substitui a autorização do servidor.
3. **Áudio ao vivo:** reconhecimento de fala em streaming, detecção de turnos, interrupção pelo cliente, cancelamento do áudio anterior e síntese incremental. Medir tempo até o primeiro áudio e resposta completa, percentis e concorrência nos três idiomas. Separar esta infraestrutura da aplicação de filas. Usar GPU dedicada ou serviço de voz contratado após comparar qualidade, custo e latência; a telefonia permanece substituível.
4. **Telefonia e passagem humana:** adaptador de mídia Asterisk/SIP e adaptador Twilio, eventos correlacionados por tenant/conversa/chamada. Reservar capacidade, preservar AMD separado, transferir com resumo e validar disponibilidade humana antes da transferência. Regras atuais de agentes online nos disparos continuam vigentes; mudar para operação autônoma exige decisão explícita de produto.
5. **Homologação e supervisão:** cenários internos de silêncio, interrupção, sotaques, nomes, valores, datas, falta de conhecimento, pedidos de saída e falhas de rede. Métricas de qualificação e transferência, auditoria das ferramentas, custo por conversa e limites por empresa. Testes externos somente quando autorizados.

## Critérios de qualidade

Naturalidade é uma avaliação auditiva, não consequência automática do nome do modelo. Comparar amostras com os mesmos roteiros nos três idiomas e avaliar pronúncia, pausas, velocidade, repetições, palavras omitidas e sotaque. A conversão WAV de 8 kHz para telefonia reduz fidelidade frente ao MP3/OGG; ouvir também a saída telefônica. Não tratar qualidade de arquivo ou teste unitário como aprovação humana da voz.

A referência de produto [Findor](https://findor.ai/) descreve qualificação, follow-up, agendamento e passagem com contexto. Isso orienta a jornada do SDR; não prova que seus componentes de voz sejam os mesmos.

Fontes técnicas: [Chatterbox e modelos por idioma](https://github.com/resemble-ai/chatterbox), [modelo es-MX/LatAm](https://huggingface.co/ResembleAI/Chatterbox-Multilingual-es-mx-latam), [vozes Kokoro](https://huggingface.co/hexgrad/Kokoro-82M/blob/main/VOICES.md). Uma opção futura de comparação comercial é [ElevenLabs](https://elevenlabs.io/docs/overview/models), sem integração ou cobrança ativada nesta entrega.

## Validação técnica nesta VM

458 testes de backend passaram em SQLite e PostgreSQL, além de 11 testes JavaScript. O navegador validou desktop/celular, seleção de vozes, persistência de pronúncias e comparação no mesmo idioma. Seis amostras fictícias foram geradas em workers isolados, sem rede, sem chamadas e sem mensagens.

| Motor | Idioma | Áudio | Tempo de geração |
| --- | --- | --- | --- |
| Kokoro | PT-BR | 4,72 s | 15,13 s |
| Kokoro | EN-US | 4,82 s | 7,12 s |
| Kokoro | ES-MX (voz geral) | 5,14 s | 6,81 s |
| Chatterbox | PT-BR | 7,07 s | 247,79 s |
| Chatterbox | EN-US | 4,67 s | 123,59 s |
| Chatterbox | ES-MX/LatAm | 6,80 s | 182,49 s |

São amostras pontuais, com carga compartilhada de homologação, e não percentis de produção. O primeiro áudio Kokoro inclui carregamento do modelo. Os testes confirmam geração e formato, não a aprovação auditiva da naturalidade, do sotaque ou da pronúncia.

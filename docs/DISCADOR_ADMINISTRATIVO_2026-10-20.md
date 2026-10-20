# Discador administrativo — 20/10/2026

O administrador geral pode abrir `/platform-calling` pela seleção de empresas ou pela administração. Escolhe um número autorizado da plataforma e sua operadora, sem trocar de empresa. Administradores de clientes, supervisores e agentes não têm acesso a esse discador central.

O catálogo reúne as conexões configuradas nos ambientes ativos. Mostra número, operadora, método e empresa; números indisponíveis ficam desabilitados. Inventário contratado sem conexão de telefonia configurada não é anunciado como apto a ligar.

A chamada usa o áudio do navegador, não exige presença online em fila e é registrada como teste administrativo manual, sem fila ou campanha. Contato autorizado, lista de destinos de homologação, suspensão, capacidade, duração e limites da conexão continuam sendo verificados. Não cria contatos, não ativa filas e não dispara WhatsApp ou e-mail.

O modal só minimiza por ação explícita. Exibe o andamento e o resultado confirmado pela telefonia, preservando a última tentativa. Conectar o áudio do navegador não significa que o destinatário atendeu. Fechar a aba interrompe o áudio; ao voltar, a tentativa pendente é recuperada sem rediscagem.

## Implementação

- Portal registra a solicitação antes de obter a reserva da conexão; o identificador é único, e há no máximo uma tentativa administrativa não encerrada por operador, inclusive entre empresas.
- O catálogo entrega uma seleção cifrada, vinculada ao operador e com validade de uma hora. O backend deriva a origem dessa seleção; o navegador não pode fornecer uma origem arbitrária.
- Comunicação interna assinada por tenant, com confirmação do papel de administrador geral no portal a cada operação. O papel é novamente conferido antes de autorizar a discagem na operadora/PBX.
- A reserva não disca. Apenas o SDK de áudio, após o clique do usuário, utiliza a autorização temporária e de uso único. Autorizações e senhas de mídia não são gravadas no histórico central.
- A API pública do discador comum não aceita o marcador privado de teste administrativo.
- Falhas de transporte mantêm o resultado indefinido e bloqueiam novas tentativas até a consulta. Não há repetição automática de reserva ou chamada.
- Cancelar libera uma reserva ainda não utilizada. Em chamada estabelecida, o navegador encerra o áudio e o estado final depende do retorno da telefonia. Twilio dispõe de consulta explícita; SIP depende dos eventos do Asterisk.
- A política de conteúdo do portal permite os destinos de mídia Twilio e WebSocket DiscAI usados pelos SDKs. As demais restrições e o formulário OAuth Google permanecem.

## Validação

102 testes PHP (797 asserções) de telefonia, filas, SIP, portal e e-mail; testes JavaScript de resultados e autorização de mídia; build Vite; testes de interface em 1440, 390 e 320 px. O contrato HTTP foi exercitado com servidor real em loopback para sucesso, conexão interrompida, redirecionamento e acesso revogado. Ambiente Postman/Orbit indisponível: usada a infraestrutura de fixtures HTTP já existente no projeto.

A verificação em produção identificou que consultas internas consumiam o contador compartilhado do login, causando HTTP 429 sem tentativas de senha suficientes. O login passou a ter contador próprio, preservando cinco tentativas por minuto. O teste reproduziu a falha antes da correção e confirmou o isolamento e o bloqueio da sexta tentativa depois dela.

Os testes usam banco e credenciais sintéticos, transporte simulado e SDK de mídia substituído no navegador. Nenhuma chamada, mensagem ou e-mail real faz parte desta validação. Qualidade de áudio, entrega e identificação de origem pela operadora ainda dependem de um teste real feito pelo usuário.

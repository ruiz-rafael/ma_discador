# Verificação de WhatsApp por ligação no ambiente do cliente

## Fluxo entregue

- Meus números no portal → Conectar WhatsApp; ou menu WhatsApp oficial na operação.
- Combo com as linhas ativas atribuídas ao ambiente, incluindo o tronco Twilio dedicado do cadastro anterior, com operadora (Twilio / Fale Vono). O endpoint revalida a atribuição em todas as ações. Caller IDs apenas verificados para saída não entram no catálogo.
- A tela confere rota de entrada, fila receptiva, agente disponível, seleção de filas, presença recente, áudio SIP/WebRTC e capacidade livre. Isso é uma conferência local; não garante a chegada da chamada da operadora.
- O administrador do cliente informa o nome comercial, confirma autorização/recebimento e solicita explicitamente a ligação. Não há pedido automático ao abrir a página, mudar de número ou consultar andamento.
- O código de seis dígitos é enviado uma vez ao provedor. Não é persistido na aplicação, nem incluído em auditoria ou flash de validação. O navegador limpa o campo após o envio.
- A consulta recupera um remetente já criado, sem criar outro ou reenviar código. ONLINE é exigido para mostrar conectado.
- Perfil comercial/foto continua disponível no editor existente, sujeito à aprovação da Meta.

## Autorização da plataforma

A administração geral libera o fluxo por ambiente, na seção Administração DiscAI da nova tela. Ela informa o WABA autorizado, e o backend vincula essa liberação à conta Twilio configurada privadamente naquele ambiente. Clientes não veem nem editam IDs de conta, WABA, parceria ou credenciais. O assistente técnico anterior agora é restrito ao administrador geral.

O provedor deve retornar apenas o WABA autorizado e já ter um remetente ONLINE nessa conta. O primeiro cadastro/autorizações empresariais continuam sendo pré-requisitos do provedor. A verificação telefônica não substitui essas autorizações. Não copiamos credenciais de outro tenant nem ativamos liberações durante esta entrega.

Na entrega inicial, a conexão WhatsApp da Bossa Nova ainda dependia da configuração/autorização inicial e do desbloqueio da tentativa no Meta. A ativação posterior está registrada abaixo. Twilio e Vono aparecem como linhas disponíveis; nenhuma nova chamada de verificação foi solicitada nesta entrega.

## Operações e recuperação

Contrato: `source/postman/whatsapp-verification.openapi.json`.

A tabela `wa_number_verifications` registra número, conta/WABA privados, usuário, operação, estado, código numérico de erro e pausa. As reservas usam transação e lock no workspace. Há um intervalo local de um minuto entre mutações. Resposta perdida, 5xx ou identidade divergente ficam em estado desconhecido, sem repetição automática. Consultar pode recuperar uma criação confirmada pelo provedor; ausência do remetente não libera uma recriação de resultado desconhecido.

HTTP 429 e erro Twilio 63114 geram pausa preventiva local de 24 horas. A interface informa que isso não é um prazo garantido da Meta. A consulta não solicita outro código. Não foi implementado reenvio por um endpoint não documentado: um cadastro pendente sem código requer revisão administrativa.

As chamadas usam Twilio Senders API v2, `configuration.verification_method=voice` na criação e `configuration.verification_code` na confirmação. O cadastro configura apenas o webhook de mensagens, preservando o tronco SIP e o recebimento telefônico.

Fontes consultadas:
- https://www.twilio.com/docs/whatsapp/api/senders
- https://www.twilio.com/docs/api/errors/63114
- https://www.twilio.com/docs/whatsapp/register-senders-using-api

## Validação

62 testes PHP / 369 asserções passaram em SQLite isolado e rede desabilitada: verificação, onboarding anterior, perfil público, login e receptivo SIP. HTTP do provedor foi simulado com Laravel Http::fake; não é homologação real da Twilio/Meta. O executável Postman local é o aplicativo desktop, sem CLI de simulation disponível.

Build Vite concluído. A prévia Playwright usa assets compilados e respostas de verificação simuladas; evidências em `evidence/wa-verification-20261021`. Os testes de navegador não solicitam OTP nem fazem chamadas reais. Validação de produção restrita a leitura e navegação.

Produção conferida: links do portal abrem a nova tela; Bossa Nova lista +551149330254 (Twilio) e +551154443245 (Fale Vono); Polishop apresenta o estado sem números. Interface responsiva sem erros JavaScript. Ativação WhatsApp permanece pendente e a Vono não foi registrada. Backup: `/srv/zyrex-ma/backups/wa-verification-20261021T152628Z`.

## Ativação posterior da Bossa Nova — 21/10/2026

Após Rafael concluir a verificação no console, a Twilio confirmou o número +551149330254 ONLINE, com nome público Bossa Nova. O painel continuava pendente porque o ambiente isolado da Bossa Nova não tinha conexão privada nem liberação do WABA configuradas.

A conexão da conta Twilio da DiscAI foi instalada de forma cifrada no ambiente autorizado Bossa Nova, mantendo os parâmetros existentes de homologação de mensagens. A liberação foi conferida pelo inventário real da Twilio. O serviço de consulta importou o remetente existente, sem registrar outro número nem solicitar OTP. O perfil público foi consultado e o webhook de entrada passou a apontar para `https://bossanova.discai.ia.br/callbacks/twilio/whatsapp/inbound`, método POST, confirmado por leitura posterior na API. Vono permanece sem Sender SID e não verificada; telefonia não foi alterada.

A atualização do webhook revelou que a Twilio pode responder com um reconhecimento parcial (202). O serviço agora consulta o remetente depois da atualização e valida a identidade da resposta completa, evitando o falso erro de número divergente. Um teste de regressão cobre GET → POST parcial → GET, sem reenvio da atualização nem mensagens.

Validação no navegador de produção: API HTTP 200, estado `online`, `setup_ready=true`, solicitação e confirmação de código desabilitadas. O painel apresenta “WhatsApp conectado” e “Consultar andamento” confirma o estado no provedor. Evidências em `evidence/bossa-whatsapp-online-20261021/`. Nenhum template foi criado nem mensagem enviada nesta ativação; a conexão não equivale à aprovação de templates ou à homologação de entrega de mensagens.

Correção validada com 45 testes PHP / 265 asserções (onboarding, verificação e perfil), em SQLite isolado, sem rede e com armazenamento privado vazio. Publicação restrita ao serviço PHP compartilhado; não foi necessário reiniciar telefonia ou alterar o frontend.

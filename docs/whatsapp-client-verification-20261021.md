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

Em Bossa Nova, a conexão WhatsApp ainda depende da configuração/autorização inicial e do desbloqueio da tentativa no Meta. Twilio e Vono aparecem como linhas disponíveis; nenhuma nova chamada de verificação foi solicitada nesta entrega.

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

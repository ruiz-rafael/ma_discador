# E-mail OAuth por empresa e convites de novos tenants

Implementação de 19/10/2026. Um aplicativo Google DiscAI atende todas as empresas. Cada empresa autoriza sua própria caixa; a administração geral autoriza outra caixa para comunicações da plataforma. A conexão não libera envios automaticamente.

## Onde configurar

- Administração geral → **E-mail da DiscAI** (`/email-administration`): remetente dos convites de empresas e de equipes. Exemplo: `comercial@discai.ia.br`.
- Minhas empresas → cartão da empresa → **E-mail** (`/companies/{id}/email`): conexão do cliente, usada nos passos de e-mail das cadências. Somente administrador do tenant ou administrador geral.
- Administração geral → **Convidar nova empresa** (`/signup-invitations`): dados do destinatário, empresa, plano e limites. Gerar link funciona sem conexão de e-mail; enviar exige remetente administrativo liberado.
- O destinatário abre o botão **Criar minha empresa na DiscAI**, informa nome, senha e empresa. E-mail e limites vêm do convite. Recebe papel de administrador exclusivamente do novo tenant, nunca de administrador geral.
- Conta já cadastrada exige sua senha atual. O convite não redefine senhas nem substitui outros acessos.
- Convites expiram em três dias, são de uso único e podem ser cancelados. Novo convite para o mesmo endereço invalida o anterior. Abrir o link não o consome.
- O tenant entra em `provisioning`, atendido pelo provisionador existente. DNS/TLS e capacidade ainda precisam estar disponíveis; criar cadastro não promete operação imediata. Não se copiam credenciais de telefonia de outra empresa.

## Cadastro no Google

No projeto DiscAI, habilitar a Gmail API; configurar Google Auth Platform com marca DiscAI e público externo (para clientes de outras organizações). Durante testes, cadastrar os usuários de teste. Criar cliente OAuth do tipo **Aplicativo da Web** com esta URI exata:

`https://app.discai.ia.br/oauth/google/callback`

Escopos: `openid`, `email`, `https://www.googleapis.com/auth/gmail.send`. Não há permissão para leitura da caixa ou gestão de domínio. `gmail.send` é sensível: publicação/verificação Google é uma etapa separada da instalação do código. Referências oficiais: [OAuth para aplicações Web](https://developers.google.com/identity/protocols/oauth2/web-server), [escopos Gmail](https://developers.google.com/workspace/gmail/api/auth/scopes).

Salvar o JSON do cliente Web fora do repositório, sem enviá-lo em conversa. Caminho de recebimento local: `.secrets/google-oauth.json` (modo 0600). No portal, instalar em `/srv/zyrex-ma/discai/portal-storage/app/private/google-oauth.json`, proprietário 82:82, modo 0600. O arquivo deve conter a chave `web`, `client_id` e `client_secret`. Alternativa: variáveis `GOOGLE_MAIL_CLIENT_ID` e `GOOGLE_MAIL_CLIENT_SECRET` apenas no portal. Nada é instalado nos tenants. Nunca registrar ou imprimir os valores.

Após instalar: administrador conecta a caixa Google, confere o endereço confirmado, define limite e libera envio. Repetir para cada empresa usando a respectiva conta. O remetente nesta versão é o endereço principal; aliases ainda não são selecionáveis. Conexão Gmail não cria a caixa comercial nem configura MX do domínio.

## Isolamento, limites e falhas

- Tokens criptografados com a chave do portal. Conta Google exclusiva por escopo: `platform` ou `tenant:<id>`; não pode ser conectada simultaneamente em dois espaços.
- OAuth com state aleatório, PKCE S256, validade de dez minutos, uso único e vínculo à sessão/usuário/revisão. Permissões verificadas novamente no retorno. Trocar/reconectar a conta pausa envios até liberação explícita.
- Tenant consulta/envia pela ponte HMAC existente. O portal determina o remetente pela identidade assinada do tenant; ignora `scope` e `from` enviados pelo chamador.
- Revisão da conexão acompanha o envio: mudança entre preparação e despacho bloqueia a tentativa. Suspensão do tenant também bloqueia.
- Limite diário por conexão (padrão 20, teto central `GOOGLE_MAIL_DAILY_LIMIT_MAX`, padrão 500). Limites reais Google continuam aplicáveis. Tentativas incertas contam para evitar extrapolação.
- Identificador durável por tentativa, hash do conteúdo e revisão; nenhuma repetição automática após timeout, desconexão ou resposta incerta. `accepted` significa aceito pela API, sem confirmar entrega/leitura; `unknown` exige conferência; `submitting` persistente exige investigação.
- Autorização revogada pausa a conexão. Nenhum fallback para e-mail de outra empresa ou da DiscAI. SMTP previamente configurado por ambiente continua possível enquanto não existir conexão gerenciada naquele escopo; após começar a conexão, pausa/desconexão não reativa SMTP.
- Desconectar apaga tokens locais; remoção da permissão no Google pode ser feita pelo proprietário da conta. Auditoria não armazena tokens nem links de convite em texto aberto.
- Esta entrega cobre Gmail/Google Workspace. Outlook/Microsoft e recebimento de respostas por e-mail não estão incluídos.

## Verificação

`PortalMailTest`, `PortalSignupTest`, `ManagedCadenceMailTest`, `GoogleMailHttpTest` e regressões existentes. O teste HTTP usa servidor local de contrato com troca de código, renovação, MIME, queda de conexão e redirecionamento; não utiliza Google real. Simulação local adotada porque o executável Postman disponível é o aplicativo desktop (CLI indisponível) e Orbit respondeu HTTP 403 sem autenticação. Contrato em `source/postman/google-mail.openapi.json`.

Testes de navegador em portal isolado para desktop/celular, convite, cadastro, fluxo de autorização simulado, configuração e desconexão. Não são enviados e-mails reais, chamadas ou WhatsApp.

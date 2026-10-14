# DiscAI: portal de empresas e ambientes isolados

Entrega de 14/10/2026. Entrada central: https://app.discai.ia.br/login.

## Arquitetura adotada

O inventário do código encontrou consultas de MA sem escopo de empresa e configurações de telefonia vinculadas ao workspace 1. Por isso, esta entrega usa um portal central e ambientes operacionais isolados, compartilhando uma versão do código. Não habilitamos vários clientes no banco legado apenas alterando o workspace.

- Portal: containers próprios, banco de identidade separado, sessões e chave próprios.
- Novas empresas: PostgreSQL, volumes privados, chave de criptografia, sessão, rede e processos próprios. Dentro de cada banco, o workspace 1 representa exclusivamente aquela empresa.
- Dados de clientes e credenciais de operadoras não são copiados do MA existente.
- A operação Zyrex existente permanece em `ma.zyrex.ia.br`, acessível pelo portal mediante vínculo explícito. O endereço antigo e o login local foram preservados durante a transição.
- Contas existentes foram importadas no portal com hashes de senha, sem exportar senhas em texto. Alterações posteriores de senha no portal não alteram a senha do login legado.

O modelo é multi-tenant com isolamento de implantação. Não é ainda um único processo de aplicação atendendo bancos de várias empresas simultaneamente. A separação consome mais recursos por empresa e permite migrar clientes para outras máquinas depois.

## Acesso

O usuário entra no portal. Uma pessoa com um único vínculo ativo entra automaticamente na empresa, exceto o operador da plataforma, que vê a gestão. Quem tem vários vínculos escolhe o ambiente. Uma conta sem vínculo vê orientação para solicitar convite.

Cada vínculo possui papel de administrador, supervisor ou atendente. Ser operador da plataforma não concede acesso implícito aos dados operacionais: é necessário vínculo explícito com a empresa, auditado.

O SSO utiliza estado aleatório vinculado à sessão do navegador, código de uso único com validade de 60 segundos e resgate autenticado por segredo diferente para cada empresa. A empresa, o estado, a validade e o vínculo são verificados antes de consumir o código. O backend revalida acessos feitos via portal nas requisições da API. Senhas alteradas invalidam sessões anteriores. Sair do portal invalida as sessões abertas por ele por versão de autenticação.

No ambiente legado, contas que entraram diretamente pelo login antigo continuam sob o controle local. A suspensão no portal não deve ser interpretada como revogação desses acessos locais preexistentes. A migração obrigatória de todos os acessos legados deve ser planejada para não interromper agentes.

## Operação do portal

- **Minhas empresas:** seleção do ambiente e alteração da própria senha.
- **Administrar plataforma:** cadastro de empresas, plano, limites contratados e auditoria.
- **Gerenciar equipe:** convite individual, perfil e suspensão de vínculo. Administradores de empresa gerenciam apenas sua própria equipe.
- Convites expiram em três dias e são apresentados para compartilhamento manual. Não há envio automático de e-mail nesta entrega.
- Conta já existente deve confirmar a senha atual ao aceitar um convite. O convite não redefine senhas existentes.
- O limite de usuários é verificado ao convidar, aceitar e reativar vínculos. Convites pendentes reservam lugares.
- A última pessoa com perfil administrador não pode ser removida por esse formulário.
- Novos ambientes exigem login central; o cadastro de identidades ocorre pelo portal. Filas e atribuições operacionais continuam no MA.

Recuperação de senha por e-mail, cobrança automática e faturas não estão habilitadas. Canais e armazenamento exibidos no portal são parâmetros contratuais; não representam liberação automática de capacidade nem quota rígida de disco. A capacidade operacional inicial continua em uma chamada simultânea, até homologação. A VM também tem limites de memória e CPU por ambiente e um limiar de memória livre para novas criações.

## DNS e provisionamento

O portal usa o registro `app.discai.ia.br`, já publicado com HTTPS.

Para novas empresas, o provisionador usa `identificador.app.discai.ia.br`. Configure `A *.app → IP da VM`, preferencialmente **Somente DNS**, ou registros A individuais. Subdomínios com dois níveis podem não estar cobertos pelo certificado de borda padrão da Cloudflare; por isso não basta presumir que um CNAME com proxy estará pronto.

Uma empresa é cadastrada como **Preparando**. O provisionador cria infraestrutura vazia, executa as migrações, verifica DNS e HTTPS e só então libera a empresa. Falta de capacidade, DNS ou certificado mantém a preparação pendente, com mensagem na administração. Os processos de fila e agendamento são próprios da empresa e iniciam após a validação do domínio. Não há credenciais, campanhas ou agentes online copiados do MA.

SIP/Asterisk, QR Code e serviços de voz sintética exigem provisionamento específico por empresa antes do uso. O provisionador inicial não replica esses serviços nem seus segredos. As rotas Twilio passam a usar a origem configurada do ambiente; o cabeçalho Host recebido não determina URLs de callbacks.

## Administração e recuperação

Instalação em `/srv/zyrex-ma/discai`, separada da aplicação existente. Scripts versionados em `deploy/discai` e executados pela operação em `/srv/zyrex-ma/ops`.

- `discai-provision.timer`: processa uma criação pendente de cada vez.
- `discai-usage.timer`: coleta somente contadores locais; não consulta nem aciona operadoras.
- `discai-backup.timer`: backup diário do banco central, configurações privadas e, quando existentes, bancos e arquivos privados dos novos ambientes.
- Backups em `/srv/zyrex-ma/backups/discai`, com permissão restrita. Contêm segredos e nunca devem ser publicados.
- O backup usa a API de backup do SQLite e confere integridade. PostgreSQL é exportado por `pg_dump`.
- A restauração deve ocorrer em ambiente isolado primeiro, preservando as chaves correspondentes. Não restaure um banco antigo sobre a operação em execução.
- O provisionador valida faixas privadas antes de criar suas redes. Não remove redes de outros projetos.
- Certificados são renováveis pelo Certbot; o hook valida a configuração antes do reload do proxy.

## Validação

A regressão cobre o MA e os novos fluxos do portal. Testes específicos incluem:

- Acesso cruzado entre empresas, perfis independentes e ausência de acesso implícito do operador.
- Código de SSO expirado, repetido, com estado incorreto ou pertencente a outra empresa.
- Revogação de vínculo, suspensão e versão de autenticação.
- Convite sem tomada de conta existente e bloqueio de excesso de usuários.
- Dois runtimes descartáveis com IDs iguais, bancos e arquivos separados e recusa de descriptografia usando a chave da outra empresa.
- Layout e navegação em desktop e celular.
- Login administrativo real no portal e SSO para o MA, bloqueando escritas operacionais no navegador de verificação.

Nenhuma chamada real, mensagem WhatsApp ou ativação de agente foi necessária. A entrega não homologa áudio, capacidade de operadora, funcionamento do QR por nova empresa ou telefonia simultânea em produção.

# Atribuir um número existente a uma empresa

O administrador geral acessa **Clientes e planos → Solicitações de números → Vincular número existente**. Também há atalho na administração de cada empresa, com o cliente previamente selecionado.

1. Seleciona a empresa ativa, escolhe o **Número e operadora** no inventário e define o uso desejado.
2. Confirma a reserva. O portal consulta a conta Twilio configurada e verifica propriedade, capacidade de voz, ausência de rota e ausência de outro vínculo.
3. Confere a empresa e o número na página de detalhes e usa **Liberar para [empresa]**. O fluxo existente configura os callbacks e entrega o número ao ambiente correto em duas etapas.
4. O cliente encontra o número em **Meus números** e escolhe a fila.

Não é necessário um pedido do cliente. A origem é registrada como `admin_assignment`, com responsável, empresa e auditoria `number.assigned_by_admin`. A reserva não compra um número, não modifica a operadora e não inicia chamadas. O campo de data de compra permanece vazio para números já contratados; a liberação recebe sua própria data.

Números brasileiros e internacionais são aceitos em E.164. O DDD só aparece para números brasileiros. A contratação de novos números por solicitação continua restrita às opções brasileiras já suportadas.

O seletor consulta os números já contratados na conta central Twilio, com paginação, e os números cadastrados nos ambientes dos clientes. Separa **Disponíveis** e **Já utilizados ou reservados**; os últimos aparecem bloqueados com a empresa responsável. **Atualizar lista** consulta novamente o inventário, sem comprar ou reservar números.

As conexões Fale Vono cadastradas no ambiente da empresa aparecem no inventário como vinculadas a ela. Este seletor não transfere credenciais SIP entre empresas: a atribuição e liberação central continuam usando o fluxo Twilio existente.

A consulta interna `POST /discai-internal/number-inventory` é autenticada com a assinatura HMAC do tenant e retorna apenas telefone e operadora, sem credenciais. Se qualquer ambiente não responder, a seleção de números livres fica bloqueada até a conferência dos vínculos. Uma falha da Twilio não esconde os números SIP conhecidos.

Um número com trunk, aplicação de voz, webhook de chamada ou callback já configurado precisa ser revisado antes de ser transferido. A atribuição direta não sobrescreve uma rota existente nem transfere um número já reservado por outro cliente. O formulário possui identificador para repetição idempotente; índices únicos protegem número e identificador da operadora, inclusive entre empresas diferentes.

## Validação

Os testes cobrem permissões, confirmação, ausência de pedido anterior, repetição do formulário, conflito entre empresas, números internacionais, conta incorreta, incapacidade de voz e rotas existentes. O fluxo completo de reserva, liberação e catálogo do cliente é exercitado no navegador em banco isolado, com operadora simulada.

O teste `test_direct_assignment_runs_real_http_and_recovers_from_provider_error` inicia um servidor HTTP de loopback e executa o cliente HTTP real em cenários de erro 500 e sucesso. O middleware de teste redireciona somente a URL do provedor; não existe chave ou endereço alternativo configurável na aplicação de produção. O mock segue os campos de IncomingPhoneNumbers consumidos por `NumberProvider::owned` e pelos testes anteriores. Nenhum mock externo foi importado: o executável Postman disponível é o aplicativo desktop e não oferece o CLI de simulação. Os testes existentes de liberação continuam usando `Http::fake` e bloqueiam requisições externas não previstas.

`NumberInventoryTest` cobre acesso restrito, números ocupados, reservas, paginação, rejeição de destinos de paginação externos e ausência de credenciais na resposta interna. Sua simulação HTTP real exercita duas páginas do inventário, a assinatura do tenant e o bloqueio após erro 503, sem criar pedidos ou fazer chamadas.

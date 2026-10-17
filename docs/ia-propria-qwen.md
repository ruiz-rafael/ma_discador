# Qwen como alternativa de texto no DiscAI

Entrega de 17/10/2026. Qwen3-8B é uma alternativa ao GPT para respostas escritas, incluindo o laboratório e o processamento de novas mensagens WhatsApp. A fala ao vivo continua na integração OpenAI existente. Esta entrega não contém um pipeline Whisper → Qwen → Chatterbox para telefonia.

## Administração

Portal central → Administrar plataforma → Inteligência artificial → empresa → Configurar IA.

Somente operadores da plataforma acessam esta configuração. Administradores de clientes continuam definindo identidade, conhecimento e regras de seus agentes. Não recebem chaves, endereços de inferência ou formulário de credenciais; a configuração direta de OpenAI é bloqueada em ambientes conectados ao portal. Supervisores conservam acesso apenas à supervisão.

O operador define o provedor de texto (GPT ou Qwen), origem do servidor Ollama, chave de autenticação, modelo instalado (`qwen3:8b` ou `qwen3:8b-q4_K_M`), limite diário, contexto (8192/16384 tokens), simultaneidade por empresa (1–4) e tempo máximo de espera (10–60 segundos). OpenAI tem sua chave e liberação separadas, permitindo Qwen escrito e OpenAI falado na mesma empresa. Cada provedor tem seu próprio limite de solicitações; eles não equivalem a um teto financeiro.

Salvar uma configuração desativada não realiza inferência nem baixa o modelo. “Verificar servidor” consulta apenas `/api/tags`; liberar Qwen também exige confirmação de que o modelo está instalado. Isso não comprova latência ou qualidade. Depois de publicar o agente, é necessário concluir um teste de texto com o provedor selecionado antes de ativar WhatsApp. Nenhum vínculo/canal é ativado pela troca de parâmetros. Não há fallback automático para OpenAI.

## Servidor próprio

Use servidor dedicado com Ollama e o modelo já instalado. Coloque HTTPS e um proxy de autenticação Bearer na frente do serviço: Ollama local não fornece essa autenticação sozinho. Exponha ao DiscAI somente `GET /api/tags` e `POST /api/chat`; mantenha download, criação e remoção de modelos inacessíveis. Não exponha a porta de inferência sem proteção.

Como referência inicial para Qwen quantizado: 32 GB de RAM, GPU com 12–16 GB de VRAM, contexto curto e uma resposta simultânea. Isso é dimensionamento inicial, não garantia de desempenho. Instalar o modelo consome vários GB e deve ser uma ação explícita da infraestrutura. Configure também a concorrência global do Ollama considerando a soma dos limites por empresa. Não conte apenas as sessões abertas: o parâmetro DiscAI limita respostas em geração.

A aplicação exige origem HTTPS pública na porta 443, sem caminho, usuário, senha ou query. Endereços privados precisam constar explicitamente em `AI_LOCAL_TRUSTED_ORIGINS` na configuração da infraestrutura de cada runtime; nunca são liberados por formulário de tenant. Para destinos públicos, resolve, valida e fixa o IP da conexão; redirects ficam desativados. A chave nunca segue em URL e é armazenada cifrada. Trocar de servidor sem uma nova chave apaga a chave anterior (ou impede a ativação).

A VM atual foi verificada em 17/10: 8 CPUs, aproximadamente 24 GB de RAM total e 4 GB disponíveis naquele momento; `nvidia-smi` não estava disponível. Não foi instalado nem carregado Qwen nesta VM compartilhada. Um servidor de inferência precisa ser disponibilizado para a homologação real.

## Execução e custo

Ollama `/api/chat`: `stream:false`, `think:false`, JSON schema com `reply`, `action`, `summary`. A resposta precisa estar concluída (`done:true`, `done_reason:stop`) e cumprir o contrato. Respostas truncadas, inválidas, 429, 5xx e timeout não são enviadas ao cliente. O fluxo existente encaminha o caso à equipe. Pedidos explícitos de humano ou descadastro continuam sendo tratados antes da inferência.

Um limite conservador por bytes evita que o servidor trunque silenciosamente as instruções obrigatórias e o conhecimento. Se a conversa exceder o contexto configurado, solicita atendimento humano em vez de perder instruções. Isso pode bloquear antes do máximo teórico do tokenizador e deve ser considerado na homologação.

Cada sessão registra provedor e modelo. O provedor de sessões em andamento não é trocado. Troca de provedor, modelo, endereço ou chave é bloqueada enquanto há conversa escrita aberta e não expirada; desabilitar o serviço é permitido. Qwen registra tokens e latência, sem aplicar tarifas OpenAI. “Infraestrutura própria” não significa custo total zero: hardware, hospedagem, operação, telefonia e WhatsApp são separados. O relatório agregado de texto estima somente tarifas de API, sem contabilizar infraestrutura própria.

## Validação

Testes automatizados de contratos, permissões, cifragem, isolamento, assinatura do gerenciamento, respostas inválidas, limites, contexto, desativação e ausência de fallback pago. Transporte HTTP exercitado contra simulação Postman do Ollama, incluindo autenticação, 429, 503, JSON inválido, resposta truncada e timeout. Não equivale a teste do modelo ou de voz real; estes dependem de servidor de inferência e posterior avaliação nos três idiomas.

Fontes de contrato: https://docs.ollama.com/api/chat, https://docs.ollama.com/capabilities/structured-outputs, https://huggingface.co/Qwen/Qwen3-8B.

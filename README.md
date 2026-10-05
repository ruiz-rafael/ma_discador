# Zyrex MA · Discador e automação

Projeto independente de Marketing Automation com editor visual de cadências, discador, WhatsApp e relatórios. Preparado para integração futura com CRMs por API e discador incorporável; o CRM não faz parte deste repositório.

## Funcionalidades

- Cadências Vue Flow: público, chamadas, intervalos, decisões, WhatsApp e encerramento, com configuração por cartão.
- Segmentos por inclusão ou regras dinâmicas: condições sobre campos e atendimento/resposta na cadência, com prévia e reavaliação da participação.
- Voz: Twilio Programmable Voice pelo navegador e integração Asterisk/SIP; limites, filas preview/progressivas, disponibilidade e tabulação.
- Jornada de retorno: tentativas configuráveis, saída ao atender/responder e mensagem personalizada após o limite de não atendimentos.
- WhatsApp: API Twilio e conector QR, templates com variáveis e botões QR experimentais, entregas, leituras, respostas e opções escolhidas.
- Segmentos: painéis laterais para cadastro manual, seleção de contatos existentes, CSV e webhooks com campos selecionáveis, exemplo de payload e validação sem importação.
- Atendimento: headset no topo com status global ou por fila, disponibilidade mantida entre telas e teclado para ligações manuais, sem iniciar cadência ou WhatsApp automático.
- Filas: origem de voz e remetente WhatsApp centralizados, telefonia API/SIP, equipe e permissões herdadas para fixos/celulares. A cadência seleciona a fila; a discagem manual autorizada independe da pausa das cadências.
- Equipe e receptivo: perfis, filas, distribuição, transferência e relatórios com gráficos e detalhe por atendimento.
- Integrações: API v1, escopos, idempotência, eventos assinados, discador incorporável e triagem de leads.
- Supervisão: dashboards por cadência, custos conhecidos, limites e diagnóstico interno de áudio com métricas WebRTC.

Implementação e homologação são estados distintos. Consulte o [roteiro vigente](docs/roteiro-proximos-marcos.md) e os [marcos 4E–6C](docs/marcos-4e-6c.md) para capacidades e pendências.

## Estrutura

| Diretório | Conteúdo |
| --- | --- |
| `source/` | Laravel, Vue, migrações, contrato OpenAPI e testes |
| `whatsapp-qr/` | Serviço Node.js/Baileys e testes do conector QR |
| `deploy/` | Dockerfiles, modelos Compose, Nginx e suporte ao PBX |
| `docs/` | Arquitetura funcional, configuração e evolução dos marcos |

O [documento balizador](docs/referencias/Projeto_SaaS_Voz_WhatsApp_IA.pdf) integra a documentação. O contrato público está em [OpenAPI](source/resources/contracts/openapi.json).

## Desenvolvimento

Ambiente utilizado: PHP 8.4, Composer 2, Node.js 22, Laravel 12, Vue 3, PostgreSQL 17 e Redis 7.4. Dependências fixadas pelos respectivos arquivos de lock. O PBX de referência usa Asterisk 22.9.

Em um ambiente local separado:

```sh
cd source
cp .env.example .env
composer install
php artisan key:generate
npm ci
npm run build
```

Configure o banco local no `.env` e execute `php artisan migrate` apenas nesse banco. PostgreSQL e Redis/Horizon são usados na operação; SQLite em memória é usado na suíte isolada. O administrador local deve ser provisionado com credenciais próprias e vinculado ao workspace apropriado. Não existe senha administrativa incluída no repositório.

Os canais dependem de credenciais e arquivos privados de configuração. Consulte [voz API/SIP](docs/voz-dois-metodos.md), [WhatsApp](docs/whatsapp-twilio.md) e [QR e botões](docs/templates-qr-botoes.md). A cópia do código não transfere sessões QR, números, autorizações ou histórico do ambiente operacional.

## Verificação

A versão de aplicação importada foi validada em 05/10/2026: **329 testes e 2.659 asserções**, tanto em SQLite quanto em PostgreSQL isolado; seis testes das estatísticas WebRTC e da verificação de microfone; compilação Vite e validação visual em desktop/celular. Para reprodução local, instale as dependências e use banco de testes separado:

```sh
cd source
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php vendor/bin/phpunit
node --test tests/audio-quality.mjs tests/microphone.mjs
npm run build
```

Os quatro arquivos de teste que referenciavam a origem de homologação foram publicados com um número fictício equivalente; a implementação da aplicação foi preservada.

Para o conector:

```sh
cd whatsapp-qr
npm ci
npm test
```

O teste de eco interno verifica o percurso navegador–PBX sem telefonar para contatos. Não substitui a homologação do headset e do áudio pela operadora.

## Implantação e conteúdo privado

Os arquivos em `deploy/` são modelos do ambiente MA existente: precisam de revisão de caminhos, domínios, rede, volumes e segredos antes do uso em outra instalação. O Compose principal pressupõe seus arquivos na raiz do ambiente de implantação, conforme [documentação operacional](docs/implantacao.md). Esta publicação no GitHub não implanta nem reinicia a aplicação.

Não foram incluídos senhas, tokens, chaves SSH, sessões WhatsApp, banco de dados, inventário da VM, evidências com dados de contatos, backups, dependências instaladas, builds ou scripts de testes reais utilizados na operação. Registros históricos privados citados nos documentos permanecem fora do Git. Exemplos sintéticos e mocks permanecem nos testes.

Alguns documentos descrevem entregas históricas. Para o estado atual, prevalece o [roteiro dos marcos](docs/roteiro-proximos-marcos.md).

O incremento operacional do marco 6C inclui custos por canal, histórico e recuperação de eventos com proteção contra respostas atrasadas, e retenção técnica reversível. Consulte [o guia operacional](docs/custos-recuperacao-retencao.md).

A API 1.1.0 acrescenta consultas de receptivo, conversas/respostas a botões e custos, com permissões explícitas. [Guia das consultas para CRM](docs/api-consultas-crm.md).

Filas independentes de campanhas, recepção por número e caixa WhatsApp com fichas por conexão estão descritas em [Operação e supervisão](docs/operacao-supervisao.md). A verificação de microfone informa ausência, permissão bloqueada ou dispositivo ocupado antes de ativar a disponibilidade.

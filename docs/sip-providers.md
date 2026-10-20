# Telefonia por operadora e número

O administrador geral entra na empresa e acessa **Cadências → Canais → Telefonia e operadoras → Configurar número**. O modal apresenta os campos da Twilio API ou da Fale Vono SIP Trunk. As credenciais de operadora são restritas à administração da plataforma; o administrador do cliente utiliza os números nas filas.

## Fale Vono

- Servidor permitido: `vono2.me`, SIP UDP 5060.
- Usuário, senha, número de saída autorizado e nome da conexão.
- Canais simultâneos e tentativas por segundo: iniciar com 1 e ajustar à contratação. Os limites gerais e da fila também se aplicam.
- Formato enviado: DDD+número, país+DDD+número ou E.164.
- A senha é criptografada na base do tenant e nunca retorna pela API. Deixar o campo vazio ao editar mantém a senha.
- Salvar não ativa filas/campanhas nem inicia chamadas. Um reconciliador privado aplica as alterações no PBX quando não há chamadas em andamento. A tela consulta o estado de registro.
- Registro válido, configuração aplicada e verificação recente são necessários para liberar chamadas. A conexão não é apresentada como pronta com registro recusado, vencido ou ainda não aplicado.
- O número pode ser escolhido em **Filas de atendimento**, telefonia **SIP Trunk · Asterisk**. O discador manual lista as origens das filas atribuídas ao agente, respeitando permissões. Não muda sua disponibilidade automaticamente.

Esta primeira integração Vono configura **saída SIP**. Recebimento de chamadas pelo tronco depende de implementação de rota receptiva própria. O status de registro não comprova áudio, bina ou autorização comercial para discagem preditiva. Esses itens precisam de homologação/validação com a operadora.

## Operação privada

`deploy/discai/sync_sip_trunks.py` deve ser instalado no host DiscAI e executado por timer. Ele consulta apenas containers deste projeto, valida a configuração em um Asterisk isolado sem rede, faz backup e aplica os arquivos. Alterações de transporte exigem reinício do PBX sem chamadas ativas. As rotas antigas são preservadas.

O comando interno `voice:sip-runtime export` contém segredos destinados ao reconciliador; não executar com saída em logs ou terminais compartilhados. Arquivos gerados e backups são privados. `voice:sip-runtime status` recebe a confirmação com hash da configuração, impedindo que uma confirmação atrasada libere uma configuração nova.

Asterisk recebe uma autorização de uso único, assinada e vinculada à chamada; o navegador nunca recebe a senha da operadora. O destino e o endpoint vêm do backend. Entrada direta no tronco é rejeitada. Transferência SIP arbitrária permanece desabilitada.

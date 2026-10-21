# Correção do receptivo Twilio — 21/10/2026

Entre 18:29 e 18:30 UTC, chamadas ao +551149330254 chegaram à Twilio e foram encaminhadas ao tronco TLS, mas não chegaram ao aplicativo. A chamada CA701e8cce21760254babbdfd981743514 registrou o erro Twilio 32011 (Unable to connect). O agente estava online e a rota estava habilitada para a fila LANÇAMENTOS da Bossa Nova.

O Asterisk listava o transporte discai-inbound-tls, mas não tinha socket escutando em 15062. A publicação Docker 5061/tcp e a lista de IPs Twilio estavam presentes. A causa exata da perda do socket ainda não foi estabelecida. Reiniciar somente o PBX, sem chamadas ativas, restabeleceu a escuta e a negociação TLS 1.2 com certificado válido.

O reconciliador agora verifica a negociação TLS real em cada execução, mesmo sem alteração de configuração. Se indisponível, tenta recuperar o PBX somente sem reservas nem canais ativos; se continuar indisponível, termina com erro em vez de anunciar sucesso. A verificação de zero canais exige correspondência da linha inteira (10 canais não equivalem a zero).

Validação: 17 testes Python de runtime, callback e recuperação; negociação TLS válida em produção. Nenhum novo código Meta foi solicitado durante o diagnóstico. A chegada de uma chamada externa ao navegador ainda precisa ser confirmada após o usuário reconectar sua operação.

Backup operacional: /srv/zyrex-ma/backups/sip-listener-20261021/sync_sip_trunks.py.

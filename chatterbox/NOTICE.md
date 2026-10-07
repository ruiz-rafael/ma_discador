# Chatterbox PT-BR no MA

Este serviço usa o modelo oficial especializado em português brasileiro, com uma voz de referência do demonstrador oficial. O Chatterbox não usa a Twilio para sintetizar áudio. Há custo de infraestrutura.

## Artefatos e licença

- Modelo: [ResembleAI/Chatterbox-Multilingual-pt-br](https://huggingface.co/ResembleAI/Chatterbox-Multilingual-pt-br), revisão `b3952f18bc2eaa72b9bd7c17d2c4653bcad4770d`, MIT.
- Implementação compatível com esse checkpoint: [demonstrador oficial PT-BR](https://huggingface.co/spaces/ResembleAI/Chatterbox-Multilingual-TTS-pt-br/tree/9e515821e826e207cd617a0fdd0223899ed108ea), MIT, preservada em `LICENSE-UPSTREAM-MIT.txt`.
- Voice encoder: `ResembleAI/chatterbox`, revisão `5bb1f6ee58e50c3b8d408bc82a6d3740c2db6e18`.
- Referência: arquivo `pt_br_f2.wav` utilizado pelo demonstrador oficial. Não representa uma identidade cadastrada pelo cliente. A interface não permite enviar amostras de outras pessoas.
- `source-manifest.json` e `model-manifest.json` fixam URLs e hashes SHA-256. O script `fetch.py` recusa conteúdo com hash diferente. Pesos e amostras não são versionados no repositório.
- A marca d'água Perth do modelo permanece no áudio.

## Execução

A imagem usa PyTorch CPU; os blocos lineares do transformer são quantizados dinamicamente para int8, mantendo o decoder e a cabeça de saída em ponto flutuante. Cada geração roda em subprocesso, liberando o modelo ao terminar. A inferência usa `language_id=pt`, temperatura 0,8, expressividade 0,5 e CFG 0,5. Não há downloads durante a inferência.

O limite inicial é de 300 caracteres preenchidos e uma geração simultânea neste motor. A inferência tem prazo de 480 segundos; o worker retorna falha explícita em erro ou interrupção. O Kokoro continua independente. O tempo total inclui carregar/quantizar o modelo e pode ser de vários minutos em CPU. Este serviço é para preparar recados, não para conversa em tempo real.

## Instalação no projeto MA

1. Baixar os arquivos com `python3 chatterbox/fetch.py chatterbox/model-manifest.json chatterbox-runtime/models`.
2. Preparar `source/storage/app/private/speech/chatterbox`, proprietário `82:82`, modo 700.
3. Usar o token privado de `speech-runtime/service.env` (`SPEECH_TOKEN`, mínimo 32 caracteres; arquivo modo 600).
4. Construir/iniciar somente o serviço `chatterbox` com `compose.yaml` e `deploy/compose.chatterbox.yaml`. Ajustar caminhos relativos se os overrides não estiverem no diretório do compose principal.
5. Adicionar `chatterbox_url: http://chatterbox:8091` ao JSON privado `source/storage/app/private/voice/speech.json`, preservando `url` e `token` do Kokoro. Esse arquivo pertence a `82:82`, modo 600.
6. Aplicar a migração aditiva de motores e publicar o frontend compilado.

Não publicar a porta 8091. O override limita a CPU a 1,5 e a memória a 3 GiB, sem swap adicional; usa raiz somente leitura e armazenamento privado. O worker aceita apenas texto, a voz pré-definida e velocidade entre 0,8 e 1,2. Não aceita caminhos ou URLs de áudio do usuário.

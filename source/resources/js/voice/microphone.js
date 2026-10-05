export function microphoneMessage(error) {
  const name=error?.name||'',message=error?.message||''
  if(['NotFoundError','DevicesNotFoundError'].includes(name)||/requested device not found/i.test(message))return 'Nenhum microfone foi encontrado. Conecte um headset ou habilite o microfone nas configurações de som do computador. Depois clique em Verificar microfone.'
  if(['NotAllowedError','PermissionDeniedError','SecurityError'].includes(name))return 'O acesso ao microfone foi bloqueado. Nas permissões deste site, permita o microfone e confira também a permissão de microfone do navegador no sistema operacional.'
  if(['NotReadableError','TrackStartError'].includes(name))return 'O microfone está ocupado ou indisponível. Feche outros aplicativos que estejam usando o áudio, reconecte o headset e tente novamente.'
  if(['OverconstrainedError','ConstraintNotSatisfiedError'].includes(name))return 'O dispositivo de áudio selecionado não está disponível. Escolha um microfone conectado como entrada padrão do navegador e do sistema operacional.'
  if(name==='AbortError')return 'A abertura do microfone foi interrompida. Confira a conexão do headset e tente novamente.'
  return message||'Não foi possível verificar o microfone. Confira as configurações de áudio do navegador.'
}
export async function checkMicrophone(mediaDevices=globalThis.navigator?.mediaDevices) {
  if(!mediaDevices?.getUserMedia)throw new Error('Abra o MA em HTTPS, em um navegador com suporte a microfone. Se estiver incorporado, o sistema integrador precisa permitir o microfone no iframe.')
  let stream
  try {
    stream=await mediaDevices.getUserMedia({audio:true,video:false})
    const tracks=stream.getAudioTracks()
    if(!tracks.length||tracks.every(t=>t.readyState==='ended'))throw Object.assign(new Error(),{name:'NotFoundError'})
    return {label:tracks[0].label||'Microfone padrão',ready:true}
  } catch(error) {throw new Error(microphoneMessage(error))}
  finally {stream?.getTracks().forEach(t=>t.stop())}
}

export async function voiceFetch(url,options) {
  try {return await fetch(url,options)}
  catch(error) {
    if(error instanceof TypeError||['AbortError','TimeoutError','NetworkError'].includes(error?.name)) {
      const failure=new Error('A conexão com o MA falhou. Verifique sua rede e aguarde a atualização antes de repetir a ação.')
      failure.name='VoiceConnectionError'
      throw failure
    }
    throw error
  }
}

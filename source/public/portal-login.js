(() => {
  const form = document.getElementById('portal-login-form');
  if (!form) return;
  const button = form.querySelector('button[type="submit"]');
  const message = document.getElementById('login-refresh-message');
  const label = button.textContent;
  let submitting = false;
  const password = form.querySelector('input[type="password"]');
  async function requestJson(url, options, timeout) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);
    try {
      const response = await fetch(url, {
        credentials: 'same-origin', cache: 'no-store', redirect: 'error', ...options,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal,
      });
      // The deadline covers the body too, not only receipt of HTTP headers.
      const data = await response.json();
      if (!response.ok) {
        if (response.status === 419) throw new Error('Sua sessão expirou. Digite sua senha e tente novamente.');
        if (response.status === 429) throw new Error('Muitas tentativas. Aguarde um minuto antes de tentar novamente.');
        if (response.status === 422) throw new Error(data.message || 'Confira o e-mail e a senha.');
        throw new Error('O serviço de acesso está indisponível. Tente novamente em instantes.');
      }
      return data;
    } finally { clearTimeout(timer); }
  }
  const reset = () => { submitting = false; button.disabled = false; button.textContent = label; };
  window.addEventListener('pageshow', reset);
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitting) return;
    submitting = true;
    button.disabled = true;
    button.textContent = 'Conectando…';
    message.hidden = true;
    try {
      // Refresh only the same-origin session token; credentials go only to the login POST.
      const data = await requestJson('/login/session', { method: 'GET' }, 10000);
      if (typeof data.token !== 'string' || !/^[A-Za-z0-9]{40}$/.test(data.token)) throw new Error('invalid_session');
      form.querySelector('input[name="_token"]').value = data.token;
      const result = await requestJson('/login', { method: 'POST', body: new FormData(form) }, 20000);
      const next = new URL(result.redirect, location.origin);
      if (next.origin !== location.origin || !/^\/(companies|t\/[a-z0-9-]+)$/.test(next.pathname)) throw new Error('invalid_destination');
      password.value = '';
      button.textContent = 'Abrindo sua empresa…';
      location.assign(next.href);
      // If navigation is interrupted, keep an explicit, safe continuation available.
      setTimeout(() => {
        message.textContent = 'Login confirmado. ';
        const link = document.createElement('a'); link.href = next.href; link.textContent = 'Continuar para minha conta';
        message.appendChild(link); message.hidden = false; reset();
      }, 8000);
    } catch (error) {
      password.value = '';
      message.textContent = /^(Sua sessão|Muitas tentativas|E-mail ou senha|O serviço|Confira o e-mail)/.test(error.message)
        ? error.message : 'Não foi possível concluir o acesso. Confira sua conexão, digite sua senha e tente novamente.';
      message.hidden = false;
      reset();
    }
  });
})();

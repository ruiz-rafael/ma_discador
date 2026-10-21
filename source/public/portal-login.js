(() => {
  const form = document.getElementById('portal-login-form');
  if (!form) return;
  const button = form.querySelector('button[type="submit"]');
  const message = document.getElementById('login-refresh-message');
  const label = button.textContent;
  let submitting = false;
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
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 10000);
      let response;
      try {
        response = await fetch('/login/session', {
          credentials: 'same-origin', cache: 'no-store', redirect: 'error',
          headers: { Accept: 'application/json' }, signal: controller.signal,
        });
      } finally { clearTimeout(timer); }
      if (!response.ok) throw new Error('session_unavailable');
      const data = await response.json();
      if (typeof data.token !== 'string' || !/^[A-Za-z0-9]{40}$/.test(data.token)) throw new Error('invalid_session');
      form.querySelector('input[name="_token"]').value = data.token;
      HTMLFormElement.prototype.submit.call(form);
    } catch (_) {
      message.textContent = 'Não foi possível atualizar sua sessão. Confira sua conexão e tente entrar novamente.';
      message.hidden = false;
      reset();
    }
  });
})();

// Experimental Native Flow support, isolated from the ordinary text transport.
// Protocol fields are present in Baileys 7.0.0-rc14; rendering still needs device QA.
export function buttons(value) {
  if (!Array.isArray(value) || value.length < 1 || value.length > 3) throw new Error('Invalid buttons');
  const ids = new Set();
  return value.map(b => {
    if (!b || !['reply', 'url'].includes(b.type) || typeof b.id !== 'string' || !/^[a-zA-Z0-9_-]{1,64}$/.test(b.id) || ids.has(b.id)) throw new Error('Invalid button ID');
    ids.add(b.id);
    if (typeof b.label !== 'string' || !b.label.trim() || [...b.label].length > 20 || /[\x00-\x1f{}]/u.test(b.label)) throw new Error('Invalid label');
    if (b.type === 'url') {
      if (typeof b.url !== 'string' || b.url.length > 1000 || /[\s{}]/u.test(b.url)) throw new Error('Invalid URL');
      const url = new URL(b.url);
      if (url.protocol !== 'https:' || url.username || url.password) throw new Error('Invalid URL');
      return {type: b.type, id: b.id, label: b.label.trim(), url: b.url};
    }
    if (b.url) throw new Error('Reply cannot have URL');
    return {type: b.type, id: b.id, label: b.label.trim()};
  });
}

export function interactiveContent(text, values) {
  const items = buttons(values);
  return {
    documentWithCaptionMessage: {message: {interactiveMessage: {
      body: {text},
      nativeFlowMessage: {buttons: items.map(b => ({
        name: b.type === 'reply' ? 'quick_reply' : 'cta_url',
        buttonParamsJson: JSON.stringify(b.type === 'reply' ? {display_text: b.label, id: b.id} : {display_text: b.label, url: b.url}),
      }))},
    }}},
  };
}

export function interactiveNodes() {
  // These are configured campaign messages, not Meta AI responses. Do not
  // attach a bot/biz_bot marker. Button rendering remains experimental.
  return [
    {tag: 'biz', attrs: {}, content: [{tag: 'interactive', attrs: {type: 'native_flow', v: '1'}, content: [{tag: 'native_flow', attrs: {v: '9', name: 'mixed'}}]}]},
  ];
}

export async function transmit(socket, to, text, id, interactive, generate) {
  if (!interactive) return socket.sendMessage(to, {text}, {messageId: id});
  if (interactive.mode !== 'experimental_buttons') throw new Error('Unknown interactive mode');
  const content = interactiveContent(text, interactive.buttons);
  const message = generate(to, content, {userJid: socket.user.id, messageId: id});
  // One attempt only. Never fall back to text after a relay error or timeout.
  return socket.relayMessage(to, message.message, {messageId: id, additionalNodes: interactiveNodes()});
}

export function inboundContent(message) {
  let content = message;
  for (let n = 0; n < 5; n++) {
    const nested = content?.documentWithCaptionMessage?.message || content?.ephemeralMessage?.message || content?.viewOnceMessage?.message || content?.viewOnceMessageV2?.message;
    if (!nested) break;
    content = nested;
  }
  let body = content?.conversation || content?.extendedTextMessage?.text || content?.imageMessage?.caption || content?.videoMessage?.caption || '';
  let reply = null;
  const native = content?.interactiveResponseMessage;
  const legacy = content?.buttonsResponseMessage || content?.templateButtonReplyMessage;
  if (native) {
    const raw = native.nativeFlowResponseMessage?.paramsJson;
    let data = {};
    if (typeof raw === 'string' && raw.length <= 8192) { try { data = JSON.parse(raw); } catch {} }
    if (typeof data?.id === 'string') reply = {id: data.id.slice(0, 64), label: String(data.display_text || native.body?.text || '').slice(0, 100), context_id: String(native.contextInfo?.stanzaId || '').slice(0, 128)};
  } else if (legacy) {
    reply = {id: String(legacy.selectedButtonId || legacy.selectedId || '').slice(0, 64), label: String(legacy.selectedDisplayText || legacy.selectedText || '').slice(0, 100), context_id: String(legacy.contextInfo?.stanzaId || '').slice(0, 128)};
  }
  if (reply) body = reply.label || reply.id;
  return {body: String(body).slice(0, 4096), ...(reply ? {reply} : {})};
}

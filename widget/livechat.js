/*!
 * LiveChat widget: one file, no dependencies.
 *
 *   <script src="/widget/livechat.js" data-api="/standalone/api.php" defer></script>
 *
 * Optional attributes on that tag:
 *   data-color="#2563eb"        button and header color
 *   data-title="Chat with us"   header text
 *   data-greeting="Hi! ..."     first message shown to the visitor
 *   data-position="left"        put it bottom-left instead of bottom-right
 */
(function () {
  'use strict';

  if (window.LiveChat) return;   // script was included twice

  // ---------- settings ----------

  var script = document.currentScript || document.querySelector('script[src*="livechat.js"]');
  var attr = function (name) { return script ? script.getAttribute(name) : null; };

  // If data-api is missing, assume the usual layout where widget/ sits next to api.php
  var api = attr('data-api') ? new URL(attr('data-api'), location.href).href
                             : new URL('../api.php', script.src).href;

  // The color ends up inside CSS, so only accept a plain hex value
  var color = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(attr('data-color') || '') ? attr('data-color') : '#2563eb';

  var cfg = {
    title: attr('data-title') || 'Chat with us',
    greeting: attr('data-greeting') || 'Hi! How can we help?',
    side: attr('data-position') === 'left' ? 'left' : 'right',
    pollMs: 3000,        // replaced by whatever the server says in /config
  };

  // white or black text, whichever is readable on the chosen color
  function textOn(hex) {
    var h = hex.slice(1);
    if (h.length === 3) h = h.replace(/./g, '$&$&');
    var n = parseInt(h, 16);
    var lum = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255;
    return lum > 0.6 ? '#111' : '#fff';
  }

  // ---------- state ----------

  var STORE_KEY = 'livechat:' + api;
  var state = {
    token: null,         // proves which conversation is ours, issued by the server
    seenId: 0,           // newest message the visitor has actually looked at
    cursor: 0,           // newest message we've downloaded
    ids: new Set(),      // message ids already on screen
    pending: {},         // client_id -> bubble, for messages we sent and haven't confirmed
    open: false,
    configLoaded: false,
    agentOnline: false,
    closed: false,       // conversation ended by the team
    unread: 0,
    polling: false,
    errors: 0,
    timer: null,
    starting: null,
    offlineSent: false,
    connFailed: false,   // couldn't ask the server whether anyone is online
  };

  // localStorage can be blocked (private mode, strict settings), so never trust it
  function loadStore() {
    try {
      var s = JSON.parse(localStorage.getItem(STORE_KEY) || '{}');
      state.token = typeof s.token === 'string' ? s.token : null;
      state.seenId = Number(s.seenId) || 0;
    } catch (e) { /* start fresh */ }
  }
  function saveStore() {
    try {
      if (state.token) localStorage.setItem(STORE_KEY, JSON.stringify({ token: state.token, seenId: state.seenId }));
      else localStorage.removeItem(STORE_KEY);
    } catch (e) { /* works without it, we just forget the chat on reload */ }
  }

  // Per tab, not per browser: a visitor who is mid-chat and clicks a link on the
  // site should still see the chat open, but a new tab starts closed.
  function wasOpen() {
    try { return sessionStorage.getItem(STORE_KEY + ':open') === '1'; } catch (e) { return false; }
  }
  function rememberOpen(on) {
    try {
      if (on) sessionStorage.setItem(STORE_KEY + ':open', '1');
      else sessionStorage.removeItem(STORE_KEY + ':open');
    } catch (e) { /* not important */ }
  }

  // ---------- talking to the server ----------

  function request(method, path, body) {
    var opts = { method: method, headers: {}, credentials: 'omit' };
    if (state.token) opts.headers['X-Chat-Token'] = state.token;
    if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(api + path, opts).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          var err = new Error((data.error && data.error.message) || 'Request failed');
          err.status = res.status;
          err.code = data.error && data.error.code;
          throw err;
        }
        return data;
      });
    });
  }

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }

  // ---------- building the interface ----------
  // Everything lives in a shadow root, so the host site's CSS can't break the
  // widget and ours can't leak out into their page.

  function h(tag, props, kids) {
    var e = document.createElement(tag);
    Object.keys(props || {}).forEach(function (k) {
      if (k === 'class') e.className = props[k];
      else if (k === 'text') e.textContent = props[k];     // always textContent, never innerHTML
      else e.setAttribute(k, props[k]);
    });
    (kids || []).forEach(function (c) { e.appendChild(c); });
    return e;
  }

  var host = h('div', { id: 'livechat-root' });
  var root = host.attachShadow({ mode: 'open' });

  var css = [
    ':host{all:initial}',
    '[hidden]{display:none!important}',
    '.wrap{--c:' + color + ';--on:' + textOn(color) + ';--bg:#fff;--fg:#111827;--muted:#6b7280;--line:#e5e7eb;--bubble:#f3f4f6;',
    '  position:fixed;bottom:20px;' + cfg.side + ':20px;z-index:2147483000;',
    '  font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--fg)}',
    '@media (prefers-color-scheme:dark){.wrap{--bg:#1f2937;--fg:#f3f4f6;--muted:#9ca3af;--line:#374151;--bubble:#374151}}',
    '*{box-sizing:border-box}',
    'button{font:inherit;cursor:pointer}',
    '.launcher{position:relative;width:56px;height:56px;border:0;border-radius:50%;background:var(--c);color:var(--on);',
    '  box-shadow:0 4px 14px #0004;display:flex;align-items:center;justify-content:center;float:' + cfg.side + '}',
    '.launcher svg{width:26px;height:26px;fill:currentColor}',
    '.badge{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;',
    '  background:#dc2626;color:#fff;font-size:12px;font-weight:700;line-height:20px;text-align:center}',
    '.panel{display:flex;flex-direction:column;width:360px;height:min(520px,calc(100vh - 110px));margin-bottom:12px;',
    '  background:var(--bg);border:1px solid var(--line);border-radius:14px;box-shadow:0 8px 30px #0004;overflow:hidden}',
    '.head{display:flex;align-items:center;gap:8px;padding:12px 14px;background:var(--c);color:var(--on)}',
    '.head .t{flex:1;min-width:0}.head strong{display:block;font-size:15px}.head small{opacity:.85}',
    '.close{border:0;background:none;color:inherit;font-size:24px;line-height:1;padding:2px 8px;border-radius:6px}',
    '.msgs{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px}',
    '.row{display:flex;flex-direction:column;max-width:82%}',
    '.row.visitor{align-self:flex-end;align-items:flex-end}.row.agent,.row.note{align-self:flex-start}',
    '.bubble{padding:8px 12px;border-radius:14px;background:var(--bubble);white-space:pre-wrap;overflow-wrap:anywhere}',
    '.row.visitor .bubble{background:var(--c);color:var(--on);border-bottom-right-radius:4px}',
    '.row.agent .bubble{border-bottom-left-radius:4px}',
    '.row.note{align-self:center;max-width:100%;text-align:center;color:var(--muted);font-size:13px}',
    '.meta{font-size:11px;color:var(--muted);margin:2px 4px 0}',
    '.row.pending .bubble{opacity:.55}',
    '.row.failed .bubble{outline:2px solid #dc2626;cursor:pointer}.row.failed .meta{color:#dc2626}',
    'form{display:flex;flex-direction:column;gap:8px;padding:12px}',
    '.composer{flex-direction:row;align-items:flex-end;border-top:1px solid var(--line)}',
    'textarea,input{width:100%;padding:9px 10px;font:inherit;color:inherit;background:var(--bg);border:1px solid var(--line);border-radius:8px}',
    'textarea{resize:none;max-height:120px}',
    'textarea:focus,input:focus{outline:2px solid var(--c);outline-offset:-1px}',
    '.send,.primary{border:0;border-radius:8px;background:var(--c);color:var(--on);padding:9px 14px;font-weight:600}',
    '.send:disabled,.primary:disabled{opacity:.5;cursor:not-allowed}',
    '.link{border:0;background:none;color:var(--c);text-decoration:underline;padding:0}',
    '.pre{display:flex;gap:6px;padding:8px 12px 0}.pre input{padding:6px 8px;font-size:13px}',
    '.conn{padding:12px;text-align:center;color:var(--muted);font-size:13px}',
    '.offline{overflow-y:auto;padding:12px}.offline p{margin:0}.err{color:#dc2626;font-size:13px;min-height:1em}',
    '@media (max-width:480px){.wrap{bottom:12px;' + cfg.side + ':12px}',
    '  .panel{position:fixed;inset:0;width:auto;height:100vh;height:100dvh;margin:0;border:0;border-radius:0;',
    '    padding-bottom:env(safe-area-inset-bottom)}.head{padding-top:calc(12px + env(safe-area-inset-top))}}',
  ].join('\n');

  var ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/></svg>';

  var el = {};
  el.title = h('strong', { text: cfg.title });
  el.status = h('small', { text: '' });
  el.close = h('button', { class: 'close', type: 'button', 'aria-label': 'Close chat', text: '\u00d7' });
  el.msgs = h('div', { class: 'msgs', 'aria-live': 'polite' });
  el.pre = h('div', { class: 'pre' }, [
    el.preName = h('input', { type: 'text', placeholder: 'Name (optional)', maxlength: '100', 'aria-label': 'Your name' }),
    el.preEmail = h('input', { type: 'email', placeholder: 'Email (optional)', maxlength: '150', 'aria-label': 'Your email' }),
  ]);
  el.input = h('textarea', { rows: '1', maxlength: '2000', placeholder: 'Type your message\u2026', 'aria-label': 'Your message' });
  el.send = h('button', { class: 'send', type: 'submit', text: 'Send' });
  el.composer = h('form', { class: 'composer' }, [el.input, el.send]);

  el.offName = h('input', { type: 'text', placeholder: 'Your name', maxlength: '100', 'aria-label': 'Your name' });
  el.offEmail = h('input', { type: 'email', placeholder: 'Your email', maxlength: '150', required: '', 'aria-label': 'Your email' });
  el.offBody = h('textarea', { rows: '4', maxlength: '2000', required: '', placeholder: 'How can we help?', 'aria-label': 'Your message' });
  el.offErr = h('div', { class: 'err', role: 'alert' });
  el.offSubmit = h('button', { class: 'primary', type: 'submit', text: 'Send message' });
  el.offline = h('form', { class: 'offline' }, [
    h('p', { text: "We're not online right now. Leave a message and we'll reply by email." }),
    el.offName, el.offEmail, el.offBody, el.offErr, el.offSubmit,
  ]);
  el.conn = h('div', { class: 'conn', role: 'status', hidden: '', text: "We can't reach support right now. Please try again in a moment." });
  el.thanks = h('div', { class: 'offline' }, [h('p', { text: 'Thanks! Your message is on its way. We\u2019ll reply by email.' })]);

  el.badge = h('span', { class: 'badge', hidden: '' });
  el.launcher = h('button', { class: 'launcher', type: 'button', 'aria-label': 'Open chat', 'aria-expanded': 'false' }, [el.badge]);
  el.launcher.insertAdjacentHTML('afterbegin', ICON);   // constant markup, no user input

  el.panel = h('section', { class: 'panel', role: 'dialog', 'aria-label': cfg.title, hidden: '' }, [
    h('div', { class: 'head' }, [h('div', { class: 't' }, [el.title, el.status]), el.close]),
    el.msgs, el.conn, el.thanks, el.offline, el.pre, el.composer,
  ]);

  root.appendChild(h('style', { text: css }));
  root.appendChild(h('div', { class: 'wrap' }, [el.panel, el.launcher]));

  // ---------- showing the right thing ----------

  // Offline form only for someone who hasn't started a chat yet.
  // Anyone already chatting keeps the chat view.
  function render() {
    var hasChat = !!state.token;
    var showOffline = !hasChat && state.configLoaded && !state.agentOnline && !state.offlineSent;
    var showThanks = !hasChat && state.offlineSent;
    var showChat = !showOffline && !showThanks;
    // new visitor and the server hasn't said yet whether anyone is online:
    // showing the chat box now would just make it flicker into the offline form
    var waiting = !hasChat && !state.configLoaded;

    el.offline.hidden = !showOffline;
    el.thanks.hidden = !showThanks;
    el.msgs.hidden = !showChat;
    el.conn.hidden = !(waiting && state.connFailed);
    el.composer.hidden = !showChat || waiting || state.closed;
    el.pre.hidden = !showChat || waiting || hasChat || state.closed;

    el.status.textContent = !state.configLoaded ? '' : state.agentOnline ? "We're online" : "We're offline";

    var n = state.unread;
    el.badge.hidden = n < 1 || state.open;
    el.badge.textContent = n > 9 ? '9+' : String(n);
  }

  function note(text, action) {
    var row = h('div', { class: 'row note' }, [h('span', { text: text })]);
    if (action) {
      row.appendChild(document.createTextNode(' '));
      row.appendChild(h('button', { class: 'link', type: 'button', text: action.label }));
      row.lastChild.addEventListener('click', action.run);
    }
    el.msgs.appendChild(row);
    scrollDown(true);
    return row;
  }

  function scrollDown(force) {
    var m = el.msgs;
    if (force || m.scrollHeight - m.scrollTop - m.clientHeight < 80) m.scrollTop = m.scrollHeight;
  }

  function clock(iso) {
    var d = new Date(iso);
    return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  // ---------- messages ----------

  function bubble(m, extraClass) {
    var row = h('div', { class: 'row ' + m.sender + (extraClass ? ' ' + extraClass : '') }, [
      h('div', { class: 'bubble', text: m.body }),
      h('div', { class: 'meta', text: m.created_at ? clock(m.created_at) : 'Sending\u2026' }),
    ]);
    el.msgs.appendChild(row);
    scrollDown(m.sender === 'visitor');
    return row;
  }

  // One message arrived from the server (by polling, or as the answer to our own send)
  function receive(m) {
    if (state.ids.has(m.id)) return;
    state.ids.add(m.id);

    var mine = m.client_id && state.pending[m.client_id];
    if (mine) {                                    // our own message coming back: confirm it
      delete state.pending[m.client_id];
      mine.classList.remove('pending', 'failed');
      mine.lastChild.textContent = clock(m.created_at);
    } else {
      bubble(m);
    }

    if (m.sender === 'agent' && m.id > state.seenId && !(state.open && !document.hidden)) {
      state.unread++;
    }
  }

  function markSeen() {
    state.unread = 0;
    if (state.cursor > state.seenId) {
      state.seenId = state.cursor;
      saveStore();
    }
    render();
  }

  // ---------- polling ----------
  // Chained timeouts, not setInterval, so a slow request can't pile up behind itself.

  function schedule() {
    clearTimeout(state.timer);
    if (!state.token) return;
    // chill out when nobody's looking: 5x slower with the chat closed, 10x with the tab hidden
    var base = document.hidden ? cfg.pollMs * 10 : state.open ? cfg.pollMs : cfg.pollMs * 5;
    var delay = Math.min(60000, base * Math.pow(2, state.errors));          // back off while the server is struggling
    state.timer = setTimeout(poll, delay);
  }

  function poll() {
    if (!state.token || state.polling) return;
    state.polling = true;

    request('GET', '/messages?after=' + state.cursor).then(function (data) {
      state.errors = 0;
      state.agentOnline = !!data.agent_online;
      data.messages.forEach(receive);
      state.cursor = Math.max(state.cursor, data.next_after || 0);
      if (data.status === 'closed') endConversation();
      if (state.open && !document.hidden) markSeen(); else render();
    }).catch(function (err) {
      if (err.code === 'invalid_token') return resetConversation();    // the server forgot us
      state.errors++;
    }).then(function () {
      state.polling = false;
      schedule();
    });
  }

  function endConversation() {
    if (state.closed) return;
    state.closed = true;
    note('This conversation has ended.', { label: 'Start a new chat', run: resetConversation });
    render();
  }

  function resetConversation() {
    clearTimeout(state.timer);
    state.token = null; state.cursor = 0; state.seenId = 0; state.unread = 0;
    state.closed = false; state.errors = 0; state.polling = false;
    state.ids = new Set(); state.pending = {};
    saveStore();
    el.msgs.textContent = '';
    greet();
    loadConfig().then(function () { render(); });
    render();
  }

  // ---------- sending ----------

  function ensureConversation() {
    if (state.token) return Promise.resolve();
    if (state.starting) return state.starting;     // two quick sends must not open two conversations

    state.starting = request('POST', '/conversations', {
      name: el.preName.value.trim(),
      email: el.preEmail.value.trim(),
      page_url: location.href,
      user_agent: navigator.userAgent,
    }).then(function (data) {
      state.token = data.visitor_token;
      saveStore();
      render();
    }).then(function () { state.starting = null; }, function (e) { state.starting = null; throw e; });

    return state.starting;
  }

  function sendMessage(text, clientId, row) {
    row.classList.add('pending');
    row.classList.remove('failed');
    row.lastChild.textContent = 'Sending\u2026';

    ensureConversation().then(function () {
      return request('POST', '/messages', { body: text, client_id: clientId });
    }).then(function (data) {
      receive(data.message);
      schedule();
    }).catch(function (err) {
      if (err.code === 'conversation_closed') {
        row.remove();
        delete state.pending[clientId];
        return endConversation();
      }
      if (err.code === 'invalid_token') return resetConversation();

      row.classList.remove('pending');
      row.classList.add('failed');
      row.lastChild.textContent = err.code === 'rate_limited' ? 'Too fast, wait a moment. Tap to retry.'
                                : err.code === 'message_too_long' ? 'Too long. Tap to edit and retry.'
                                : 'Not sent. Tap to retry.';
    });
  }

  function submitMessage() {
    var text = el.input.value.trim();
    if (!text || state.closed) return;

    var email = el.preEmail.value.trim();
    if (!state.token && email && !/^\S+@\S+\.\S+$/.test(email)) {
      el.preEmail.focus();
      return;
    }

    var clientId = uuid();
    var row = bubble({ sender: 'visitor', body: text });
    state.pending[clientId] = row;
    // tapping a failed bubble sends it again with the same id, so the server never stores it twice
    row.addEventListener('click', function () {
      if (row.classList.contains('failed')) sendMessage(text, clientId, row);
    });

    el.input.value = '';
    autosize();
    sendMessage(text, clientId, row);
  }

  function autosize() {
    el.input.style.height = 'auto';
    el.input.style.height = Math.min(el.input.scrollHeight, 120) + 'px';
  }

  el.composer.addEventListener('submit', function (e) { e.preventDefault(); submitMessage(); });
  el.input.addEventListener('input', autosize);
  el.input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); submitMessage(); }
  });

  // ---------- leave a message (nobody online) ----------

  el.offline.addEventListener('submit', function (e) {
    e.preventDefault();
    el.offErr.textContent = '';
    el.offSubmit.disabled = true;

    request('POST', '/offline', {
      name: el.offName.value.trim(), email: el.offEmail.value.trim(), body: el.offBody.value.trim(),
    }).then(function () {
      state.offlineSent = true;
      render();
    }).catch(function (err) {
      el.offErr.textContent = err.status ? err.message : 'Could not reach the server. Please try again.';
    }).then(function () { el.offSubmit.disabled = false; });
  });

  // ---------- opening and closing ----------

  function greet() {
    bubble({ sender: 'agent', body: cfg.greeting });
  }

  function loadConfig() {
    state.connFailed = false;
    return request('GET', '/config').then(function (data) {
      cfg.pollMs = Math.max(1000, Number(data.transport && data.transport.interval_ms) || 3000);
      state.agentOnline = !!data.agent_online;
      state.configLoaded = true;
    }).catch(function () {
      state.configLoaded = false;      // try again next time the panel opens
      state.connFailed = true;
    });
  }

  function open(quiet) {
    if (state.open) return;
    state.open = true;
    rememberOpen(true);
    el.panel.hidden = false;
    el.launcher.setAttribute('aria-expanded', 'true');
    el.launcher.setAttribute('aria-label', 'Close chat');
    render();

    (state.configLoaded ? Promise.resolve() : loadConfig()).then(function () {
      render();
      if (state.token) poll();
    });
    markSeen();
    // don't grab the keyboard when we re-open by ourselves on page load
    if (!quiet) setTimeout(function () { if (!el.composer.hidden) el.input.focus(); }, 0);
    scrollDown(true);
    schedule();
  }

  function close() {
    if (!state.open) return;
    state.open = false;
    rememberOpen(false);
    el.panel.hidden = true;
    el.launcher.setAttribute('aria-expanded', 'false');
    el.launcher.setAttribute('aria-label', 'Open chat');
    render();
    schedule();
    el.launcher.focus();
  }

  function toggle() { state.open ? close() : open(); }

  el.launcher.addEventListener('click', toggle);
  el.close.addEventListener('click', close);
  el.panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) return schedule();
    if (state.token) { state.errors = 0; poll(); }     // back on the tab: catch up right away
    if (state.open) markSeen();
  });

  // ---------- start ----------

  function start() {
    loadStore();
    greet();
    document.body.appendChild(host);
    render();
    if (state.token) {
      if (wasOpen()) open(true);      // they were mid-chat on the previous page
      else loadConfig().then(function () { poll(); });   // otherwise just fetch history and count what they missed
    }
  }

  window.LiveChat = { open: function () { open(false); }, close: close, toggle: toggle };

  if (document.body) start();
  else document.addEventListener('DOMContentLoaded', start);
})();

/* roundcube plugin_manager: ACE auto-attacher (loop-proof)
 * Path: plugins/plugin_manager/assets/pm-ace-auto.js
 * This file is intentionally self-contained and conservative. It will:
 *  - watch for a large visible <textarea> in the plugin manager UI
 *  - bind an ACE editor once, then disconnect the observer to avoid loops
 *  - keep the underlying <textarea> in sync for form submission
 *  - avoid loading themes/modes to prevent 404s (falls back to defaults)
 */
/* pm-ace-auto: adopt plugin's Ace, keep it writable, and force-save edited text */
(function () {
  'use strict';
  if (window.__pmAceAutoV3__) return;
  window.__pmAceAutoV3__ = true;

  // Public debug surface
  window.pmAce = {
    editor: null,
    host: null,
    hidden: null,
    names: [],
    debug: false,
    hooks: { xhr: false, fetch: false }
  };
  const log = (...a) => window.pmAce.debug && console.log('pm-ace:', ...a);

  // ------------------------
  // Helpers
  // ------------------------
  const visible = el => !!(el && el.offsetParent && getComputedStyle(el).visibility !== 'hidden');
  const $all = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const isSaveUrl = url => /plugin\.plugin_manager(\.|\/)save_config/.test(String(url || ''));

  function findPluginAceHost() {
    const els = $all('.ace_editor').filter(visible);
    if (!els.length) return null;
    els.sort((a,b) => (b.clientWidth * b.clientHeight) - (a.clientWidth * a.clientHeight));
    return els[0];
  }

  function candidateFields(scope) {
    const root = scope || document;
    const list = []
      .concat($all('textarea[name="config"]', root))
      .concat($all('input[type="hidden"][name="config"]', root))
      .concat($all('textarea[name="content"]', root))
      .concat($all('input[type="hidden"][name="content"]', root))
      .concat($all('textarea[name]', root))
      .concat($all('input[type="hidden"][name]', root));
    // unique by name, prefer visible or within same form as host
    const out = [];
    const seen = new Set();
    for (const el of list) {
      const nm = el.getAttribute('name');
      if (!nm || seen.has(nm)) continue;
      seen.add(nm);
      out.push(el);
    }
    return out;
  }

  function ensureWritableEditor(ed) {
    try {
      ed.setReadOnly(false);
      ed.setOptions({ readOnly: false });
      const ti = ed.textInput && ed.textInput.getElement && ed.textInput.getElement();
      if (ti) {
        ti.disabled = false; ti.readOnly = false;
        ti.removeAttribute('disabled'); ti.removeAttribute('readonly');
        ti.style.pointerEvents = 'auto';
      }
      const stop = e => { e.stopPropagation(); e.stopImmediatePropagation?.(); };
      ed.container.addEventListener('keydown', stop);
      if (ti) ti.addEventListener('keydown', stop);
      const style = document.createElement('style');
      style.textContent = `
        .ace_editor, .ace_editor * { pointer-events: auto !important; }
        .ace_text-input { pointer-events: auto !important; }
      `;
      document.head.appendChild(style);
    } catch (e) {
      console.warn('pm-ace: ensureWritable failed', e);
    }
  }

  function findConfigFieldNear(el) {
    const form = el && el.closest ? el.closest('form') : null;
    // Prefer fields inside the same form/dialog
    const local = candidateFields(form || (el ? el.parentNode : null));
    if (local.length) return local[0];
    // Fallback: any candidate in document
    const any = candidateFields(document);
    return any[0] || null;
  }

  function syncHiddenFromEditor(ed, hidden) {
    if (!ed || !hidden) return;
    const push = () => { try { hidden.value = ed.getValue(); } catch (_) {} };
    try { hidden.removeAttribute('disabled'); hidden.removeAttribute('readonly'); hidden.disabled = false; hidden.readOnly = false; } catch (_) {}
    push();
    ed.session.off && ed.session.off('change', push); // avoid dupes
    ed.session.on('change', push);
  }

  // Maintain list of name keys we should force into the request
  function refreshNameList(host, hidden) {
    const names = new Set(window.pmAce.names || []);
    // Local first
    for (const el of candidateFields(host?.closest?.('form') || host?.parentNode || document)) {
      const nm = el.getAttribute('name');
      if (!nm) continue;
      // Heuristic: favor typical config keys
      if (/^(config|content|data|source|file|filedata|body)$/i.test(nm) || el === hidden) {
        names.add(nm);
      }
    }
    // Always include common names
    ['config','content','data','source','file','filedata','body'].forEach(n => names.add(n));
    window.pmAce.names = Array.from(names);
    log('names:', window.pmAce.names);
  }

  // ------------------------
  // Adopt existing Ace
  // ------------------------
  function attachToExistingAce() {
    if (!window.ace) return false;
    const host = findPluginAceHost();
    if (!host) return false;

    host.removeAttribute('data-readonly');
    host.removeAttribute('aria-readonly');

    const ed = ace.edit(host);
    window.pmAce.editor = ed;
    window.pmAce.host = host;

    ensureWritableEditor(ed);

    const hidden = findConfigFieldNear(host);
    if (hidden) {
      window.pmAce.hidden = hidden;
      syncHiddenFromEditor(ed, hidden);
    }
    refreshNameList(host, hidden);

    setTimeout(() => { try { ed.focus(); } catch(_) {} }, 30);

    log('adopted Ace');
    return true;
  }

  function attachIfNeeded() {
    if (window.pmAce.host && document.contains(window.pmAce.host)) return;
    attachToExistingAce();
  }

  // ------------------------
  // Failsafe: patch XHR & fetch to inject editor value into save payload
  // ------------------------
  (function patchXHR() {
    if (window.pmAce.hooks.xhr) return;
    const origOpen = XMLHttpRequest.prototype.open;
    const origSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
      this.__pmURL = url;
      return origOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function (body) {
      try {
        if (isSaveUrl(this.__pmURL) && window.pmAce.editor) {
          const val = window.pmAce.editor.getValue();
          // Update FormData
          if (body instanceof FormData) {
            const keys = window.pmAce.names || [];
            if (typeof body.set === 'function') {
              let touched = false;
              for (const k of keys) {
                if (body.has(k)) { body.set(k, val); touched = true; }
              }
              if (!touched) body.set(keys[0] || 'config', val);
            } else {
              // Rebuild (rarely needed)
              const fd = new FormData();
              const seen = new Set();
              for (const [k, v] of body.entries()) {
                if ((window.pmAce.names || []).includes(k) && !seen.has(k)) {
                  fd.append(k, val); seen.add(k);
                } else {
                  fd.append(k, v);
                }
              }
              body = fd;
            }
            log('xhr payload (FormData) injected');
          }
          // Update URL-encoded string
          else if (typeof body === 'string') {
            const params = new URLSearchParams(body);
            let any = false;
            for (const k of (window.pmAce.names || [])) {
              if (params.has(k)) { params.set(k, val); any = true; }
            }
            if (!any) {
              params.set((window.pmAce.names && window.pmAce.names[0]) || 'config', val);
            }
            body = params.toString();
            log('xhr payload (urlencoded) injected');
          }
        }
      } catch (e) {
        console.warn('pm-ace: xhr inject failed', e);
      }
      return origSend.call(this, body);
    };

    window.pmAce.hooks.xhr = true;
  })();

  (function patchFetch() {
    if (window.pmAce.hooks.fetch || !window.fetch) return;
    const origFetch = window.fetch;
    window.fetch = function (input, init) {
      try {
        const url = (typeof input === 'string') ? input : (input && input.url);
        if (isSaveUrl(url) && window.pmAce.editor) {
          const val = window.pmAce.editor.getValue();
          init = init || {};
          // Headers/content-type detection
          const ct = (init.headers && (init.headers['Content-Type'] || init.headers['content-type'])) || '';
          // FormData body
          if (init.body instanceof FormData) {
            const keys = window.pmAce.names || [];
            if (typeof init.body.set === 'function') {
              let touched = false;
              for (const k of keys) {
                if (init.body.has(k)) { init.body.set(k, val); touched = true; }
              }
              if (!touched) init.body.set(keys[0] || 'config', val);
            }
          }
          // URL-encoded string
          else if (typeof init.body === 'string' && /application\/x-www-form-urlencoded/i.test(ct)) {
            const params = new URLSearchParams(init.body);
            let any = false;
            for (const k of (window.pmAce.names || [])) {
              if (params.has(k)) { params.set(k, val); any = true; }
            }
            if (!any) params.set((window.pmAce.names && window.pmAce.names[0]) || 'config', val);
            init.body = params.toString();
          }
          // JSON body
          else if (typeof init.body === 'string' && /application\/json/i.test(ct)) {
            try {
              const obj = JSON.parse(init.body);
              const keys = window.pmAce.names || ['config','content','data'];
              for (const k of keys) if (k in obj) obj[k] = val;
              init.body = JSON.stringify(obj);
            } catch (_) {}
          }
          window.pmAce.hooks.fetch = true;
          log('fetch payload injected');
        }
      } catch (e) {
        console.warn('pm-ace: fetch inject failed', e);
      }
      return origFetch.call(this, input, init);
    };
  })();

  // ------------------------
  // Observe for dialog/editor mount/unmount
  // ------------------------
  function start() {
    attachIfNeeded();
    const root = document.body || document.documentElement;
    if (!root) return;
    const mo = new MutationObserver(() => {
      // If our editor host disappeared, try to re-adopt
      if (!window.pmAce.host || !document.contains(window.pmAce.host)) {
        attachIfNeeded();
      }
    });
    mo.observe(root, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }

  // ------------------------
  // Console helper
  // ------------------------
  window.pmAceDebug = function () {
    const host = findPluginAceHost();
    const ed = window.pmAce.editor;
    const ti = ed && ed.textInput && ed.textInput.getElement && ed.textInput.getElement();
    return {
      ace: !!window.ace,
      hostFound: !!host,
      adopted: !!ed,
      readOnly: ed ? ed.getReadOnly() : null,
      textInputDisabled: !!(ti && ti.disabled),
      hiddenSynced: !!window.pmAce.hidden,
      editorsOnPage: $all('.ace_editor').length,
      names: window.pmAce.names,
      hooks: window.pmAce.hooks
    };
  };
})();

// ---- PATCH: Force-sync Ace value into the form exactly before save ----
(function hookSave(){
  if (!window.rcmail || window.__pmAceHookedSave__) return;
  window.__pmAceHookedSave__ = true;

  function preSaveSync(){
    try{
      const ed = window.pmAce && window.pmAce.editor;
      if (!ed) return;
      const val = ed.getValue();

      // Limit to the open dialog if present
      const dlg = Array.from(document.querySelectorAll('.ui-dialog')).find(d => d.offsetParent) || document;

      // Find plausible config/content fields
      const fields = Array.from(dlg.querySelectorAll('textarea, input[type="hidden"], input[type="text"]'));
      const names = new Set(window.pmAce.names || []);

      for (const el of fields){
        const nm  = el.getAttribute('name') || '';
        const cur = el.value || '';

        const isPath = (
          /^(file|path|target|plugin|name)$/i.test(nm) ||
          (/^(\/|[A-Za-z]:\\|[a-z0-9_\-]+\/)/.test(cur) && !/\n/.test(cur))
        );

        const looksLikeConfig =
          el.tagName === 'TEXTAREA' ||
          /^(config|content|data|body|source|filedata)$/i.test(nm) ||
          /\n/.test(cur) || cur.length > 100;

        if (!isPath && looksLikeConfig){
          // Make sure it will serialize
          el.removeAttribute('disabled');
          el.disabled  = false;
          el.readOnly  = false;

          // Push Ace value
          el.value = val;

          // Let any observers know
          el.dispatchEvent(new Event('input',  { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));

          if (nm) names.add(nm);
        }
      }

      window.pmAce.names = Array.from(names);
      if (window.pmAce.debug) console.log('pm-ace: preSaveSync', { names: window.pmAce.names });
    } catch (e) {
      console.warn('pm-ace preSaveSync failed', e);
    }
  }

  // Intercept the save command
  const origCmd = rcmail.command;
  rcmail.command = function(name, props, obj, ev){
    if (name === 'plugin.plugin_manager.save_config') {
      preSaveSync();
    }
    return origCmd.apply(this, arguments);
  };
})();

// --- pm-ace: Ensure save payload includes the Ace value (XHR + fetch) ---
(function pmAceWireSavePayload(){
  if (window.__pmAceWireSavePayload__) return;
  window.__pmAceWireSavePayload__ = true;

  const DBG = () => !!(window.pmAce && window.pmAce.debug);

  function getAceVal() {
    try {
      return (window.pmAce && window.pmAce.editor) ? window.pmAce.editor.getValue() : null;
    } catch { return null; }
  }

  function isSaveActionFromData(fd) {
    const a = fd.get('_action') || fd.get('action');
    return a === 'plugin.plugin_manager.save_config';
  }

  function upsertKeyInFormData(fd, val) {
    const keys = ['config','content','data','body','text'];
    let used = null;
    for (const k of keys) {
      if (fd.has(k)) { fd.set(k, val); used = k; break; }
    }
    if (!used) { fd.set('config', val); used = 'config'; }
    return used;
  }

  function rewriteUrlParamsStr(qs, val) {
    if (!qs || typeof qs !== 'string') return qs;
    if (!/_action=plugin\.plugin_manager\.save_config/.test(qs)) return qs;

    const keys = ['config','content','data','body','text'];
    let hit = false;

    qs = qs.replace(
      new RegExp('(?:^|&)(?:' + keys.join('|') + ')=([^&]*)', 'g'),
      (m, v, off, full) => {
        hit = true;
        const eq = m.indexOf('='); // keep original key
        return m.slice(0, eq + 1) + encodeURIComponent(val);
      }
    );
    if (!hit) {
      qs += (qs.includes('&') ? '&' : '') + 'config=' + encodeURIComponent(val);
    }
    return qs;
  }

  // ---- XMLHttpRequest hook ----
  const origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function(body){
    try {
      const val = getAceVal();
      if (val != null) {
        if (typeof body === 'string') {
          const newBody = rewriteUrlParamsStr(body, val);
          if (DBG() && newBody !== body) console.log('pm-ace: XHR string body patched');
          body = newBody;
        } else if (body instanceof FormData) {
          if (isSaveActionFromData(body)) {
            const used = upsertKeyInFormData(body, val);
            if (DBG()) console.log('pm-ace: XHR FormData patched key=', used);
          }
        } else if (body instanceof URLSearchParams) {
          if ((body.get('_action') || body.get('action')) === 'plugin.plugin_manager.save_config') {
            const keys = ['config','content','data','body','text'];
            let used = null;
            for (const k of keys) {
              if (body.has(k)) { body.set(k, val); used = k; break; }
            }
            if (!used) { body.set('config', val); used = 'config'; }
            if (DBG()) console.log('pm-ace: XHR URLSearchParams patched key=', used);
          }
        }
      }
    } catch (e) { console.warn('pm-ace: XHR patch failed', e); }
    return origSend.call(this, body);
  };

  // ---- fetch() hook ----
  const origFetch = window.fetch && window.fetch.bind(window);
  if (origFetch) {
    window.fetch = function(input, init){
      try {
        const i = init || {};
        const val = getAceVal();
        if (val != null && i && 'body' in i && i.body) {
          // Try to detect action in URL or body
          const url = (typeof input === 'string') ? input : (input && input.url) || '';
          const urlSaysSave = /_action=plugin\.plugin_manager\.save_config/.test(url);

          if (typeof i.body === 'string') {
            if (urlSaysSave || /_action=plugin\.plugin_manager\.save_config/.test(i.body)) {
              const nb = rewriteUrlParamsStr(i.body, val);
              if (DBG() && nb !== i.body) console.log('pm-ace: fetch string body patched');
              i.body = nb;
            }
          } else if (i.body instanceof URLSearchParams) {
            const act = i.body.get('_action') || i.body.get('action');
            if (urlSaysSave || act === 'plugin.plugin_manager.save_config') {
              const keys = ['config','content','data','body','text'];
              let used = null;
              for (const k of keys) { if (i.body.has(k)) { i.body.set(k,val); used=k; break; } }
              if (!used) { i.body.set('config', val); used = 'config'; }
              if (DBG()) console.log('pm-ace: fetch URLSearchParams patched key=', used);
            }
          } else if (i.body instanceof FormData) {
            if (urlSaysSave || isSaveActionFromData(i.body)) {
              const used = upsertKeyInFormData(i.body, val);
              if (DBG()) console.log('pm-ace: fetch FormData patched key=', used);
            }
          }
        }
      } catch (e) { console.warn('pm-ace: fetch patch failed', e); }
      return origFetch(input, init);
    };
  }

  // Extra: also run just before the save command, to keep DOM field in sync
  if (window.rcmail) {
    const origCmd = rcmail.command;
    rcmail.command = function(name){
      if (name === 'plugin.plugin_manager.save_config') {
        try {
          const ed = window.pmAce && window.pmAce.editor;
          const dlg = Array.from(document.querySelectorAll('.ui-dialog')).find(d => d.offsetParent) || document;
          if (ed) {
            const val = ed.getValue();
            const fields = Array.from(dlg.querySelectorAll('textarea,input[type="hidden"],input[type="text"]'));
            for (const el of fields) {
              if (el.name && !/^(file|path|target|plugin|name)$/i.test(el.name)) {
                el.removeAttribute('disabled'); el.disabled = false; el.readOnly = false;
                el.value = val;
                el.dispatchEvent(new Event('input', {bubbles:true}));
                el.dispatchEvent(new Event('change',{bubbles:true}));
              }
            }
            if (DBG()) console.log('pm-ace: DOM preSave sync fired');
          }
        } catch (e) { console.warn('pm-ace: preSave sync failed', e); }
      }
      return origCmd.apply(this, arguments);
    };
  }
})();

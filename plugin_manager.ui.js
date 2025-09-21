/* Plugin Manager UI (ACE editor + CSRF-safe I/O)
   - Opens ACE modal to edit config files
   - GET loads config from config.inc.php (fallback .dist/.sample)
   - POST saves to config.inc.php
   - Adds Roundcube CSRF token to all requests
   - Uses Roundcube toasts with translatable messages
*/

(function () {
  const RC = window.rcmail || window.rcmail || {};
  const TOKEN = (RC.env && RC.env.request_token) ? RC.env.request_token : null;
  const COMM = (RC.env && RC.env.comm_path) ? RC.env.comm_path : (window.location.search || '');
  function t(key, fallback) {
    try {
      if (RC.gettext) {
        // Try plugin domain first
        const msg = RC.gettext(key, 'plugin_manager');
        if (msg && msg !== key) return msg;
        // Try without domain (core)
        const core = RC.gettext(key);
        if (core && core !== key) return core;
      }
    } catch (e) {}
    return fallback || key;
  }

  function toast(message, type) {
    if (RC.display_message) {
      // Roundcube v1.5+: display_message(msg, type, timeout)
      RC.display_message(String(message), String(type || 'notice'), 4000);
      return;
    }
    if (RC.show_message) { // older API
      RC.show_message(String(message), String(type || 'notice'));
      return;
    }
    // fallback
    (type === 'error') ? alert(message) : console.log('[PM]', type || 'notice', message);
  }

  function buildUrl(action, params) {
    const u = new URL(window.location.href);
    // Keep base path, rebuild query
    const qs = new URLSearchParams(COMM && COMM.startsWith('?') ? COMM.slice(1) : (COMM || ''));
    if (!qs.get('_task')) qs.set('_task', 'settings');
    qs.set('_action', action);
    if (TOKEN && !qs.get('_token')) qs.set('_token', TOKEN);
    if (params) {
      Object.keys(params).forEach(k => {
        if (params[k] !== undefined && params[k] !== null) qs.set(k, params[k]);
      });
    }
    u.search = '?' + qs.toString();
    return u.toString();
  }

  function jsonOrText(resp) {
    const ct = resp.headers.get('content-type') || '';
    if (ct.includes('application/json')) return resp.json();
    return resp.text().then(t => {
      // try JSON anyway
      try { return JSON.parse(t); } catch (e) { return { ok:false, _raw:t, _nonjson:true }; }
    });
  }

  function showModal(title, initial, onSave) {
    // modal compatible with Roundcube markup, narrower sizing
    let wrap = document.getElementById('pm-ace-modal');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'pm-ace-modal';
      wrap.style.position = 'fixed';
      wrap.style.inset = '10vh 10vw';           // ~80vh x 80vw viewport box
      wrap.style.maxWidth = '900px';            // cap width for narrower feel
      wrap.style.margin = '0 auto';
      wrap.style.background = 'var(--pm-bg, #fff)';
      wrap.style.border = '1px solid #888';
      wrap.style.borderRadius = '8px';
      wrap.style.boxShadow = '0 10px 30px rgba(0,0,0,.25)';
      wrap.style.zIndex = 9999;
      wrap.style.display = 'flex';
      wrap.style.flexDirection = 'column';
      wrap.style.overflow = 'hidden';
      wrap.innerHTML = [
        '<div style="padding:8px 12px; display:flex; align-items:center; gap:8px; border-bottom:1px solid #ddd;">',
          '<strong id="pm-ace-title" style="flex:1 1 auto; min-width:0;"></strong>',
          '<button type="button" id="pm-ace-close" class="button">', t('close','Close') ,'</button>',
        '</div>',
        '<div id="pm-ace-editor" style="flex:1 1 auto; min-height: 150px; height:60vh;"></div>',
        '<div style="padding:8px 12px; border-top:1px solid #ddd; display:flex; gap:8px; justify-content:flex-end;">',
          '<button type="button" id="pm-ace-save" class="button main">', t('save','Save') ,'</button>',
        '</div>'
      ].join('');
      document.body.appendChild(wrap);
      document.getElementById('pm-ace-close').addEventListener('click', () => wrap.remove());
    }
    document.getElementById('pm-ace-title').textContent = title || t('edit_config','Edit config');
    const ediv = document.getElementById('pm-ace-editor');
    ediv.textContent = initial || '';

    function mountAce() {
      if (window.ace && ediv && !ediv._ace) {
        const editor = window.ace.edit(ediv);
        ediv._ace = editor;
        editor.session.setMode('ace/mode/php');
        const theme = (RC.env && RC.env.pm_ace_theme && RC.env.pm_ace_theme !== 'auto')
          ? RC.env.pm_ace_theme
          : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? (RC.env && RC.env.pm_ace_dark_theme) || 'ace/theme/dracula' : (RC.env && RC.env.pm_ace_light_theme) || 'ace/theme/github');
        if (theme && !theme.startsWith('ace/theme/')) {
          editor.setTheme('ace/theme/' + theme);
        } else if (theme) {
          editor.setTheme(theme);
        }
        editor.setValue(initial || '', -1);
        editor.session.setUseWrapMode(true);
        editor.setOptions({fontSize: '12px'});
        return editor;
      }
      return null;
    }

    let editor = mountAce();
    if (!editor) {
      // lazy load ACE from env
      const base = (RC.env && RC.env.pm_ace_base) ? RC.env.pm_ace_base : 'plugins/plugin_manager/assets/ace';
      const s = document.createElement('script');
      s.src = base.replace(/\/+$/,'') + '/ace.js';
      s.onload = () => { editor = mountAce(); };
      document.head.appendChild(s);
    }

    const saveBtn = document.getElementById('pm-ace-save');
    saveBtn.onclick = () => {
      if (!editor) { toast(t('editor_not_ready','Editor not ready yet'), 'error'); return; }
      const content = editor.getValue();
      const old = saveBtn.textContent;
      saveBtn.disabled = true;
      saveBtn.textContent = t('saving','Saving...');
      onSave(content, wrap, saveBtn, old);
    };
  }

  function loadConfig(plug) {
    const url = buildUrl('plugin.plugin_manager.load_config', { _pm_plug: plug, _: Date.now() });
    return fetch(url, {
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-Roundcube-Request': '1'
      }
    }).then(jsonOrText);
  }

  function saveConfig(plug, content) {
    const url = buildUrl('plugin.plugin_manager.save_config');
    const fd = new FormData();
    fd.append('_pm_plug', plug);
    fd.append('_pm_content', content);
    if (TOKEN) fd.append('_token', TOKEN);
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-Roundcube-Request': '1'
      },
      body: fd
    }).then(jsonOrText);
  }

  function onEditClick(ev) {
    ev.preventDefault();
    const a = ev.currentTarget;
    const plug = a.dataset.plug || a.getAttribute('data-plug') || a.dataset.dir || a.getAttribute('data-dir') || '';
    if (!plug) { toast('No plugin id on link', 'error'); return; }

    loadConfig(plug).then(j => {
      if (!j || !j.ok) {
        const msg = (j && j.error) ? j.error : (j && j._raw) ? j._raw : 'Load failed';
        toast(t('config_load_failed','Load failed') + (msg ? (': ' + msg) : ''), 'error');
        return;
      }
      showModal(t('edit_config','Edit config') + ': ' + plug, j.content || '', (text, modal, btn, oldLabel) => {
        saveConfig(plug, text).then(res => {
          if (res && res.ok) {
            btn.textContent = t('saved','Saved');
            toast(t('config_saved_ok','Config Saved successfully'), 'confirmation');
            setTimeout(() => modal.remove(), 450);
          } else {
            const emsg = (res && (res.error || res._raw)) ? (res.error || res._raw) : '';
            toast(t('config_save_failed','Save failed') + (emsg ? (': ' + emsg) : ''), 'error');
            btn.disabled = false;
            btn.textContent = oldLabel || t('save','Save');
          }
        }).catch(err => {
          toast(t('config_save_failed','Save failed') + ': ' + err, 'error');
          btn.disabled = false;
          btn.textContent = oldLabel || t('save','Save');
        });
      });
    }).catch(err => {
      toast(t('config_load_failed','Load failed') + ': ' + err, 'error');
    });
  }

  function init() {
    const links = document.querySelectorAll('.pm-edit-config');
    links.forEach(a => {
      a.addEventListener('click', onEditClick);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();

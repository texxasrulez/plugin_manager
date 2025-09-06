
// ==== BEGIN: Ace Editor integration for Plugin Manager ====
(function(){
  function log(){ try { if (window.console && console.debug) console.debug.apply(console, arguments); } catch(e){} }
  function warn(){ try { if (window.console && console.warn) console.warn.apply(console, arguments); } catch(e){} }

  function loadScript(src){
    return new Promise(function(resolve, reject){
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = function(){ resolve(src); };
      s.onerror = function(){ reject(new Error('Failed to load ' + src)); };
      document.head.appendChild(s);
    });
  }

  // Expose globals for debugging
  window.pmLoadAce = function pmLoadAce(){
    if (window.ace) return Promise.resolve('already');
    var base = (window.rcmail && rcmail.env && rcmail.env.pm_ace_base) || 'plugins/plugin_manager/assets/ace';
    // prefer local; fall back to CDN
    var localAce = base.replace(/\/+$/, '') + '/ace.js';
    var cdnBase  = 'https://cdn.jsdelivr.net/npm/ace-builds@1.32.3/src-min-noconflict';
    var cdnAce   = cdnBase + '/ace.js';

    function setBasePath(p){
      try { ace && ace.config && ace.config.set('basePath', p); } catch(e){}
    }

    return loadScript(localAce).then(function(){
      setBasePath(base);
      log('pm: ace loaded local', localAce);
      return 'local';
    }).catch(function(err){
      warn('pm: local ace load failed', err);
      return loadScript(cdnAce).then(function(){
        setBasePath(cdnBase);
        log('pm: ace loaded cdn', cdnAce);
        return 'cdn';
      });
    });
  };

  window.pmAttachAce = function pmAttachAce(textareaId, options){
    options = options || {};
    var ta = document.getElementById(textareaId);
    if (!ta) return null;

    if (ta.__pmAceAttached) return ta.__pmAceAttached;
    var wrap = document.createElement('div');
    wrap.id = textareaId + '-ace';
    wrap.style.height = (ta.offsetHeight ? ta.offsetHeight+'px' : '440px');
    wrap.style.width  = '100%';
    wrap.style.border = '1px solid #bbb';
    wrap.style.borderRadius = '6px';
    ta.parentNode.insertBefore(wrap, ta);
    ta.style.display = 'none';

    var editor = ace.edit(wrap.id);
    try {
      editor.session.setMode('ace/mode/php');
      editor.setTheme('ace/theme/monokai');
    } catch(e){}
    editor.setOptions({
      showPrintMargin: true,
      printMarginColumn: 100,
      tabSize: 2,
      useSoftTabs: true,
      highlightActiveLine: true,
      wrap: false,
      fontSize: '13px',
      enableBasicAutocompletion: true,
      enableLiveAutocompletion: false
    });
    editor.session.setValue(ta.value || '');
    if (options.readOnly) editor.setReadOnly(true);

    function bridgeTextarea(){
      try { ta.value = editor.getValue(); } catch(e){}
    }

    function wireSave(){
      var btn = document.getElementById('pm-editor-save') ||
                document.querySelector('.dialog input[type="button"][value="Save"], .dialog input.mainaction, .ui-dialog-buttonpane button');
      if (btn && !btn.__pmAceWired){
        btn.addEventListener('click', bridgeTextarea, {capture: true});
        btn.__pmAceWired = true;
      }
    }
    wireSave();
    var wInt = setInterval(function(){ if (!document.body.contains(wrap)) { clearInterval(wInt); } else { wireSave(); } }, 500);

    editor.commands.addCommand({
      name: 'save',
      bindKey: {win: 'Ctrl-S', mac: 'Command-S'},
      exec: function(){
        bridgeTextarea();
        var btn = document.getElementById('pm-editor-save') ||
                  document.querySelector('.dialog input[type="button"][value="Save"], .dialog input.mainaction, .ui-dialog-buttonpane button');
        if (btn) btn.click();
      }
    });

    var api = {editor: editor, textarea: ta, container: wrap};
    ta.__pmAceAttached = api;
    return api;
  };

  function ensureAce(){
    var ta = document.getElementById('pm-editor');
    if (!ta) return;
    if (ta.__pmAceAttached) return;
    window.pmLoadAce().then(function(){
      var ro = !!(ta.readOnly || ta.disabled || ta.getAttribute('data-readonly') == '1');
      window.__pmAce = window.pmAttachAce('pm-editor', {readOnly: ro});
    }).catch(function(err){
      warn('pm: ace not available; fallback to textarea', err);
    });
  }

  try {
    var mo = new MutationObserver(function(muts){
      for (var i=0;i<muts.length;i++){
        var m = muts[i];
        if (m.addedNodes && m.addedNodes.length) { ensureAce(); break; }
      }
    });
    mo.observe(document.body, {childList:true, subtree:true});
  } catch(e){}
  try { window.__pmAceInterval = setInterval(ensureAce, 400); } catch(e){}
  setTimeout(ensureAce, 50);
})();
// ==== END: Ace Editor integration ====


// --- Ace Editor integration (lazy-loaded with CDN fallback) ---
(function(){
  function loadScript(src){
    return new Promise(function(resolve, reject){
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = function(){ resolve(); };
      s.onerror = function(){ reject(new Error('Failed to load ' + src)); };
      document.head.appendChild(s);
    });
  }
  window.pmLoadAce = function(){
    if (window.ace) return Promise.resolve();
    var localBase = (window.rcmail && rcmail.env && rcmail.env.pm_ace_base) || 'plugins/plugin_manager/assets/ace';
    var local = localBase + '/ace.js';
    var cdnBase = 'https://cdn.jsdelivr.net/npm/ace-builds@1.32.3/src-min-noconflict';
    var cdn = cdnBase + '/ace.js';

    function setBasePath(base){
      try { window.ace && window.ace.config && ace.config.set('basePath', base); } catch(e){}
    }

    // try local first, then CDN
    return loadScript(local).then(function(){
      setBasePath(localBase);
      return Promise.resolve();
    }).catch(function(){
      return loadScript(cdn).then(function(){
        setBasePath(cdnBase);
        return loadScript(cdnBase + '/ext-language_tools.js').catch(function(){ /* optional */ });
      });
    });
  };

  window.pmAttachAce = function(textareaId, readOnly){
    var ta = document.getElementById(textareaId);
    if (!ta) return null;
    var wrap = document.createElement('div');
    wrap.id = textareaId + '-ace';
    wrap.style.height = (ta.offsetHeight ? ta.offsetHeight+'px' : '440px');
    wrap.style.width  = '100%';
    wrap.style.border = '1px solid #bbb';
    wrap.style.borderRadius = '6px';
    ta.parentNode.insertBefore(wrap, ta);
    ta.style.display = 'none';

    var editor = ace.edit(wrap.id);
    try {
      editor.session.setMode('ace/mode/php');
      editor.setTheme('ace/theme/monokai');
    } catch(e){ /* fallback if modules not present */ }
    editor.setOptions({
      showPrintMargin: true,
      printMarginColumn: 100,
      tabSize: 2,
      useSoftTabs: true,
      highlightActiveLine: true,
      wrap: false,
      fontSize: '13px',
      enableBasicAutocompletion: true,
      enableLiveAutocompletion: false
    });
    editor.session.setValue(ta.value || '');
    if (readOnly) editor.setReadOnly(true);

    editor.commands.addCommand({
      name: 'save',
      bindKey: {win: 'Ctrl-S', mac: 'Command-S'},
      exec: function(ed){
        var btn = document.getElementById('pm-editor-save');
        if (btn) btn.click();
      }
    });
    return {editor: editor, textarea: ta, container: wrap};
  };
})();
// --- end Ace Editor integration ---

// --- Ace auto-attach observer ---
(function(){
  function ensureAce(){
    var ta = document.getElementById('pm-editor');
    if (!ta) return;
    if (window.__pmAce && __pmAce.container && __pmAce.container.parentNode) return; // already attached
    pmLoadAce().then(function(){
      var readonly = !!(ta.readOnly || ta.disabled || ta.getAttribute('data-readonly') == '1');
      window.__pmAce = pmAttachAce('pm-editor', readonly);
      // try to tag the Save button for keyboard shortcut
      var btn = document.getElementById('pm-editor-save') ||
                document.querySelector('.dialog input[type="button"][value="Save"], .dialog input.mainaction, .ui-dialog-buttonpane button');
      if (btn && !btn.id) btn.id = 'pm-editor-save';
    }).catch(function(e){
      console.warn('Ace load failed, falling back to textarea', e);
    });
  }
  var mo = new MutationObserver(function(muts){
    for (var i=0;i<muts.length;i++){
      if (muts[i].addedNodes && muts[i].addedNodes.length) { ensureAce(); break; }
    }
  });
  try { mo.observe(document.body, {childList:true, subtree:true}); } catch(e){}
  // run once in case modal already exists
  setTimeout(ensureAce, 50);
})();
// --- end Ace auto-attach observer ---


(function(){
  window.__pmErrors = window.__pmErrors || [];
  window.addEventListener('error', function(e){ try{ __pmErrors.push(String(e.error || e.message || e)); }catch(_){}});

  function ready(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded', fn);} }
  function q(sel, root){ return (root && root.querySelector) ? root.querySelector(sel) : document.querySelector(sel); }
  function qa(sel, root){ return Array.prototype.slice.call((root && root.querySelectorAll) ? root.querySelectorAll(sel) : document.querySelectorAll(sel)); }

  function findPluginTable(root){
    var t = q('#pm-table', root);
    if (t) return t;
    var tables = qa('table', root);
    for (var i=0;i<tables.length;i++){
      var ths = qa('thead th', tables[i]);
      if (ths.length < 3) continue;
      var labels = ths.map(function(th){return (th.textContent||'').trim().toLowerCase();});
      if (labels.indexOf('plugin')>-1 && labels.indexOf('directory')>-1 && (labels.indexOf('status')>-1 || labels.indexOf('latest version')>-1)){
        return tables[i];
      }
    }
    return null;
  }

  function getDirForRow(tr, table){
    var d = tr.getAttribute('data-plugin') || tr.getAttribute('data-dir') || tr.getAttribute('data-name');
    if (d) return d.trim();
    var ths = qa('thead th', table);
    var dirIdx = -1;
    for (var i=0;i<ths.length;i++){
      if ((ths[i].textContent||'').trim().toLowerCase() === 'directory') { dirIdx=i; break; }
    }
    if (dirIdx >= 0 && tr.children[dirIdx]){
      return (tr.children[dirIdx].textContent||'').trim();
    }
    return '';
  }

  function findUpdateUrlForRow(tr){
    var link = qa('a', tr).find(function(a){
      var t = (a.textContent||'').trim().toLowerCase();
      var href = a.getAttribute('href') || '';
      return t === 'update' || /_pm_update(_plugin)?=|_pm_update=|update=/.test(href);
    });
    return link ? link.getAttribute('href') : null;
  }

  function ensureSelectColumnAtEnd(table){
    var thead = q('thead', table);
    var trh = thead ? thead.querySelector('tr') : null;
    if (!trh) return;
    var ths = qa('th', trh);
    // Remove any previous leftmost "Select" we might have added
    if (ths.length && (ths[0].textContent||'').trim().toLowerCase() === 'select'){
      trh.removeChild(ths[0]);
      // Also remove first cell from each body row if it only contains our checkbox
      qa('tbody tr', table).forEach(function(tr){
        var td = tr.firstElementChild;
        if (!td) return;
        var onlyCB = td.children.length===1 && td.querySelector('input.pm-select');
        if (onlyCB) tr.removeChild(td);
      });
      ths = qa('th', trh);
    }
    // If a "Select" header already exists at the end, do nothing
    ths = qa('th', trh);
    var last = ths[ths.length-1];
    if (last && (last.textContent||'').trim().toLowerCase() === 'select') return;

    // Create at end
    var selTh = document.createElement('th');
    selTh.textContent = 'Select';
    selTh.className = 'pm-select-th';
    selTh.style.width = '1%';
    trh.appendChild(selTh);

    // Append cells to each row
    qa('tbody tr', table).forEach(function(tr){
      if (tr.querySelector('td input.pm-select')) return; // if we left some in middle (unlikely), skip
      var tdSel = document.createElement('td');
      var cb = document.createElement('input');
      cb.type='checkbox'; cb.className='pm-select';
      cb.value = getDirForRow(tr, table) || '';
      tdSel.appendChild(cb);
      tr.appendChild(tdSel);
    });
  }

  function fixScroll(pluginbody){
    var boxcontent = q('.boxcontent', pluginbody) || pluginbody;
    boxcontent.style.overflowY = 'auto';
    boxcontent.style.maxHeight = '';
    setTimeout(function(){ window.dispatchEvent(new Event('resize')); }, 0);
  }

  function buildUI(){
    var pluginbody = q('#pluginbody');
    if (!pluginbody) return;

    var bulkbar = q('.pm-bulkbar', pluginbody);
    if (!bulkbar) return; // respect server-side hide

    // Clean stray toolbars
    qa('.pm-inline-toolbar').forEach(function(el){
      if (!bulkbar.contains(el)) el.parentNode && el.parentNode.removeChild(el);
    });
    if (q('.pm-inline-toolbar', bulkbar)) return;

    var table = findPluginTable(pluginbody);
    if (!table) return;

    // Put Select column at the END so native sort indices stay correct
    ensureSelectColumnAtEnd(table);

    // Build inline toolbar (no Test Updates)
    var wrap = document.createElement('div');
    wrap.className = 'pm-inline-toolbar';
    wrap.style.display = 'inline-flex';
    wrap.style.flexWrap = 'wrap';
    wrap.style.alignItems = 'center';
    wrap.style.gap = '12px';
    wrap.style.marginTop = '8px';
    wrap.style.marginBottom = '4px';

    var btn = document.createElement('a');
    btn.id = 'pm-bulk-update';
    btn.className = 'button pm-update-all';
    btn.href = '#';
    btn.textContent = 'Update Selected';

    var o1 = document.createElement('label');
    o1.innerHTML = '<input type="checkbox" id="pm-filter-outdated"> Only outdated';
    var o2 = document.createElement('label');
    o2.innerHTML = '<input type="checkbox" id="pm-filter-enabled"> Only enabled';
    var o3 = document.createElement('label');
    o3.innerHTML = '<input type="checkbox" id="pm-filter-errors"> Only errors';

    wrap.appendChild(btn);
    wrap.appendChild(o1);
    wrap.appendChild(o2);
    wrap.appendChild(o3);
    bulkbar.appendChild(wrap);

    // Bulk update via per-row Update links (sequential fetch)
    btn.addEventListener('click', function(ev){
      ev.preventDefault();
      var rows = qa('tbody tr', table).filter(function(tr){
        var cb = tr.querySelector('input.pm-select');
        return cb && cb.checked;
      });
      if (!rows.length){ alert('Select at least one plugin'); return; }
      btn.textContent = 'Updating...'; btn.style.pointerEvents = 'none';

      var queue = rows.map(function(tr){
        var url = findUpdateUrlForRow(tr);
        if (url) return url;
        var dir = getDirForRow(tr, table);
        if (dir) return '?_task=settings&_action=plugin.plugin_manager&_pm_update=' + encodeURIComponent(dir);
        return null;
      }).filter(Boolean);

      (function next(){
        if (!queue.length){ window.location.reload(); return; }
        var url = queue.shift();
        fetch(url, { credentials: 'same-origin' }).then(function(){ next(); }).catch(function(){ next(); });
      })();
    });

    // Scroll fix
    fixScroll(pluginbody);
  }

  ready(buildUI);
})();

// === Plugin Manager: inline config editor ===
(function(){
  function ready(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded', fn);} }
  function dlg(html){
    var d = document.createElement('div');
    d.id = 'pm-config-modal';
    d.style.position='fixed'; d.style.left='0'; d.style.top='0'; d.style.right='0'; d.style.bottom='0';
    d.style.background='rgba(0,0,0,0.35)'; d.style.zIndex='9999'; d.style.display='flex'; d.style.alignItems='center'; d.style.justifyContent='center';
    d.innerHTML = '<div style="background:#fff; max-width:900px; width:90%; padding:16px; box-shadow:0 8px 30px rgba(0,0,0,.3); border-radius:8px;">'
      + '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">'
      +   '<strong>Edit config.inc.php</strong>'
      +   '<button id="pm-close" class="btn btn-secondary" style="margin-left:8px;">Close</button>'
      + '</div>'
      + '<div id="pm-msg" style="color:#b00; margin:6px 0; display:none;"></div>'
      + '<textarea id="pm-text" spellcheck="false" style="width:100%; height:420px; font-family:monospace; font-size:12px;"></textarea>'
      + '<div style="margin-top:10px; display:flex; gap:8px; justify-content:flex-end;">'
      +   '<button id="pm-save" class="btn btn-primary" id="pm-editor-save">Save</button>'
      +   '<button id="pm-cancel" class="btn">Cancel</button>'
      + '</div>'
      + '</div>';
    document.body.appendChild(d);
    return d;
  }
  function showMsg(d, t){ var m=d.querySelector('#pm-msg'); m.textContent=t||''; m.style.display = t? 'block':'none'; }
  ready(function(){
    document.addEventListener('click', function(ev){
      var a = ev.target.closest && ev.target.closest('a.pm-editcfg');
      if (!a) return;
      ev.preventDefault();
      var plug = a.getAttribute('data-plugin');
      var url = (rcmail && rcmail.env && rcmail.env.comm_path ? rcmail.env.comm_path : window.location.pathname + '?_task=settings');
      url += '&_remote=1&_action=plugin.plugin_manager.load_config&_plugin=plugin_manager&_pm_plug=' + encodeURIComponent(plug) + '&_token=' + encodeURIComponent(rcmail.env.request_token || '');
      fetch(url, {credentials:'same-origin'}).then(function(r){
        return r.text().then(function(t){
          try { return {ok: r.ok, json: JSON.parse(t)}; } catch(e){ throw new Error('HTTP '+r.status+' '+r.statusText+' | Body: ' + t.slice(0,200)); }
        });
      }).then(function(resp){
        var j = resp.json;

        if (!j || !j.ok) { console.error('pm load_config server json', j); throw new Error((j && j.error) || 'Failed to load'); }
        var d = dlg();
        var ta = d.querySelector('#pm-text');
        ta.value = j.content || '';
        var close = function(){ d.remove(); };
        d.querySelector('#pm-close').onclick = close;
        d.querySelector('#pm-cancel').onclick = close;
        d.addEventListener('click', function(e){ if (e.target === d) close(); });
        d.querySelector('#pm-save').onclick = function(){
          showMsg(d, '');
          var data = new URLSearchParams();
          data.set('_pm_plug', plug);
          data.set('_pm_content', ta.value);
          data.set('_token', (rcmail.env && rcmail.env.request_token) || '');
          var post = (rcmail && rcmail.env && rcmail.env.comm_path ? rcmail.env.comm_path : window.location.pathname + '?_task=settings');
          post += '&_remote=1&_action=plugin.plugin_manager.save_config&_plugin=plugin_manager';
          fetch(post, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:String(data)})
            .then(function(r){
              return r.text().then(function(t){
                try { return {ok:r.ok, json: JSON.parse(t)}; } catch(e){ throw new Error('HTTP '+r.status+' '+r.statusText+' | Body: ' + t.slice(0,200)); }
              });
            })
            .then(function(resp){
              var j2 = resp.json;
              if (!j2 || !j2.ok) { console.error('pm save_config server json', j2); throw new Error((j2 && j2.error) || 'Save failed'); }
              close();
              if (window.rcmail && rcmail.display_message) {
                rcmail.display_message('Saved ' + (j2.file || ''), 'confirmation');
              }
            })
            .catch(function(err){
              showMsg(d, String(err && err.message || err));
            });
        };
      }).catch(function(err){ console.error('pm load_config error', err);
        alert('Failed to load config: ' + String(err && err.message || err));
      });
    });
  });
})();


// --- Ace auto-attach observer + interval ---
(function(){
  function ensureAce(){
    var ta = document.getElementById('pm-editor');
    if (!ta) return;
    if (window.__pmAce && __pmAce.container && __pmAce.container.parentNode) return; // already attached
    if (!window.pmLoadAce || !window.pmAttachAce) return;
    window.pmLoadAce().then(function(){
      var readonly = !!(ta.readOnly || ta.disabled || ta.getAttribute('data-readonly') == '1');
      window.__pmAce = pmAttachAce('pm-editor', readonly);
      var btn = document.getElementById('pm-editor-save') ||
                document.querySelector('.dialog input[type="button"][value="Save"], .dialog input.mainaction, .ui-dialog-buttonpane button');
      if (btn && !btn.id) btn.id = 'pm-editor-save';
    }).catch(function(e){ /* silent fallback */ });
  }
  try { window.__pmAceInterval = window.setInterval(ensureAce, 400); } catch(e){}
  try {
    var mo = new MutationObserver(function(muts){
      for (var i=0;i<muts.length;i++){
        if (muts[i].addedNodes && muts[i].addedNodes.length) { ensureAce(); break; }
      }
    });
    mo.observe(document.body, {childList:true, subtree:true});
  } catch(e) {}
  setTimeout(ensureAce, 50);
})();
// --- end Ace auto-attach ---


// Kick once on load if Ace already present (preloaded)
try { if (window.ace) { (function(){ 
  var t = setInterval(function(){ 
    var el = document.getElementById('pm-editor'); 
    if (el) { try { window.pmAttachAce && window.pmAttachAce('pm-editor', {readOnly: !!(el.readOnly||el.disabled||el.getAttribute('data-readonly')=='1')}); } catch(e){} clearInterval(t); } 
  }, 300);
})(); } } catch(e) {}


// === PM Ace hardening ===
(function(){
  function pm_bridge_readonly(ta){
    return !!(ta.readOnly || ta.disabled || ta.getAttribute('data-readonly') == '1');
  }
  function pm_try_attach(){
    try {
      var ta = document.getElementById('pm-editor') 
            || (function(){ 
                  var d = document.querySelector('.ui-dialog, .dialog'); 
                  if (!d) return null; 
                  return d.querySelector('textarea'); 
               })();
      if (!ta) return false;
      if (ta.__pmAceAttached) return true;
      if (!window.ace || !window.pmAttachAce) return false;
      // Ensure element has an id
      if (!ta.id) ta.id = 'pm-editor';
      window.__pmAce = window.pmAttachAce(ta.id, {readOnly: pm_bridge_readonly(ta)});
      return !!window.__pmAce;
    } catch(e){ return false; }
  }

  // Burst timer when user clicks edit-config links
  document.addEventListener('click', function(ev){
    var t = ev.target && (ev.target.closest && ev.target.closest('.pm-edit-config,[data-pm-edit]'));
    if (!t) return;
    var end = Date.now() + 8000;
    var burst = setInterval(function(){
      if (pm_try_attach() || Date.now()>end) clearInterval(burst);
    }, 150);
  }, true);

  // Persistent pump: small interval that gives multiple chances
  try {
    var ticks = 0;
    var pump = setInterval(function(){
      if (pm_try_attach()) { clearInterval(pump); return; }
      if (++ticks > 120) clearInterval(pump); // ~24s
    }, 200);
  } catch(e){}

  // Mutation observer
  try {
    var mo = new MutationObserver(function(muts){
      for (var i=0;i<muts.length;i++){
        if (muts[i].addedNodes && muts[i].addedNodes.length) {
          if (pm_try_attach()) break;
        }
      }
    });
    mo.observe(document.body, {childList:true, subtree:true});
  } catch(e){}
})();
// === end PM Ace hardening ===

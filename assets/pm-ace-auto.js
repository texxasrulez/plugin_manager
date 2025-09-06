
// plugin_manager/assets/pm-ace-auto.js
(function(){
  'use strict';

  // --- Utilities ---
  function log(){ try{ console.debug.apply(console, ['[pm-ace]'].concat([].slice.call(arguments))); }catch(_){/*nope*/} }
  function q(sel, root){ return (root||document).querySelector(sel); }
  function qa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

  // Detect Roundcube jQuery UI dialog container
  function isDialog(el){
    return el && el.classList && (el.classList.contains('ui-dialog') || el.closest && el.closest('.ui-dialog'));
  }

  // Find a suitable textarea inside a dialog that is likely our config editor
  function findEditorTextarea(root){
    var scopes = [];
    if (root) scopes.push(root);
    scopes.push(document);

    for (var s=0; s<scopes.length; s++){
      var scope = scopes[s];
      // Prefer known IDs/classes first
      var cand = q('#pm-config-editor, textarea.pm-config-editor, textarea[name="pm_config_editor"], textarea[name="config"], textarea[name="content"]', scope);
      if (cand) return cand;
      // Fallback: any textarea inside an rc dialog that is reasonably large
      var list = qa('.ui-dialog textarea', scope);
      if (list.length === 1) return list[0];
      if (list.length > 1){
        // pick the one with the longest value
        list.sort(function(a,b){ return (b.value||'').length - (a.value||'').length; });
        return list[0];
      }
    }
    return null;
  }

  // Create Ace editor on top of the found textarea
  function attachAce(textarea){
    if (!textarea || textarea.hasAttribute('data-pm-aceified')) return null;
    if (!(window.ace && ace.edit)) { log('ace not ready'); return null; }

    // Wrap
    var wrap = document.createElement('div');
    wrap.className = 'pm-ace-wrap';
    // Copy width from textarea's computed style to avoid shrinking in Larry
    try {
      var cs = window.getComputedStyle(textarea);
      wrap.style.width = cs.width;
    } catch(_){}

    var editorDiv = document.createElement('div');
    editorDiv.id = 'pm-ace-editor-' + Math.random().toString(36).slice(2);
    editorDiv.className = 'pm-ace';
    editorDiv.style.height = (Math.max(textarea.clientHeight, 400) || 400) + 'px';

    textarea.parentNode.insertBefore(wrap, textarea);
    wrap.appendChild(editorDiv);

    // Hide the original but keep it in DOM so forms work
    textarea.style.display = 'none';
    textarea.setAttribute('data-pm-aceified','1');

    var ed = ace.edit(editorDiv);
    try { ed.session.setMode('ace/mode/php'); } catch(_){}
    try { ed.setTheme('ace/theme/chrome'); } catch(_){}
    ed.setOptions({
      tabSize: 2,
      useSoftTabs: true,
      showPrintMargin: false,
      wrap: true,
      highlightActiveLine: true,
      enableBasicAutocompletion: true,
      enableLiveAutocompletion: false
    });
    ed.session.setValue(textarea.value || '');

    // Keep textarea in sync
    ed.session.on('change', function(){ textarea.value = ed.session.getValue(); });

    // Resize when dialog opens/resizes
    var resize = function(){
      try {
        var h = Math.max(400, window.innerHeight ? Math.floor(window.innerHeight * 0.6) : editorDiv.clientHeight);
        editorDiv.style.height = h + 'px';
        ed.resize();
      } catch(_){}
    };
    resize();
    window.addEventListener('resize', resize);

    // If placed in jQuery UI dialog, hook close to cleanup
    var dlg = textarea.closest && textarea.closest('.ui-dialog');
    if (dlg) {
      // Observe removal to clean listeners
      var mo = new MutationObserver(function(muts){
        for (var i=0;i<muts.length;i++){
          muts[i].removedNodes && muts[i].removedNodes.forEach(function(n){
            if (n === dlg) {
              try { ed.destroy(); } catch(_){}
              window.removeEventListener('resize', resize);
              mo.disconnect();
            }
          });
        }
      });
      mo.observe(dlg.parentNode || document.body, { childList: true });
    }

    log('Ace attached to', textarea);
    return ed;
  }

  // Try to derive ace base from env or from our own script URL
  function aceBase(){
    if (window.rcmail && rcmail.env && rcmail.env.pm_ace_base) return rcmail.env.pm_ace_base;
    // detect from the script tag src
    var scripts = qa('script[src*="plugin_manager"]');
    for (var i=0;i<scripts.length;i++){
      var src = scripts[i].getAttribute('src');
      if (!src) continue;
      var k = src.indexOf('/plugins/plugin_manager/');
      if (k >= 0){
        var base = src.slice(0, k + '/plugins/plugin_manager/'.length) + 'assets/ace';
        return base.replace(/\/+$/, '');
      }
    }
    return 'plugins/plugin_manager/assets/ace';
  }

  // Lazy loader for ace if missing
  function ensureAce(callback){
    if (window.ace && ace.edit){ callback(); return; }
    var base = aceBase();
    var queue = [
      base + '/ace.js',
      base + '/mode-php.js',
      base + '/worker-php.js',
      base + '/theme-chrome.js',
      base + '/ext-language_tools.js'
    ];
    var i = 0;
    function next(){
      if (i >= queue.length){ return callback(); }
      var s = document.createElement('script');
      s.src = queue[i++];
      s.onload = next;
      s.onerror = next;
      document.head.appendChild(s);
    }
    next();
  }

  // Scan existing dialogs now and again
  function scan(root){
    var ta = findEditorTextarea(root);
    if (ta) ensureAce(function(){ attachAce(ta); });
  }

  // Observe dialogs popping up
  var mo = new MutationObserver(function(muts){
    for (var i=0;i<muts.length;i++){
      var m = muts[i];
      for (var j=0;j<m.addedNodes.length;j++){
        var n = m.addedNodes[j];
        if (n.nodeType === 1 && isDialog(n)){
          // Wait a tick to allow HTML to be inserted
          setTimeout(function(){ scan(n); }, 0);
        }
      }
    }
  });
  mo.observe(document.body || document.documentElement, { childList: true, subtree: true });

  // Also try once after DOM ready (in case modal already exists)
  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', function(){ scan(); });
  } else {
    scan();
  }
})();

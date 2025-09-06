/*! pm-ace-attach.js — robust Ace hookup for Roundcube Plugin Manager
 *  Attaches Ace to the Edit-Config modal's textarea (#pm-config-editor).
 *  - Works across skins and plugin versions (MutationObserver + RC events)
 *  - Keeps hidden textarea in-sync so existing save flow continues
 *  - Uses rcmail.env.pm_ace_base if present (optional)
 */
(function () {
  function log() {
    if (window.console && console.debug) {
      try { console.debug.apply(console, ['pm-ace:'].concat([].slice.call(arguments))); }
      catch (e) { console.debug('pm-ace:', arguments); }
    }
  }

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  function attach(context) {
    if (!window.ace) { log('ace.js not present'); return; }

    var ta = (context || document).querySelector('#pm-config-editor');
    if (!ta) return;

    if (ta.dataset && ta.dataset.aceAttached) {
      log('already attached');
      return;
    }

    // Optional base path config (so worker/modes load correctly if needed)
    try {
      var base = (window.rcmail && rcmail.env && rcmail.env.pm_ace_base) ? rcmail.env.pm_ace_base.replace(/\/+$/,'') + '/' : null;
      if (base) {
        ace.config.set('basePath', base);
        ace.config.set('workerPath', base);
        ace.config.set('modePath', base);
        ace.config.set('themePath', base);
        log('ace base set to', base);
      }
    } catch (e) { /* no-op */ }

    // Create editor container next to textarea and hide textarea
    var wrap = document.createElement('div');
    wrap.id = 'pm-ace-editor';
    wrap.style.width = '100%';
    // Height: use existing textarea height or a sane default
    var rect = ta.getBoundingClientRect();
    wrap.style.height = Math.max(rect.height || 520, 420) + 'px';

    ta.parentNode.insertBefore(wrap, ta.nextSibling);
    ta.style.display = 'none';
    ta.dataset.aceAttached = '1';

    var editor = ace.edit(wrap);
    // Mode/theme can be changed freely; monokai is readable on Larry variants
    try { editor.session.setMode('ace/mode/php'); } catch (e) {}
    try { editor.setTheme('ace/theme/monokai'); } catch (e) {}

    editor.setOptions({
      fontSize: '12px',
      useSoftTabs: true,
      tabSize: 2,
      wrap: true,
      showPrintMargin: false,
      enableBasicAutocompletion: true,
      enableLiveAutocompletion: false,
      enableSnippets: true
    });

    // Seed with current textarea content
    editor.session.setValue(ta.value || '', -1);

    // Keep textarea in sync for existing save handler
    editor.session.on('change', function () {
      ta.value = editor.getValue();
    });

    // Resize Ace when dialog resizes (basic observer)
    var ro;
    if ('ResizeObserver' in window) {
      ro = new ResizeObserver(function () { editor.resize(); });
      ro.observe(wrap);
    }

    // Expose for debugging
    window.pmAceEditor = editor;
    log('attached');
  }

  ready(function () {
    if (!window.ace) {
      log('waiting for ace.js');
    }

    // Try immediate attach (dialog might already be in DOM)
    attach(document);

    // Observe future dialogs
    var mo = new MutationObserver(function (mutations) {
      for (var m of mutations) {
        if (m.type === 'childList' && m.addedNodes.length) {
          for (var n of m.addedNodes) {
            if (n.nodeType !== 1) continue;
            if (n.id === 'pm-config-editor' || (n.querySelector && n.querySelector('#pm-config-editor'))) {
              attach(n);
            }
          }
        }
      }
    });
    mo.observe(document.body, { childList: true, subtree: true });

    // Also hook Roundcube custom events when available
    if (window.rcmail && typeof rcmail.addEventListener === 'function') {
      var events = [
        'plugin.plugin_manager.load_config',
        'plugin_manager.load_config',
        'plugin.plugin_manager.show_editor'
      ];
      events.forEach(function (ev) {
        try { rcmail.addEventListener(ev, function () { setTimeout(function(){ attach(document); }, 0); }); } catch (e) {}
      });
    }
  });
})();

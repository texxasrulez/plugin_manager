// pm-ace-theme-guard.js — hardened theme loader for Roundcube Plugin Manager
// - Race-proof AMD→Ace shim
// - Local-first, noconflict-first theme loading with robust CDN fallbacks
// - Normalized theme aliases + "monokai override" protection
// - Throttled re-apply on dynamic editor creation
(function () {
  var defQ = [], reqQ = [];

  // Ensure define/require exist immediately, even before Ace arrives
  if (!window.define)  window.define  = function(){ defQ.push(arguments); };
  if (!window.require) window.require = function(){ reqQ.push(arguments); };

  function bind() {
    if (!(window.ace && ace.define && ace.require)) return false;

    // Adopt Ace's AMD impls
    window.define  = ace.define;
    window.require = ace.require;

    // Set basePath once Ace is adoptable
    try {
      var env  = (window.rcmail && rcmail.env) || {};
      var base = env.pm_ace_base || 'plugins/plugin_manager/assets/ace';
      ace.config.set('basePath', base);
    } catch (_) {}

    // Flush anything queued before Ace was ready
    try { defQ.forEach(function(args){ ace.define.apply(null, args); }); } catch(e){}
    try { reqQ.forEach(function(args){ ace.require.apply(null, args); }); } catch(e){}

    defQ = reqQ = null;
    return true;
  }

  // Try now; if Ace isn't ready yet, keep trying briefly and on DOM ready
  if (!bind()) {
    var iv = setInterval(function(){ if (bind()) clearInterval(iv); }, 10);
    try { document.addEventListener('DOMContentLoaded', bind, { once: true }); } catch(_) {
      document.addEventListener('DOMContentLoaded', bind);
    }
  }
})();

(function(){
  function onReady(fn){ if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  onReady(function(){
    if (!window.ace) return;

    var env = (window.rcmail && rcmail.env) || {};
    var selected = (env.pm_ace_theme != null ? env.pm_ace_theme : 'auto') + '';
    var light    = (env.pm_ace_light_theme || 'github') + '';
    var dark     = (env.pm_ace_dark_theme  || 'dracula') + '';
    var desired  = selected === 'auto'
      ? ((window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? dark : light)
      : selected;

    // Normalize + alias
    desired = (desired||'').toLowerCase().replace(/[\s-]+/g,'_');
    var aliases = {
      solarizeddark:'solarized_dark', solarizedlight:'solarized_light',
      tomorrownight:'tomorrow_night',
      'one_dark':'one_dark', 'one-dark':'one_dark',
      'github_dark':'github_dark', 'github-dark':'github_dark',
      'github_dark_default':'github_dark', 'github-dark-default':'github_dark',
      'github_light_default':'github_light_default', 'github-light-default':'github_light_default',
      'gruvbox_dark_hard':'gruvbox_dark_hard', 'gruvbox-dark-hard':'gruvbox_dark_hard',
      'gruvbox_light_hard':'gruvbox_light_hard', 'gruvbox-light-hard':'gruvbox_light_hard'
    };
    if (aliases[desired]) desired = aliases[desired];

    var base = env.pm_ace_base || 'plugins/plugin_manager/assets/ace';

    function loadScript(url){
      return new Promise(function(res, rej){
        var s = document.createElement('script');
        s.src = url; s.async = true;
        s.onload = function(){ res(url); };
        s.onerror = function(){ rej(new Error('load failed: '+url)); };
        (document.head || document.documentElement).appendChild(s);
      });
    }

    function ensureThemeModule(){
      try { if (ace.require('ace/theme/' + desired)) return Promise.resolve(); } catch(_) {}
      var urls = [
        // Local first
        base + '/theme-' + desired + '.js',
        // Prefer noconflict on CDN fallbacks
        'https://cdn.jsdelivr.net/npm/ace-builds@1.32.3/src-min-noconflict/theme-' + desired + '.js',
        'https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.3/src-min-noconflict/theme-' + desired + '.js',
        'https://unpkg.com/ace-builds@1.32.3/src-min-noconflict/theme-' + desired + '.js'
      ];
      // try each in order
      var p = Promise.reject();
      urls.forEach(function(u){ p = p.catch(function(){ return loadScript(u); }); });
      return p;
    }

    // Guard: redirect monokai -> desired if someone forces it late
    try {
      var Editor = ace.require('ace/editor').Editor;
      if (Editor && !Editor.__pmGuarded) {
        var _orig = Editor.prototype.setTheme;
        Editor.prototype.setTheme = function(name){
          try {
            if ((name === 'ace/theme/monokai' || name === 'monokai') && desired && desired !== 'monokai') {
              name = 'ace/theme/' + desired;
            }
          } catch(_) {}
          return _orig.call(this, name);
        };
        Editor.__pmGuarded = true;
      }
    } catch(_) {}

    function applyAll(){
      var els = document.querySelectorAll('.ace_editor');
      for (var i=0;i<els.length;i++){
        try { ace.edit(els[i]).setTheme('ace/theme/' + desired); } catch(_){}
      }
    }

    ensureThemeModule()
      .catch(function(e){
        // Hard fallback if theme truly missing
        try { ace.require('ace/theme/' + desired); }
        catch(_) { desired = 'github'; }
      })
      .finally(function(){
        applyAll();
        // Throttled observer to apply theme to editors created later
        var pending = false;
        var mo = new MutationObserver(function(){
          if (pending) return;
          pending = true;
          requestAnimationFrame(function(){ pending = false; applyAll(); });
        });
        mo.observe(document.body, {childList:true, subtree:true});
      });
  });
})();

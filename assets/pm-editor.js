
(function(){
  // Simple helper to load a script once
  function loadScript(src){
    return new Promise(function(resolve, reject){
      if (document.querySelector('script[src="'+src+'"]')) { resolve(); return; }
      var s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  // Minimal modal
  function ensureModal(){
    if (document.getElementById('pm-ace-overlay')) return;
    var css = [
      '#pm-ace-overlay{position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:9998;display:none;}',
      '#pm-ace-modal{position:fixed;z-index:9999;top:8vh;left:50%;transform:translateX(-50%);width:min(500px,50vw);background:#fff;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.35);display:none;}',
      '#pm-ace-head{padding:10px 12px;border-bottom:1px solid #ddd;display:flex;align-items:center;justify-content:space-between;}',
      '#pm-ace-title{font-weight:600;}',
      '#pm-ace-body{height:min(50vh,500px);}',
      '#pm-ace-editor{width:100%;height:100%;}',
      '#pm-ace-foot{padding:10px 12px;border-top:1px solid #ddd;display:flex;gap:8px;justify-content:flex-end;}',
      '.pm-btn{background:#1976d2;color:#fff;border:none;border-radius:6px;padding:8px 12px;cursor:pointer;}',
      '.pm-btn[disabled]{opacity:.6;cursor:not-allowed;}',
      '.pm-btn.secondary{background:#777;}',
      '.pm-inline-actions{white-space:nowrap; font-weight:normal;}',
      '.pm-inline-actions a{margin-left:6px;font-weight:normal;}',
      '#pm-ace-path{opacity:.7;font-size:.9em;margin-left:8px;}'
    ].join('\\n');
    var style = document.createElement('style'); style.textContent = css; document.head.appendChild(style);

    var overlay = document.createElement('div'); overlay.id='pm-ace-overlay';
    var modal   = document.createElement('div'); modal.id='pm-ace-modal';
    modal.innerHTML = [
      '<div id="pm-ace-head">',
        '<div>',
          '<span id="pm-ace-title">Edit config</span>',
          '<span id="pm-ace-path"></span>',
        '</div>',
        '<button id="pm-ace-close" class="pm-btn secondary">Close</button>',
      '</div>',
      '<div id="pm-ace-body"><div id="pm-ace-editor"></div></div>',
      '<div id="pm-ace-foot">',
        '<button id="pm-ace-save" class="pm-btn">Save</button>',
      '</div>'
    ].join('');

    document.body.appendChild(overlay);
    document.body.appendChild(modal);

    function close(){ overlay.style.display='none'; modal.style.display='none'; }
    document.getElementById('pm-ace-close').addEventListener('click', close);
    overlay.addEventListener('click', close);
  }

  async function openEditorFor(plugin){
    try{
      ensureModal();

      // Ensure ACE is loaded
      var aceBase = (window.rcmail && rcmail.env && rcmail.env.pm_ace_base) ? rcmail.env.pm_ace_base : 'plugins/plugin_manager/assets/ace';
      if (!window.ace) {
        await loadScript(aceBase.replace(/\/$/,'') + '/ace.js');
      }
      // Load PHP mode & theme (optional)
      if (!ace.require('ace/mode/php')) {
        await loadScript(aceBase.replace(/\/$/,'') + '/mode-php.js');
      }
      if (!ace.require('ace/theme/dracula')) {
        await loadScript(aceBase.replace(/\/$/,'') + '/theme-dracula.js');
      }
      if (!ace.require('ace/theme/github')) {
        await loadScript(aceBase.replace(/\/$/,'') + '/theme-github.js');
      }

      // Fetch config content
      var url = '?_task=settings&_action=plugin.plugin_manager.load_config&_pm_plug=' + encodeURIComponent(plugin) + '&_=' + Date.now();
      const resp = await fetch(url, {credentials:'same-origin'});
      const text = await resp.text();
      let data; try { data = JSON.parse(text); } catch(e) {
        alert('Editor load failed: response was not JSON'); console.warn(text); return;
      }
      if (!data || !data.ok) { alert('Load failed: ' + (data && data.error ? data.error : 'unknown')); return; }

      // Show modal
      document.getElementById('pm-ace-overlay').style.display='block';
      var modal = document.getElementById('pm-ace-modal'); modal.style.display='block';
      document.getElementById('pm-ace-title').textContent = 'Edit config: ' + plugin;
      document.getElementById('pm-ace-path').textContent = ' — ' + (data.path || '');

      // Init ACE
      var edHost = document.getElementById('pm-ace-editor');
      edHost.textContent = ''; // reset
      var editor = ace.edit(edHost);
      var theme = (window.rcmail && rcmail.env && rcmail.env.pm_ace_theme) ? rcmail.env.pm_ace_theme : 'auto';
      if (theme === 'dark' || (theme === 'auto' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        editor.setTheme('ace/theme/dracula');
      } else {
        editor.setTheme('ace/theme/github');
      }
      editor.session.setMode('ace/mode/php');
      editor.session.setValue(data.content || '');
      editor.session.setUseWrapMode(true);
      editor.setOptions({ fontSize: '12px', showPrintMargin: false });

      // Save
      var saving = false;
      document.getElementById('pm-ace-save').onclick = async function(){
        if (saving) return;
        saving = true;
        this.disabled = true;
        try {
          var body = new URLSearchParams();
          body.set('_pm_plug', plugin);
          body.set('_pm_content', editor.getValue());
          if (window.rcmail && rcmail.env && rcmail.env.request_token) {
            body.set('_token', rcmail.env.request_token);
          }
          const r = await fetch('?_task=settings&_action=plugin.plugin_manager.save_config', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString()
          });
          const t = await r.text();
          let j; try { j = JSON.parse(t); } catch(e) {
            alert('Save error: Response was not JSON'); console.warn(t); return;
          }
          if (j.ok) {
            if (window.rcmail && rcmail.display_message) rcmail.display_message('Saved.', 'confirmation');
            // Close after short delay
            setTimeout(function(){
              document.getElementById('pm-ace-overlay').style.display='none';
              document.getElementById('pm-ace-modal').style.display='none';
            }, 200);
          } else {
            alert('Save failed: ' + (j.error || 'unknown'));
          }
        } catch(err){
          alert('Network error while saving'); console.error(err);
        } finally {
          saving = false;
          document.getElementById('pm-ace-save').disabled = false;
        }
      };
    } catch(err){
      console.error('[pm-editor] open failed', err);
      alert('Could not open editor. See console.');
    }
  }

  function bind(){
    document.querySelectorAll('.pm-edit-config').forEach(function(a){
      a.addEventListener('click', function(ev){
        ev.preventDefault();
        var plug = this.dataset.plug || this.getAttribute('data-dir') || '';
        if (!plug){ alert('Missing plugin id'); return; }
        openEditorFor(plug);
      });
    });
  }

  // Re-bind on load & when Roundcube updates content
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind, {once:true});
  } else { bind(); }
  if (window.rcmail) {
    rcmail.addEventListener && rcmail.addEventListener('init', bind);
  }
})();

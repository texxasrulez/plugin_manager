
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
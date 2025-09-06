(function(){
  function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  function setIcon(el, active){
    if(!el) return;
    el.classList.add('plugin_manager');
    try {
      el.style.backgroundImage = 'url(' + (active ? window.PM_ICON_ACTIVE : window.PM_ICON_INACTIVE) + ')';
      el.style.backgroundRepeat = 'no-repeat';
      el.style.backgroundPosition = '0 0';
      el.style.webkitMaskImage = '';
      el.style.maskImage = '';
    } catch(e){}
  }
  function bindFor(icon){
    if(!icon) return;
    var li = icon.closest && icon.closest('li');
    var a  = icon.closest && icon.closest('a');
    var target = li || a || icon;
    var selected = false;
    if(li) selected = li.classList.contains('selected') || li.classList.contains('active');
    if(a)  selected = selected || a.classList.contains('selected') || a.classList.contains('active');
    setIcon(icon, !!selected);
    if('MutationObserver' in window && target){
      new MutationObserver(function(){
        var sel = target.classList.contains('selected') || target.classList.contains('active');
        setIcon(icon, sel);
      }).observe(target, { attributes:true, attributeFilter:['class'] });
    }
  }
  ready(function(){
    var sels = [
      '.listing li a[href*="plugin.plugin_manager"] .icon',
      '.boxlist li a[href*="plugin.plugin_manager"] .icon',
      '.navlist li a[href*="plugin.plugin_manager"] .icon',
      '#settings-menu li a[href*="plugin.plugin_manager"] .icon',
      'a[href*="plugin.plugin_manager"] .icon',
      'li.plugin_manager > a .icon',
      'li.plugin > a .icon' // last-resort for some templates
    ];
    var found = false;
    for(var i=0;i<sels.length;i++){
      var nodes = document.querySelectorAll(sels[i]);
      if(nodes && nodes.length){
        found = true;
        for(var j=0;j<nodes.length;j++) bindFor(nodes[j]);
      }
    }
    // last-resort: scan any .icon whose nearest text says Plugin Manager
    if(!found){
      var icons = document.querySelectorAll('.icon');
      for(var k=0;k<icons.length;k++){
        var ic = icons[k];
        var a = ic.closest && ic.closest('a');
        if(a && /plugin\.plugin_manager/.test(a.getAttribute('href')||'')){
          bindFor(ic);
          found = true;
        }
      }
    }
  });
})();
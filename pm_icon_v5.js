(function(){
  function log(){ if(window.console && console.debug){ console.debug.apply(console, ['[plugin_manager icon]'].concat([].slice.call(arguments))); } }
  function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  function selectNodes(){
    var sels = [
      '.listing li a[href*="plugin.plugin_manager"]',
      '.boxlist li a[href*="plugin.plugin_manager"]',
      '.navlist li a[href*="plugin.plugin_manager"]',
      '#settings-menu li a[href*="plugin.plugin_manager"]',
      'a[href*="plugin.plugin_manager"]'
    ];
    for(var i=0;i<sels.length;i++){
      var n = document.querySelectorAll(sels[i]);
      if(n && n.length) return Array.prototype.slice.call(n);
    }
    return [];
  }
  function setStyles(a, active){
    if(!a) return;
    var url = (active ? window.rcmail?.env?.pm_icon_active : window.rcmail?.env?.pm_icon_inactive) || window.PM_ICON_ACTIVE || window.PM_ICON_INACTIVE;
    if(!url) return;

    // Target icon span if present, else the anchor itself
    var icon = a.querySelector('.icon');
    var target = icon || a;

    // Nuke stock skin bg
    target.style.background = 'transparent none !important';
    target.style.backgroundImage = 'url(' + url + ')';
    target.style.backgroundRepeat = 'no-repeat';
    target.style.backgroundPosition = '0 0';

    // Ensure some left padding on <a> when styling anchor directly
    if(!icon){ a.style.paddingLeft = '24px'; }

    // Add class hook for skins if desired
    target.classList.add('plugin_manager');

    // For Elastic which may rely on mask/svg, ensure no mask interferes
    target.style.webkitMaskImage = '';
    target.style.maskImage = '';
  }
  function isActive(a){
    var li = a.closest('li');
    var state = a.classList.contains('active') || a.classList.contains('selected');
    if(li) state = state || li.classList.contains('active') || li.classList.contains('selected');
    return state;
  }
  function bind(a){
    setStyles(a, isActive(a));
    if('MutationObserver' in window){
      var target = a.closest('li') || a;
      new MutationObserver(function(){
        setStyles(a, isActive(a));
      }).observe(target, { attributes:true, attributeFilter:['class'] });
    }
  }
  function tick(maxTries){
    var as = selectNodes();
    if(as.length){
      log('menu links found:', as.length);
      as.forEach(bind);
      return true;
    }
    if(maxTries<=0) return false;
    setTimeout(function(){ tick(maxTries-1); }, 250);
    return false;
  }
  ready(function(){
    log('boot', { inactive: window.rcmail?.env?.pm_icon_inactive, active: window.rcmail?.env?.pm_icon_active });
    tick(20); // retry up to ~5s while settings panel mounts
  });
})();
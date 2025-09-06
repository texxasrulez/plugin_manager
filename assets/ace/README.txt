Local Ace build placeholder
---------------------------
Default load path tries local first: plugins/plugin_manager/assets/ace/ace.js
If not found, falls back to CDN:
  https://cdn.jsdelivr.net/npm/ace-builds@1.32.3/src-min-noconflict/

To use local only (e.g., strict CSP), place these files here:
  ace.js
  mode-php.js
  theme-monokai.js
  ext-language_tools.js
Optionally set:
  rcmail.env.pm_ace_base = 'plugins/plugin_manager/assets/ace';

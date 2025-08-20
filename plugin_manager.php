<?php
/**
 * Roundcube Plugin Manager
 * Folder: plugin_manager
 * Class: plugin_manager
 *
 * Lists installed/enabled plugins and (optionally) checks Packagist/GitHub for updates.
 * Includes robust debug logging. Enable via $config['pm_debug'] = true; or URL &_pm_debug=1
 *
 * @license MIT
 */

class plugin_manager extends rcube_plugin
{
    private $remote_checks = true;
    public $task = 'settings';

    /** @var rcube */
    private $rc;
    /** @var rcube_config */
    private $config;

    private $cache_file;
    private $cache_ttl;

    private $plugin_root;
    private $timeout;
    private $debug = false;
    private $force_check = '';
    private $diag = false;
    private $last_reason = '';
    private $last_via = '';
    private $gh_token = '';
    private $hidden_plugins = array();
function init()
    {
        $this->rc = rcube::get_instance();
        $this->pm_load_config();
        $this->add_texts('localization/');
        $rcmail = rcmail::get_instance();
        if ($rcmail->task === 'settings' && $rcmail->action === 'plugin.plugin_manager') {
        $this->register_handler('plugin.body', array($this, 'render_page'));
        }
        
        // Early handler registration so templates always resolve
                $this->log_debug('init current action', array('action'=>rcube_utils::get_input_value('_action', rcube_utils::INPUT_GPC)));

        $this->add_hook('settings_actions', array($this, 'settings_actions'));
        $this->register_action('plugin.plugin_manager', array($this, 'action_list'));
        $this->register_action('plugin_manager.refresh', array($this, 'action_refresh'));
            }

    /**
     * Load plugin config without clobbering parent signature
     */
    private function pm_load_config()
    {
        parent::load_config('config.inc.php.dist');
        parent::load_config('config.inc.php');

        $this->config        = $this->rc->config;
        $tempdir             = $this->config->get('temp_dir', sys_get_temp_dir());
        $this->cache_file    = rtrim($tempdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pm_cache.json';
        $this->cache_ttl     = (int)$this->config->get('pm_cache_ttl', 43200);
        $this->plugin_root   = $this->config->get('pm_plugin_root');
        $this->timeout       = (int)$this->config->get('pm_request_timeout', 7);
        $this->remote_checks = (bool)$this->config->get('pm_remote_checks', true);
        $this->force_check   = isset($_GET['_pm_check']) ? (string)$_GET['_pm_check'] : '';
        $this->debug         = (bool)$this->config->get('pm_debug', false) || isset($_GET['_pm_debug']);
        $this->diag           = isset($_GET['_pm_diag']);
        $this->gh_token       = (string)$this->config->get('pm_github_token', '');
        $this->hidden_plugins = array();
        $hp = $this->config->get('pm_hidden_plugins', array());
        if (!is_array($hp)) { $hp = array($hp); }
        foreach ($hp as $h) { if (is_string($h) && $h!=='') { $this->hidden_plugins[] = strtolower($h); } }
        $this->log_debug('pm_load_config done', array(
            'cache_file' => $this->cache_file,
            'remote_checks' => $this->remote_checks,
            'plugin_root' => $this->plugin_root
        ));
    }

    private function log_debug($msg, $context = array())
    {
        if (!$this->debug) return;
        $entry = '[' . date('c') . '] ' . $msg;
        if (!empty($context)) {
            $entry .= ' ' . json_encode($context);
        }
        rcube::write_log('plugin.plugin_manager', $entry);
    }

    function settings_actions($args)
    {
        $args['actions'][] = array(
            'action' => 'plugin.plugin_manager',
            'label'  => 'plugin_manager.plugin_manager_title',
            'domain' => 'plugin.plugin_manager',
            'class'  => 'plugin.plugin_manager',
        );
        return $args;
    }

    function action_toggle_remote()
    {
        $this->remote_checks = !$this->remote_checks;
        $statefile = $this->cache_file . '.state';
        @file_put_contents($statefile, json_encode(array('remote' => $this->remote_checks, 'ts' => time())));
        $this->rc->output->redirect(array('_action' => 'plugin_manager'));
    }

        private function read_toggle_state()
    {
        // start with config default
        $this->remote_checks = (bool)$this->config->get('pm_remote_checks', true);
        $statefile = $this->cache_file . '.state';
        if (is_readable($statefile)) {
            $data = json_decode(@file_get_contents($statefile), true);
            if (isset($data['remote'])) {
                $this->remote_checks = (bool)$data['remote'];
            }
        }
        // override via query toggle and persist
        if (isset($_GET['_pm_remote'])) {
            $this->remote_checks = $_GET['_pm_remote'] ? true : false;
            @file_put_contents($statefile, json_encode(array('remote' => $this->remote_checks, 'ts' => time())));
        }
    }

    function action_refresh()
    {
        @unlink($this->cache_file);
        $this->rc->output->redirect(array('_action' => 'plugin_manager'));
    }

    function action_list()
    {
        $this->read_toggle_state();
        $this->log_debug('action_list start');

        $this->rc->output->set_pagetitle($this->gettext('plugin_manager_title'));
        // Proper handler registration for template object
        
        $this->log_debug('sending template plugin');
        $this->rc->output->send('plugin');
    }

    function render_page()
    {
        try {
            $this->include_stylesheet($this->local_skin_path() . '/plugin_manager.css');
            $this->log_debug('render_page start');

            $plugins = $this->discover_plugins();
            $this->log_debug('discovered_plugins', array('count' => count($plugins)));

            $enabled = $this->enabled_plugins();

            $rows = array();
            foreach ($plugins as $info) {
                $dir = $info['dir'];
                $meta = $this->read_plugin_meta($dir);
                $local_version = $this->detect_local_version($dir, $meta);
                $sources = $this->build_sources($meta);

                $remote = array('version' => $this->gettext('unknown'), 'status' => $this->gettext('not_checked'), 'reason' => '');
                if (!empty($sources['bundled'])) {
                $remote['version'] = '—';
                $remote['status']  = $this->gettext('bundled');
                $remote['reason']  = 'bundled';
            } elseif ($this->remote_checks) {
                    $remote_ver = $this->latest_version_cached($sources);
                    if ($remote_ver) {
                        $remote['version'] = $remote_ver;
                        $cmp = $this->compare_versions($local_version, $remote_ver);
                        $remote['status'] = ($cmp >= 0) ? $this->gettext('up_to_date') : $this->gettext('update_available');
                    }
                }

            // i18n-safe: store a boolean, not a localized string
            $enabled_bool = in_array(basename($dir), $enabled, true);

            $rows[] = array(
                'name'    => $info['name'],
                'dir'     => basename($dir),
                'enabled' => $enabled_bool, // boolean
                'local'   => $local_version ?: $this->gettext('unknown'),
                'remote'  => $remote['version'],
                'status'  => $remote['status'],
                'reason'  => isset($remote['reason']) ? $remote['reason'] : '',
                'via'     => isset($remote['via']) ? $remote['via'] : '',
                'links'   => $sources,
            );
            }

            $h = array();
            $h[] = '<div class="box">';
            $h[] = '<h2>&nbsp;&nbsp;' . rcube::Q($this->gettext('plugin_manager_title')) . '</h2>';
            $h[] = '<p>&nbsp;&nbsp;&nbsp;&nbsp;' . rcube::Q($this->gettext('plugin_manager_desc')) . '</p>';
        if (!$this->remote_checks) {
            $u = $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_remote'=>1));
            $h[] = '&nbsp;&nbsp;<strong><div class="remote-off">' . rcube::Q($this->gettext('remote_off_notice')) . '</div></strong>';
        }


if ($this->diag) {
    $h[] = '<div class="box propform">';
    $h[] = '<h3>Connectivity diagnostics</h3>';
    $ok = array(); $msgs = array();
    // Packagist test
    $st=0;$er=null; $this->http_get2('https://repo.packagist.org/p2/roundcube/roundcubemail.json', array(), $st, $er);
    $ok[] = 'Packagist: HTTP ' . intval($st);
    // GitHub test
    $hdrs = array('User-Agent: Roundcube-Plugin-Manager');
    if (!empty($this->gh_token)) { $hdrs[] = 'Authorization: token ' . $this->gh_token; }
    $stg=0;$erg=null; $this->http_get2('https://api.github.com/repos/roundcube/roundcubemail/releases/latest', $hdrs, $stg, $erg);
    $ok[] = 'GitHub releases: HTTP ' . intval($stg);
    $h[] = '<ul><li>' . rcube::Q(implode('</li><li>', $ok)) . '</li></ul>';
    $h[] = '</div>';
}

            if ($this->debug) {
                $h[] = '<div class="notice"><strong>Debug on</strong>. Check logs/plugin_manager.log</div>';
            }
            $h[] = '<div style="margin:8px 0;">';
            
            $h[] = '<a class="button" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager')) . '">Reload</a> ' .
                   '<a class="button" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_diag'=>1)) . '">Diagnostics</a> ';
            $toggle_label = $this->remote_checks ? $this->gettext('disable_remote') : $this->gettext('enable_remote');
            $h[] = '<a class="button" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_remote'=>($this->remote_checks?0:1))) . '">' . rcube::Q($toggle_label) . '</a>';
            $h[] = '</div>';
        $h[] = '<script>(function(){var c=document.querySelector(".pm-scroll");if(!c)return;function fit(){var r=c.getBoundingClientRect();var vh=window.innerHeight||document.documentElement.clientHeight;var h=vh - r.top - 24; if(h<200) h=200; c.style.maxHeight=h+"px";}fit(); window.addEventListener("resize", fit);})();</script>';

            $h[] = '<div class="pm-scroll">';
        $h[] = '<table class="records-table" id="pm-table">';
            $h[] = '<thead><tr>'
                . '<th class="pm-sort" data-type="text">' . rcube::Q($this->gettext('plugin')) . '</th>'
                . '<th class="pm-sort" data-type="text">' . rcube::Q($this->gettext('directory')) . '</th>'
                . '<th class="pm-sort" data-type="bool">' . rcube::Q($this->gettext('enabled')) . ' / ' . rcube::Q($this->gettext('disabled')) .'</th>'
                . '<th class="pm-sort" data-type="semver">' . rcube::Q($this->gettext('version_local')) . '</th>'
                . '<th class="pm-sort" data-type="semver">' . rcube::Q($this->gettext('version_remote')) . '</th>'
                . '<th class="pm-sort" data-type="text">' . rcube::Q($this->gettext('status')) . '</th>'
                . '<th class="pm-sort" data-type="text">' . rcube::Q($this->gettext('actions')) . '</th>'
                . '</tr></thead><tbody>';

            foreach ($rows as $r) {
            // Build status cell HTML up-front (bold/color if update available; green+bold if up_to_date)
            $st = (string)$r['status'];
            $st_raw = strtolower($st);
            $st_html = rcube::Q($st);

            // Bold red-ish for updates (existing behavior keeps class for custom skins)
            if ($st === $this->gettext('update_available') || strpos($st_raw, 'update') !== false) {
                $st_html = '<strong class="pm-update">' . $st_html . '&nbsp;&nbsp;!!!</strong>';
            }

            // NEW: bold + Green Color for up_to_date (uses localized string; English fallback)
            if ($st === $this->gettext('up_to_date') || strpos($st_raw, 'up to date') !== false) {
                $st_html = '<strong class="pm-ok">' . $st_html . '&nbsp;&nbsp;&#10003;</strong>';
            }

            if (!empty($r['reason'])) {
                $st_html .= ' <span class="hint">(' . rcube::Q(trim($r['reason'] . (empty($r['via']) ? '' : ( $r['reason'] ? ', ' : '' ) . 'via ' . $r['via']))) . ')</span>';
            }

                $links_html = array();
                if (!empty($r['links']['packagist'])) {
                    $links_html[] = '<a target="_blank" rel="noreferrer" href="'.rcube::Q($r['links']['packagist']).'">Packagist</a>';
                }
                if (!empty($r['links']['github'])) {
                    $links_html[] = '<a target="_blank" rel="noreferrer" href="'.rcube::Q($r['links']['github']).'">GitHub</a>';
                }

                $rowcls = ($r['status'] === $this->gettext('bundled')) ? ' class="pm-bundled"' : '';

                // Enabled column: i18n-safe label + data-sort attribute
                $en_label_yes = rcube::Q($this->gettext('enabled'));
                $en_label_no  = rcube::Q($this->gettext('disabled'));
                $en_html = $r['enabled'] ? '<strong class="pm-enabled">' . $en_label_yes . '</strong>' : '<strong class="pm-disabled">' . $en_label_no . '</strong>';
                $en_sort = $r['enabled'] ? '1' : '0';

            $h[] = '<tr' . $rowcls . '>'
                    . '<td>' . rcube::Q($r['name']) . '</td>'
                    . '<td>' . rcube::Q($r['dir']) . '</td>'
                    . '<td data-sort="' . $en_sort . '">' . $en_html . '</td>'
                    . '<td>' . rcube::Q($r['local']) . '</td>'
                    . '<td>' . rcube::Q($r['remote']) . '</td>'
                    . '<td>' . $st_html . '</td>'
                    . '<td>' . implode(' &middot; ', $links_html) . '</td>'
                    . '</tr>';
            }

            
        $h[] = '</tbody></table>';
        $h[] = '<script>
		(function(){
		  var table = document.getElementById("pm-table");
		  if (!table) return;
		  function cellText(cell){ return (cell && (cell.textContent || cell.innerText) || "").trim(); }
		  function parseSemver(v){
			v = (v||"").trim();
			if (!v || v === "—") return {k:[-1]};
			// Handle "unknown" (translated or not): treat as lowest
			var low = ["unknown","unk","?"];
			var vl = v.toLowerCase();
			for (var i=0;i<low.length;i++){ if (vl.indexOf(low[i]) !== -1) return {k:[-1]}; }
			// strip leading v
			v = v.replace(/^v/i,"");
			// split by non-alphanum to capture digits and lex parts (e.g., -alpha)
			var parts = v.split(/[^0-9a-zA-Z]+/).filter(Boolean);
			var nums = [];
			for (var j=0;j<parts.length;j++){
			  var p = parts[j];
			  if (/^\d+$/.test(p)) nums.push(parseInt(p,10));
			  else {
				// pre-release tags sort lower than any numeric patch
				nums.push(-0.5);
			  }
			}
			return {k:nums, raw:v};
		  }
		  function cmpCells(aCell,bCell,type){
			if (type==="bool"){
			  var av = parseInt(aCell.getAttribute("data-sort") || "0",10);
			  var bv = parseInt(bCell.getAttribute("data-sort") || "0",10);
			  return av - bv;
			}
			if (type==="semver"){
			  var sa = parseSemver(cellText(aCell)).k, sb = parseSemver(cellText(bCell)).k;
			  var n = Math.max(sa.length, sb.length);
			  for (var i=0;i<n;i++){
				var ai = (i<sa.length)?sa[i]:0, bi=(i<sb.length)?sb[i]:0;
				if (ai !== bi) return ai - bi;
			  }
			  return 0;
			}
			// default text, natural-ish compare (case-insensitive)
			var a = cellText(aCell).toLowerCase(), b = cellText(bCell).toLowerCase();
			if (a === b) return 0;
			return a > b ? 1 : -1;
		  }
		  var thead = table.tHead;
		  if (!thead) return;
		  var headers = thead.rows[0].cells;
		  var tbody = table.tBodies[0];
		  function clearSortIndicators(){
			for (var i=0;i<headers.length;i++){
			  headers[i].removeAttribute("aria-sort");
			  headers[i].classList.remove("pm-sorted-asc","pm-sorted-desc");
			}
		  }
		  function sortBy(colIndex, type, dir){
			var rows = Array.prototype.slice.call(tbody.rows);
			rows.sort(function(r1,r2){
			  var c1 = r1.cells[colIndex]||document.createElement("td");
			  var c2 = r2.cells[colIndex]||document.createElement("td");
			  var c = cmpCells(c1,c2,type);
			  return dir==="asc" ? c : -c;
			});
			var frag = document.createDocumentFragment();
			rows.forEach(function(r){ frag.appendChild(r); });
			tbody.appendChild(frag);
			clearSortIndicators();
			headers[colIndex].setAttribute("aria-sort", dir === "asc" ? "ascending" : "descending");
			headers[colIndex].classList.add(dir==="asc"?"pm-sorted-asc":"pm-sorted-desc");
		  }
		  var state = {col: null, dir: "asc"};
		  for (let i=0;i<headers.length;i++){
			let th = headers[i];
			if (!th.classList.contains("pm-sort")) continue;
			th.style.cursor = "pointer";
			th.setAttribute("role","button");
			th.addEventListener("click", function(){
			  var type = th.getAttribute("data-type") || "text";
			  if (state.col === i){ state.dir = (state.dir==="asc"?"desc":"asc"); }
			  else { state.col = i; state.dir = "asc"; }
			  sortBy(i, type, state.dir);
			});
		  }
		})();</script>';

        $h[] = '</div>';
        $h[] = '<script>(function(){var c=document.querySelector(".pm-scroll");if(!c)return;function fit(){var r=c.getBoundingClientRect();var vh=window.innerHeight||document.documentElement.clientHeight;var h=vh - r.top - 24; if(h<200) h=200; c.style.maxHeight=h+"px";}fit(); window.addEventListener("resize", fit);})();</script>';
            $h[] = '</div>';
        $h[] = '<script>(function(){var c=document.querySelector(".pm-scroll");if(!c)return;function fit(){var r=c.getBoundingClientRect();var vh=window.innerHeight||document.documentElement.clientHeight;var h=vh - r.top - 24; if(h<200) h=200; c.style.maxHeight=h+"px";}fit(); window.addEventListener("resize", fit);})();</script>';

            $html = implode("
", $h);
            $this->log_debug('render_page end', array('html_len' => strlen($html)));
            return $html;
        } catch (Throwable $e) {
            $msg = 'Plugin Manager error: ' . get_class($e) . ' ' . $e->getMessage();
            $this->log_debug($msg, array('trace' => $e->getTraceAsString()));
            return '<div class=\"box warning\">' . rcube::Q($msg) . '</div>';
        }
    }

    private function enabled_plugins()
    {
        $list = $this->config->get('plugins', array());
        if (!is_array($list)) return array();
        return array_map(function($x){
            return is_array($x) ? ($x['name'] ?? '') : (string)$x;
        }, $list);
    }

    private function plugins_root_dir()
    {
        if (!empty($this->plugin_root) && is_dir($this->plugin_root)) {
            return realpath($this->plugin_root);
        }
        $here = dirname(__FILE__);
        $plugins = dirname($here);
        return realpath($plugins);
    }

    private function discover_plugins()
    {
        $root = $this->plugins_root_dir();
        $this->log_debug('discover_plugins root', array('root' => $root));
        $out = array();
        if (!$root) return $out;

        $dh = @opendir($root);
        if (!$dh) return $out;

        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            $dir = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($dir)) {
                if (!empty($this->hidden_plugins) && in_array(strtolower($entry), $this->hidden_plugins, true)) { continue; }
                $out[$entry] = array('name' => $entry, 'dir' => $dir);
            }
        }
        closedir($dh);
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    private function read_plugin_meta($dir)
    {
        $meta = array('composer' => null, 'mainfile' => null);
        $cj = $dir . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_readable($cj)) {
            $json = json_decode(@file_get_contents($cj), true);
            if (is_array($json)) $meta['composer'] = $json;
        }
        $base = basename($dir);
        $candidates = array(
            $dir . DIRECTORY_SEPARATOR . $base . '.php',
            $dir . DIRECTORY_SEPARATOR . 'plugin.php',
            $dir . DIRECTORY_SEPARATOR . $base . '_plugin.php',
        );
        foreach ($candidates as $mf) {
            if (is_readable($mf)) { $meta['mainfile'] = $mf; break; }
        }
        return $meta;
    }

    private function detect_local_version($dir, $meta)
    {
        if (!empty($meta['composer']['version'])) {
            return (string)$meta['composer']['version'];
        }
        if (!empty($meta['mainfile'])) {
            $src = @file_get_contents($meta['mainfile']);
            if ($src !== false) {
                if (preg_match('/const\s+VERSION\s*=\s*[\'"]([^\'"]+)[\'"]\s*;?/i', $src, $m)) {
                    return trim($m[1]);
                }
                if (preg_match('/define\s*\(\s*[\'"]VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)\s*;?/i', $src, $m)) {
                    return trim($m[1]);
                }
                if (preg_match('/^\s*\*\s*Version:\s*([0-9][0-9a-zA-Z\.\-\+_]*)/mi', $src, $m)) {
                    return trim($m[1]);
                }
            }
        }
        $vf = $dir . DIRECTORY_SEPARATOR . 'VERSION';
        if (is_readable($vf)) {
            $v = trim(@file_get_contents($vf));
            if ($v !== '') return $v;
        }
        return null;
    }    

private function load_sources_map() {
    $file = $this->home . '/sources.map.php';
    if (is_readable($file)) {
        $map = @include($file);
        if (is_array($map)) return $map;
    }
    return array();
}

    private function build_sources($meta)
{
    $out = array('packagist' => null, 'github' => null, 'composer_name' => null);
    if (!empty($meta['composer']['name'])) {
            $name = $meta['composer']['name'];
            $out['composer_name'] = $name;
            $out['packagist'] = 'https://packagist.org/packages/' . rawurlencode($name);
        }
        $gh = null;
        if (!empty($meta['composer']['support']['source'])) {
            $gh = $meta['composer']['support']['source'];
        } elseif (!empty($meta['composer']['homepage'])) {
            $gh = $meta['composer']['homepage'];
        }

    if ($gh && preg_match('~^https?://github\.com/[^/\s]+/[^/\s#]+~i', $gh, $m)) {
        $out['github'] = rtrim($m[0], '/');
    }
    // Heuristic: scan README files for a GitHub repo URL
    if (empty($out['github']) && !empty($meta['mainfile'])) {
        $dir = dirname($meta['mainfile']);
        foreach (array('README.md','README','readme.md','readme') as $rf) {
            $rp = $dir . DIRECTORY_SEPARATOR . $rf;
            if (is_readable($rp)) {
                $txt = @file_get_contents($rp);
                if ($txt && preg_match('~https?://github\.com/[^/\s]+/[^/\s#]+~i', $txt, $m2)) {
                    $out['github'] = rtrim($m2[0], '/');
                    break;
                }
            }
        }
    }
    // Heuristic: scan main plugin file comments for a "URL:" or GitHub link
    if (empty($out['github']) && !empty($meta['mainfile']) && is_readable($meta['mainfile'])) {
        $src = @file_get_contents($meta['mainfile']);
        if ($src && preg_match('~https?://github\.com/[^/\s]+/[^/\s#]+~i', $src, $m3)) {
            $out['github'] = rtrim($m3[0], '/');
        }
    }
    return $out;
}

    private function latest_version_cached($sources, $force=false)
    {
        $this->last_reason = '';
        $this->last_via = '';
        $cache = $this->read_cache();
        $key = sha1(json_encode($sources));
        if (!$force && isset($cache[$key]) && (time() - $cache[$key]['ts'] < $this->cache_ttl)) {
            $this->last_via = isset($cache[$key]['via']) ? $cache[$key]['via'] : '';
            $this->last_reason = isset($cache[$key]['reason']) ? $cache[$key]['reason'] : '';

            return $cache[$key]['ver'];
        }
        $meta = $this->latest_version_online($sources);
        $ver = $meta ? (isset($meta['ver']) ? $meta['ver'] : null) : null;
        if ($meta) { $this->last_via = isset($meta['via']) ? $meta['via'] : ''; $this->last_reason = isset($meta['reason']) ? $meta['reason'] : ''; }
        if ($ver) {
            $cache[$key] = array('ver' => $ver, 'ts' => time(), 'via' => $this->last_via, 'reason' => $this->last_reason);
            $this->write_cache($cache);
        }
        return $ver;
    }

    private function read_cache()
    {
        if (is_readable($this->cache_file)) {
            $json = json_decode(@file_get_contents($this->cache_file), true);
            if (is_array($json)) return $json;
        }
        return array();
    }

    private function write_cache($data)
    {
        @file_put_contents($this->cache_file, json_encode($data));
    }

    private function latest_version_online($sources)
    {
        $result = array('ver'=>null,'via'=>'','reason'=>'');
        $result = array('ver'=>null,'via'=>'','reason'=>'');
        $result = array('ver'=>null,'via'=>'','reason'=>'');
        if (!empty($sources['composer_name'])) {
            $name = $sources['composer_name'];
            $url = 'https://repo.packagist.org/p2/' . $name . '.json';
            $st=0;$er=null; $resp = $this->http_get2($url, array(), $st, $er);
            if ($st === 200 && $resp && ($data = json_decode($resp, true)) && !empty($data['packages'][$name])) {
                $versions = $data['packages'][$name];
                $best = null;
                foreach ($versions as $v) {
                    $ver = isset($v['version']) ? $v['version'] : null;
                    if (!$ver) continue;
                    if (preg_match('/^dev-|dev$/i', $ver)) continue;
                    if ($best === null || version_compare(ltrim($best,'v'), ltrim($ver,'v')) < 0) {
                        $best = $ver;
                    }
                }
                if ($best) { $result['ver']=$best; $result['via']='packagist'; return $result; }
            }
        }
        if (!empty($sources['github'])) {
            $gh = $sources['github'];
            if (preg_match('~github\.com/([^/\s]+)/([^/\s#]+)~i', $gh, $m)) {
                $repo = $m[1] . '/' . $m[2];
                $hdrs = array('User-Agent: Roundcube-Plugin-Manager');
                if (!empty($this->gh_token)) { $hdrs[] = 'Authorization: token ' . $this->gh_token; }
                $api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
                $st=0;$er=null; $resp = $this->http_get2($api, $hdrs, $st, $er);
                if ($st === 200 && $resp && ($data = json_decode($resp, true)) && !empty($data['tag_name'])) {
                    $result['ver'] = ltrim((string)$data['tag_name'], 'v');
                    $result['via'] = 'github-release';
                    return $result;
                }
                // Fallback: fetch tags and pick the highest semver-ish
                $api2 = 'https://api.github.com/repos/' . $repo . '/tags';
                $st2=0;$er2=null; $resp2 = $this->http_get2($api2, $hdrs, $st2, $er2);
                if ($st2 === 200 && $resp2 && ($tags = json_decode($resp2, true)) && is_array($tags)) {
                    $best = null;
                    foreach ($tags as $t) {
                        $tag = isset($t['name']) ? $t['name'] : '';
                        if (!$tag) continue;
                        $tag = ltrim($tag, 'v');
                        if ($best === null || version_compare($best, $tag) < 0) { $best = $tag; }
                    }
                    if ($best) { $result['ver']=$best; $result['via']='github-tags'; return $result; }
                }
                $result['reason'] = $st ? 'http_' . $st : 'no_release';
            }
        }
        return $result;
    }

    private function compare_versions($local, $remote)
    {
        if (!$local) return -1;
        $a = ltrim((string)$local, 'v');
        $b = ltrim((string)$remote, 'v');
        return version_compare($a, $b);
    }

private function http_get2($url, $headers = array(), &$status = null, &$err = null)
{
    $status = 0; $err = null;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
        ));
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return false;
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($resp, $header_size);
        $status = (int)$code;
        curl_close($ch);
        return $body;
    }
    $ctx = stream_context_create(array('http' => array('method' => 'GET', 'timeout'=> $this->timeout, 'header' => implode("\r\n", $headers))));
    $body = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) { $status = (int)$m[1]; break; }
        }
    }
    if ($body === false) { $err = 'stream_error'; }
    return $body;
}

private function http_get($url, $headers = array())
{
    $st = 0; $er = null;
    return $this->http_get2($url, $headers, $st, $er);
}
}

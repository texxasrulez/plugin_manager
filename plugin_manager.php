<?php
/**
 * Roundcube Plugin Manager
 * Folder: plugin_manager
 * Class: plugin_manager
 *
 * Lists installed/enabled plugins and (optionally) checks Packagist/GitHub for updates.
 * Includes robust debug logging. Enable via $config['pm_debug'] = true; or URL &_pm_debug=1
 *
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
    private $last_ts = 0;
    private $gh_token = '';
    private $hidden_plugins = array();

    // --- Simple flash message persistence across redirects (works in all skins) ---
    private function flash_add($message, $type = 'notice')
    {
        $file = $this->cache_file . '.flash';
        $list = array();
        if (is_readable($file)) {
            $j = json_decode(@file_get_contents($file), true);
            if (is_array($j)) $list = $j;
        }
        $list[] = array('m' => (string)$message, 't' => (string)$type);
        @file_put_contents($file, json_encode($list));
    }

    private function flash_flush()
    {
        $file = $this->cache_file . '.flash';
        if (!is_readable($file)) return;
        $j = json_decode(@file_get_contents($file), true);
        @unlink($file);
        if (is_array($j)) {
            foreach ($j as $it) {
                $m = isset($it['m']) ? (string)$it['m'] : '';
                $t = isset($it['t']) ? (string)$it['t'] : 'notice';
                if ($m !== '') $this->rc->output->show_message($m, $t);
            }
        }
    }

    function init()
    {
        $this->rc = rcube::get_instance();
        $this->pm_load_config();
        $this->maybe_send_update_alert();

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
        $this->register_action('plugin_manager.update', array($this, 'action_update'));
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
        $this->diag          = isset($_GET['_pm_diag']);
        $this->gh_token      = (string)$this->config->get('pm_github_token', '');
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
        $this->rc->output->redirect(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
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
        $this->rc->output->redirect(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
    }

    function action_list()
    {
        // Inline bulk update (config-gated)
        if ($this->cfg_true('pm_enable_update_all', true) && $this->is_update_admin()) {
            $pm_all = rcube_utils::get_input_value('_pm_update_all', rcube_utils::INPUT_GPC);
            $pm_dry = rcube_utils::get_input_value('_pm_dry', rcube_utils::INPUT_GPC) ? true : false;
            $this->log_debug('bulk_handler', array('where'=>'action_list', 'pm_all'=>$pm_all, 'pm_dry'=>$pm_dry));
            if ($pm_all) { /* inline bulk path */
                $res = $this->update_all_outdated($pm_dry);
                $summary = $pm_dry
                    ? sprintf(rcube::Q($this->gettext('testing_complete')) . ': %d would update, %d would fail, %d skipped.', (int)$res['ok'], (int)$res['fail'], (int)count($res['skipped']))
                    : sprintf(rcube::Q($this->gettext('bulk_update_complete')) . ': %d updated, %d failed, %d skipped.', (int)$res['ok'], (int)$res['fail'], (int)count($res['skipped']));
                // Persist message via flash (more reliable across redirect/skins)
                $this->flash_add($summary, $pm_dry ? 'notice' : ($res['fail'] ? 'warning' : 'confirmation'));
                if (!empty($res['skipped'])) {
                    $detail = rcube::Q($this->gettext('details')) . ': ' . $this->format_skip_reasons($res['skipped']);
                    $this->flash_add($detail, 'notice');
                }
                $this->send_webhook('bulk', array('ok'=>$res['ok'],'fail'=>$res['fail']));
                $this->rc->output->redirect(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
                return;
            }
        }

        // Inline update fallback handler (runs before rendering list)
        $uid = method_exists($this->rc, 'get_user_id') ? intval($this->rc->get_user_id()) : (isset($this->rc->user) && isset($this->rc->user->ID) ? intval($this->rc->user->ID) : 0);
        $pm_upd = rcube_utils::get_input_value('_pm_update', rcube_utils::INPUT_GPC);
        $pm_dir = rcube_utils::get_input_value('_pm', rcube_utils::INPUT_GPC);
        $pm_rst = rcube_utils::get_input_value('_pm_restore', rcube_utils::INPUT_GPC);
        if ($pm_rst && $pm_dir && $uid === 1) {
            $pm_dir = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$pm_dir);
            try {
                $bak = $this->restore_plugin($pm_dir);
                if ($bak) {
                    $this->flash_add(''. rcube::Q($this->gettext('restore_finished')) .': ' . rcube::Q($pm_dir) . ' <= ' . rcube::Q($bak), 'confirmation');
                } else {
                    $this->flash_add(''. rcube::Q($this->gettext('restore_nothing')) .': ' . rcube::Q($pm_dir), 'notice');
                }
            } catch (Exception $ex) {
                $this->flash_add('Restore failed: ' . rcube::Q($ex->getMessage()), 'error');
            }
            $this->rc->output->redirect(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
            return;
        }

        if ($pm_upd && $pm_dir && $uid === 1) {
            $pm_dir = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$pm_dir);
            try {
                $ok = $this->perform_update($pm_dir);
                if ($ok === true) {
                    $this->flash_add(rcube::Q($this->gettext('plugin_update_good')) . '.', 'confirmation');
                    $this->send_webhook('update', array('plugin'=>$pm_dir));
                    $this->rc->output->redirect(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
                    return;
                } else {
                    $this->flash_add(rcube::Q($this->gettext('udpate_finished')) . ': ' . rcube::Q((string)$ok), 'notice');
                }
            } catch (Exception $e) {
                $this->log_debug('inline update error', array('plugin'=>$pm_dir, 'err'=>$e->getMessage()));
                $this->flash_add(rcube::Q($this->gettext('udpate_failed')) . ': ' . rcube::Q($e->getMessage()), 'error');
                $this->send_webhook('update', array('plugin'=>$pm_dir, 'error'=>true));
                $this->rc->output->redirect(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
                return;
            }
        }

        $this->read_toggle_state();
        $this->log_debug('action_list start');

        $this->rc->output->set_pagetitle($this->gettext('plugin_manager_title'));
        // Proper handler registration for template object

        $this->log_debug('sending template plugin');
        $this->rc->output->send('plugin');
    }

    function render_page()
    {
        // Flush any pending flash messages from a prior redirect
        $this->flash_flush();

        // Handle Update All or refresh early
        $pm_all = rcube_utils::get_input_value('_pm_update_all', rcube_utils::INPUT_GPC);
        $pm_dry = rcube_utils::get_input_value('_pm_dry', rcube_utils::INPUT_GPC) ? true : false;
        $pm_refresh = rcube_utils::get_input_value('_pm_refresh', rcube_utils::INPUT_GPC);

        if ($pm_refresh) {
            @unlink($this->cache_file);
            $this->rc->output->redirect(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
            return;
        }

        $this->log_debug('update_all_trigger', array('pm_all'=>$pm_all, 'pm_dry'=>$pm_dry, 'where'=>'detect'));
        if ($pm_all && $this->cfg_true('pm_enable_update_all', true) && $this->is_update_admin()) {
            // Do the same as action_list path just in case the template gets here first
            $this->log_debug('bulk_handler', array('where'=>'render_page'));
            $res = $this->update_all_outdated($pm_dry);
            $summary = $pm_dry
                ? sprintf(rcube::Q($this->gettext('testing_complete')) . ': %d would update, %d would fail, %d skipped.', (int)$res['ok'], (int)$res['fail'], (int)count($res['skipped']))
                : sprintf(rcube::Q($this->gettext('bulk_update_complete')) . ': %d updated, %d failed, %d skipped.', (int)$res['ok'], (int)$res['fail'], (int)count($res['skipped']));
            $this->flash_add($summary, $pm_dry ? 'notice' : ($res['fail'] ? 'warning' : 'confirmation'));
            if (!empty($res['skipped'])) {
                $detail = rcube::Q($this->gettext('details')) . ': ' . rcube::Q($this->format_skip_reasons($res['skipped']));
                $this->flash_add($detail, 'notice');
            }
            $this->send_webhook('bulk', array('ok'=>$res['ok'],'fail'=>$res['fail']));
            $this->rc->output->redirect(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
            return;
        }

        try {
            $this->include_stylesheet($this->local_skin_path() . '/plugin_manager.css');
            // Column width tuning (configurable percentages)
            $cw = (array)$this->config->get('pm_column_widths', array('local'=>'8%','latest'=>'8%','status'=>'30%'));
            $local_w  = isset($cw['local'])  ? $cw['local']  : '8%';
            $latest_w = isset($cw['latest']) ? $cw['latest'] : '8%';
            $status_w = isset($cw['status']) ? $cw['status'] : '30%';
            $this->rc->output->add_header('<style>
                #pm-table { table-layout:auto; }
                #pm-table td:nth-child(4), #pm-table th:nth-child(4) { width: ' . rcube::Q($local_w) . '; }
                #pm-table td:nth-child(5), #pm-table th:nth-child(5) { width: ' . rcube::Q($latest_w) . '; }
                #pm-table td:nth-child(6), #pm-table th:nth-child(6) { width: ' . rcube::Q($status_w) . '; white-space: nowrap; }
            
				.pm-skip {
					background:#ccc;
					color:#333;
					border-radius:8px;
					padding:1px 5px;
					margin-left:6px;
					font-size:80%;
					vertical-align:middle;
				}
				</style>');
            $this->rc->output->add_header('<style>.pm-checked { color: #666; font-size: 90%; }</style>');
            $this->rc->output->add_header('<style>.pm-skip-hint { margin-left:6px; cursor:help; font-weight:bold; } .pm-skip-hint:hover { filter: brightness(0.85); }</style>');

            $this->log_debug('render_page start');

            $plugins = $this->discover_plugins();
            $this->log_debug('discovered_plugins', array('count' => count($plugins)));

            $enabled = $this->enabled_plugins();

            $rows = array();
            foreach ($plugins as $info) {
                $dir = $info['dir'];
                $meta = $this->read_plugin_meta($dir);

                $base = basename($dir);
                $policy = $this->policy_for($base);
                if ($policy['ignored']['ui']) {
                    continue; // hide from UI entirely
                }
                $local_version = $this->detect_local_version($dir, $meta);
                $sources = $this->build_sources($meta);

                $remote = array('version' => $this->gettext('unknown'), 'status' => $this->gettext('not_checked'), 'reason' => '');
                $checked_ts = 0;
                if (!empty($sources['bundled'])) {
                    $remote['version'] = '—';
                    $remote['status']  = $this->gettext('bundled');
                    $remote['reason']  = 'bundled';
                } elseif ($this->remote_checks && !$policy['ignored']['discover']) {
                    $remote_ver = $this->latest_version_cached($sources);
                    if ($remote_ver) {
                        $checked_ts = $this->last_ts ?: 0;
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
                    'checked_ts' => $checked_ts,
                    'policy'  => $policy,
                    'policy_reason' => isset($policy['reason']) ? $policy['reason'] : '',
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
                $h[] = '<h3>&nbsp;&nbsp;&nbsp;' . rcube::Q($this->gettext('conn_diag')) . '</h3>';
                $ok = array(); $msgs = array();
                // Packagist test
                $st=0;$er=null; $this->http_get2('https://repo.packagist.org/p2/roundcube/roundcubemail.json', array(), $st, $er);
                // GitHub test
                $hdrs = array('User-Agent: Roundcube-Plugin-Manager');
                if (!empty($this->gh_token)) { $hdrs[] = 'Authorization: token ' . $this->gh_token; }
                $stg=0;$erg=null; $this->http_get2('https://api.github.com/repos/roundcube/roundcubemail/releases/latest', $hdrs, $stg, $erg);
                $ok[] = rcube::Q($this->gettext('github_test')) . intval($stg);
                $h[] = '<ul><li>' . rcube::Q(implode('</li><li>', $ok)) . '</li></ul>';
                $h[] = '</div>';
            }

            if ($this->debug) {
                $h[] = '<div class="pm-disabled"><strong>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . rcube::Q($this->gettext('debug_on')) . '</strong>. ' . rcube::Q($this->gettext('debug_on_text')) . '</div>';
            }
            $h[] = '<div style="margin:8px 0;">';

            $h[] = '<a class="button" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager')) . '">' . rcube::Q($this->gettext('reload_page')) . '</a> ' .
                   '<a class="button" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_diag'=>1)) . '">' . rcube::Q($this->gettext('diagnostics')) . '</a> ';
            $toggle_label = $this->remote_checks ? $this->gettext('disable_remote') : $this->gettext('enable_remote');
            $h[] = '<a class="button" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_remote'=>($this->remote_checks?0:1))) . '">' . rcube::Q($toggle_label) . '</a>' .
                   ' <a class="button" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_refresh'=>1)) . '">' . rcube::Q($this->gettext('refresh_versions')) . '</a>&nbsp;&nbsp;<span class="pm-lastupdate" style="margin:6px 0 4px 0;">' . rcube::Q($this->gettext('last_checked')) . ':&nbsp;' . ( $this->last_ts ? '<span class="pm-checked">' . rcube::Q($this->pm_time_ago($this->last_ts)) . '</span>' : '<span class="pm-checked">'. rcube::Q($this->gettext('never')) .'</span>' ) . '</div>';
            $h[] = '</div>';
            $h[] = '<script>(function(){var c=document.querySelector(".pm-scroll");if(!c)return;function fit(){var r=c.getBoundingClientRect();var vh=window.innerHeight||document.documentElement.clientHeight;var h=vh - r.top - 24; if(h<200) h=200; c.style.maxHeight=h+"px";}fit(); window.addEventListener("resize", fit);})();</script>';

            if ($this->cfg_true('pm_enable_update_all', true) && $this->is_update_admin()) {
                $eligible = (int)$this->eligible_count();
                $btn  = '<div class="pm-bulkbar" style="margin:10px 0;">';
                $btn .= '<a class="button pm-update-all" href="?_task=settings&_action=plugin.plugin_manager&_pm_update_all=1"';
                $btn .= ' onclick="if(!confirm(&quot; '. rcube::Q($this->gettext('update_all_ood')) . '?&quot;)) return false; this.textContent=&quot;'. rcube::Q($this->gettext('update_all')) . '...&quot;; this.style.pointerEvents=&quot;none&quot;; return true;">';
                $btn .= rcube::Q($this->gettext('updateall')) . ' <span class="pm-badge" style="margin-left:8px;padding:2px 7px;border-radius:10px;font-size:85%;display:inline-block;">' . $eligible . '</span>';
                $btn .= '</a>';
                $btn .= ' <a class="button pm-update-all" href="?_task=settings&_action=plugin.plugin_manager&_pm_update_all=1&_pm_dry=1"';
                $btn .= ' onclick="this.textContent=\'' .rcube::Q($this->gettext('testing_update')) . ' ...\'; this.style.pointerEvents=\'none\'; return true;">';
                $btn .= rcube::Q($this->gettext('test_update'));
                $btn .= '</a></div>';
                $h[] = $btn;
            }

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
                // Precompute optional Update All anchor (config-gated)
                $uall_html = '';
                if ($this->cfg_true('pm_enable_update_all', true) && $this->is_update_admin()) {
                    $uall_html = ' <a class="pm-update-all" href="?_task=settings&_action=plugin.plugin_manager&_pm_update_all=1" onclick="this.textContent=&quot;' . rcube::Q($this->gettext('updating_all')) .  '...&quot;; this.style.pointerEvents=&quot;none&quot;; return true;">[' . rcube::Q($this->gettext('update')) . ']</a>';
                }

                $dir_name = basename($r['dir']);

                // Build status cell HTML up-front (bold/color if update available; green+bold if up_to_date)
                $st = (string)$r['status'];
                $st_raw = strtolower($st);
                $st_html = rcube::Q($st);

                // Bold red-ish for updates (existing behavior keeps class for custom skins)
                if ($st === $this->gettext('update_available') || strpos($st_raw, 'update') !== false) {

                    // Only show direct Update link to Roundcube user id=1 (admin list)
                    $uid = method_exists($this->rc, 'get_user_id') ? intval($this->rc->get_user_id()) : (isset($this->rc->user) && isset($this->rc->user->ID) ? intval($this->rc->user->ID) : 0);
                    if ($this->is_update_admin() && (empty($r['policy']['pinned']) || $this->config->get('pm_pins_allow_manual', true))) {
                        $u = $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_update'=>1,'_pm'=>$r['dir']));
                        $st_html .= ' <a class="pm-update-link" href="' . rcube::Q($u) . '" onclick="if(!confirm(\'' . rcube::Q($this->gettext('dnload_update')) . '?\')) return false; this.textContent=\'' . rcube::Q($this->gettext('updating')) . '...\'; this.style.pointerEvents=\'none\'; return true;">[Update]</a>';
                        // Optional global Update All button is injected at top of the page; no per-row "Update All" here.
                    }

                    // Policy badges
                    if (!empty($r['policy']['pinned'])) {
                        $st_html .= ' <span class="pm-policy-badge pm-badge-pinned">[Pinned ' . rcube::Q($r['policy']['pinned']) . ']</span>';
                    }
                    $ignored_for = array();
                    foreach (array('bulk','notify') as $sc) {
                        if (!empty($r['policy']['ignored'][$sc])) $ignored_for[] = $sc;
                    }
                    if (!empty($ignored_for)) {
                        $st_html .= ' <span class="pm-policy-badge pm-badge-ignored">[Ignored: ' . rcube::Q(implode(',', $ignored_for)) . ']</span>';
                    }
                    $st_html = '<strong class="pm-update">' . $st_html . '</strong>';
                }

                // NEW: bold + Green Color for up_to_date (uses localized string; English fallback)
                if ($st === $this->gettext('up_to_date') || strpos($st_raw, 'up to date') !== false) {
                    $st_html = '<strong class="pm-ok">' . $st_html . '&nbsp;&nbsp;&#10003;</strong>';
                }
                // Offer Restore if a backup exists (admin-only), even when up to date
                if ($this->is_update_admin() && $this->has_backup($dir_name)) {
                    $rst  = $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_restore'=>1,'_pm'=>$r['dir']));
                    $st_html .= ' <a class="pm-restore-link" href="' . rcube::Q($rst) . '" onclick="return confirm(\' '. rcube::Q($this->gettext('version_local')) . '?\');">[' . rcube::Q($this->gettext('restore')) . ']</a>';
                }

                if (!empty($r['reason']) || !empty($r['via'])) {
                    $st_html .= ' <span class="hint">(' . rcube::Q(trim(($r['reason'] ? $r['reason'] : '') . (empty($r['via']) ? '' : (($r['reason'] ? ', ' : '') . 'via ' . $r['via'])))) . ')</span>';
                }

                // Inline skip reasons tooltip (explains why Update All would skip this row)
                $__skip_msgs = array();
                if (!empty($r['policy']['pinned'])) {
                    $__skip_msgs[] = '' . rcube::Q($this->gettext('pin_to')) . ' ' . rcube::Q((string)$r['policy']['pinned']);
                }
                if (!empty($r['policy']['ignored']['bulk'])) {
                    $__skip_msgs[] = rcube::Q($this->gettext('ignore_updates'));
                }
                if (!empty($r['policy']['ignored']['discover'])) {
                    $__skip_msgs[] = rcube::Q($this->gettext('remote_disabled'));
                }
                // If remote is missing (not bundled) and not up_to_date, note missing source
                $remote_raw = isset($r['remote']) ? (string)$r['remote'] : '';
                $status_raw = isset($r['status']) ? strtolower((string)$r['status']) : '';
                if (empty($r['links']['github']) && empty($r['links']['packagist']) && $status_raw !== 'bundled') {
                    $__skip_msgs[] = rcube::Q($this->gettext('no_source'));
                }
                if ($remote_raw === '' || $remote_raw === '—') {
                    $__skip_msgs[] = rcube::Q($this->gettext('no_remote_ver'));
                }
                if (!empty($__skip_msgs)) {
                    $title = rcube::Q(implode('; ', $__skip_msgs));
                    $st_html .= ' <span class="pm-skip-hint" title="' . $title . '">⚠</span>';
                }

                $links_html = array();
                if (!empty($r['links']['packagist'])) {
                    $links_html[] = '<a target="_blank" rel="noreferrer" href="'.rcube::Q($r['links']['packagist']).'">' . rcube::Q($this->gettext('packagist')) . '</a>';
                }
                if (!empty($r['links']['github'])) {
                    $links_html[] = '<a target="_blank" rel="noreferrer" href="'.rcube::Q($r['links']['github']).'">' . rcube::Q($this->gettext('github')) . '</a>';
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

            $html = implode("\n", $h);
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
            // hide backup directories like plugin.bak-YYYYmmdd-HHMMSS
            if (stripos($entry, '.bak') !== false) continue;
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
            $this->last_ts = isset($cache[$key]['ts']) ? intval($cache[$key]['ts']) : time();
            $this->last_via = isset($cache[$key]['via']) ? $cache[$key]['via'] : '';
            $this->last_reason = isset($cache[$key]['reason']) ? $cache[$key]['reason'] : '';
            return $cache[$key]['ver'];
        }
        $meta = $this->latest_version_online($sources);
        $ver = $meta ? (isset($meta['ver']) ? $meta['ver'] : null) : null;
        if ($meta) { $this->last_via = isset($meta['via']) ? $meta['via'] : ''; $this->last_reason = isset($meta['reason']) ? $meta['reason'] : ''; }
        if ($ver) {
            $this->last_ts = time();
            $cache[$key] = array('ver' => $ver, 'ts' => $this->last_ts, 'via' => $this->last_via, 'reason' => $this->last_reason);
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

    private function pm_time_ago($ts) {
        $ts = intval($ts);
        if ($ts <= 0) return '';
        $diff = time() - $ts;
        if ($diff < 60) return rcube::Q($this->gettext('just_now'));
        if ($diff < 3600) return intval($diff/60) . rcube::Q($this->gettext('min_ago'));
        if ($diff < 86400) return intval($diff/3600) . rcube::Q($this->gettext('hr_ago'));
        return intval($diff/86400) . ' d ago';
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

    function action_update()
    {
        $rc = $this->rc;
        $user_id = method_exists($rc, 'get_user_id') ? intval($rc->get_user_id()) : (isset($rc->user) && isset($rc->user->ID) ? intval($rc->user->ID) : 0);
        if (!$this->is_update_admin()) {
            $rc->output->show_message('' . rcube::Q($this->gettext('not_authorized')) . '.', 'error');
            $rc->output->redirect(array('_task'=>'settings','_action' => 'plugin.plugin_manager'));
            return;
        }
        $plugin_dir = rcube_utils::get_input_value('_pm', rcube_utils::INPUT_GPC);
        $plugin_dir = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$plugin_dir);
        if (!$plugin_dir) {
            $rc->output->show_message('' . rcube::Q($this->gettext('missing_parameter')) . '.', 'error');
            $rc->output->redirect(array('_task'=>'settings','_action' => 'plugin.plugin_manager'));
            return;
        }
        try {
            $ok = $this->perform_update($plugin_dir);
            if ($ok === true) {
                $rc->output->show_message('' . rcube::Q($this->gettext('plugin_update_good')) . '.', 'confirmation');
                $rc->output->command('display_message', '' . rcube::Q($this->gettext('plugin_update_good')) . '.', 'confirmation');
            } else {
                $rc->output->show_message('Update finished: ' . rcube::Q((string)$ok), 'notice');
                $rc->output->command('display_message', '' . rcube::Q($this->gettext('udpate_finished')) . '', 'notice');
            }
        } catch (Exception $e) {
            $this->log_debug('Update error', array('plugin'=>$plugin_dir, 'err'=>$e->getMessage()));
            $rc->output->show_message('Update failed: ' . rcube::Q($e->getMessage()), 'error');
            $rc->output->command('display_message', '' . rcube::Q($this->gettext('udpate_failed')) . ': ' . rcube::Q($e->getMessage()), 'error');
        }
        $rc->output->redirect(array('_task'=>'settings','_action' => 'plugin.plugin_manager'));
    }

    private function perform_update($dir_name)
    {
        $root = $this->plugins_root_dir();
        if (!$root) { $root = realpath(INSTALL_PATH . 'plugins'); }
        if (!$root) { $root = realpath(RCUBE_INSTALL_PATH . 'plugins'); }
        if (!$root) { $this->log_debug('perform_update no_root'); throw new Exception(rcube::Q($this->gettext('no_locate_dir'))); }

        $plugdir = $root . DIRECTORY_SEPARATOR . $dir_name;
        // backup before update
        $do_bak = $this->config->get('pm_backups', true);
        if ($do_bak) {
            $bak = $plugdir . '.bak-' . date('Ymd-His');
            $this->recurse_copy($plugdir, $bak, array());
            $this->prune_backups($dir_name);
        }

        if (!is_dir($plugdir)) { throw new Exception('' . rcube::Q($this->gettext('plugin_not_found')) . ': ' . $dir_name); }

        // discover plugin sources
        $plugins = $this->discover_plugins();
        $source = array();
        foreach ($plugins as $p) {
            $pol = $this->policy_for(basename($p['dir']));
            if (basename($p['dir']) === $dir_name) {
                $source = isset($p['sources']) ? $p['sources'] : (isset($p['links']) ? $p['links'] : array());
                break;
            }
        }
        // override via config map
        $map = (array)$this->config->get('pm_sources_map', array());
        if (isset($map[$dir_name])) {
            $source['github'] = $map[$dir_name];
        }

        // composer.json fallback: support.source or homepage
        $composer_json = $plugdir . '/composer.json';
        if (empty($source['github']) && is_readable($composer_json)) {
            $cj = @json_decode(@file_get_contents($composer_json), true);
            $gh = null;
            if (is_array($cj)) {
                if (!empty($cj['support']) && !empty($cj['support']['source'])) {
                    $gh = $cj['support']['source'];
                } elseif (!empty($cj['homepage'])) {
                    $gh = $cj['homepage'];
                }
            }
            if ($gh && preg_match('~^https://github.com/[^/]+/[^/]+~', $gh)) {
                $source['github'] = $gh;
            }
        }

        // decide channel
        $channel = strtolower((string)$this->config->get('pm_update_channel', 'release')); // 'release' or 'dev'
        $zipurl = null;
        if (!empty($source['github']) && preg_match('~https://github.com/([^/]+)/([^/\.]+)~', $source['github'], $m)) {
            $owner = $m[1]; $repo = $m[2];
            if ($channel === 'release') {
                $api = "https://api.github.com/repos/$owner/$repo/releases/latest";
                $hdrs = array('User-Agent: Roundcube-Plugin-Manager');
                if (!empty($this->gh_token)) { $hdrs[] = 'Authorization: token ' . $this->gh_token; }
                $st=0;$er=null;$resp=$this->http_get2($api, $hdrs, $st, $er);
                if ($st >= 200 && $st < 300 && $resp) {
                    $j = @json_decode($resp, true);
                    if (!empty($j['zipball_url'])) { $zipurl = $j['zipball_url']; }
                }
            }
            if (!$zipurl || $channel === 'dev') {
                $zipurl = "https://github.com/$owner/$repo/archive/refs/heads/master.zip";
            }
        }
        if (!$zipurl) {
            throw new Exception(rcube::Q($this->gettext('no_dnld_url')) . $dir_name);
        }

        try {
            $status=0;$err=null;
            $data = $this->http_get2($zipurl, array('User-Agent: Roundcube-Plugin-Manager'), $status, $err);
            if (($status < 200 || $status >= 300 || !$data) && preg_match('~archive/refs/heads/master\.zip$~', $zipurl)) {
                $zipurl = preg_replace('~master\.zip$~', 'main.zip', $zipurl);
                $status=0;$err=null;
                $data = $this->http_get2($zipurl, array('User-Agent: Roundcube-Plugin-Manager'), $status, $err);
            }
            if ($status < 200 || $status >= 300 || !$data) {
                throw new Exception(rcube::Q($this->gettext('dnld_failed')) . ' (HTTP ' . intval($status) . ', ' . $err . ')');
            }
            // Optional checksum verification (release channel)
            if ($channel === 'release') {
                try { $this->verify_zip_checksum($owner, $repo, $j, $data); }
                catch (Exception $ve) { if ($this->cfg_true('pm_require_checksum', false)) { throw $ve; } }
            }

            if (!class_exists('ZipArchive')) { throw new Exception(rcube::Q($this->gettext('zip_no_avail'))); }
            $tmpzip = $plugdir . '.update.zip';
            @file_put_contents($tmpzip, $data);

            $tmpdir = $plugdir . '.update';
            $this->rrmdir($tmpdir);
            @mkdir($tmpdir, 0775, true);

            $zip = new ZipArchive();
            if ($zip->open($tmpzip) !== true) {
                @unlink($tmpzip);
                throw new Exception('Cannot open zip');
            }
            $zip->extractTo($tmpdir);
            $zip->close();
            @unlink($tmpzip);

            // find payload dir
            $entries = @scandir($tmpdir);
            $srcdir = $tmpdir;
            if ($entries) {
                foreach ($entries as $e) {
                    if ($e === '.' || $e === '..') continue;
                    if (is_dir($tmpdir . DIRECTORY_SEPARATOR . $e)) { $srcdir = $tmpdir . DIRECTORY_SEPARATOR . $e; break; }
                }
            }

            $this->recurse_copy($srcdir, $plugdir, array('config.inc.php')); // preserve existing config
            $this->merge_config_dist($plugdir);
            $this->rrmdir($tmpdir);
            // purge pm cache so status refreshes immediately
            if (!empty($this->cache_file) && file_exists($this->cache_file)) { @unlink($this->cache_file); }
        }
        catch (Exception $ex) {
            // Auto-rollback if configured and backup exists
            if ($this->cfg_true('pm_auto_rollback', true) && isset($bak) && is_dir($bak)) {
                try {
                    // Restore backup over the plugin dir
                    $this->recurse_copy($bak, $plugdir, array());
                    $this->rc->output->show_message(rcube::Q($this->gettext('update_fail_restore')) . ': ' . rcube::Q(basename($bak)) . '. Reason: ' . rcube::Q($ex->getMessage()), 'error');
                    $this->send_webhook('update', array('plugin'=>$dir_name,'error'=>true,'restored'=>basename($bak),'reason'=>$ex->getMessage()));
                } catch (Exception $rex) {
                    // If restore fails, include both reasons
                    $this->rc->output->show_message(rcube::Q($this->gettext('update_restore_fail')) . ': ' . rcube::Q($ex->getMessage()) . '; restore: ' . rcube::Q($rex->getMessage()), 'error');
                }
            }
            throw $ex;
        }
        return true;
    }

    private function recurse_copy($src, $dst, $preserve = array())
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen($src)+1);
            $target = $dst . DIRECTORY_SEPARATOR . $rel;
            if ($item->isDir()) {
                if (!is_dir($target)) { @mkdir($target, 0775, true); }
            } else {
                $base = basename($rel);
                if (in_array($base, $preserve, true) && file_exists($target)) { continue; }
                @copy($item->getPathname(), $target);
            }
        }
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            if ($file->isDir()) { @rmdir($file->getPathname()); }
            else { @unlink($file->getPathname()); }
        }
        @rmdir($dir);
    }

    private function merge_config_dist($plugdir)
    {
        $dist = $plugdir . DIRECTORY_SEPARATOR . 'config.inc.php.dist';
        $cfg  = $plugdir . DIRECTORY_SEPARATOR . 'config.inc.php';
        if (!is_readable($dist)) { return; }
        $dist_text = @file_get_contents($dist);
        $cfg_text  = is_readable($cfg) ? @file_get_contents($cfg) : "<?php\n// Auto-created by plugin_manager update\n\$config = array();\n";

        preg_match_all('~\$config\[["\']([^"\']+)["\']\]\s*=~', $dist_text, $m);
        $keys = array_unique($m[1]);
        $missing = array();
        foreach ($keys as $k) {
            if (!preg_match('~\$config\[["\']' . preg_quote($k, '~') . '["\']\]\s*=~', $cfg_text)) {
                if (preg_match('~(\$config\[["\']' . preg_quote($k, '~') . '["\']\]\s*=.*?;)~s', $dist_text, $mm)) {
                    $missing[] = trim($mm[1]);
                }
            }
        }
        if ($missing) {
            if (strpos($cfg_text, '<?php') === false) { $cfg_text = "<?php\n" . $cfg_text; }
            $cfg_text .= "\n\n// Added from config.inc.php.dist by plugin_manager update on " . date('c') . "\n" . implode("\n", $missing) . "\n";
            @file_put_contents($cfg_file, $cfg_text);
        }
    }

    private function is_update_admin()
    {
        $uid = method_exists($this->rc, 'get_user_id') ? intval($this->rc->get_user_id()) : (isset($this->rc->user) && isset($this->rc->user->ID) ? intval($this->rc->user->ID) : 0);
        $admins = (array)$this->config->get('pm_update_admins', array(1));
        $admins = array_map('intval', $admins);
        return in_array($uid, $admins, true);
    }

    private function has_backup($dir_name)
    {
        $root = $this->plugins_root_dir();
        if (!$root) { $root = realpath(INSTALL_PATH . 'plugins'); }
        if (!$root) { $root = realpath(RCUBE_INSTALL_PATH . 'plugins'); }
        if (!$root) { return false; }
        $plugdir = $root . DIRECTORY_SEPARATOR . $dir_name;
        $parent = dirname($plugdir);
        $prefix = basename($plugdir) . '.bak-';
        foreach ((array)@scandir($parent) as $e) {
            if ($e === '.' || $e === '..') continue;
            if (strpos($e, $prefix) === 0 && is_dir($parent . DIRECTORY_SEPARATOR . $e)) {
                return true;
            }
        }
        return false;
    }

    private function restore_plugin($dir_name)
    {
        $root = $this->plugins_root_dir();
        if (!$root) { $root = realpath(INSTALL_PATH . 'plugins'); }
        if (!$root) { $root = realpath(RCUBE_INSTALL_PATH . 'plugins'); }
        if (!$root) { throw new Exception('Cannot locate plugins directory'); }
        $plugdir = $root . DIRECTORY_SEPARATOR . $dir_name;
        if (!is_dir($plugdir)) { throw new Exception(rcube::Q($this->gettext('plugin_not_found')) . ': ' . $dir_name); }

        $parent = dirname($plugdir);
        $prefix = basename($plugdir) . '.bak-';
        $latest = null; $latest_m = 0;
        foreach ((array)@scandir($parent) as $e) {
            if ($e === '.' || $e === '..') continue;
            if (strpos($e, $prefix) === 0) {
                $p = $parent . DIRECTORY_SEPARATOR . $e;
                $m = @filemtime($p);
                if ($m > $latest_m) { $latest_m = $m; $latest = $p; }
            }
        }
        if (!$latest) { throw new Exception(rcube::Q($this->gettext('no_backup_found'))); }
        $this->recurse_copy($latest, $plugdir, array());
        return basename($latest);
    }

    /**
     * Periodically check for plugin updates and email a summary.
     * Runs lazily on page load; throttled by pm_notify_interval_hours and deduped by digest.
     */
    private function maybe_send_update_alert()
    {
        if (!$this->config->get('pm_notify_enabled', false)) return;

        $recips = (array)$this->config->get('pm_notify_recipients', array());
        if (empty($recips)) return;

        $now = time();
        $cache_path = $this->cache_file ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_cache.json');
        $json = @file_get_contents($cache_path);
        $cache = $json ? (json_decode($json, true) ?: array()) : array();

        $last_ts = isset($cache['pm_last_notify_ts']) ? intval($cache['pm_last_notify_ts']) : 0;
        $min_hours = (int)$this->config->get('pm_notify_interval_hours', 24);
        if ($min_hours < 1) $min_hours = 1;
        if ($now - $last_ts < $min_hours * 3600) return;

        // Discover using existing logic/caches
        $plugins = $this->discover_plugins();
        $needs = array();
        foreach ($plugins as $p) {
            $pol = $this->policy_for(basename($p['dir']));
            $d = basename($p['dir']);
            if (stripos($d, '.bak') !== false) continue;
            $remote = isset($p['remote']) ? (string)$p['remote'] : '';
            $local  = isset($p['local'])  ? (string)$p['local']  : '';
            if ($remote === '' || $remote === '—') continue;
            if ($this->compare_versions($local, $remote) < 0 && empty($pol['pinned']) && empty($pol['ignored']['notify'])) {
                $needs[] = array(
                    'name'   => isset($p['name']) ? (string)$p['name'] : $d,
                    'dir'    => $d,
                    'local'  => $local,
                    'remote' => $remote,
                );
            }
        }
        if (empty($needs)) return;

        $digest = sha1(json_encode($needs));
        if (isset($cache['pm_last_notify_digest']) && $cache['pm_last_notify_digest'] === $digest) {
            // No change; just push the window so we don't re-check every view
            $cache['pm_last_notify_ts'] = $now;
            @file_put_contents($cache_path, json_encode($cache));
            return;
        }

        if ($this->send_update_alert_email($needs)) {
            $this->send_webhook('notify', array('count'=>count($needs),'plugins'=>$needs));
            $cache['pm_last_notify_ts'] = $now;
            $cache['pm_last_notify_digest'] = $digest;
            @file_put_contents($cache_path, json_encode($cache));
        }
    }

    /**
     * Send the actual email using PHP's mail(). Keep it dependency-free.
     */
    private function send_update_alert_email(array $needs)
    {
        $recips = (array)$this->config->get('pm_notify_recipients', array());
        if (empty($recips)) return false;

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : php_uname('n');
        $subject = '[Roundcube] ' . count($needs) . ' plugin update' . (count($needs) === 1 ? '' : 's') . ' available';
        $url = $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
        $lines = array();
        foreach ($needs as $n) {
            $lines[] = sprintf('• %s (%s): %s → %s', $n['name'], $n['dir'], $n['local'] ?: '0', $n['remote']);
        }
        $body = "Host: {$host}\n\nThe following Roundcube plugins have updates available:\n\n" . implode("\n", $lines) . "\n\nOpen Plugin Manager:\n{$url}\n";

        $from = (string)$this->config->get('pm_notify_from', 'roundcube@' . $host);
        $headers = "From: {$from}\r\n" . "MIME-Version: 1.0\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
        $to = implode(',', array_map('trim', $recips));

        $ok = @mail($to, $subject, $body, $headers);
        if (!$ok) {
            $this->log_debug('notify mail() failed', array('to' => $to, 'subject' => $subject));
        }
        return $ok;
    }

    /**
     * Prune old backups for a plugin directory.
     * - pm_keep_backups: keep N newest backups (N<1 means unlimited)
     * - pm_backups_max_age_days: delete backups older than this many days (0/absent disables age-based pruning)
     */
    private function prune_backups($dir_name)
    {
        $keep = (int)$this->config->get('pm_keep_backups', 3);
        $max_age_days = (int)$this->config->get('pm_backups_max_age_days', 0);
        $root = $this->plugins_root_dir();
        if (!$root) { $root = realpath(INSTALL_PATH . 'plugins'); }
        if (!$root) { $root = realpath(RCUBE_INSTALL_PATH . 'plugins'); }
        if (!$root) { return; }

        $plugdir = $root . DIRECTORY_SEPARATOR . $dir_name;
        $parent = dirname($plugdir);
        $prefix = basename($plugdir) . '.bak-';
        $now = time();
        $items = array();

        foreach ((array)@scandir($parent) as $e) {
            if ($e === '.' || $e === '..') continue;
            if (strpos($e, $prefix) !== 0) continue;
            $p = $parent . DIRECTORY_SEPARATOR . $e;
            if (!is_dir($p)) continue;
            $items[] = array('path' => $p, 'name' => $e, 'mtime' => @filemtime($p) ?: 0);
        }

        if (!$items) return;

        // sort newest first
        usort($items, function($a,$b){ return ($b['mtime'] <=> $a['mtime']); });

        $delete = array();

        // Age rule: anything older than threshold is a candidate (but don't touch the newest $keep)
        if ($max_age_days > 0) {
            $cut = $now - ($max_age_days * 86400);
            foreach ($items as $idx => $it) {
                if ($keep > 0 && $idx < $keep) continue;
                if ($it['mtime'] > 0 && $it['mtime'] < $cut) $delete[] = $it;
            }
        }

        // Keep-N rule: beyond the first $keep, delete remaining (if keep >=1)
        if ($keep > 0) {
            for ($i = $keep; $i < count($items); $i++) {
                // avoid duplicates if already scheduled by age rule
                $already = false;
                foreach ($delete as $d) { if ($d['path'] === $items[$i]['path']) { $already = true; break; } }
                if (!$already) $delete[] = $items[$i];
            }
        }

        if (!$delete) return;

        foreach ($delete as $d) {
            $this->log_debug('prune_backup delete', array('path' => $d['path'], 'mtime' => $d['mtime']));
            $this->rrmdir($d['path']);
        }
    }

    /**
     * Global-aware feature toggle: respects pm_features_enabled master switch.
     */
    private function cfg_true($key, $default=false) {
        $master = $this->config->get('pm_features_enabled', true);
        if (!$master) return false;
        return (bool)$this->config->get($key, $default);
    }

    private function policy_for($dir_name) {
        $out = array(
            'ignored' => array('discover'=>false, 'notify'=>false, 'bulk'=>false, 'ui'=>false),
            'pinned'  => null,
            'reason'  => '',
        );
        if (!$this->cfg_true('pm_policies_enabled', true)) return $out;

        // Pins first (highest precedence)
        if ($this->cfg_true('pm_pins_enabled', true)) {
            $pins = (array)$this->config->get('pm_pins', array());
            if (isset($pins[$dir_name]) && is_string($pins[$dir_name]) && $pins[$dir_name] !== '') {
                $out['pinned'] = (string)$pins[$dir_name];
                $out['reason'] = 'pinned';
            }
        }
        // Ignore rules
        if ($this->cfg_true('pm_ignore_enabled', true)) {
            $rules = (array)$this->config->get('pm_ignore_rules', array());
            foreach ($rules as $r) {
                $match = false;
                if (isset($r['plugin']) && is_string($r['plugin']) && $r['plugin'] === $dir_name) $match = true;
                elseif (isset($r['pattern']) && is_string($r['pattern']) && @preg_match($r['pattern'], $dir_name)) $match = true;
                if ($match) {
                    $scopes = isset($r['scopes']) && is_array($r['scopes']) ? $r['scopes'] : array('bulk','notify');
                    foreach ($scopes as $s) {
                        if (isset($out['ignored'][$s])) $out['ignored'][$s] = true;
                    }
                    if (empty($out['reason']) && !empty($r['reason'])) $out['reason'] = (string)$r['reason'];
                }
            }
        }
        return $out;
    }

    private function send_webhook($event, array $payload) {
        if (!$this->cfg_true('pm_webhook_enabled', false)) return false;
        $url = (string)$this->config->get('pm_webhook_url', '');
        if ($url === '') return false;
        $allow = (array)$this->config->get('pm_webhook_events', array('notify','update','bulk'));
        if (!in_array($event, $allow, true)) return false;

        $body = json_encode(array(
            'event' => $event,
            'host'  => (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : php_uname('n')),
            'time'  => date('c'),
            'data'  => $payload,
        ));
        $hdrs = array('Content-Type: application/json');

        // Prefer curl when available
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_exec($ch);
            $ok = (curl_errno($ch) === 0);
            curl_close($ch);
            return $ok;
        }

        // Fallback to streams
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 6,
            )
        ));
        @file_get_contents($url, false, $ctx);
        return true;
    }

    private function verify_zip_checksum($owner, $repo, $release_json, $zip_bytes) {
        if (!$this->cfg_true('pm_require_checksum', false)) return true;
        $regex = $this->config->get('pm_checksum_asset_regex', '/(sha256|checksums).*\.(txt|sha256)$/i');
        if (!is_array($release_json) || empty($release_json['assets']) || !is_array($release_json['assets'])) {
            throw new Exception('Checksum required but no checksum asset found');
        }
        $asset_url = null;
        foreach ($release_json['assets'] as $a) {
            $name = isset($a['name']) ? $a['name'] : '';
            $url  = isset($a['browser_download_url']) ? $a['browser_download_url'] : '';
            if ($name && $url && @preg_match($regex, $name)) { $asset_url = $url; break; }
        }
        if (!$asset_url) throw new Exception('Checksum required but no matching asset');
        $st=0;$er=null;
        $txt = $this->http_get2($asset_url, array('User-Agent: Roundcube-Plugin-Manager'), $st, $er);
        if ($st < 200 || $st >= 300 || !$txt) throw new Exception('Checksum download failed');
        $want = null;
        if (preg_match('/\b([a-f0-9]{64})\b/i', $txt, $m)) { $want = strtolower($m[1]); }
        if (!$want) throw new Exception('Checksum parse failed');
        $have = hash('sha256', $zip_bytes);
        if ($want !== strtolower($have)) throw new Exception('Checksum mismatch');
        return true;
    }

    /**
     * Update all outdated plugins honoring policies and .bak skip.
     * Returns array('ok'=>int, 'fail'=>int, 'skipped'=>array)
     */
    private function update_all_outdated($dry = false)
    {
        $ok = 0; $fail = 0; $skipped = array();
        $plugins = $this->discover_plugins();
        $root = $this->plugins_root_dir();

        foreach ($plugins as $p) {
            $d = basename($p['dir']);

            if ($this->config->get('pm_hide_bak', true) && stripos($d, '.bak') !== false) {
                $skipped[] = array('dir'=>$d, 'reason'=>'backup_dir');
                continue;
            }
            $pol = $this->policy_for($d);
            if (!empty($pol['pinned'])) {
                $skipped[] = array('dir'=>$d, 'reason'=>'pinned');
                continue;
            }
            if (!empty($pol['ignored']['bulk'])) {
                $skipped[] = array('dir'=>$d, 'reason'=>'ignored_bulk');
                continue;
            }

            $plugdir = $root . DIRECTORY_SEPARATOR . $d;
            $meta = $this->read_plugin_meta($plugdir);
            $local = $this->detect_local_version($plugdir, $meta);
            $sources = $this->build_sources($meta);

            if (empty($sources['composer_name']) && empty($sources['github'])) {
                $skipped[] = array('dir'=>$d, 'reason'=>'no_sources');
                continue;
            }

            // force remote lookup for bulk
            $remote = $this->latest_version_cached($sources, true) ?: '';

            if ($remote === '' || $remote === '—') {
                $skipped[] = array('dir'=>$d, 'reason'=>'no_remote_version');
                continue;
            }
            if ($this->compare_versions($local, $remote) >= 0) {
                $skipped[] = array('dir'=>$d, 'reason'=>'up_to_date');
                continue;
            }

            if ($dry) {
                $skipped[] = array('dir'=>$d, 'reason'=>'dry', 'from'=>$local ?: '0', 'to'=>$remote);
                continue;
            }

            try {
                if ($this->perform_update($d)) { $ok++; }
                else { $fail++; $this->log_debug('bulk_updated_fail', array('dir'=>$d)); }
            } catch (Exception $e) {
                $fail++;
                $this->log_debug('bulk_update_exception', array('dir' => $d, 'error' => $e->getMessage()));
            }
        }
        return array('ok' => $ok, 'fail' => $fail, 'skipped' => $skipped);
    }

    private function format_skip_reasons($skipped, $limit = 12) {
        if (!$skipped) return '';
        $map = array(
            'backup_dir' => 'backup dir',
            'pinned' => 'pinned',
            'ignored_bulk' => 'ignored by policy',
            'no_sources' => 'no sources',
            'no_remote_version' => 'no remote version',
            'up_to_date' => 'up to date',
            'dry' => 'dry-run'
        );
        $parts = array();
        $n = 0;
        foreach ($skipped as $s) {
            $n++;
            if ($n > $limit) break;
            $dir = isset($s['dir']) ? $s['dir'] : '?';
            $why = isset($s['reason']) ? (isset($map[$s['reason']]) ? $map[$s['reason']] : $s['reason']) : '';
            $extra = '';
            if ($s['reason'] === 'dry' && isset($s['from']) && isset($s['to'])) {
                $extra = ' ' . $s['from'] . '→' . $s['to'];
            }
            $parts[] = $dir . ' (' . $why . $extra . ')';
        }
        $more = count($skipped) - min(count($skipped), $limit);
        $msg = implode(', ', $parts);
        if ($more > 0) { $msg .= ', +' . $more . ' more'; }
        return $msg;
    }

    private function eligible_count()
    {
        $root = $this->plugins_root_dir();
        if (!$root) return 0;
        $eligible = 0;

        $dirs = $this->discover_plugins();
        foreach ($dirs as $entry => $pinfo) {
            $dir = $pinfo['dir'];
            $base = basename($dir);

            if (!empty($this->hidden_plugins) && in_array(strtolower($base), $this->hidden_plugins, true)) continue;
            if ($this->config->get('pm_hide_bak', true) && stripos($base, '.bak') !== false) continue;

            $policy = $this->policy_for($base);
            if (!empty($policy['pinned']) || !empty($policy['ignored']['bulk'])) continue;

            $meta = $this->read_plugin_meta($dir);
            $local = $this->detect_local_version($dir, $meta);
            $sources = $this->build_sources($meta);

            if (!empty($sources['bundled'])) continue;

            $remote_ver = '';
            if ($this->remote_checks && !$policy['ignored']['discover']) {
                $remote_ver = $this->latest_version_cached($sources) ?: '';
            }
            if ($remote_ver === '' || $remote_ver === '—') continue;

            if ($this->compare_versions($local, $remote_ver) < 0) {
                $eligible++;
            }
        }
        return $eligible;
    }
}

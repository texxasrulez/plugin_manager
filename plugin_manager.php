<?php
/**
 * Roundcube Plugin Manager
 */

class plugin_manager extends rcube_plugin
{
    protected $home;

    private $installed_versions_file;
    private $data_dir;
    private $central_version_file;

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
    private $visibility = 'mixed';

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
        $this->pm_data_init();
        if (method_exists($this, 'pm_write_central_versions')) {
            $this->pm_write_central_versions();
        }

        if (isset($_GET['_pm_versions'])) {
            header('Content-Type: application/json; charset=UTF-8');
            echo @file_get_contents($this->central_version_file);
            exit;
        }

        $this->pm_load_config();

        if ($this->rc && $this->rc->output) {
            if (!isset($this->rc->output->env['pm_ace_base'])) {
                $this->rc->output->set_env('pm_ace_base', 'plugins/plugin_manager/assets/ace');
            }
            $cfg = $this->rc->config;
            $this->rc->output->set_env('pm_ace_theme',       $cfg->get('pm_ace_theme', 'auto'));
            $this->rc->output->set_env('pm_ace_light_theme', $cfg->get('pm_ace_light_theme', 'github'));
            $this->rc->output->set_env('pm_ace_dark_theme',  $cfg->get('pm_ace_dark_theme', 'dracula'));
        }

        $this->register_action('plugin.plugin_manager', array($this, 'action_list'));
        $this->register_action('plugin_manager.refresh', array($this, 'action_refresh'));
        $this->register_action('plugin.plugin_manager.update', array($this, 'action_update'));
        $this->register_action('plugin.plugin_manager.restore', array($this, 'action_restore'));
        $this->register_action('plugin.plugin_manager.load_config', array($this, 'action_load_config'));
        $this->register_action('plugin.plugin_manager.save_config', array($this, 'action_save_config'));

        $this->add_texts('localization/');
        if ($this->rc->task === 'settings' && $this->rc->action === 'plugin.plugin_manager') {
            $this->register_handler('plugin.body', array($this, 'render_page'));
        }

        if ($this->rc->task === 'settings') {
            $this->include_stylesheet($this->local_skin_path() . '/plugin_manager.css');
        }

        $this->add_hook('settings_actions', array($this, 'settings_actions'));
    }

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
        $this->visibility    = (string)$this->config->get('pm_visibility', 'mixed');

        $this->hidden_plugins = array();
        $hp = $this->config->get('pm_hidden_plugins', array());
        if (!is_array($hp)) { $hp = array($hp); }
        foreach ($hp as $h) { if (is_string($h) && $h!=='') { $this->hidden_plugins[] = strtolower($h); } }
        $this->pm_data_init();
        $flag = $this->data_dir . DIRECTORY_SEPARATOR . 'remote.off';
        $req_remote = rcube_utils::get_input_value('_pm_remote', rcube_utils::INPUT_GPC);
        if ($req_remote !== null && $req_remote !== '') {
            if ((string)$req_remote === '0' || $req_remote === 0) {
                @file_put_contents($flag, '1');
            } else {
                if (file_exists($flag)) { @unlink($flag); }
            }
        }
        if (file_exists($flag)) {
            $this->remote_checks = false;
        }
    

        $this->log_debug('pm_load_config done', array(
            'cache_file'    => $this->cache_file,
            'remote_checks' => $this->remote_checks,
            'plugin_root'   => $this->plugin_root
        ));
    }

    private function log_debug($msg, $context = array())
    {
        if (!$this->debug) return;
        $entry = '[' . date('c') . '] ' . $msg;
        if (!empty($context)) $entry .= ' ' . json_encode($context);
        rcube::write_log('plugin.plugin_manager', $entry);
    }

    function settings_actions($args)
    {
        if (!$this->can_view()) return $args;

        $args['actions'][] = array(
            'command' => 'plugin.plugin_manager',
            'action'  => 'plugin.plugin_manager',
            'type'    => 'link',
            'label'   => 'plugin_manager.plugin_manager_title',
            'title'   => 'plugin_manager.plugin_manager_title',
            'class'   => 'plugin_manager',
        );
        return $args;
    }

    function action_refresh()
    {
        @unlink($this->cache_file);
        $this->rc->output->redirect(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
    }

    function action_list()
    {
        if ($this->cfg_true('pm_enable_update_select', true) && $this->is_update_admin()) {
            $pm_all = rcube_utils::get_input_value('_pm_update_all', rcube_utils::INPUT_GPC);
            $pm_dry = rcube_utils::get_input_value('_pm_dry', rcube_utils::INPUT_GPC) ? true : false;
            $this->log_debug('bulk_handler', array('where'=>'action_list', 'pm_all'=>$pm_all, 'pm_dry'=>$pm_dry));
            if ($pm_all) {
                $res = $this->update_all_outdated($pm_dry);
                $summary = $pm_dry
                    ? sprintf(rcube::Q($this->gettext('testing_complete')) . ': %d would update, %d would fail, %d skipped.', (int)$res['ok'], (int)$res['fail'], (int)count($res['skipped']))
                    : sprintf(rcube::Q($this->gettext('bulk_update_complete')) . ': %d updated, %d failed, %d skipped.', (int)$res['ok'], (int)$res['fail'], (int)count($res['skipped']));
                $this->flash_add($summary, $pm_dry ? 'notice' : ($res['fail'] ? 'warning' : 'confirmation'));
                if (!empty($res['skipped'])) {
                    $detail = rcube::Q($this->gettext('details')) . ': ' . $this->format_skip_reasons($res['skipped']);
                    $this->flash_add($detail, 'notice');
                }
                $this->rc->output->redirect(array('_task'=>'settings','_action'=>'plugin.plugin_manager'));
                return;
            }
        }

        $this->rc->output->set_pagetitle($this->gettext('plugin_manager_title'));
        $this->rc->output->send('plugin');
    }

    function render_page()
    {
        $this->flash_flush();
        $this->log_debug('render_page start');

        $pm_refresh = rcube_utils::get_input_value('_pm_refresh', rcube_utils::INPUT_GPC);
        if ($pm_refresh) {
            if (!empty($this->cache_file) && file_exists($this->cache_file)) {
                @unlink($this->cache_file);
            }
            $plugins_for_refresh = $this->discover_plugins();
            foreach ($plugins_for_refresh as $pfr) {
                $meta_fr    = $this->read_plugin_meta($pfr['dir']);
                $sources_fr = $this->build_sources($meta_fr);
                if (!empty($sources_fr['composer_name']) || !empty($sources_fr['github'])) {
                    $this->latest_version_cached($sources_fr, true);
                }
            }
            $this->last_ts = time();
        }

        $this->include_stylesheet($this->local_skin_path() . '/plugin_manager.css');
        $cw = (array)$this->config->get('pm_column_widths', array('select'=>'4%','local'=>'8%','latest'=>'8%','status'=>'30%'));
        $select_w  = isset($cw['select']) ? $cw['select'] : '4%';
        $local_w  = isset($cw['local'])  ? $cw['local']  : '8%';
        $latest_w = isset($cw['latest']) ? $cw['latest'] : '8%';
        $status_w = isset($cw['status']) ? $cw['status'] : '30%';

        $this->rc->output->add_header('<style>
            #pm-table { table-layout:auto; }
            #pm-table td:nth-child(1), #pm-table th:nth-child(1) { width: ' . rcube::Q($select_w) . '; }
            #pm-table td:nth-child(5), #pm-table th:nth-child(5) { width: ' . rcube::Q($local_w) . '; }
            #pm-table td:nth-child(6), #pm-table th:nth-child(6) { width: ' . rcube::Q($latest_w) . '; }
            #pm-table td:nth-child(7), #pm-table th:nth-child(7) { width: ' . rcube::Q($status_w) . '; white-space: nowrap; }
            .pm-busy{opacity:.65;pointer-events:none;}
            .pm-ok{color:#1b5e20;}
            .pm-update{color:#8b0000;}
            .pm-enabled{color:#1b5e20;font-weight:bold;}
            .pm-disabled{color:#8b0000;font-weight:bold;}
            .pm-scroll{overflow:auto;height:1px;}
            th.pm-sort{cursor:pointer;user-select:none;}
            th.pm-sort.pm-sorted-asc::after{content:" \\25B2";}
            th.pm-sort.pm-sorted-desc::after{content:" \\25BC";}
        </style>');

        $plugins = $this->discover_plugins();
        $enabled = $this->enabled_plugins();

        $rows = array();
        foreach ($plugins as $info) {
            $dir  = $info['dir'];
            $meta = $this->read_plugin_meta($dir);

            $base   = basename($dir);
            $policy = $this->policy_for($base);
            if ($policy['ignored']['ui']) continue;

            $local_version = $this->detect_local_version($dir, $meta);
            $sources       = $this->build_sources($meta);

            $remote     = array('version' => $this->gettext('unknown'), 'status' => $this->gettext('not_checked'), 'reason' => '');
            $checked_ts = 0;

            if (!empty($sources['bundled'])) {
                $remote['version'] = '—';
                $remote['status']  = $this->gettext('bundled');
                $remote['reason']  = 'bundled';
            } elseif ($this->remote_checks && !$policy['ignored']['discover']) {
                $remote_ver = $this->latest_version_cached($sources);
                if ($remote_ver) {
                    $checked_ts        = $this->last_ts ?: 0;
                    $remote['version'] = $remote_ver;
                    $cmp               = $this->compare_versions($local_version, $remote_ver);
                    $remote['status']  = ($cmp >= 0) ? $this->gettext('up_to_date') : $this->gettext('update_available');
                }
            }

            $enabled_bool = in_array(basename($dir), $enabled, true);

            $rows[] = array(
                'name'       => $info['name'],
                'dir'        => basename($dir),
                'enabled'    => $enabled_bool,
                'local'      => $local_version ?: $this->gettext('unknown'),
                'remote'     => $remote['version'],
                'status'     => $remote['status'],
                'reason'     => isset($remote['reason']) ? $remote['reason'] : '',
                'via'        => isset($remote['via']) ? $remote['via'] : '',
                'links'      => $sources,
                'checked_ts' => $checked_ts,
                'policy'     => $policy,
                'policy_reason' => isset($policy['reason']) ? $policy['reason'] : '',
            );
        }

        if (empty($this->last_ts)) {
            $this->last_ts = $this->pm_cache_last_ts();
        }

        $h = array();
        $h[] = '<div class="box">';
        $h[] = '<div class="boxtitle">' . rcube::Q($this->gettext('plugin_manager_title')) . '</div>';
        $h[] = '<div class="boxcontent">';
        $h[] = '<p class="pm-desc" style="text-align:left;">' . rcube::Q($this->gettext('plugin_manager_desc')) . '</p>';
        if ($this->debug) {
            $h[] = '<div class="pm-debug" style="margin:6px 0; display: inline-block; border:2px solid #8b0000; border-radius:4px; background-color:#ffcccc; color:#8b0000; padding:4px;"><strong>'
                . rcube::Q($this->gettext('debug_on')) . '</strong>. '
                . rcube::Q($this->gettext('debug_on_text')) . '</div>';
        }    

        // top controls
        $h[] = '<div style="margin:8px 0;">';
        $h[] = '<a class="button pm-reload" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager')) . '">' . rcube::Q($this->gettext('reload_page')) . '</a> ';
        $h[] = '<a class="button pm-diagnostics" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_diag'=>1)) . '">' . rcube::Q($this->gettext('diagnostics')) . '</a> ';
        $toggle_label = $this->remote_checks ? $this->gettext('disable_remote') : $this->gettext('enable_remote');
        $h[] = '<a class="button pm-toggle-remote" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_remote'=>($this->remote_checks?0:1))) . '">' . rcube::Q($toggle_label) . '</a>';
        $h[] = ' <a class="button pm-refresh" href="' . $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager','_pm_refresh'=>1)) . '">' . rcube::Q($this->gettext('refresh_versions')) . '</a>';
        $h[] = '&nbsp;&nbsp;<span class="pm-lastupdate" style="margin:6px 0 4px 0;">' . rcube::Q($this->gettext('last_checked')) . ':&nbsp;' . ( $this->last_ts ? '<span class="pm-checked">' . rcube::Q($this->pm_time_ago($this->last_ts)) . '</span>' : '<span class="pm-checked">'. rcube::Q($this->gettext('never')) .'</span>' ) . '</span>';
        $h[] = '</div>';

        // bulk toolbar
        if ($this->cfg_true('pm_enable_update_select', true) && $this->is_update_admin()) {
            $h[] = '<div class="pm-bulkbar" style="margin:10px 0; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
            $h[] = '<button class="button pm-update-selected" type="button">' . rcube::Q($this->gettext('update_selected') ?: 'Update Selected') . '</button>';
            $h[] = '<label><input type="checkbox" class="pm-only-outdated" /> ' . rcube::Q($this->gettext('only_outdated') ?: 'Only outdated') . '</label>';
            $h[] = '<label><input type="checkbox" class="pm-only-enabled" /> ' . rcube::Q($this->gettext('only_enabled') ?: 'Only enabled') . '</label>';
            $h[] = '<label><input type="checkbox" class="pm-only-errors" /> ' . rcube::Q($this->gettext('only_errors') ?: 'Only errors') . '</label>';
            $h[] = '</div>';
        }

        // table
        $h[] = '<div class="pm-scroll">';
        $h[] = '<table class="records-table" id="pm-table">';
        $h[] = '<thead><tr>'
             . '<th class="pm-sort" data-type="text" style="text-align:left;">' . rcube::Q($this->gettext('select')) . '</th>'
             . '<th class="pm-sort" data-type="text" style="text-align:left;">' . rcube::Q($this->gettext('plugin')) . '</th>'
             . '<th class="pm-sort" data-type="text" style="text-align:left;">' . rcube::Q($this->gettext('directory')) . '</th>'
             . '<th class="pm-sort" data-type="bool" style="text-align:left;">' . rcube::Q($this->gettext('enabled')) . ' / ' . rcube::Q($this->gettext('disabled')) .'</th>'
             . '<th class="pm-sort" data-type="text" style="text-align:left;">' . rcube::Q($this->gettext('version_remote')) . '</th>'
             . '<th class="pm-sort" data-type="semver" style="text-align:left;">' . rcube::Q($this->gettext('version_local')) . '</th>'
             . '<th class="pm-sort" data-type="semver" style="text-align:left;">' . rcube::Q($this->gettext('status')) . '</th>'
             . '<th class="pm-sort" data-type="text" style="text-align:left;">' . rcube::Q($this->gettext('websites')) . '</th>'
             . '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $dir_name = basename($r['dir']);
            $st       = (string)$r['status'];
            $st_raw   = strtolower($st);
            $st_html  = rcube::Q($st);
            $plugins_root = dirname(__DIR__);
            if (!$plugins_root) { $plugins_root = realpath(INSTALL_PATH . 'plugins'); }
            if (!$plugins_root) { $plugins_root = realpath(RCUBE_INSTALL_PATH . 'plugins'); }
            if ($plugins_root) {
                $plugdir = $plugins_root . DIRECTORY_SEPARATOR . $dir_name;
                $baks = (array)glob($plugdir . '.bak-*', GLOB_NOSORT);
                if (!empty($baks)) {
                    usort($baks, function($a,$b){ return filemtime($b) <=> filemtime($a); });
                    $latest_bak = basename($baks[0]);
                    $restore_url = $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager.restore','_pm'=>$dir_name,'_bak'=>$latest_bak));
                    $st_html .= ' <a class="pm-restore-link" href="' . rcube::Q($restore_url) . '">[' . rcube::Q($this->gettext('restore')) . ']</a>';
                }
            }

            if ($st === $this->gettext('update_available') || strpos($st_raw, 'update') !== false) {
                if ($this->is_update_admin()) {
                    $u    = $this->rc->url(array('_task'=>'settings','_action'=>'plugin.plugin_manager.update','_pm'=>$dir_name));
                    $st_html .= ' <a class="pm-update-link" href="' . rcube::Q($u) . '" data-busy="Updating ...">[' . rcube::Q($this->gettext('update')) . ']</a>';
                }
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
            } elseif ($st === $this->gettext('up_to_date') || strpos($st_raw, 'up to date') !== false) {
                $st_html = '<strong class="pm-ok">' . $st_html . '&nbsp;&nbsp;&#10003;</strong>';
            }

            if (!empty($r['reason']) || !empty($r['via'])) {
                $st_html .= ' <span class="hint">(' . rcube::Q(trim(($r['reason'] ? $r['reason'] : '') . (empty($r['via']) ? '' : (($r['reason'] ? ', ' : '') . ( $this->gettext('via') ?: 'via' ) . ' ' . $r['via'])))) . ')</span>';
            }

            $links_html = array();
            if (!empty($r['links']['packagist'])) {
                $links_html[] = '<a target="_blank" rel="noreferrer" href="'.rcube::Q($r['links']['packagist']).'">' . rcube::Q($this->gettext('packagist')) . '</a>';
            }
            if (!empty($r['links']['github'])) {
                $links_html[] = '<a target="_blank" rel="noreferrer" href="'.rcube::Q($r['links']['github']).'">' . rcube::Q($this->gettext('github')) . '</a>';
            }

            $en_label_yes = rcube::Q($this->gettext('enabled'));
            $en_label_no  = rcube::Q($this->gettext('disabled'));
            $en_html = $r['enabled'] ? '<strong class="pm-enabled">' . $en_label_yes . '</strong>' : '<strong class="pm-disabled">' . $en_label_no . '</strong>';
            $en_sort = $r['enabled'] ? '1' : '0';

            
            $edit_label = $this->gettext('edit_config') ?: 'Edit config';
            // Only show Edit Config when some config file exists in the plugin directory
            $has_cfg = false;
            $cfg_p  = $plugins_root ? ($plugins_root . DIRECTORY_SEPARATOR . $dir_name . DIRECTORY_SEPARATOR . 'config.inc.php') : null;
            $cfg_d  = $plugins_root ? ($plugins_root . DIRECTORY_SEPARATOR . $dir_name . DIRECTORY_SEPARATOR . 'config.inc.php.dist') : null;
            $cfg_s  = $plugins_root ? ($plugins_root . DIRECTORY_SEPARATOR . $dir_name . DIRECTORY_SEPARATOR . 'config.inc.php.sample') : null;
            if (($cfg_p && is_file($cfg_p)) || ($cfg_d && is_file($cfg_d)) || ($cfg_s && is_file($cfg_s))) {
                $edit_link = '<a href="#" class="pm-edit-config" data-plug="'.rcube::Q($dir_name).'">['.rcube::Q($edit_label).']</a>';
            } else {
                $edit_link = '';
            }
				$h[] = '<tr>'
                . '<td style="text-align:center;"><input type="checkbox" class="pm-select" data-dir="' . rcube::Q($dir_name) . '"></td>'
                . '<td style="text-align:left;"><strong>' . rcube::Q($r['name']) . '</strong> &nbsp; ' . $edit_link . '</td>'
                . '<td style="text-align:left;">' . rcube::Q($r['dir']) . '</td>'
                . '<td data-sort="' . $en_sort . '" style="text-align:left;">' . $en_html . '</td>'
                . '<td style="text-align:left;">' . rcube::Q($r['local']) . '</td>'
                . '<td style="text-align:left;">' . rcube::Q($r['remote']) . '</td>'
                . '<td style="text-align:left;">' . $st_html . '</td>'
                . '<td style="text-align:left;">' . implode(' &middot; ', $links_html) . '</td>'
                . '</tr>';
        }

        $h[] = '</tbody></table>';
        $h[] = '</div>'; // .pm-scroll

        // Busy text + basic handlers
        $h[] = '<script>(function(){function setBusy(el,txt){if(!el||el.classList.contains("pm-busy"))return;el.dataset.originalText=el.textContent;el.textContent=txt;el.classList.add("pm-busy");el.setAttribute("aria-busy","true");}var reload=document.querySelector(".pm-reload");if(reload)reload.addEventListener("click",function(){setBusy(this,this.getAttribute("data-busy")||"'. rcube::Q($this->gettext('reloading') ?: 'Reloading …') .'");});var diag=document.querySelector(".pm-diagnostics");if(diag)diag.addEventListener("click",function(){setBusy(this,this.getAttribute("data-busy")||"'. rcube::Q($this->gettext('running') ?: 'Running …') .'");});var refresh=document.querySelector(".pm-refresh");if(refresh)refresh.addEventListener("click",function(){setBusy(this,this.getAttribute("data-busy")||"'. rcube::Q($this->gettext('checking') ?: 'Checking …') .'");});document.addEventListener("click",function(ev){var a=ev.target.closest(".pm-update-link");if(!a)return;setBusy(a,a.getAttribute("data-busy")||"'. rcube::Q($this->gettext('updating') ?: 'Updating …') .'");});var bulkBtn=document.querySelector(".pm-update-selected");if(bulkBtn){bulkBtn.addEventListener("click",function(){setBusy(this,this.getAttribute("data-busy")||"'. rcube::Q($this->gettext('updating') ?: 'Updating …') .'");window.location.href="?_task=settings&_action=plugin.plugin_manager&_pm_update_all=1";});}})();</script>';

        // Column sorting
        $h[] = '<script>(function(){var table=document.getElementById("pm-table");if(!table)return;var thead=table.tHead,tbody=table.tBodies[0];if(!thead||!tbody)return;function txt(el){return(el&&(el.textContent||el.innerText)||"").trim();}function parseSemver(v){v=(v||"").trim();if(!v||v==="—")return{k:[-1]};var vl=v.toLowerCase();if(vl==="unknown"||vl==="unk"||vl==="?")return{k:[-1]};v=v.replace(/^v/i,"");var parts=v.split(/[^0-9a-zA-Z]+/).filter(Boolean);var out=[];for(var i=0;i<parts.length;i++){var p=parts[i];if(/^\d+$/.test(p))out.push(parseInt(p,10));else out.push(-0.5);}return{k:out,raw:v};}function cmpCells(aCell,bCell,type){if(type==="bool"){var av=parseInt(aCell.getAttribute("data-sort")||"0",10);var bv=parseInt(bCell.getAttribute("data-sort")||"0",10);return av-bv;}if(type==="semver"){var sa=parseSemver(txt(aCell)).k,sb=parseSemver(txt(bCell)).k;var n=Math.max(sa.length,sb.length);for(var i=0;i<n;i++){var ai=(i<sa.length)?sa[i]:0,bi=(i<sb.length)?sb[i]:0;if(ai!==bi)return ai-bi;}return 0;}var a=txt(aCell).toLowerCase(),b=txt(bCell).toLowerCase();if(a===b)return 0;return a>b?1:-1;}var headers=thead.rows[0].cells;var state={col:null,dir:"asc"};function clearIndicators(){for(var i=0;i<headers.length;i++){headers[i].classList.remove("pm-sorted-asc","pm-sorted-desc");headers[i].removeAttribute("aria-sort");}}function sortBy(colIndex,type,dir){var rows=Array.prototype.slice.call(tbody.rows);rows.sort(function(r1,r2){var c1=r1.cells[colIndex]||document.createElement("td");var c2=r2.cells[colIndex]||document.createElement("td");var c=cmpCells(c1,c2,type);return dir==="asc"?c:-c;});var frag=document.createDocumentFragment();rows.forEach(function(r){frag.appendChild(r);});tbody.appendChild(frag);clearIndicators();var th=headers[colIndex];th.classList.add(dir==="asc"?"pm-sorted-asc":"pm-sorted-desc");th.setAttribute("aria-sort",dir==="asc"?"ascending":"descending");}for(let i=0;i<headers.length;i++){let th=headers[i];if(!th.classList.contains("pm-sort"))continue;th.addEventListener("click",function(){var type=th.getAttribute("data-type")||"text";if(state.col===i){state.dir=(state.dir==="asc"?"desc":"asc");}else{state.col=i;state.dir="asc";}sortBy(i,type,state.dir);});}})();</script>';

        // Fit table to viewport
        $h[] = '<script>(function(){function footerHeight(){var f=document.querySelector("#footer")||document.querySelector(".footer")||document.querySelector(".taskbar");if(!f)return 32;var r=f.getBoundingClientRect();return Math.max(0,r.height||32);}function fit(){var c=document.querySelector(".pm-scroll");if(!c)return;var vh=window.innerHeight||document.documentElement.clientHeight||0;var rect=c.getBoundingClientRect();var fh=footerHeight();var pad=24;var h=Math.max(220,vh-rect.top-fh-pad);c.style.height=h+"px";c.style.overflow="auto";}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){fit();setTimeout(fit,150);setTimeout(fit,500);},{once:true});}else{fit();setTimeout(fit,150);setTimeout(fit,500);}window.addEventListener("resize",fit,{passive:true});})();</script>';

        // Load UI JS file (web path)
        $h[] = '<script src="plugins/plugin_manager/plugin_manager.ui.js"></script>';

        $h[] = '</div></div>';

        $html = implode("\n", $h);
        $this->log_debug('render_page end', array('html_len' => strlen($html)));
        return $html;
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
        $root = dirname(__DIR__);
        $this->log_debug('discover_plugins root', array('root' => $root));
        $out = array();
        if (!$root) return $out;

        $dh = @opendir($root);
        if (!$dh) return $out;

        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
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

        $map = $this->pm_read_installed_versions();
        $base = basename($dir);
        if (!empty($map[$base]) && !empty($map[$base]['version'])) {
            return (string)$map[$base]['version'];
        }

        return $this->pm_read_plugin_version($dir);
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
            $this->last_ts     = isset($cache[$key]['ts']) ? intval($cache[$key]['ts']) : time();
            $this->last_via    = isset($cache[$key]['via']) ? $cache[$key]['via'] : '';
            $this->last_reason = isset($cache[$key]['reason']) ? $cache[$key]['reason'] : '';
            return $cache[$key]['ver'];
        }
        $meta = $this->latest_version_online($sources);
        $ver  = $meta ? (isset($meta['ver']) ? $meta['ver'] : null) : null;
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
                $result['reason'] = $st ? ('http_' . (string)$st) : 'no_release';
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
            $proto_https = defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : (defined('CURLPROTO_HTTP') ? (CURLPROTO_HTTP|CURLPROTO_HTTPS) : 3);
            curl_setopt_array($ch, array(
                CURLOPT_PROTOCOLS => $proto_https,
                CURLOPT_REDIR_PROTOCOLS => $proto_https,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => isset($this->http_user_agent) ? $this->http_user_agent : 'Roundcube-Plugin-Manager/1.0',
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

    function action_update()
    {
        $rc = $this->rc;
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
            } else {
                $rc->output->show_message('Update finished: ' . rcube::Q((string)$ok), 'notice');
            }
        } catch (Exception $e) {
            $this->log_debug('Update error', array('plugin'=>$plugin_dir, 'err'=>$e->getMessage()));
            $rc->output->show_message('Update failed: ' . rcube::Q($e->getMessage()), 'error');
        }
        $rc->output->redirect(array('_task'=>'settings','_action' => 'plugin.plugin_manager'));
    }    

    function action_restore()
    {
        $rc = $this->rc;
        if (!$this->is_update_admin()) {
            $rc->output->show_message('' . rcube::Q($this->gettext('not_authorized')) . '.', 'error');
            $rc->output->redirect(array('_task'=>'settings','_action' => 'plugin.plugin_manager'));
            return;
        }
        $dir_name = rcube_utils::get_input_value('_pm', rcube_utils::INPUT_GPC);
        $dir_name = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$dir_name);
        $bak_name = rcube_utils::get_input_value('_bak', rcube_utils::INPUT_GPC);
        $bak_name = preg_replace('~[^a-zA-Z0-9_\-\.]+~', '', (string)$bak_name);

        $plugins_root = dirname(__DIR__);
        if (!$plugins_root) { $plugins_root = realpath(INSTALL_PATH . 'plugins'); }
        if (!$plugins_root) { $plugins_root = realpath(RCUBE_INSTALL_PATH . 'plugins'); }
        if (!$plugins_root || !$dir_name) {
            $rc->output->show_message('' . rcube::Q($this->gettext('missing_parameter')) . '.', 'error');
            $rc->output->redirect(array('_task'=>'settings','_action' => 'plugin.plugin_manager'));
            return;
        }

        $plugdir = $plugins_root . DIRECTORY_SEPARATOR . $dir_name;
        $bakdir = null;
        if ($bak_name && is_dir($plugins_root . DIRECTORY_SEPARATOR . $bak_name)) {
            $bakdir = $plugins_root . DIRECTORY_SEPARATOR . $bak_name;
        } else {
            $candidates = (array)glob($plugdir . '.bak-*', GLOB_NOSORT);
            if ($candidates) {
                usort($candidates, function($a,$b){ return filemtime($b) <=> filemtime($a); });
                $bakdir = $candidates[0];
            }
        }
        if (!$bakdir || !is_dir($bakdir)) {
            $rc->output->show_message('' . rcube::Q($this->gettext('no_backups')) . '.', 'error');
            $rc->output->redirect(array('_task'=>'settings','_action' => 'plugin.plugin_manager'));
            return;
        }

        try {
            $stamp = date('Ymd-His');
            $restore_bak = $plugdir . '.bak-restore-' . $stamp;
            if (is_dir($plugdir)) { $this->recurse_copy($plugdir, $restore_bak, array()); $this->rrmdir($plugdir); }
            $this->recurse_copy($bakdir, $plugdir, array());

            $meta_r = $this->read_plugin_meta($plugdir);
            $ver_r  = $this->detect_local_version($plugdir, $meta_r);
            $installed_r = $this->pm_read_installed_versions();
            if (!is_array($installed_r)) { $installed_r = array(); }
            $installed_r[$dir_name] = array('version' => $ver_r, 'last_updated' => gmdate('c'));
            $this->pm_write_installed_versions($installed_r);
            $this->pm_write_central_versions();

            $rc->output->show_message('' . rcube::Q($this->gettext('restored')) . '.', 'confirmation');
        } catch (Exception $e) {
            $rc->output->show_message('' . rcube::Q($e->getMessage()) . '.', 'error');
        }
        $rc->output->redirect(array('_task'=>'settings','_action' => 'plugin.plugin_manager'));
    }

    private function perform_update($dir_name)
    {
        $root = dirname(__DIR__);
        if (!$root) { $root = realpath(INSTALL_PATH . 'plugins'); }
        if (!$root) { $root = realpath(RCUBE_INSTALL_PATH . 'plugins'); }
        if (!$root) { throw new Exception(rcube::Q($this->gettext('no_locate_dir'))); }

        $plugdir = $root . DIRECTORY_SEPARATOR . $dir_name;

        $do_bak = $this->config->get('pm_backups', true);
        if ($do_bak) {
            $bak = $plugdir . '.bak-' . date('Ymd-His');
            $this->recurse_copy($plugdir, $bak, array());
            $this->prune_backups($dir_name);
        }

        if (!is_dir($plugdir)) { throw new Exception('' . rcube::Q($this->gettext('plugin_not_found')) . ': ' . $dir_name); }

        $meta    = $this->read_plugin_meta($plugdir);
        $sources = $this->build_sources($meta);
        $channel = strtolower((string)$this->config->get('pm_update_channel', 'release'));

        $zipurl = null; $j = null; $owner = null; $repo = null;
        if (!empty($sources['github']) && preg_match('~https://github\.com/([^/]+)/([^/\.]+)~', $sources['github'], $m)) {
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

        $entries = @scandir($tmpdir);
        $srcdir = $tmpdir;
        if ($entries) {
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') continue;
                if (is_dir($tmpdir . DIRECTORY_SEPARATOR . $e)) { $srcdir = $tmpdir . DIRECTORY_SEPARATOR . $e; break; }
            }
        }

        $this->recurse_copy($srcdir, $plugdir, array('config.inc.php'));
        $this->rrmdir($tmpdir);

        if (!empty($this->cache_file) && file_exists($this->cache_file)) { @unlink($this->cache_file); }
        
        try {
            $meta_new  = $this->read_plugin_meta($plugdir);
            $ver_remote = $this->latest_version_cached($sources, true);
            $ver_local  = $this->detect_local_version($plugdir, $meta_new);
            $ver_final  = $ver_remote ?: $ver_local;
            $map = $this->pm_read_installed_versions();
            if (!is_array($map)) { $map = array(); }
            $map[$dir_name] = array('version' => (string)$ver_final, 'last_updated' => gmdate('c'));
            $this->pm_write_installed_versions($map);
        } catch (Exception $e) { }
        
        $this->pm_write_central_versions();

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

    private function prune_backups($dir_name)
    {
        $keep = (int)$this->config->get('pm_keep_backups', 3);
        $max_age_days = (int)$this->config->get('pm_backups_max_age_days', 0);
        $root = dirname(__DIR__);
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

        usort($items, function($a,$b){ return ($b['mtime'] <=> $a['mtime']); });

        $delete = array();
        if ($max_age_days > 0) {
            $cut = $now - ($max_age_days * 86400);
            foreach ($items as $idx => $it) {
                if ($keep > 0 && $idx < $keep) continue;
                if ($it['mtime'] > 0 && $it['mtime'] < $cut) $delete[] = $it;
            }
        }
        if ($keep > 0) {
            for ($i = $keep; $i < count($items); $i++) {
                $already = false;
                foreach ($delete as $d) { if ($d['path'] === $items[i]['path']) { $already = true; break; } }
                if (!$already) $delete[] = $items[$i];
            }
        }
        foreach ($delete as $d) {
            $this->log_debug('prune_backup delete', array('path' => $d['path'], 'mtime' => $d['mtime']));
            $this->rrmdir($d['path']);
        }
    }

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

        if ($this->cfg_true('pm_pins_enabled', true)) {
            $pins = (array)$this->config->get('pm_pins', array());
            if (isset($pins[$dir_name]) && is_string($pins[$dir_name]) && $pins[$dir_name] !== '') {
                $out['pinned'] = (string)$pins[$dir_name];
                $out['reason'] = 'pinned';
            }
        }
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

    private function pm_data_init()
    {
        $this->home = dirname(__FILE__);
        $this->plugin_root = realpath($this->home);
        $this->data_dir = $this->plugin_root . DIRECTORY_SEPARATOR . 'data';
        if (!is_dir($this->data_dir)) @mkdir($this->data_dir, 0775, true);
        $this->central_version_file    = $this->data_dir . DIRECTORY_SEPARATOR . 'version.json';
        $this->installed_versions_file = $this->data_dir . DIRECTORY_SEPARATOR . 'installed_versions.json';
    }

    private function pm_read_plugin_version($dir)
    {
        $vj = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'version.json';
        if (is_file($vj)) {
            $raw = @file_get_contents($vj);
            $j = @json_decode($raw, true);
            if (is_array($j) && !empty($j['version'])) return (string)$j['version'];
        }
        $cj = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($cj)) {
            $raw = @file_get_contents($cj);
            $j = @json_decode($raw, true);
            if (is_array($j) && !empty($j['version'])) {
                $v = trim((string)$j['version']);
                if ($v !== '') return $v;
            }
        }
        $latest = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isDir()) continue;
            $mt = @filemtime($f->getPathname());
            if ($mt && $mt > $latest) $latest = $mt;
        }
        return 'local-' . gmdate('Y.m.d.His', $latest ?: time());
    }

    public function pm_write_central_versions()
    {
        $this->pm_data_init();
        $plugins_root = dirname(__DIR__);

        $out = array(
            'generated_at' => gmdate('c'),
            'plugins_root' => $plugins_root,
            'plugins'      => array(),
        );

        $entries = array();
        if (is_dir($plugins_root)) {
            $dh = @opendir($plugins_root);
            if ($dh) {
                while (($entry = readdir($dh)) !== false) {
                    if ($entry === '.' || $entry === '..') continue;
                    if (preg_match('/\.bak-\d{8}-\d{6}$/', $entry)) continue;
                    $dir = $plugins_root . DIRECTORY_SEPARATOR . $entry;
                    if (!is_dir($dir)) continue;
                    $entries[] = $entry;
                }
                closedir($dh);
            }
        }
        sort($entries, SORT_STRING);

        foreach ($entries as $entry) {
            $dir = $plugins_root . DIRECTORY_SEPARATOR . $entry;
            $map = $this->pm_read_installed_versions();
            if (!empty($map[$entry]) && !empty($map[$entry]['version'])) {
                $out['plugins'][$entry] = (string)$map[$entry]['version'];
            } else {
                $out['plugins'][$entry] = $this->pm_read_plugin_version($dir);
            }
        }

        @file_put_contents($this->central_version_file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function pm_read_installed_versions()
    {
        $this->pm_data_init();
        if (!is_file($this->installed_versions_file)) return array();
        $raw = @file_get_contents($this->installed_versions_file);
        $j = @json_decode($raw, true);
        if (!is_array($j)) return array();
        $out = array();
        foreach ($j as $name => $val) {
            if (is_string($val)) {
                $out[$name] = array('version' => $val, 'last_updated' => null);
            } else if (is_array($val)) {
                $v = isset($val['version']) ? (string)$val['version'] : null;
                $lu = isset($val['last_updated']) ? $val['last_updated'] : null;
                $out[$name] = array('version' => $v, 'last_updated' => $lu);
            }
        }
        return $out;
    }

    private function pm_write_installed_versions(array $map)
    {
        $this->pm_data_init();
        @file_put_contents($this->installed_versions_file, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function can_view()
    {
        if ($this->visibility === 'admin_only') {
            return $this->is_update_admin();
        }
        return true;
    }

    private function is_update_admin()
    {
        $uid = method_exists($this->rc, 'get_user_id') ? intval($this->rc->get_user_id()) : (isset($this->rc->user) && isset($this->rc->user->ID) ? intval($this->rc->user->ID) : 0);
        $admins = (array)$this->config->get('pm_update_admins', array(1));
        $admins = array_map('intval', $admins);
        return in_array($uid, $admins, true);
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

    public function action_load_config()
    {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $this->rc->output->reset();
        @header('Content-Type: application/json; charset=UTF-8');

        $plug = rcube_utils::get_input_value('_pm_plug', rcube_utils::INPUT_GPC);
        $plug = preg_replace('~[^a-zA-Z0-9_\-\.]+~', '', (string)$plug);

        $root = dirname(__DIR__);
        $plugdir = ($plug !== '' && $root) ? ($root . DIRECTORY_SEPARATOR . $plug) : null;
        $cfg   = $plugdir ? ($plugdir . DIRECTORY_SEPARATOR . 'config.inc.php') : null;
        $cfgd  = $plugdir ? ($plugdir . DIRECTORY_SEPARATOR . 'config.inc.php.dist') : null;
        $cfgs  = $plugdir ? ($plugdir . DIRECTORY_SEPARATOR . 'config.inc.php.sample') : null;

        if ($plug === '' || !$plugdir || !is_dir($plugdir)) {
            echo json_encode(array('ok'=>false, 'error'=>'plugdir_not_found'));
            exit;
        }

        $path = null; $readonly = false;
        if ($cfg && is_readable($cfg)) { $path = $cfg; }
        elseif ($cfgd && is_readable($cfgd)) { $path = $cfgd; $readonly = true; }
        elseif ($cfgs && is_readable($cfgs)) { $path = $cfgs; $readonly = true; }
        else { echo json_encode(array('ok'=>false,'error'=>'no_config')); exit; }

        $text = @file_get_contents($path);
        if ($text === false) {
            echo json_encode(array('ok'=>false,'error'=>'read_fail'));
            exit;
        }
        echo json_encode(array('ok'=>true, 'path'=>$path, 'readonly'=>$readonly, 'content'=>$text, 'plug'=>$plug));
        exit;
    }

    public function action_save_config()
    {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $this->rc->output->reset();
        @header('Content-Type: application/json; charset=UTF-8');

        $plug = rcube_utils::get_input_value('_pm_plug', rcube_utils::INPUT_POST);
        $plug = preg_replace('~[^a-zA-Z0-9_\-\.]+~', '', (string)$plug);
        $content = rcube_utils::get_input_value('_pm_content', rcube_utils::INPUT_POST, true);

        $root = dirname(__DIR__);
        $plugdir = ($plug !== '' && $root) ? ($root . DIRECTORY_SEPARATOR . $plug) : null;

        if ($plug === '' || $content === null) {
            echo json_encode(array('ok'=>false, 'error'=>'bad_params'));
            exit;
        }
        if (!$plugdir || !is_dir($plugdir)) {
            echo json_encode(array('ok'=>false, 'error'=>'plugdir_not_found'));
            exit;
        }

        $file = $plugdir . DIRECTORY_SEPARATOR . 'config.inc.php';
        if (file_exists($file) && !is_writable($file)) {
            echo json_encode(array('ok'=>false, 'error'=>'not_writable'));
            exit;
        }
        if (!file_exists($file) && !is_writable($plugdir)) {
            echo json_encode(array('ok'=>false, 'error'=>'dir_not_writable'));
            exit;
        }
        $ok = @file_put_contents($file, $content);
        if ($ok === false) {
            echo json_encode(array('ok'=>false, 'error'=>'write_fail'));
            exit;
        }
        echo json_encode(array('ok'=>true, 'file'=>$file));
        exit;
    }

    private function pm_cache_last_ts() {
        $ts = 0;
        if (is_readable($this->cache_file)) {
            $json = @json_decode(@file_get_contents($this->cache_file), true);
            if (is_array($json)) {
                foreach ($json as $k => $v) {
                    if (is_array($v) && isset($v['ts'])) {
                        $t = intval($v['ts']);
                        if ($t > $ts) $ts = $t;
                    }
                }
            }
        }
        return $ts;
    }

    private function update_all_outdated($dry = false)
    {
        $result = array('ok'=>0,'fail'=>0,'skipped'=>array());
        $plugins = $this->discover_plugins();
        foreach ($plugins as $info) {
            $dir = $info['dir'];
            $base = basename($dir);
            $policy = $this->policy_for($base);
            if (!empty($policy['ignored']['bulk'])) {
                $result['skipped'][] = array('dir'=>$base,'reason'=>'ignored_bulk');
                continue;
            }
            if (preg_match('/\.bak-\d{8}-\d{6}$/', $dir)) {
                $result['skipped'][] = array('dir'=>$base,'reason'=>'backup_dir');
                continue;
            }
            $meta = $this->read_plugin_meta($dir);
            $sources = $this->build_sources($meta);
            if (empty($sources['composer_name']) && empty($sources['github'])) {
                $result['skipped'][] = array('dir'=>$base,'reason'=>'no_sources');
                continue;
            }
            $local = $this->detect_local_version($dir, $meta);
            $remote = $this->latest_version_cached($sources, true);
            if (!$remote) {
                $result['skipped'][] = array('dir'=>$base,'reason'=>'no_remote_version');
                continue;
            }
            if ($this->compare_versions($local, $remote) >= 0) {
                $result['skipped'][] = array('dir'=>$base,'reason'=>'up_to_date');
                continue;
            }
            if ($dry) {
                $result['skipped'][] = array('dir'=>$base,'reason'=>'dry','from'=>$local,'to'=>$remote);
                continue;
            }
            try {
                $ok = $this->perform_update($base);
                if ($ok === true) $result['ok']++;
                else $result['fail']++;
            } catch (Exception $e) {
                $result['fail']++;
            }
        }
        return $result;
    }
}
?>

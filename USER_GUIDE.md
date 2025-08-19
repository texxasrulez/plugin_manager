
# User Guide

Open: **Settings → Plugin Manager**

## Remote Checks
- The header shows whether remote checks are ON.
- You can override per request using URL params:
  - `&_pm_remote=1` → force ON
  - `&_pm_remote=0` → force OFF

## Status meanings
- **up_to_date (via …)** — Local version ≥ latest
- **Update available (via …)** — Latest is greater than local (emphasized)
- **bundled** — Marked as bundled with Roundcube; no remote check attempted
- **not_checked (no_source)** — No composer name or GitHub URL found
- **not_checked (no_release)** — Repo found but no releases/tags
- **not_checked (disabled)** — Remote checks are off for this load

## (check now)
Click the `(check now)` link in a row to bypass cache for that one plugin.

## Diagnostics
Click **Diagnostics** in the header to test Packagist and GitHub connectivity.
If you hit API limits, add a GitHub token in the plugin config:
```php
$config['pm_github_token'] = 'ghp_xxxxx';
```

## Mapping stubborn plugins
Copy `plugins/plugin_manager/sources.map.php.dist` → `sources.map.php` and add entries:
```php
<?php
return [
  'plugin_dir' => ['github' => 'owner/repo'],
  'other_dir'  => ['packagist' => 'vendor/name'],
  // To mark as bundled and skip checks:
  'core_plugin' => ['bundled' => true],
];
```

### Hide plugins from the UI
You can suppress specific plugins from appearing in the Plugin Manager by adding their directory names to
`pm_hidden_plugins` in `config.inc.php`.

```php
// Hide the built-in example plugins
$config['pm_hidden_plugins'] = array('zipdownload', 'managesieve');
```
This only affects display in the manager; it does **not** enable/disable the plugin itself.


# Plugin Manager for Roundcube

Lists installed plugins, shows local vs latest versions, and highlights **Update available**. Works with Larry, Elastic, and custom skins.

## Features
- Discovers installed plugins
- Shows local version (from composer.json, @version tags, or best-effort)
- Checks online (Packagist / GitHub releases, falls back to tags)
- Bold “Update available”
- One-click **(check now)** per row to bypass cache
- Diagnostics panel for connectivity
- `sources.map.php` to resolve outliers or mark plugins as **bundled**
- Scroll-friendly UI for large lists


# Install

## A) Composer (recommended)

1. In your Roundcube root (the folder with `composer.json`), run:
   ```bash
   composer require texxarulez/plugin_manager
   ```
   This uses `roundcube/plugin-installer` to place the plugin in `plugins/plugin_manager`.

2. Enable the plugin in Roundcube config (e.g. `config/config.inc.php`):
   ```php
   $config['plugins'][] = 'plugin_manager';
   ```

3. Clear caches.

## B) Manual

1. Copy the `plugin_manager/` folder into `roundcube/plugins/`.
2. Enable in config:
   ```php
   $config['plugins'][] = 'plugin_manager';
   ```
3. Clear caches.

## Optional
- To raise GitHub API limits, set in `plugins/plugin_manager/config.inc.php` (or main config):
  ```php
  $config['pm_github_token'] = 'ghp_xxxxx';
  ```

- To default remote checks on/off:
  ```php
  $config['pm_remote_checks'] = true; // or false
  ```

See `INSTALL.md` and `USER_GUIDE.md` for details.

### Hide plugins from the UI
You can suppress specific plugins from appearing in the Plugin Manager by adding their directory names to
`pm_hidden_plugins` in `config.inc.php`.

```php
// Hide the built-in example plugins
$config['pm_hidden_plugins'] = array('zipdownload', 'managesieve');
```
This only affects display in the manager; it does **not** enable/disable the plugin itself.

Screenshot of Update available:
![Alt text](/images/plugin-manager-screenshot-update.png?raw=true "Plugin Manager Update Screenshot")
Screenshot of Up to Date system:
![Alt text](/images/plugin-manager-screenshot.png?raw=true "Plugin Manager Screenshot")

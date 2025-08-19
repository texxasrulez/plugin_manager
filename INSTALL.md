
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

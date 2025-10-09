# Plugin Manager for Roundcube

[![Packagist](https://img.shields.io/packagist/dt/texxasrulez/plugin_manager?style=plastic&logo=packagist&logoColor=white&labelColor=blue&color=gold)](https://packagist.org/packages/texxasrulez/plugin_manager)
[![Packagist Version](https://img.shields.io/packagist/v/texxasrulez/plugin_manager?style=plastic&logo=packagist&logoColor=white&labelColor=blue&color=limegreen)](https://packagist.org/packages/texxasrulez/plugin_manager)
[![Project license](https://img.shields.io/github/license/texxasrulez/plugin_manager?style=plastic&labelColor=blue&color=coral)](https://github.com/texxasrulez/plugin_manager/LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/texxasrulez/plugin_manager?style=plastic&logo=github&labelColor=blue&color=deepskyblue)](https://github.com/texxasrulez/plugin_manager/stargazers)
[![issues](https://img.shields.io/github/issues/texxasrulez/plugin_manager?style=plastic&labelColor=blue&color=aqua)](https://github.com/texxasrulez/plugin_manager/issues)
[![GitHub contributors](https://img.shields.io/badge/Github-Contributors-orchid.svg?style=plastic&logo=github&logoColor=white&labelColor=blue&color=orchid)](https://github.com/texxasrulez/plugin_manager/graphs/contributors)
[![GitHub forks](https://img.shields.io/github/forks/texxasrulez/plugin_manager?style=plastic&logo=github&logoColor=white&labelColor=blue&color=darkorange)](https://github.com/texxasrulez/plugin_manager/forks)
[![Donate to this project using Paypal](https://img.shields.io/badge/paypal-Money_Please-blue.svg?style=plastic&labelColor=blue&color=forestgreen&logo=paypal)](https://www.paypal.me/texxasrulez)

Lists installed plugins, shows local vs latest versions, and highlights **Update available**.

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

![Plugin Manager Screenshot](/images/plugin-manager-screenshot.png?raw=true "Plugin Manager Screenshot")
![Plugin Manager Update Screenshot](/images/plugin-manager-screenshot-update.png?raw=true "Plugin Manager Update Screenshot")
![Plugin Manager Edit Config Screenshot](/images/plugin-manager-screenshot-edit-config.png?raw=true "Plugin Manager Edit Config Screenshot")

# Plugin Manager for Roundcube

![Downloads](https://img.shields.io/github/downloads/texxasrulez/plugin_manager/total?style=plastic&logo=github&logoColor=white&label=Downloads&labelColor=aqua&color=blue)
[![Packagist Downloads](https://img.shields.io/packagist/dt/texxasrulez/plugin_manager?style=plastic&logo=packagist&logoColor=white&label=Downloads&labelColor=blue&color=gold)](https://packagist.org/packages/texxasrulez/plugin_manager)
[![Packagist Version](https://img.shields.io/packagist/v/texxasrulez/plugin_manager?style=plastic&logo=packagist&logoColor=white&label=Version&labelColor=blue&color=limegreen)](https://packagist.org/packages/texxasrulez/plugin_manager)
[![Github License](https://img.shields.io/github/license/texxasrulez/plugin_manager?style=plastic&logo=github&label=License&labelColor=blue&color=coral)](https://github.com/texxasrulez/plugin_manager/LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/texxasrulez/plugin_manager?style=plastic&logo=github&label=Stars&labelColor=blue&color=deepskyblue)](https://github.com/texxasrulez/plugin_manager/stargazers)
[![GitHub Issues](https://img.shields.io/github/issues/texxasrulez/plugin_manager?style=plastic&logo=github&label=Issues&labelColor=blue&color=aqua)](https://github.com/texxasrulez/plugin_manager/issues)
[![GitHub Contributors](https://img.shields.io/github/contributors/texxasrulez/plugin_manager?style=plastic&logo=github&logoColor=white&label=Contributors&labelColor=blue&color=orchid)](https://github.com/texxasrulez/plugin_manager/graphs/contributors)
[![GitHub Forks](https://img.shields.io/github/forks/texxasrulez/plugin_manager?style=plastic&logo=github&logoColor=white&label=Forks&labelColor=blue&color=darkorange)](https://github.com/texxasrulez/plugin_manager/forks)
[![Donate Paypal](https://img.shields.io/badge/Paypal-Money_Please!-blue.svg?style=plastic&labelColor=blue&color=forestgreen&logo=paypal)](https://www.paypal.me/texxasrulez)

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

![Alt text](/images/plugin-manager-screenshot.png?raw=true "Plugin Manager Screenshot")

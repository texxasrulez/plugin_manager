# Plugin Manager for Roundcube

[![GitHub Workflow Status (branch)](https://img.shields.io/github/workflow/status/texxasrulez/plugin_manager/frontend/master?style=flat-square&label=build%20frontend)](https://github.com/texxasrulez/plugin_manager/actions)
[![GitHub Workflow Status (branch)](https://img.shields.io/github/workflow/status/texxasrulez/plugin_manager/backend/master?style=flat-square&label=build%20backend)](https://github.com/texxasrulez/plugin_manager/actions)
[![Packagist](https://img.shields.io/packagist/dt/texxasrulez/plugin_manager?style=flat-square)](https://packagist.org/packages/texxasrulez/plugin_manager)
[![Packagist Version](https://img.shields.io/packagist/v/texxasrulez/plugin_manager?style=flat-square)](https://packagist.org/packages/texxasrulez/plugin_manager)
[![Project license](https://img.shields.io/github/license/texxasrulez/plugin_manager?style=flat-square)](https://github.com/texxasrulez/plugin_manager/LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/texxasrulez/plugin_manager?style=flat-square&logo=github)](https://github.com/texxasrulez/plugin_manager/stargazers)
[![Donate to this project using Paypal](https://img.shields.io/badge/paypal-donate-blue.svg?style=flat-square&logo=paypal)](https://www.paypal.me/texxasrulez)

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

<h1 align="center">Hi 👋, I'm Gene Hawkins</h1>
<h3 align="center">Just a simple man from Texas</h3>

<p align="left"> <img src="https://komarev.com/ghpvc/?username=texxasrulez&label=Profile%20views&color=0e75b6&style=flat" alt="texxasrulez" /> </p>

<p align="left"> <a href="https://github.com/ryo-ma/github-profile-trophy"><img src="https://github-profile-trophy.vercel.app/?username=texxasrulez" alt="texxasrulez" /></a> </p>

<h3 align="left">Connect with me:</h3>
<p align="left">
</p>

<h3 align="left">Languages and Tools:</h3>
<p align="left"> <a href="https://www.w3schools.com/css/" target="_blank" rel="noreferrer"> <img src="https://raw.githubusercontent.com/devicons/devicon/master/icons/css3/css3-original-wordmark.svg" alt="css3" width="40" height="40"/> </a> <a href="https://www.w3.org/html/" target="_blank" rel="noreferrer"> <img src="https://raw.githubusercontent.com/devicons/devicon/master/icons/html5/html5-original-wordmark.svg" alt="html5" width="40" height="40"/> </a> <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript" target="_blank" rel="noreferrer"> <img src="https://raw.githubusercontent.com/devicons/devicon/master/icons/javascript/javascript-original.svg" alt="javascript" width="40" height="40"/> </a> <a href="https://www.linux.org/" target="_blank" rel="noreferrer"> <img src="https://raw.githubusercontent.com/devicons/devicon/master/icons/linux/linux-original.svg" alt="linux" width="40" height="40"/> </a> <a href="https://www.mysql.com/" target="_blank" rel="noreferrer"> <img src="https://raw.githubusercontent.com/devicons/devicon/master/icons/mysql/mysql-original-wordmark.svg" alt="mysql" width="40" height="40"/> </a> <a href="https://www.php.net" target="_blank" rel="noreferrer"> <img src="https://raw.githubusercontent.com/devicons/devicon/master/icons/php/php-original.svg" alt="php" width="40" height="40"/> </a> </p>

<p><img align="left" src="https://github-readme-stats.vercel.app/api/top-langs?username=texxasrulez&show_icons=true&locale=en&layout=compact" alt="texxasrulez" /></p>

<p>&nbsp;<img align="center" src="https://github-readme-stats.vercel.app/api?username=texxasrulez&show_icons=true&locale=en" alt="texxasrulez" /></p>

<p><img align="center" src="https://github-readme-streak-stats.herokuapp.com/?user=texxasrulez&" alt="texxasrulez" /></p>


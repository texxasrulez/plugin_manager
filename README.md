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

## Versioning
- `plugin_manager` now keeps its own canonical version in `plugin_manager::PLUGIN_VERSION` inside `plugin_manager.php`.
- `plugin_manager::info()` exposes the plugin metadata array used for self-identification.
- Development builds should use a `+dev` suffix such as `1.0.0+dev`.
- Release builds should use a clean tagged version such as `1.0.0`.

For a release bump:
1. Update `plugin_manager::PLUGIN_VERSION` in `plugin_manager.php` or run `sh scripts/bump-version.sh 1.0.0`.
2. Update `CHANGELOG.md`.
3. Create the matching release tag after verification.

## Features
- Discovers installed plugins
- Shows local version (from composer.json, explicit version constants/tags, or best-effort)
- Checks online (Packagist / GitHub releases, falls back to tags)
- Bold “Update available”
- Post-update advisory scan for upgrade/migration signals such as `migrations/`, `sql/`, `UPGRADE.md`, and composer metadata hints
- Optional allowlisted Plugin Manager-owned post-update hook files, disabled by default and scoped per plugin
- Manual admin-only `Run maintenance` action for plugins with an eligible allowlisted hook
- Per-plugin maintenance status badges and a recent maintenance activity panel
- One-click **(check now)** per row to bypass cache
- Diagnostics panel for connectivity
- `sources.map.php` to resolve outliers or mark plugins as **bundled**
- Scroll-friendly UI for large lists


# Install

## A) Composer (recommended)

1. In your Roundcube root (the folder with `composer.json`), run:
   ```bash
   composer require texxasrulez/plugin_manager
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

## Local version notes

On startup, Plugin Manager bootstraps `data/installed_versions.json` and `data/version.json` if they are missing.

For most modern plugins, the local installed version can be detected immediately from local plugin files such as `composer.json` or explicit PHP version constants.

Some older or legacy plugins do not publish a reliable local version inside the installed files.
For those plugins, Plugin Manager may show a best-effort local value until the plugin is updated through the Plugin Manager UI or the upstream plugin adopts explicit local version metadata.

## Post-update advisory and hooks

After a successful plugin update, Plugin Manager now performs a read-only inspection of the updated plugin directory.
It looks for migration or upgrade signals such as:

- `sql/`, `db/`, `database/`, `migrations/`, `updates/`, `upgrade/`
- `UPGRADE`, `UPGRADING`, `INSTALL`, `CHANGELOG`, `README`, `UPGRADE.md`, `INSTALL.md`
- `composer.json` signals such as install/update-related `scripts`, `extra`, or `suggest` metadata

This inspection is advisory only. It does not run SQL, shell commands, composer scripts, or arbitrary plugin code.

If signals are found, Plugin Manager shows an advisory flash message after the normal update success message.

### Allowlisted post-update hooks

Plugin Manager can also run a strictly allowlisted internal PHP hook file after a successful install or update.
This is deny-by-default: nothing runs unless you explicitly configure it in `pm_post_update_hooks`.

The executable maintenance code now lives inside `plugin_manager/webhooks/`, not inside the target plugin directory.
That means:

- third-party plugins do not need to ship their own maintenance hook files
- administrators do not need to patch vendor plugins just to add maintenance support
- Plugin Manager remains the only codebase that owns runnable maintenance hook files
- target plugin directories are still inspected for advisory signals and used as data inputs, but not as hook sources

Important safety rules:

- only hooks listed by the administrator in config are considered
- only `type = 'internal'` is supported
- the configured hook path must resolve under `plugin_manager/webhooks/<plugin>/`
- plugin metadata does not authorize hook execution
- manual maintenance appears only for plugins with a valid allowlisted hook that explicitly supports manual execution
- hook preflight validation is reused for status badges, automatic runs, and manual runs
- no shell commands, SQL execution, or composer script execution are performed by Plugin Manager

#### Where the hook file lives

The hook file lives under a dedicated directory inside Plugin Manager:

```text
plugin_manager/
└── webhooks/
    └── archive/
        └── post-update.php
```

The config path is relative to the per-plugin webhook folder, not to the Roundcube root and not to the target plugin directory.
For the example above:

```php
'path' => 'post-update.php',
```

Conceptually:

1. create `plugin_manager/webhooks/<plugin>/`
2. put a hook file in that folder
3. allowlist that file for the matching plugin in `pm_post_update_hooks`

Example config shape:

```php
$config['pm_post_update_hooks'] = [
    'archive' => [
        'enabled' => true,
        'type' => 'internal',
        'path' => 'post-update.php',
        'label' => 'Run archive maintenance',
        'timeout' => 10,
        'args' => [],
        'run_on' => ['update', 'manual'],
        'allow_manual_run' => true,
        'require_confirmation' => true,
    ],
];
```

In that example:

- the plugin key is `archive`
- the executable code is `plugin_manager/webhooks/archive/post-update.php`
- Plugin Manager will only consider that hook file for the `archive` plugin
- no file inside `plugins/archive/` is used as executable hook code
- the plugin directory is still passed in the context array so the hook can inspect or update plugin-owned files when appropriate

#### Step-by-step setup

1. Decide which plugin needs maintenance support.
2. Create a directory under `plugin_manager/webhooks/` that matches the plugin directory name.
3. Put a dedicated hook file in that folder, such as `post-update.php`.
4. Keep that hook file narrow and plugin-specific.
5. Add an allowlist entry under the plugin’s exact directory name in `pm_post_update_hooks`.
6. Set `path` to the file path relative to `plugin_manager/webhooks/<plugin>/`.
7. Decide which events may run it with `run_on`.
8. Set `require_confirmation` if you want automatic update-time execution suppressed until an admin explicitly runs it.
9. If you want manual execution available at all, include `manual` in `run_on` and leave `allow_manual_run` enabled.
10. Set `show_manual_link` to `true` only if you want the row-level UI action visible even when the hook can already auto-run.

Plugin Manager now ships a growing set of example webhook files under `webhooks/` for popular plugins.
These examples are meant to save admins time and provide a safe starting point for common plugin maintenance reviews.

Example:

```php
$config['pm_post_update_hooks'] = [
    'archive' => [
        'enabled' => true,
        'type' => 'internal',
        'path' => 'post-update.php',
        'label' => 'Run archive maintenance',
        'run_on' => ['update', 'manual'],
        'allow_manual_run' => true,
        'show_manual_link' => false,
        'require_confirmation' => true,
    ],
];
```

In that example:

- the plugin directory name is `archive`
- Plugin Manager will only consider hooks for `archive` if that exact key exists
- the executable maintenance code is `plugin_manager/webhooks/archive/post-update.php`
- the hook will not auto-run on install because `install` is not listed in `run_on`
- the hook will not auto-run on update because `require_confirmation` is `true`
- the admin can still use the row-level `Run maintenance` action because confirmation is required

Field notes:

- `enabled`: master switch for this plugin hook
- `type`: currently only `internal`
- `path`: relative path under `plugin_manager/webhooks/<plugin>/`
- `timeout`: parsed and logged, but not enforced in the current phase
- `run_on`: supported values are `install`, `update`, and `manual`
- `allow_manual_run`: if omitted it is treated as enabled, but the hook must still allow the `manual` event
- `show_manual_link`: if `true`, show the row-level manual action even when the hook could auto-run; default behavior keeps it hidden unless confirmation is required
- `require_confirmation`: if `true`, the hook is not auto-run after update; the manual action satisfies that confirmation requirement

#### What Plugin Manager validates before it will run a hook

For every automatic or manual maintenance attempt, Plugin Manager performs a preflight check.
The hook is refused unless all of the following are true:

- the current user is allowed to perform update/maintenance actions
- the request token is valid
- the plugin name is syntactically valid
- the plugin has an exact allowlist entry in `pm_post_update_hooks`
- the hook config is enabled
- `type` is exactly `internal`
- the current event is allowed by `run_on`
- manual runs are allowed when the event is `manual`
- the configured `path` is syntactically safe
- the resolved hook file exists under `plugin_manager/webhooks/<plugin>/`
- the hook file is readable, is a regular file, is not a symlink, and remains inside that webhook directory

If any of those checks fail:

- the hook is not run
- the plugin may show a `Blocked` maintenance badge
- the admin gets a warning when a run was attempted
- the refusal is recorded in the maintenance audit trail
- update ZIP archives are also sanity-checked before extraction so obviously unsafe archive paths are rejected early

#### How to write an internal hook file

An internal hook file should be a narrow PHP file inside `plugin_manager/webhooks/<plugin>/`.
It should return a callable that accepts a plain context array and returns a normalized result array.
Keep the file explicit and easy to review.

Recommended practices for hook files:

- keep each hook file dedicated to one plugin or one narrow maintenance task
- use the provided `$context` array rather than inferring event state indirectly
- return concise admin-facing messages
- treat missing prerequisites as a clean failure and report them in `message` or `details`
- keep plugin-specific filesystem writes scoped to the selected plugin directory unless there is a strong reason not to

Avoid in hook files:

- printing output directly
- calling `exit` or `die`
- returning scalars or non-array data
- assuming Plugin Manager will execute shell commands for you
- assuming Plugin Manager will run Composer for you
- assuming Plugin Manager will execute SQL files for you automatically

### Maintenance status and preflight

Each plugin row can show a compact maintenance badge:

- `Available`: a valid allowlisted manual maintenance hook is ready to run
- `Blocked`: a hook is configured, but preflight validation found a problem
- `Ran recently`: a maintenance run for that plugin was recently recorded in the audit log

Preflight validation checks:

- exact plugin allowlist match
- hook config exists and is enabled
- supported hook type
- `run_on` allows the current event
- `allow_manual_run` allows manual execution
- hook path is syntactically valid
- hook file is present under `plugin_manager/webhooks/<plugin>/`
- hook file remains inside that directory after resolution
- hook file is readable, is a regular file, and is not a symlink

Blocked hooks stay blocked until the underlying configuration or file problem is fixed.

If `require_confirmation` is `true`, Plugin Manager will show a message that the hook is available but was not run automatically.

### Manual Run maintenance action

If a plugin has an eligible allowlisted hook with `run_on` containing `manual`, Plugin Manager can show a row-level `Run maintenance` action for administrators.

By default, the row link is shown only when manual confirmation is required.
If you want the manual link visible for an otherwise auto-running hook, set `show_manual_link = true` in that hook config.

This action:

- is admin-only
- requires the normal request token
- asks for confirmation before running
- uses the same allowlisted internal hook-file validation and execution path as automatic post-update hooks
- returns to the main Plugin Manager page with normal flash messages

It is not a generic script runner. Plugin Manager still does not execute shell commands, composer scripts, or arbitrary plugin-discovered code.

### Maintenance audit trail

Plugin Manager keeps a lightweight bounded audit trail of recent maintenance activity in its local data directory.
Entries include:

- timestamp
- plugin
- event (`install`, `update`, or `manual`)
- result (`success`, `warning`, `failure`, `skipped`, or `refused`)
- short message

The Plugin Manager page shows a small read-only `Recent maintenance activity` panel for administrators.
It appears below the plugin list and can be hidden entirely with the `Hide recent maintenance activity` checkbox.
That visibility preference is stored in the browser so admins can keep it collapsed if they prefer.
By default, the audit trail is enabled and retains up to 100 recent entries.

### Internal hook file contract

An internal maintenance hook file is a PHP file owned by `plugin_manager`.
Plugin Manager loads it from `plugin_manager/webhooks/<plugin>/...`, expects the file to return a callable, and then calls that callable with the maintenance context.

Example file:

```php
<?php

return function (array $context): array {
    return [
        'ok' => true,
        'message' => 'Archive maintenance completed.',
        'details' => [
            'Plugin: ' . ($context['plugin'] ?? 'unknown'),
            'Event: ' . ($context['event'] ?? 'unknown'),
        ],
    ];
};
```

Example location:

```text
plugin_manager/webhooks/archive/post-update.php
```

Plugin Manager resolves the configured path under that plugin-specific webhook directory and executes the returned callable directly.
It does not load executable hook files from the target plugin directory.
Plugin Manager also refuses webhook paths that escape the plugin-specific webhook directory, point at symlinks, or do not resolve to a readable regular file.

Example context passed to the hook:

```php
[
    'plugin' => 'example_plugin',
    'plugin_path' => '/full/path/to/roundcube/plugins/example_plugin',
    'event' => 'manual',
    'previous_version' => null,
    'current_version' => '1.2.4',
    'request_time' => 1710000000,
]
```

Expected return shape:

```php
[
    'ok' => true,
    'message' => 'Did maintenance work.',
    'details' => ['Additional note'],
]
```

Result field meaning:

- `ok`: required boolean success flag
- `message`: short summary shown to the administrator
- `details`: optional list of additional short detail strings

If `ok` is `false`, Plugin Manager treats the run as a failure and shows a warning.
If the hook throws an exception or returns malformed data, Plugin Manager catches that and reports a clean failure instead of breaking the page.

#### More complete example internal hook file

```php
<?php

return function (array $context): array {
    $plugin = $context['plugin'] ?? 'unknown';
    $event = $context['event'] ?? 'unknown';
    $pluginPath = $context['plugin_path'] ?? '';
    $currentVersion = $context['current_version'] ?? null;
    $previousVersion = $context['previous_version'] ?? null;

    if ($pluginPath === '' || !is_dir($pluginPath)) {
        return [
            'ok' => false,
            'message' => 'Plugin path is not available for maintenance.',
            'details' => [],
        ];
    }

    $details = [
        'Plugin: ' . $plugin,
        'Event: ' . $event,
    ];

    if ($previousVersion !== null && $currentVersion !== null) {
        $details[] = 'Version change: ' . $previousVersion . ' -> ' . $currentVersion;
    }

    $markerFile = $pluginPath . '/data/maintenance.last-run';
    $markerDir = dirname($markerFile);

    if (!is_dir($markerDir) && !@mkdir($markerDir, 0775, true) && !is_dir($markerDir)) {
        return [
            'ok' => false,
            'message' => 'Could not create plugin maintenance directory.',
            'details' => $details,
        ];
    }

    $written = @file_put_contents($markerFile, date('c') . "\n");
    if ($written === false) {
        return [
            'ok' => false,
            'message' => 'Could not write plugin maintenance marker.',
            'details' => $details,
        ];
    }

    $details[] = 'Wrote maintenance marker: data/maintenance.last-run';

    return [
        'ok' => true,
        'message' => 'Example plugin maintenance completed.',
        'details' => $details,
    ];
};
```

That example still operates on the target plugin directory through `$context['plugin_path']`, but the executable code itself lives in `plugin_manager`.
That is the key safety and maintenance difference in this model.

#### Choosing `run_on` in practice

Use `run_on` to limit when Plugin Manager may invoke the hook:

- `['update']`: automatic after updates only
- `['update', 'manual']`: automatic after updates and also available from the row action
- `['manual']`: never auto-run, only available when the admin clicks `Run maintenance`
- `['install', 'update', 'manual']`: eligible for all supported events

If you are unsure, start with:

```php
'run_on' => ['manual'],
'require_confirmation' => true,
```

That gives you the safest rollout: the hook is configured and visible, but it only runs when an administrator explicitly chooses to run it.

#### What Plugin Manager does not do

Allowlisting a hook does not change these limits:

- Plugin Manager does not execute shell commands for hooks
- Plugin Manager does not run Composer scripts
- Plugin Manager does not auto-run SQL files
- Plugin Manager does not trust plugin metadata as permission to execute code
- Plugin Manager does not auto-discover executable hooks from plugin files
- Plugin Manager does not execute arbitrary plugin-owned PHP files as maintenance hooks

Administrators should review any allowlisted internal hook file carefully before enabling it.
The allowlist is an explicit trust decision by the administrator.

If the hook throws, returns malformed data, or otherwise fails validation, Plugin Manager refuses it or reports the failure cleanly and continues the normal page flow.

Administrators should only allowlist hook files they trust and have reviewed. Allowlisting a hook file is an explicit decision to permit that `plugin_manager` maintenance routine to run for that specific plugin.

Plugin config editing in the manager is also limited to resolved live plugin directories. Backup directories and other non-live plugin paths are not valid config-edit targets.

If you want help creating a webhook for another plugin, contact me and I can help put one together.

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

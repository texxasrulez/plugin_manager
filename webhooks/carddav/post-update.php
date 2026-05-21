<?php

return function (array $context): array {
    $pluginPath = isset($context['plugin_path']) ? (string) $context['plugin_path'] : '';
    $details = array();
    $previousVersion = isset($context['previous_version']) ? (string) $context['previous_version'] : '';
    $currentVersion = isset($context['current_version']) ? (string) $context['current_version'] : '';

    if ($pluginPath === '' || !is_dir($pluginPath)) {
        return array(
            'ok' => false,
            'message' => 'RCMCardDAV maintenance review could not locate the plugin directory.',
            'details' => array(),
        );
    }

    $dbMigrationsDir = $pluginPath . DIRECTORY_SEPARATOR . 'dbmigrations';
    if (is_dir($dbMigrationsDir)) {
        $details[] = 'dbmigrations/ detected. Upstream performs needed schema upgrades on next Roundcube login.';
    }

    $details[] = 'Recommended upgrade flow: log out before upgrading and log in again after the upgrade so any required DB migration can run.';

    $adminSettingsDoc = $pluginPath . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . 'ADMIN-SETTINGS.md';
    if (is_file($adminSettingsDoc)) {
        $details[] = 'Review doc/ADMIN-SETTINGS.md when upgrading across major versions or changing preset/discovery configuration.';
    }

    if ($previousVersion !== '' && $currentVersion !== '') {
        $details[] = 'Version change: ' . $previousVersion . ' -> ' . $currentVersion;

        if (preg_match('/^4\./', $previousVersion) && preg_match('/^5\./', $currentVersion)) {
            $details[] = 'Upstream 4.x -> 5.x notes mention automatic DB migration plus possible manual cleanup of migrated accounts and config review.';
        } elseif (preg_match('/^3\./', $previousVersion) && preg_match('/^4\./', $currentVersion)) {
            $details[] = 'Upstream 3.x -> 4.x notes mention automatic DB migration and possible MySQL/MariaDB row-format prerequisites.';
        }
    }

    return array(
        'ok' => true,
        'message' => 'RCMCardDAV maintenance review completed.',
        'details' => $details,
    );
};

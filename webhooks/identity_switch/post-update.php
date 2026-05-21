<?php

return function (array $context): array {
    $pluginPath = isset($context['plugin_path']) ? (string) $context['plugin_path'] : '';
    $details = array();

    if ($pluginPath === '' || !is_dir($pluginPath)) {
        return array(
            'ok' => false,
            'message' => 'Identity Switch maintenance review could not locate the plugin directory.',
            'details' => array(),
        );
    }

    $configFiles = array();
    foreach (array('config.inc.php', 'config.inc.php.dist', 'README', 'README.md', 'CHANGELOG.md', 'UPGRADING') as $file) {
        if (is_file($pluginPath . DIRECTORY_SEPARATOR . $file)) {
            $configFiles[] = $file;
        }
    }

    if (!empty($configFiles)) {
        $details[] = 'Review plugin docs/config after upgrade: ' . implode(', ', $configFiles);
    } else {
        $details[] = 'Review plugin settings and identity-switch behavior after upgrade.';
    }

    $details[] = 'This webhook is intentionally conservative because no public upstream repo documentation was available during setup.';
    $details[] = 'Verify identity selection, default identity behavior, and any related account settings after updating.';

    return array(
        'ok' => true,
        'message' => 'Identity Switch maintenance review completed.',
        'details' => $details,
    );
};

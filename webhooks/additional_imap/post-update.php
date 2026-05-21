<?php

return function (array $context): array {
    $pluginPath = isset($context['plugin_path']) ? (string) $context['plugin_path'] : '';
    $details = array();

    if ($pluginPath === '' || !is_dir($pluginPath)) {
        return array(
            'ok' => false,
            'message' => 'Additional IMAP maintenance review could not locate the plugin directory.',
            'details' => array(),
        );
    }

    $sqlDir = $pluginPath . DIRECTORY_SEPARATOR . 'SQL';
    if (is_dir($sqlDir)) {
        $details[] = 'SQL/ detected. Manual installs require importing the schema from the SQL directory.';
    }

    $configFiles = array();
    foreach (array('config.inc.php', 'config.inc.php.dist') as $file) {
        if (is_file($pluginPath . DIRECTORY_SEPARATOR . $file)) {
            $configFiles[] = $file;
        }
    }
    if (!empty($configFiles)) {
        $details[] = 'Review plugin config after upgrade: ' . implode(', ', $configFiles);
    }

    $details[] = 'Upstream docs note that composer installs inject the SQL schema automatically, while manual installs require schema import.';
    $details[] = 'After enabling the plugin, review additional IMAP identities in Settings > Identities.';

    return array(
        'ok' => true,
        'message' => 'Additional IMAP maintenance review completed.',
        'details' => $details,
    );
};

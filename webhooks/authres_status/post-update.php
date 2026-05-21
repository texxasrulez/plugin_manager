<?php

return function (array $context): array {
    $pluginPath = isset($context['plugin_path']) ? (string) $context['plugin_path'] : '';
    $details = array();

    if ($pluginPath === '' || !is_dir($pluginPath)) {
        return array(
            'ok' => false,
            'message' => 'authres_status maintenance review could not locate the plugin directory.',
            'details' => array(),
        );
    }

    foreach (array('config.inc.php', 'config.inc.php.dist') as $file) {
        $path = $pluginPath . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            continue;
        }

        if (stripos($raw, 'trusted') !== false) {
            $details[] = 'Review trusted Authentication-Results authserv-id settings in ' . $file . '.';
            break;
        }
    }

    $details[] = 'Upstream docs recommend configuring trusted authserv-id values so only results from your trusted MTAs are shown.';
    $details[] = 'If you use the optional internal DKIM verifier, review performance impact after upgrading.';

    return array(
        'ok' => true,
        'message' => 'authres_status maintenance review completed.',
        'details' => $details,
    );
};

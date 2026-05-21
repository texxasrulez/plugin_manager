<?php

return function (array $context): array {
    $pluginPath = isset($context['plugin_path']) ? (string) $context['plugin_path'] : '';
    $details = array();

    if ($pluginPath === '' || !is_dir($pluginPath)) {
        return array(
            'ok' => false,
            'message' => 'libkolab maintenance review could not locate the plugin directory.',
            'details' => array(),
        );
    }

    if (is_dir($pluginPath . DIRECTORY_SEPARATOR . 'SQL')) {
        $details[] = 'SQL/ detected. Review whether this libkolab upgrade requires schema refresh for your deployment.';
    }

    foreach (array('UPGRADING', 'README', 'README.md', 'config.inc.php.dist') as $file) {
        if (is_file($pluginPath . DIRECTORY_SEPARATOR . $file)) {
            $details[] = 'Review ' . $file . ' after upgrade.';
        }
    }

    if (is_dir($pluginPath . DIRECTORY_SEPARATOR . 'bin')) {
        $details[] = 'bin/ detected. Review any libkolab helper scripts you rely on after upgrading.';
    }

    return array(
        'ok' => true,
        'message' => 'libkolab maintenance review completed.',
        'details' => $details,
    );
};

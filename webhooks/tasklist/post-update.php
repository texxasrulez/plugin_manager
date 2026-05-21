<?php

return function (array $context): array {
    $pluginPath = isset($context['plugin_path']) ? (string) $context['plugin_path'] : '';
    $details = array();

    if ($pluginPath === '' || !is_dir($pluginPath)) {
        return array(
            'ok' => false,
            'message' => 'Tasklist maintenance review could not locate the plugin directory.',
            'details' => array(),
        );
    }

    $driverDir = $pluginPath . DIRECTORY_SEPARATOR . 'drivers';
    if (is_dir($driverDir)) {
        $details[] = 'drivers/ detected. Review your active CalDAV or RoundDAV tasklist backend after upgrading.';
    }

    foreach (array('UPGRADING', 'README', 'README.md', 'config.inc.php.dist') as $file) {
        if (is_file($pluginPath . DIRECTORY_SEPARATOR . $file)) {
            $details[] = 'Review ' . $file . ' after upgrade.';
        }
    }

    $details[] = 'Upstream docs require tasklist_caldav_server and optionally nextcloud_tasks_collection for Nextcloud/CalDAV deployments.';
    $details[] = 'After upgrading, verify task list discovery and confirm the configured CalDAV endpoint still resolves correctly.';

    return array(
        'ok' => true,
        'message' => 'Tasklist maintenance review completed.',
        'details' => $details,
    );
};

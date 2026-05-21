<?php

return function (array $context): array {
    $pluginPath = isset($context['plugin_path']) ? (string) $context['plugin_path'] : '';
    $details = array();

    if ($pluginPath === '' || !is_dir($pluginPath)) {
        return array(
            'ok' => false,
            'message' => 'Scheduled Sending maintenance review could not locate the plugin directory.',
            'details' => array(),
        );
    }

    $schema = $pluginPath . DIRECTORY_SEPARATOR . 'SQL' . DIRECTORY_SEPARATOR . 'mysql.initial.sql';
    if (is_file($schema)) {
        $details[] = 'Queue schema file detected: SQL/mysql.initial.sql. Ensure the queue table exists before relying on scheduled delivery.';
    }

    if (is_file($pluginPath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'scheduled_queue_worker.php')) {
        $details[] = 'Worker helper detected: bin/scheduled_queue_worker.php';
    }
    if (is_file($pluginPath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'scheduled_send.php')) {
        $details[] = 'Worker shim detected: bin/scheduled_send.php';
    }

    $details[] = 'Upstream docs require a periodic worker call to plugin.scheduled_sending.send_due with the configured token.';
    $details[] = 'After upgrading, verify config.inc.php token/lock settings and confirm your cron or worker runner still targets the correct URL.';

    return array(
        'ok' => true,
        'message' => 'Scheduled Sending maintenance review completed.',
        'details' => $details,
    );
};

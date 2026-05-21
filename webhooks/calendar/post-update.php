<?php

function_exists('plugin_manager_extract_calendar_driver') || require_once __DIR__ . '/shared.php';

return function (array $context): array {
    $pluginPath = isset($context['plugin_path']) ? (string) $context['plugin_path'] : '';
    $details = array();

    if ($pluginPath === '' || !is_dir($pluginPath)) {
        return array(
            'ok' => false,
            'message' => 'Calendar maintenance review could not locate the plugin directory.',
            'details' => array(),
        );
    }

    $pluginsRoot = dirname($pluginPath);
    $dependencyMap = array(
        'libcalendaring' => $pluginsRoot . DIRECTORY_SEPARATOR . 'libcalendaring',
        'libkolab' => $pluginsRoot . DIRECTORY_SEPARATOR . 'libkolab',
    );

    foreach ($dependencyMap as $name => $path) {
        $details[] = is_dir($path)
            ? 'Dependency present: ' . $name
            : 'Dependency missing: ' . $name;
    }

    $driver = null;
    foreach (array('config.inc.php', 'config.inc.php.dist') as $configFile) {
        $path = $pluginPath . DIRECTORY_SEPARATOR . $configFile;
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            continue;
        }

        $parsedDriver = plugin_manager_extract_calendar_driver($raw);
        if ($parsedDriver !== null) {
            $driver = $parsedDriver;
            break;
        }
    }

    $sqlDrivers = array();
    foreach (array('database', 'caldav', 'kolab', 'rounddav') as $candidate) {
        $sqlDir = $pluginPath . DIRECTORY_SEPARATOR . 'drivers' . DIRECTORY_SEPARATOR . $candidate . DIRECTORY_SEPARATOR . 'SQL';
        if (is_dir($sqlDir)) {
            $sqlDrivers[] = $candidate;
        }
    }

    if ($driver !== null) {
        $details[] = 'Configured driver: ' . $driver;
        if (in_array($driver, $sqlDrivers, true)) {
            $details[] = 'If this upgrade needs schema refresh, review: bin/initdb.sh --dir=plugins/calendar/drivers/' . $driver . '/SQL';
        } else {
            $details[] = 'No matching SQL directory was found for configured driver: ' . $driver;
        }
    } elseif (!empty($sqlDrivers)) {
        $details[] = 'Set calendar_driver in config.inc.php and review the matching SQL directory if schema initialization is needed: ' . implode(', ', $sqlDrivers);
    }

    $details[] = 'Upstream docs indicate SQL initialization is backend-specific for calendar.';

    return array(
        'ok' => true,
        'message' => 'Calendar maintenance review completed.',
        'details' => $details,
    );
};

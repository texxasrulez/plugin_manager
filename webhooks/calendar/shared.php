<?php

if (!function_exists('plugin_manager_extract_calendar_driver')) {
    function plugin_manager_extract_calendar_driver($raw)
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        if (preg_match('~\[\s*[\'"]calendar_driver[\'"]\s*\]\s*=\s*[\'"]([^\'"]+)[\'"]\s*;~', $raw, $matches) !== 1) {
            return null;
        }

        return isset($matches[1]) && $matches[1] !== '' ? $matches[1] : null;
    }
}

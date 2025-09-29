<?php

namespace DB\Jobs;

class Nearby_Logger {

    public static function log(string $channel, string $message, array $context = []): void {
        $channel = strtoupper(trim($channel)) ?: 'GENERAL';

        $line = sprintf('[DB Nearby][%s] %s', $channel, $message);
        if (!empty($context)) {
            $context_json = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($context_json !== false) {
                $line .= ' | ' . $context_json;
            }
        }

        error_log($line);

        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
        if (!empty($uploads['basedir']) && is_dir($uploads['basedir']) && is_writable($uploads['basedir'])) {
            $log_path = trailingslashit($uploads['basedir']) . 'db-nearby-debug.log';
            $timestamp = gmdate('Y-m-d H:i:s');
            @file_put_contents($log_path, "[{$timestamp}] {$line}\n", FILE_APPEND | LOCK_EX);
        }
    }
}


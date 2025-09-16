<?php

declare(strict_types=1);

namespace EVDataBridge\Sources;

use stdClass;

/**
 * Source Registry - manages data sources and import files
 */
class Source_Registry {
    
    /**
     * Get all sources
     */
    public function get_all_sources(): array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_sources';
        $results = $wpdb->get_results("SELECT * FROM $table ORDER BY country_code, adapter_key");
        
        return array_map([$this, 'cast_to_object'], $results);
    }
    
    /**
     * Get enabled sources only
     */
    public function get_enabled_sources(): array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_sources';
        $results = $wpdb->get_results("SELECT * FROM $table WHERE enabled = 1 ORDER BY country_code, adapter_key");
        
        return array_map([$this, 'cast_to_object'], $results);
    }
    
    /**
     * Get source by ID, country code, or adapter key
     */
    public function get_source(string|int $identifier): ?stdClass {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_sources';
        
        if (is_numeric($identifier)) {
            $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $identifier));
        } else {
            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE country_code = %s OR adapter_key = %s",
                $identifier,
                $identifier
            ));
        }
        
        if (!$result) {
            return null;
        }
        
        return $this->cast_to_object($result);
    }
    
    /**
     * Update source probe result
     */
    public function update_source_probe_result(int $source_id, ?string $version, ?string $last_modified, ?string $etag): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_sources';
        
        $data = [
            'last_version_label' => $version,
            'updated_at' => current_time('mysql'),
        ];
        
        if ($last_modified) {
            $data['last_success_at'] = current_time('mysql');
        }
        
        $result = $wpdb->update(
            $table,
            $data,
            ['id' => $source_id],
            ['%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Update source success
     */
    public function update_source_success(int $source_id): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_sources';
        
        $result = $wpdb->update(
            $table,
            [
                'last_success_at' => current_time('mysql'),
                'last_error_at' => null,
                'last_error_message' => null,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $source_id],
            ['%s', null, null, '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Update source error
     */
    public function update_source_error(int $source_id, string $error_message): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_sources';
        
        $result = $wpdb->update(
            $table,
            [
                'last_error_at' => current_time('mysql'),
                'last_error_message' => $error_message,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $source_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Log import file
     */
    public function log_import_file(int $source_id, string $source_url, string $file_path, int $file_size, string $sha256, ?string $content_type = null, ?string $etag = null, ?string $last_modified = null): int {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_import_files';
        
        $result = $wpdb->insert(
            $table,
            [
                'source_id' => $source_id,
                'source_url' => $source_url,
                'file_path' => $file_path,
                'file_size' => $file_size,
                'file_sha256' => $sha256,
                'content_type' => $content_type,
                'etag' => $etag,
                'last_modified' => $last_modified,
                'status' => 'completed',
                'download_completed_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            throw new \RuntimeException('Failed to log import file: ' . $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get import file by SHA256
     */
    public function get_import_file_by_sha256(string $sha256): ?stdClass {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_import_files';
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE file_sha256 = %s", $sha256));
        
        if (!$result) {
            return null;
        }
        
        return $this->cast_to_object($result);
    }
    
    /**
     * Get import files for source
     */
    public function get_import_files_for_source(int $source_id, int $limit = 10): array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_import_files';
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE source_id = %d ORDER BY created_at DESC LIMIT %d",
            $source_id,
            $limit
        ));
        
        return array_map([$this, 'cast_to_object'], $results);
    }
    
    /**
     * Cast database result to object with proper types
     */
    private function cast_to_object($result): stdClass {
        $obj = new stdClass();
        
        foreach ($result as $key => $value) {
            if (is_numeric($value) && in_array($key, ['id', 'source_id', 'file_size'])) {
                $obj->$key = (int) $value;
            } elseif (in_array($key, ['enabled'])) {
                $obj->$key = (bool) $value;
            } else {
                $obj->$key = $value;
            }
        }
        
        return $obj;
    }
}

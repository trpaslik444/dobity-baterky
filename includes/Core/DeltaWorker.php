<?php

declare(strict_types=1);

namespace EVDataBridge\Core;

use EVDataBridge\Sources\Source_Registry;

/**
 * Delta detection and queue management worker
 */
class DeltaWorker {
    
    private Source_Registry $source_registry;
    
    public function __construct(Source_Registry $source_registry) {
        $this->source_registry = $source_registry;
    }
    
    /**
     * Build delta index from NDJSON file
     */
    public function buildDelta(string $filePath, string $source, string $version, array $opts = []): array {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: $filePath");
        }
        
        $limit = $opts['limit'] ?? null;
        $stats = [
            'rows_processed' => 0,
            'new' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'errors' => 0
        ];
        
        try {
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                throw new \RuntimeException("Cannot open file: $filePath");
            }
            
            $rowIndex = 0;
            while (($line = fgets($handle)) !== false) {
                if ($limit && $rowIndex >= $limit) {
                    break;
                }
                
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                
                $station = json_decode($line, true);
                if (!$station) {
                    $stats['errors']++;
                    continue;
                }
                
                $this->processStationDelta($station, $source, $version, $stats);
                $stats['rows_processed']++;
                $rowIndex++;
            }
            
            fclose($handle);
            
            return $stats;
            
        } catch (\Exception $e) {
            throw new \RuntimeException('Delta build failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Process single station for delta detection
     */
    private function processStationDelta(array $station, string $source, string $version, array &$stats): void {
        $uniqKey = $station['uniq_key'] ?? '';
        $rowHash = $station['row_hash'] ?? '';
        
        if (empty($uniqKey) || empty($rowHash)) {
            $stats['errors']++;
            return;
        }
        
        // Check existing index
        $existing = $this->getExistingIndex($uniqKey, $source);
        
        if (!$existing) {
            // New station
            $this->enqueueDelta($uniqKey, $source, $version, 'NEW', $station);
            $this->updateIndex($uniqKey, $source, $version, $rowHash);
            $stats['new']++;
            
        } elseif ($existing->row_hash !== $rowHash) {
            // Changed station
            $this->enqueueDelta($uniqKey, $source, $version, 'CHANGED', $station);
            $this->updateIndex($uniqKey, $source, $version, $rowHash);
            $stats['changed']++;
            
        } else {
            // Unchanged station
            $this->updateIndexLastSeen($uniqKey, $source);
            $stats['unchanged']++;
        }
    }
    
    /**
     * Get existing index entry
     */
    private function getExistingIndex(string $uniqKey, string $source): ?object {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_import_index';
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE uniq_key = %s AND source = %s",
            $uniqKey, $source
        ));
        
        return $result ?: null;
    }
    
    /**
     * Update or create index entry
     */
    private function updateIndex(string $uniqKey, string $source, string $version, string $rowHash): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_import_index';
        
        $data = [
            'uniq_key' => $uniqKey,
            'source' => $source,
            'source_version' => $version,
            'row_hash' => $rowHash,
            'last_seen_at' => current_time('mysql')
        ];
        
        $wpdb->replace($table, $data);
    }
    
    /**
     * Update last seen timestamp
     */
    private function updateIndexLastSeen(string $uniqKey, string $source): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_import_index';
        
        $wpdb->update(
            $table,
            ['last_seen_at' => current_time('mysql')],
            ['uniq_key' => $uniqKey, 'source' => $source]
        );
    }
    
    /**
     * Enqueue delta action
     */
    private function enqueueDelta(string $uniqKey, string $source, string $version, string $action, array $payload): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_delta_queue';
        
        // Check if already queued
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE uniq_key = %s AND source = %s AND status IN ('PENDING', 'PROCESSING')",
            $uniqKey, $source
        ));
        
        if ($existing) {
            // Update existing queue entry
            $wpdb->update(
                $table,
                [
                    'source_version' => $version,
                    'action' => $action,
                    'payload' => json_encode($payload),
                    'status' => 'PENDING',
                    'attempts' => 0,
                    'error_message' => null,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $existing->id]
            );
        } else {
            // Create new queue entry
            $wpdb->insert($table, [
                'uniq_key' => $uniqKey,
                'source' => $source,
                'source_version' => $version,
                'action' => $action,
                'payload' => json_encode($payload),
                'status' => 'PENDING',
                'created_at' => current_time('mysql')
            ]);
        }
    }
    
    /**
     * Get pending delta queue items
     */
    public function getPendingDeltas(int $limit = 1000, ?string $source = null): array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_delta_queue';
        
        $where = "status = 'PENDING'";
        $params = [];
        
        if ($source) {
            $where .= " AND source = %s";
            $params[] = $source;
        }
        
        $sql = "SELECT * FROM $table WHERE $where ORDER BY created_at ASC LIMIT %d";
        $params[] = $limit;
        
        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        
        return array_map([$this, 'castToObject'], $results);
    }
    
    /**
     * Mark delta as processing
     */
    public function markProcessing(int $id): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_delta_queue';
        
        $result = $wpdb->update(
            $table,
            [
                'status' => 'PROCESSING',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $id, 'status' => 'PENDING']
        );
        
        return $result !== false;
    }
    
    /**
     * Mark delta as completed
     */
    public function markCompleted(int $id): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_delta_queue';
        
        $result = $wpdb->update(
            $table,
            [
                'status' => 'DONE',
                'processed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $id]
        );
        
        return $result !== false;
    }
    
    /**
     * Mark delta as failed
     */
    public function markFailed(int $id, string $errorMessage): bool {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_delta_queue';
        
        $result = $wpdb->update(
            $table,
            [
                'status' => 'FAILED',
                'error_message' => $errorMessage,
                'attempts' => $wpdb->prepare('attempts + 1'),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $id]
        );
        
        return $result !== false;
    }
    
    /**
     * Cast database result to object
     */
    private function castToObject($result): object {
        if (is_object($result)) {
            return $result;
        }
        
        return (object) $result;
    }
}

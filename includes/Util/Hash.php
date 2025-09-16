<?php

declare(strict_types=1);

namespace EVDataBridge\Util;

/**
 * Hash generation utilities for data integrity
 */
class Hash {
    
    /**
     * Generate SHA256 hash of canonical station JSON
     */
    public static function rowHash(array $station): string {
        // Remove volatile fields for consistent hashing
        $canonical = $station;
        unset($canonical['generated_at']);
        
        // Sort keys for consistent ordering
        ksort($canonical);
        
        // Generate JSON with consistent formatting
        $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        
        if ($json === false) {
            throw new \RuntimeException('Failed to encode station data for hashing');
        }
        
        return hash('sha256', $json);
    }
    
    /**
     * Generate MD5 signature for connector identification
     */
    public static function connectorSig(array $connector): string {
        $fields = [
            $connector['charge_type'] ?? '',
            $connector['connector_standard'] ?? '',
            $connector['connector_power_kw'] ?? '',
            $connector['connection_method'] ?? '',
            $connector['excl_group'] ?? '',
            $connector['connector_index'] ?? ''
        ];
        
        $signature = implode('|', $fields);
        return substr(md5($signature), 0, 16);
    }
    
    /**
     * Generate connector UID from station and connector data
     */
    public static function connectorUid(string $uniqKey, array $connector, int $index): string {
        $signature = self::connectorSig($connector);
        $data = $uniqKey . '|' . $signature . '|' . $index;
        
        return substr(md5($data), 0, 16);
    }
}

<?php

declare(strict_types=1);

namespace EVDataBridge\Core;

/**
 * Safe upsert operations for stations and connectors
 */
class Upserter {
    
    /**
     * Upsert station with connectors
     */
    public function upsertStation(array $station): bool {
        global $wpdb;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Upsert station
            $stationId = $this->upsertStationRecord($station);
            
            if (!$stationId) {
                throw new \RuntimeException('Failed to upsert station');
            }
            
            // Upsert connectors
            $this->upsertConnectors($stationId, $station['connectors'] ?? []);
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            return true;
            
        } catch (\Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
    
    /**
     * Upsert station record (only source fields)
     */
    private function upsertStationRecord(array $station): int {
        global $wpdb;
        
        // Check if station exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}charging_locations WHERE uniq_key = %s",
            $station['uniq_key']
        ));
        
        $data = [
            'uniq_key' => $station['uniq_key'],
            'operator_original' => $station['operator_original'] ?? null,
            'op_norm' => $station['op_norm'] ?? null,
            'op_key' => $station['op_key'],
            'country_code' => $station['country_code'],
            'lat' => $station['lat'],
            'lon' => $station['lon'],
            'lat_5dp' => $station['lat_5dp'],
            'lon_5dp' => $station['lon_5dp'],
            'street' => $station['street'] ?? null,
            'city' => $station['city'] ?? null,
            'psc' => $station['psc'] ?? null,
            'opening_hours' => $station['opening_hours'] ?? null,
            'access' => $station['access'] ?? null,
            'payment' => json_encode($station['payment'] ?? []),
            'cpo_id' => $station['cpo_id'] ?? null,
            'station_max_power_kw' => $station['station_max_power_kw'] ?? null,
            'evse_count' => $station['evse_count'],
            'source' => $station['source'],
            'source_dataset' => $station['source_dataset'] ?? null,
            'source_url' => $station['source_url'] ?? null,
            'source_as_of_date' => $station['source_as_of_date'] ?? null,
            'license' => $station['license'] ?? null,
            'license_url' => $station['license_url'] ?? null,
            'is_active' => 1,
            'updated_at' => current_time('mysql')
        ];
        
        if ($existing) {
            // Update existing station
            $result = $wpdb->update(
                $wpdb->prefix . 'charging_locations',
                $data,
                ['id' => $existing->id]
            );
            
            if ($result === false) {
                throw new \RuntimeException('Failed to update station: ' . $wpdb->last_error);
            }
            
            return $existing->id;
            
        } else {
            // Insert new station
            $data['created_at'] = current_time('mysql');
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'charging_locations',
                $data
            );
            
            if ($result === false) {
                throw new \RuntimeException('Failed to insert station: ' . $wpdb->last_error);
            }
            
            return $wpdb->insert_id;
        }
    }
    
    /**
     * Upsert connectors with replace-by-signature logic
     */
    private function upsertConnectors(int $stationId, array $connectors): void {
        if (empty($connectors)) {
            return;
        }
        
        // Get existing connectors for this station
        $existingConnectors = $this->getExistingConnectors($stationId);
        
        // Process each connector
        foreach ($connectors as $connector) {
            $this->upsertConnector($stationId, $connector, $existingConnectors);
        }
        
        // Deactivate connectors that are no longer present
        $this->deactivateRemovedConnectors($stationId, $connectors, $existingConnectors);
    }
    
    /**
     * Get existing connectors for station
     */
    private function getExistingConnectors(int $stationId): array {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}connectors WHERE charging_location_id = %d AND is_active = 1",
            $stationId
        ));
        
        $connectors = [];
        foreach ($results as $result) {
            $signature = $this->generateConnectorSignature($result);
            $connectors[$signature] = $result;
        }
        
        return $connectors;
    }
    
    /**
     * Upsert single connector
     */
    private function upsertConnector(int $stationId, array $connector, array &$existingConnectors): void {
        $signature = $this->generateConnectorSignature($connector);
        
        if (isset($existingConnectors[$signature])) {
            // Connector exists and is identical - mark as processed
            unset($existingConnectors[$signature]);
            return;
        }
        
        // Insert new connector
        $data = [
            'charging_location_id' => $stationId,
            'connector_uid' => $connector['connector_uid'],
            'connector_index' => $connector['connector_index'],
            'charge_type' => $connector['charge_type'] ?? null,
            'connector_standard' => $connector['connector_standard'],
            'connector_power_kw' => $connector['connector_power_kw'] ?? null,
            'connection_method' => $connector['connection_method'] ?? null,
            'excl_group' => $connector['excl_group'] ?? null,
            'evse_uid' => $connector['evse_uid'] ?? null,
            'source' => $connector['source'],
            'source_as_of_date' => $connector['source_as_of_date'] ?? null,
            'is_active' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'connectors',
            $data
        );
        
        if ($result === false) {
            throw new \RuntimeException('Failed to insert connector: ' . $wpdb->last_error);
        }
    }
    
    /**
     * Deactivate connectors that are no longer present
     */
    private function deactivateRemovedConnectors(int $stationId, array $newConnectors, array $existingConnectors): void {
        if (empty($existingConnectors)) {
            return;
        }
        
        // Deactivate remaining existing connectors
        $connectorIds = array_column($existingConnectors, 'id');
        
        if (!empty($connectorIds)) {
            $placeholders = implode(',', array_fill(0, count($connectorIds), '%d'));
            
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}connectors SET is_active = 0, updated_at = %s WHERE id IN ($placeholders)",
                current_time('mysql'),
                ...$connectorIds
            ));
            
            if ($result === false) {
                throw new \RuntimeException('Failed to deactivate connectors: ' . $wpdb->last_error);
            }
        }
    }
    
    /**
     * Generate connector signature for comparison
     */
    private function generateConnectorSignature($connector): string {
        $fields = [
            $connector['charge_type'] ?? '',
            $connector['connector_standard'] ?? '',
            $connector['connector_power_kw'] ?? '',
            $connector['connection_method'] ?? '',
            $connector['excl_group'] ?? ''
        ];
        
        return md5(implode('|', $fields));
    }
    
    /**
     * Check if required tables exist
     */
    public function checkTables(): bool {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'charging_locations',
            $wpdb->prefix . 'connectors'
        ];
        
        foreach ($tables as $table) {
            $result = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if (!$result) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Add missing columns if needed
     */
    public function ensureColumns(): void {
        global $wpdb;
        
        // Check and add is_active column to charging_locations
        $this->addColumnIfMissing(
            $wpdb->prefix . 'charging_locations',
            'is_active',
            'TINYINT(1) DEFAULT 1'
        );
        
        // Check and add source columns to charging_locations
        $this->addColumnIfMissing(
            $wpdb->prefix . 'charging_locations',
            'source',
            'VARCHAR(50) DEFAULT NULL'
        );
        
        $this->addColumnIfMissing(
            $wpdb->prefix . 'charging_locations',
            'source_dataset',
            'VARCHAR(255) DEFAULT NULL'
        );
        
        $this->addColumnIfMissing(
            $wpdb->prefix . 'charging_locations',
            'source_url',
            'TEXT DEFAULT NULL'
        );
        
        $this->addColumnIfMissing(
            $wpdb->prefix . 'charging_locations',
            'source_as_of_date',
            'DATE DEFAULT NULL'
        );
        
        $this->addColumnIfMissing(
            $wpdb->prefix . 'charging_locations',
            'license',
            'VARCHAR(255) DEFAULT NULL'
        );
        
        $this->addColumnIfMissing(
            $wpdb->prefix . 'charging_locations',
            'license_url',
            'TEXT DEFAULT NULL'
        );
        
        // Check and add is_active column to connectors
        $this->addColumnIfMissing(
            $wpdb->prefix . 'connectors',
            'is_active',
            'TINYINT(1) DEFAULT 1'
        );
        
        // Check and add source columns to connectors
        $this->addColumnIfMissing(
            $wpdb->prefix . 'connectors',
            'source',
            'VARCHAR(50) DEFAULT NULL'
        );
        
        $this->addColumnIfMissing(
            $wpdb->prefix . 'connectors',
            'source_as_of_date',
            'DATE DEFAULT NULL'
        );
    }
    
    /**
     * Add column if it doesn't exist
     */
    private function addColumnIfMissing(string $table, string $column, string $definition): void {
        global $wpdb;
        
        $result = $wpdb->get_results("SHOW COLUMNS FROM `$table` LIKE '$column'");
        
        if (empty($result)) {
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }
}

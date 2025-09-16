<?php

declare(strict_types=1);

namespace EVDataBridge\CLI;

use EVDataBridge\Core\HTTP_Helper;
use EVDataBridge\Core\DeltaWorker;
use EVDataBridge\Core\Upserter;
use EVDataBridge\Sources\Source_Registry;
use EVDataBridge\Sources\Adapters\CZ_MPO_Adapter;
use EVDataBridge\Sources\Adapters\DE_BNetzA_Adapter;
use EVDataBridge\Sources\Adapters\FR_IRVE_Adapter;
use EVDataBridge\Sources\Adapters\ES_ArcGIS_Adapter;
use EVDataBridge\Sources\Adapters\AT_EControl_Adapter;

if (!defined('WP_CLI')) {
    return;
}

/**
 * EV Data Bridge WP-CLI commands
 */
class EV_Bridge_Command {
    
    private Source_Registry $source_registry;
    private HTTP_Helper $http_helper;
    
    public function __construct() {
        $this->source_registry = new Source_Registry();
        $this->http_helper = new HTTP_Helper();
    }
    
    /**
     * Probe a data source to check for updates
     *
     * ## OPTIONS
     *
     * --source=<source>
     * : Source to probe (country code or adapter key)
     *
     * --all
     * : Probe all enabled sources
     *
     * ## EXAMPLES
     *
     *     wp ev-bridge probe --source=DE
     *     wp ev-bridge probe --source=cz_mpo
     *     wp ev-bridge probe --all
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function probe($args, $assoc_args): void {
        if (isset($assoc_args['all'])) {
            $this->probe_all_sources();
            return;
        }
        
        if (!isset($assoc_args['source'])) {
            \WP_CLI::error('Please specify --source=<source> or use --all');
            return;
        }
        
        $source = $assoc_args['source'];
        $this->probe_source($source);
    }
    
    /**
     * Fetch data from a source
     *
     * ## OPTIONS
     *
     * --source=<source>
     * : Source to fetch from (country code or adapter key)
     *
     * --all
     * : Fetch from all enabled sources
     *
     * ## EXAMPLES
     *
     *     wp ev-bridge fetch --source=DE
     *     wp ev-bridge fetch --source=cz_mpo
     *     wp ev-bridge fetch --all
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function fetch($args, $assoc_args): void {
        if (isset($assoc_args['all'])) {
            $this->fetch_all_sources();
            return;
        }
        
        if (!isset($assoc_args['source'])) {
            \WP_CLI::error('Please specify --source=<source> or use --all');
            return;
        }
        
        $source = $assoc_args['source'];
        $this->fetch_source($source);
    }
    
    /**
     * Transform data from a source to canonical JSON format
     *
     * ## OPTIONS
     *
     * --source=<source>
     * : Source to transform (country code or adapter key)
     *
     * --file=<path>
     * : Direct file path to transform
     *
     * --out=<directory>
     * : Custom output directory
     *
     * --limit=<number>
     * : Limit processing to first N rows (for testing)
     *
     * ## EXAMPLES
     *
     *     wp ev-bridge transform --source=DE
     *     wp ev-bridge transform --source=CZ
     *     wp ev-bridge transform --source=DE --file=/path/to/file.csv
     *     wp ev-bridge transform --source=CZ --limit=100
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function transform($args, $assoc_args): void {
        if (!isset($assoc_args['source'])) {
            \WP_CLI::error('Please specify --source=<source>');
            return;
        }
        
        $source = $assoc_args['source'];
        
        // Automaticky najdi poslední stažený soubor pokud --file není
        $filePath = $assoc_args['file'] ?? $this->find_latest_source_file($source);
        
        // Automaticky nastav staging adresář pokud --out není
        $outputDir = $assoc_args['out'] ?? $this->get_default_staging_dir($source);
        
        // Limit je volitelný (null = bez limitu)
        $limit = isset($assoc_args['limit']) ? (int)$assoc_args['limit'] : null;
        
        $this->transform_source($source, $filePath, $outputDir, $limit);
    }
    
    /**
     * List all available sources
     *
     * ## EXAMPLES
     *
     *     wp ev-bridge list
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function list($args, $assoc_args): void {
        $sources = $this->source_registry->get_all_sources();
        
        if (empty($sources)) {
            \WP_CLI::warning('No sources found');
            return;
        }
        
        \WP_CLI::log('Available sources:');
        \WP_CLI::log('');
        
        foreach ($sources as $source) {
            $status = $source->enabled ? '✓' : '✗';
            $last_success = $source->last_success_at ?? 'Never';
            $last_version = $source->last_version_label ?? 'N/A';
            
            \WP_CLI::log(sprintf(
                '%s %s (%s) - %s - Last: %s - Version: %s',
                $status,
                $source->country_code,
                $source->adapter_key,
                strtoupper($source->fetch_type),
                $last_success,
                $last_version
            ));
        }
    }
    
    private function probe_source(string $source): void {
        $source_info = $this->source_registry->get_source($source);
        
        if (!$source_info) {
            \WP_CLI::error("Source '$source' not found");
            return;
        }
        
        if (!$source_info->enabled) {
            \WP_CLI::warning("Source '$source' is disabled");
            return;
        }
        
        \WP_CLI::log("Probing source: {$source_info->country_code} ({$source_info->adapter_key})");
        
        try {
            $adapter = $this->get_adapter($source_info->adapter_key);
            $result = $adapter->probe();
            
            \WP_CLI::success("Probe successful");
            \WP_CLI::log("Version: " . ($result['version'] ?? 'N/A'));
            \WP_CLI::log("Last Modified: " . ($result['last_modified'] ?? 'N/A'));
            \WP_CLI::log("ETag: " . ($result['etag'] ?? 'N/A'));
            \WP_CLI::log("URL: " . ($result['url'] ?? 'N/A'));
            
            // Update source info
            $this->source_registry->update_source_probe_result(
                $source_info->id,
                $result['version'] ?? null,
                $result['last_modified'] ?? null,
                $result['etag'] ?? null
            );
            
        } catch (\Exception $e) {
            \WP_CLI::error("Probe failed: " . $e->getMessage());
            
            // Update error info
            $this->source_registry->update_source_error(
                $source_info->id,
                $e->getMessage()
            );
        }
    }
    
    private function probe_all_sources(): void {
        $sources = $this->source_registry->get_enabled_sources();
        
        if (empty($sources)) {
            \WP_CLI::warning('No enabled sources found');
            return;
        }
        
        \WP_CLI::log("Probing " . count($sources) . " enabled sources...");
        
        $success_count = 0;
        $error_count = 0;
        
        foreach ($sources as $source) {
            try {
                \WP_CLI::log("Probing {$source->country_code} ({$source->adapter_key})...");
                
                $adapter = $this->get_adapter($source->adapter_key);
                $result = $adapter->probe();
                
                $this->source_registry->update_source_probe_result(
                    $source->id,
                    $result['version'] ?? null,
                    $result['last_modified'] ?? null,
                    $result['etag'] ?? null
                );
                
                $success_count++;
                \WP_CLI::log("✓ Success");
                
            } catch (\Exception $e) {
                $error_count++;
                \WP_CLI::log("✗ Failed: " . $e->getMessage());
                
                $this->source_registry->update_source_error(
                    $source->id,
                    $e->getMessage()
                );
            }
        }
        
        \WP_CLI::success("Probe completed: {$success_count} success, {$error_count} errors");
    }
    
    private function fetch_source(string $source): void {
        $source_info = $this->source_registry->get_source($source);
        
        if (!$source_info) {
            \WP_CLI::error("Source '$source' not found");
            return;
        }
        
        if (!$source_info->enabled) {
            \WP_CLI::warning("Source '$source' is disabled");
            return;
        }
        
        \WP_CLI::log("Fetching from source: {$source_info->country_code} ({$source_info->adapter_key})");
        
        try {
            $adapter = $this->get_adapter($source_info->adapter_key);
            $result = $adapter->fetch();
            
            \WP_CLI::success("Fetch successful");
            \WP_CLI::log("File: " . $result['file_path']);
            \WP_CLI::log("Size: " . $result['file_size'] . " bytes");
            \WP_CLI::log("SHA256: " . $result['sha256']);
            
            // Update source success
            $this->source_registry->update_source_success($source_info->id);
            
        } catch (\Exception $e) {
            \WP_CLI::error("Fetch failed: " . $e->getMessage());
            
            // Update error info
            $this->source_registry->update_source_error(
                $source_info->id,
                $e->getMessage()
            );
        }
    }
    
    private function fetch_all_sources(): void {
        $sources = $this->source_registry->get_enabled_sources();
        
        if (empty($sources)) {
            \WP_CLI::warning('No enabled sources found');
            return;
        }
        
        \WP_CLI::log("Fetching from " . count($sources) . " enabled sources...");
        
        $success_count = 0;
        $error_count = 0;
        
        foreach ($sources as $source) {
            try {
                \WP_CLI::log("Fetching from {$source->country_code} ({$source->adapter_key})...");
                
                $adapter = $this->get_adapter($source->adapter_key);
                $result = $adapter->fetch();
                
                $this->source_registry->update_source_success($source->id);
                
                $success_count++;
                \WP_CLI::log("✓ Success: " . $result['file_path']);
                
            } catch (\Exception $e) {
                $error_count++;
                \WP_CLI::log("✗ Failed: " . $e->getMessage());
                
                $this->source_registry->update_source_error(
                    $source->id,
                    $e->getMessage()
                );
            }
        }
        
        \WP_CLI::success("Fetch completed: {$success_count} success, {$error_count} errors");
    }
    
    private function get_adapter(string $adapter_key): object {
        return match ($adapter_key) {
            'cz_mpo' => new CZ_MPO_Adapter($this->http_helper),
            'de_bnetza' => new DE_BNetzA_Adapter($this->http_helper),
            'fr_irve' => new FR_IRVE_Adapter($this->http_helper),
            'es_arcgis' => new ES_ArcGIS_Adapter($this->http_helper),
            'at_econtrol' => new AT_EControl_Adapter($this->http_helper),
            default => throw new \InvalidArgumentException("Unknown adapter: $adapter_key")
        };
    }
    
    /**
     * Build delta index from transformed data
     *
     * ## OPTIONS
     *
     * --source=<source>
     * : Source to build delta for (country code or adapter key)
     *
     * --file=<path>
     * : Direct path to NDJSON file
     *
     * --version=<date>
     * : Source version (YYYY-MM-DD format)
     *
     * ## EXAMPLES
     *
     *     wp ev-bridge build-delta --source=DE
     *     wp ev-bridge build-delta --source=CZ --version=2025-01-15
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function build_delta($args, $assoc_args): void {
        if (!isset($assoc_args['source'])) {
            \WP_CLI::error('Please specify --source=<source>');
            return;
        }
        
        $source = $assoc_args['source'];
        $filePath = $assoc_args['file'] ?? null;
        $version = $assoc_args['version'] ?? date('Y-m-d');
        
        $this->build_delta_source($source, $filePath, $version);
    }
    
    /**
     * Apply delta queue to main plugin tables
     *
     * ## OPTIONS
     *
     * --limit=<number>
     * : Process up to N items (default: 1000)
     *
     * --source=<source>
     * : Process only specific source
     *
     * ## EXAMPLES
     *
     *     wp ev-bridge apply-delta
     *     wp ev-bridge apply-delta --limit=500
     *     wp ev-bridge apply-delta --source=DE
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function apply_delta($args, $assoc_args): void {
        $limit = isset($assoc_args['limit']) ? (int)$assoc_args['limit'] : 1000;
        $source = $assoc_args['source'] ?? null;
        
        $this->apply_delta_queue($limit, $source);
    }
    
    /**
     * Transform source data to canonical format
     */
    private function transform_source(string $source, ?string $filePath, ?string $outputDir, ?int $limit): void {
        $source_info = $this->source_registry->get_source($source);
        
        if (!$source_info) {
            \WP_CLI::error("Source '$source' not found");
            return;
        }
        
        if (!$source_info->enabled) {
            \WP_CLI::warning("Source '$source' is disabled");
            return;
        }
        
        \WP_CLI::log("Transforming source: {$source_info->country_code} ({$source_info->adapter_key})");
        
        try {
            // Get adapter
            $adapter = $this->get_adapter($source_info->adapter_key);
            
            // Determine file path
            if (!$filePath) {
                $filePath = $this->get_latest_file_path($source_info->id);
                if (!$filePath) {
                    \WP_CLI::error("No files found for source '$source'. Run fetch first.");
                    return;
                }
            }
            
            // Transform options
            $opts = [];
            if ($limit) {
                $opts['limit'] = $limit;
            }
            if ($outputDir) {
                $opts['out'] = $outputDir;
            }
            
            // Run transform
            $result = $adapter->transform($filePath, $opts);
            
            // Validate output
            $this->validate_transform_output($result);
            
            // Display results
            \WP_CLI::success("Transform completed successfully");
            \WP_CLI::log("Staging directory: " . $result['staging_dir']);
            \WP_CLI::log("Stations processed: " . $result['stations_count']);
            \WP_CLI::log("Summary: " . json_encode($result['summary'], JSON_PRETTY_PRINT));
            
        } catch (\Exception $e) {
            \WP_CLI::error("Transform failed: " . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Get latest file path for source
     */
    private function get_latest_file_path(int $sourceId): ?string {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_import_files';
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT file_path FROM $table WHERE source_id = %d AND status = 'completed' ORDER BY download_completed_at DESC LIMIT 1",
            $sourceId
        ));
        
        return $result ? $result->file_path : null;
    }
    
    /**
     * Validate transform output against schema
     */
    private function validate_transform_output(array $result): void {
        $schemaFile = EV_DATA_BRIDGE_PLUGIN_DIR . 'docs/schema/station.schema.json';
        
        if (!file_exists($schemaFile)) {
            \WP_CLI::warning("Schema file not found, skipping validation");
            return;
        }
        
        $schema = json_decode(file_get_contents($schemaFile), true);
        if (!$schema) {
            \WP_CLI::warning("Invalid schema file, skipping validation");
            return;
        }
        
        // Basic validation (simplified)
        $errors = [];
        $requiredFields = ['uniq_key', 'op_key', 'lat_5dp', 'lon_5dp', 'evse_count', 'connectors', 'source', 'row_hash', 'generated_at', 'country_code'];
        
        if (isset($result['summary']['stations_out']) && $result['summary']['stations_out'] > 0) {
            // Validate first station as sample
            $sampleFile = $result['staging_dir'] . '/' . $result['summary']['adapter'] . '_stations.json';
            if (file_exists($sampleFile)) {
                $stations = json_decode(file_get_contents($sampleFile), true);
                if ($stations && count($stations) > 0) {
                    $firstStation = reset($stations);
                    
                    foreach ($requiredFields as $field) {
                        if (!isset($firstStation[$field])) {
                            $errors[] = "Missing required field: $field";
                        }
                    }
                    
                    if (count($errors) > 0) {
                        \WP_CLI::error("Schema validation failed:");
                        foreach (array_slice($errors, 0, 3) as $error) {
                            \WP_CLI::error("  - $error");
                        }
                        exit(1);
                    }
                }
            }
        }
        
        \WP_CLI::log("Schema validation passed");
    }
    
    /**
     * Build delta index for source
     */
    private function build_delta_source(string $source, ?string $filePath, string $version): void {
        $source_info = $this->source_registry->get_source($source);
        
        if (!$source_info) {
            \WP_CLI::error("Source '$source' not found");
            return;
        }
        
        if (!$source_info->enabled) {
            \WP_CLI::warning("Source '$source' is disabled");
            return;
        }
        
        \WP_CLI::log("Building delta index for source: {$source_info->country_code} ({$source_info->adapter_key})");
        \WP_CLI::log("Version: $version");
        
        try {
            // Determine file path
            if (!$filePath) {
                $filePath = $this->get_latest_ndjson_path($source_info->adapter_key);
                if (!$filePath) {
                    \WP_CLI::error("No NDJSON files found for source '$source'. Run transform first.");
                    return;
                }
            }
            
            \WP_CLI::log("Processing file: $filePath");
            
            // Initialize delta worker
            $delta_worker = new DeltaWorker($this->source_registry);
            
            // Build delta
            $stats = $delta_worker->buildDelta($filePath, $source_info->adapter_key, $version);
            
            // Display results
            \WP_CLI::success("Delta build completed successfully");
            \WP_CLI::log("Rows processed: " . $stats['rows_processed']);
            \WP_CLI::log("New stations: " . $stats['new']);
            \WP_CLI::log("Changed stations: " . $stats['changed']);
            \WP_CLI::log("Unchanged stations: " . $stats['unchanged']);
            \WP_CLI::log("Errors: " . $stats['errors']);
            
            if ($stats['new'] > 0 || $stats['changed'] > 0) {
                \WP_CLI::log("Delta queue populated with " . ($stats['new'] + $stats['changed']) . " items");
                \WP_CLI::log("Run 'wp ev-bridge apply-delta' to process the queue");
            }
            
        } catch (\Exception $e) {
            \WP_CLI::error("Delta build failed: " . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Apply delta queue to main tables
     */
    private function apply_delta_queue(int $limit, ?string $source): void {
        try {
            // Check if main plugin tables exist
            $upserter = new Upserter();
            
            if (!$upserter->checkTables()) {
                \WP_CLI::error("Main plugin tables not found. Please ensure the main plugin is active.");
                return;
            }
            
            // Ensure required columns exist
            $upserter->ensureColumns();
            
            // Initialize delta worker
            $delta_worker = new DeltaWorker($this->source_registry);
            
            // Get pending deltas
            $pending = $delta_worker->getPendingDeltas($limit, $source);
            
            if (empty($pending)) {
                \WP_CLI::success("No pending deltas to process");
                return;
            }
            
            \WP_CLI::log("Processing " . count($pending) . " pending deltas");
            
            $stats = [
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];
            
            foreach ($pending as $delta) {
                try {
                    // Mark as processing
                    $delta_worker->markProcessing($delta->id);
                    
                    // Decode payload
                    $station = json_decode($delta->payload, true);
                    if (!$station) {
                        throw new \RuntimeException("Invalid payload format");
                    }
                    
                    // Upsert station
                    $result = $upserter->upsertStation($station);
                    
                    if ($result) {
                        $delta_worker->markCompleted($delta->id);
                        $stats['success']++;
                    } else {
                        throw new \RuntimeException("Upsert failed");
                    }
                    
                    $stats['processed']++;
                    
                } catch (\Exception $e) {
                    $error_msg = $e->getMessage();
                    $delta_worker->markFailed($delta->id, $error_msg);
                    $stats['failed']++;
                    $stats['errors'][] = "Delta ID {$delta->id}: $error_msg";
                    
                    \WP_CLI::warning("Failed to process delta ID {$delta->id}: $error_msg");
                }
            }
            
            // Display results
            \WP_CLI::success("Delta processing completed");
            \WP_CLI::log("Processed: " . $stats['processed']);
            \WP_CLI::log("Success: " . $stats['success']);
            \WP_CLI::log("Failed: " . $stats['failed']);
            
            if (!empty($stats['errors'])) {
                \WP_CLI::warning("Errors encountered:");
                foreach (array_slice($stats['errors'], 0, 5) as $error) {
                    \WP_CLI::warning("  - $error");
                }
                if (count($stats['errors']) > 5) {
                    \WP_CLI::warning("  ... and " . (count($stats['errors']) - 5) . " more errors");
                }
            }
            
        } catch (\Exception $e) {
            \WP_CLI::error("Delta processing failed: " . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Find latest source file for transform
     */
    private function find_latest_source_file(string $source): ?string {
        // Nejdříve zkus najít v wp_ev_import_files
        $source_info = $this->source_registry->get_source($source);
        if ($source_info) {
            $filePath = $this->get_latest_file_path($source_info->id);
            if ($filePath && file_exists($filePath)) {
                return $filePath;
            }
        }
        
        // Fallback: hledej v uploads adresáři
        $uploadDir = wp_upload_dir();
        $evBridgeDir = $uploadDir['basedir'] . '/ev-bridge';
        
        if (!is_dir($evBridgeDir)) {
            return null;
        }
        
        // Najdi poslední stažený soubor podle source
        $sourceKey = $source_info ? $source_info->adapter_key : strtolower($source);
        
        $patterns = [
            'cz' => 'cz_mpo*.xlsx',
            'de' => 'de_bnetza*.csv',
            'cz_mpo' => 'cz_mpo*.xlsx',
            'de_bnetza' => 'de_bnetza*.csv'
        ];
        
        $pattern = $patterns[$sourceKey] ?? $sourceKey . '*';
        
        // Hledej v celém ev-bridge adresáři
        $files = glob($evBridgeDir . '/**/' . $pattern, GLOB_BRACE);
        
        if (empty($files)) {
            return null;
        }
        
        // Vrať nejnovější soubor podle modifikace
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        return $files[0];
    }
    
    /**
     * Get default staging directory for source
     */
    private function get_default_staging_dir(string $source): string {
        $source_info = $this->source_registry->get_source($source);
        $country = $source_info ? $source_info->country_code : strtoupper($source);
        
        $uploadDir = wp_upload_dir();
        return $uploadDir['basedir'] . '/ev-bridge/staging/' . $country . '/' . date('Y-m');
    }
    
    /**
     * Get latest NDJSON file path for adapter
     */
    private function get_latest_ndjson_path(string $adapterKey): ?string {
        $uploadDir = wp_upload_dir();
        $stagingDir = $uploadDir['basedir'] . '/ev-bridge/staging';
        
        // Find latest staging directory
        $countries = glob($stagingDir . '/*', GLOB_ONLYDIR);
        if (empty($countries)) {
            return null;
        }
        
        $latestCountry = null;
        $latestDate = '';
        
        foreach ($countries as $country) {
            $months = glob($country . '/*', GLOB_ONLYDIR);
            foreach ($months as $month) {
                $monthName = basename($month);
                if (preg_match('/^\d{4}-\d{2}$/', $monthName)) {
                    if ($monthName > $latestDate) {
                        $latestDate = $monthName;
                        $latestCountry = basename($country);
                    }
                }
            }
        }
        
        if (!$latestCountry || !$latestDate) {
            return null;
        }
        
        $ndjsonFile = $stagingDir . '/' . $latestCountry . '/' . $latestDate . '/' . $adapterKey . '_stations.ndjson';
        
        return file_exists($ndjsonFile) ? $ndjsonFile : null;
    }
}

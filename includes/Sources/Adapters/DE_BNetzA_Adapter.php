<?php

declare(strict_types=1);

namespace EVDataBridge\Sources\Adapters;

use EVDataBridge\Core\HTTP_Helper;

/**
 * German BNetzA (Federal Network Agency) CSV adapter
 * Downloads the Ladesäulenregister (Charging Station Register) CSV data
 */
class DE_BNetzA_Adapter implements Adapter_Interface {
    
    private HTTP_Helper $http_helper;
    
    // BNetzA landing page and expected file patterns
    private const LANDING_URL = 'https://www.bundesnetzagentur.de/DE/Sachgebiete/ElektrizitaetundGas/Unternehmen_Institutionen/HandelundVermarktung/Ladesaeulenregister/Ladesaeulenregister.html';
    private const FILE_PATTERN = '/href="([^"]*\.csv?)"/i';
    private const VERSION_PATTERN = '/Stand\s+(\d{1,2}\.\d{1,2}\.\d{4})/i';
    
    public function __construct(HTTP_Helper $http_helper) {
        $this->http_helper = $http_helper;
    }
    
    public function probe(): array {
        // Get the landing page to find the latest CSV file
        $landing_content = $this->http_helper->get(self::LANDING_URL);
        
        // Look for CSV file links
        if (!preg_match_all(self::FILE_PATTERN, $landing_content, $matches)) {
            throw new \RuntimeException('No CSV files found on BNetzA landing page');
        }
        
        $csv_urls = $matches[1];
        
        // Find the most recent file
        $latest_url = $this->find_latest_csv_url($csv_urls);
        
        if (!$latest_url) {
            throw new \RuntimeException('Could not determine latest CSV file URL');
        }
        
        // Make HEAD request to get metadata
        $metadata = $this->http_helper->head($latest_url);
        
        // Extract version from landing page content
        $version = $this->extract_version_from_content($landing_content);
        
        return [
            'url' => $latest_url,
            'version' => $version,
            'last_modified' => $this->http_helper->parse_last_modified($metadata['last_modified']),
            'etag' => $this->http_helper->clean_etag($metadata['etag'] ?? ''),
            'filename' => basename($latest_url),
        ];
    }
    
    public function fetch(): array {
        // First probe to get the latest URL
        $probe_result = $this->probe();
        $download_url = $probe_result['url'];
        
        // Download the file
        $result = $this->http_helper->download_file($download_url);
        
        // Log the import file
        $source_registry = new \EVDataBridge\Sources\Source_Registry();
        $source = $source_registry->get_source('de_bnetza');
        
        if ($source) {
            $source_registry->log_import_file(
                $source->id,
                $download_url,
                $result['file_path'],
                $result['file_size'],
                $result['sha256'],
                $result['content_type'],
                $result['etag'],
                $result['last_modified']
            );
        }
        
        return $result;
    }
    
    public function get_source_name(): string {
        return 'DE_BNetzA';
    }
    
    /**
     * Transform CSV data to canonical JSON format
     */
    public function transform(string $filePath, array $opts = []): array {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: $filePath");
        }
        
        $limit = $opts['limit'] ?? null;
        $outputDir = $opts['out'] ?? null;
        
        // Create staging directory
        $stagingDir = $this->createStagingDirectory('DE', $outputDir);
        
        // Initialize aggregation buckets
        $stations = [];
        $summary = [
            'country' => 'DE',
            'adapter' => 'de_bnetza',
            'source_as_of_date' => null,
            'rows_read' => 0,
            'stations_out' => 0,
            'sum_evse' => 0,
            'max_power_kw_overall' => 0,
            'generated_at' => date('c'),
            'notes' => []
        ];
        
        try {
            // Read CSV file
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                throw new \RuntimeException("Cannot open file: $filePath");
            }
            
            // Read headers
            $headers = fgetcsv($handle, 0, ';');
            if (!$headers) {
                throw new \RuntimeException("Cannot read CSV headers");
            }
            
            // Process rows
            $rowIndex = 0;
            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if ($limit && $rowIndex >= $limit) {
                    break;
                }
                
                $this->processDERow($row, $headers, $stations, $summary);
                $rowIndex++;
            }
            
            fclose($handle);
            
            // Write output files
            $this->writeOutputFiles($stagingDir, 'de_bnetza', $stations, $summary);
            
            return [
                'staging_dir' => $stagingDir,
                'stations_count' => count($stations),
                'summary' => $summary
            ];
            
        } catch (\Exception $e) {
            throw new \RuntimeException('Transform failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Process a single DE BNetzA row
     */
    private function processDERow(array $row, array $headers, array &$stations, array &$summary): void {
        // Map columns
        $data = $this->mapDEColumns($row, $headers);
        
        if (empty($data['operator']) || empty($data['lat']) || empty($data['lon'])) {
            return; // Skip invalid rows
        }
        
        // Normalize operator
        $opKey = \EVDataBridge\Util\Canon::opKey($data['operator']);
        $uniqKey = \EVDataBridge\Util\Canon::uniqKey($opKey, $data['lat'], $data['lon']);
        
        // Create or update station
        if (!isset($stations[$uniqKey])) {
            $stations[$uniqKey] = [
                'uniq_key' => $uniqKey,
                'operator_original' => $data['operator'],
                'operator_aliases' => [$data['operator']],
                'op_norm' => mb_strtolower($data['operator'], 'UTF-8'),
                'op_key' => $opKey,
                'country_code' => 'DE',
                'lat' => $data['lat'],
                'lon' => $data['lon'],
                'lat_5dp' => round($data['lat'], 5),
                'lon_5dp' => round($data['lon'], 5),
                'street' => $data['street'] ?? null,
                'city' => $data['city'] ?? null,
                'psc' => $data['psc'] ?? null,
                'opening_hours' => null, // BNetzA doesn't provide this
                'access' => 'public', // Default for BNetzA
                'payment' => [],
                'cpo_id' => null,
                'station_max_power_kw' => $data['power'] ?? 0,
                'evse_count' => $data['evse_count'] ?? 1,
                'rows_in_source' => 1,
                'connectors' => [],
                'source' => 'BNetzA',
                'source_dataset' => 'Ladesäulenregister',
                'source_url' => 'https://www.bundesnetzagentur.de/',
                'source_as_of_date' => $this->getSourceDate(),
                'license' => null,
                'license_url' => null,
                'row_hash' => '',
                'generated_at' => date('c')
            ];
        } else {
            // Update existing station
            $stations[$uniqKey]['evse_count'] += ($data['evse_count'] ?? 1);
            $stations[$uniqKey]['station_max_power_kw'] = max(
                $stations[$uniqKey]['station_max_power_kw'],
                $data['power'] ?? 0
            );
            $stations[$uniqKey]['rows_in_source']++;
            
            if (!in_array($data['operator'], $stations[$uniqKey]['operator_aliases'])) {
                $stations[$uniqKey]['operator_aliases'][] = $data['operator'];
            }
        }
        
        // Add connectors
        $this->addConnectors($uniqKey, $data, $stations[$uniqKey]['connectors']);
        
        // Update summary
        $summary['sum_evse'] += ($data['evse_count'] ?? 1);
        $summary['max_power_kw_overall'] = max($summary['max_power_kw_overall'], $data['power'] ?? 0);
    }
    
    /**
     * Map DE BNetzA columns to data structure
     */
    private function mapDEColumns(array $row, array $headers): array {
        $data = [];
        
        // Find column indices
        $operatorIdx = array_search('Betreiber', $headers) ?: 0;
        $evseIdx = array_search('Anzahl Ladepunkte', $headers) ?: 1;
        $powerIdx = array_search('Anschlussleistung [kW]', $headers) ?: 2;
        $streetIdx = array_search('Straße', $headers) ?: 3;
        $houseIdx = array_search('Hausnummer', $headers) ?: 4;
        $pscIdx = array_search('PLZ', $headers) ?: 5;
        $cityIdx = array_search('Ort', $headers) ?: 6;
        $latIdx = array_search('Breitengrad', $headers) ?: 7;
        $lonIdx = array_search('Längengrad', $headers) ?: 8;
        
        $data['operator'] = $this->cleanString($row[$operatorIdx] ?? '');
        $data['evse_count'] = (int)($row[$evseIdx] ?? 1);
        $data['power'] = $this->parseFloat($row[$powerIdx] ?? 0);
        $data['street'] = $this->cleanString($row[$streetIdx] ?? '') . ' ' . $this->cleanString($row[$houseIdx] ?? '');
        $data['psc'] = $this->cleanString($row[$pscIdx] ?? '');
        $data['city'] = $this->cleanString($row[$cityIdx] ?? '');
        $data['lat'] = $this->parseFloat($row[$latIdx] ?? 0);
        $data['lon'] = $this->parseFloat($row[$lonIdx] ?? 0);
        
        return $data;
    }
    
    /**
     * Add connectors to station
     */
    private function addConnectors(string $uniqKey, array $data, array &$connectors): void {
        // For BNetzA, create basic connector info
        $connectorIndex = count($connectors) + 1;
        
        $connector = [
            'connector_uid' => \EVDataBridge\Util\Hash::connectorUid($uniqKey, $data, $connectorIndex),
            'connector_index' => $connectorIndex,
            'charge_type' => $this->determineChargeType($data['power'] ?? 0),
            'connector_standard' => 'type2', // Default for BNetzA
            'connector_power_kw' => $data['power'] ?? null,
            'connection_method' => 'kabel',
            'excl_group' => null,
            'evse_uid' => null,
            'source' => 'BNetzA',
            'source_as_of_date' => $this->getSourceDate()
        ];
        
        $connectors[] = $connector;
    }
    
    /**
     * Determine charge type based on power
     */
    private function determineChargeType(float $power): string {
        if ($power >= 50) {
            return 'DC';
        }
        return 'AC';
    }
    
    /**
     * Create staging directory
     */
    private function createStagingDirectory(string $country, ?string $customOut): string {
        $uploadDir = wp_upload_dir();
        $stagingDir = $customOut ?: $uploadDir['basedir'] . '/ev-bridge/staging/' . $country . '/' . date('Y-m');
        
        if (!is_dir($stagingDir)) {
            wp_mkdir_p($stagingDir);
        }
        
        return $stagingDir;
    }
    
    /**
     * Write output files
     */
    private function writeOutputFiles(string $stagingDir, string $adapter, array $stations, array $summary): void {
        $baseName = $stagingDir . '/' . $adapter;
        
        // Write NDJSON (one station per line)
        $ndjsonFile = $baseName . '_stations.ndjson';
        $ndjsonHandle = fopen($ndjsonFile, 'w');
        foreach ($stations as $station) {
            // Generate row hash
            $station['row_hash'] = \EVDataBridge\Util\Hash::rowHash($station);
            fwrite($ndjsonHandle, json_encode($station, JSON_UNESCAPED_UNICODE) . "\n");
        }
        fclose($ndjsonHandle);
        
        // Write pretty JSON
        $jsonFile = $baseName . '_stations.json';
        file_put_contents($jsonFile, json_encode($stations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Write summary
        $summary['stations_out'] = count($stations);
        $summaryFile = $baseName . '_summary.json';
        file_put_contents($summaryFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Get source date from probe/fetch metadata
     */
    private function getSourceDate(): string {
        // This would come from probe/fetch metadata
        // For now, use current date
        return date('Y-m-d');
    }
    
    /**
     * Clean string value
     */
    private function cleanString($value): string {
        if (is_null($value)) return '';
        return trim((string)$value);
    }
    
    /**
     * Parse float value
     */
    private function parseFloat($value): float {
        if (is_null($value) || $value === '') return 0.0;
        
        // Convert comma to dot for decimal
        $value = str_replace(',', '.', (string)$value);
        return (float)$value;
    }
    
    /**
     * Find the most recent CSV file from a list of URLs
     */
    private function find_latest_csv_url(array $urls): ?string {
        $latest_url = null;
        $latest_date = null;
        
        foreach ($urls as $url) {
            // Extract date from filename if possible
            $date = $this->extract_date_from_filename($url);
            
            if ($date && (!$latest_date || $date > $latest_date)) {
                $latest_date = $date;
                $latest_url = $url;
            }
        }
        
        // If no date found, return the first CSV file
        if (!$latest_url && !empty($urls)) {
            $latest_url = $urls[0];
        }
        
        return $latest_url;
    }
    
    /**
     * Extract date from filename
     */
    private function extract_date_from_filename(string $filename): ?string {
        // Look for common date patterns in German filenames
        $patterns = [
            '/(\d{4})-(\d{2})-(\d{2})/', // YYYY-MM-DD
            '/(\d{2})\.(\d{2})\.(\d{4})/', // DD.MM.YYYY
            '/(\d{4})(\d{2})(\d{2})/', // YYYYMMDD
            '/(\d{1,2})_(\d{1,2})_(\d{4})/', // DD_MM_YYYY
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                if (count($matches) === 4) {
                    // YYYY-MM-DD or DD.MM.YYYY or DD_MM_YYYY
                    if (strlen($matches[1]) === 4) {
                        return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
                    } else {
                        return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
                    }
                } elseif (count($matches) === 2) {
                    // YYYYMMDD
                    $date_str = $matches[1];
                    if (strlen($date_str) === 8) {
                        return substr($date_str, 0, 4) . '-' . substr($date_str, 4, 2) . '-' . substr($date_str, 6, 2);
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract version information from landing page content
     */
    private function extract_version_from_content(string $content): string {
        // Look for "Stand DD.MM.YYYY" pattern
        if (preg_match(self::VERSION_PATTERN, $content, $matches)) {
            return "BNetzA Stand " . $matches[1];
        }
        
        // Fallback to current date
        return "BNetzA Export " . date('Y-m-d');
    }
}

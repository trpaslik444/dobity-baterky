<?php

declare(strict_types=1);

namespace EVDataBridge\Sources\Adapters;

use EVDataBridge\Core\HTTP_Helper;

/**
 * Austrian E-Control charging station directory adapter
 * Queries the Ladestellenverzeichnis (Charging Station Directory) REST API
 */
class AT_EControl_Adapter implements Adapter_Interface {
    
    private HTTP_Helper $http_helper;
    
    // E-Control API endpoints
    private const API_BASE_URL = 'https://www.e-control.at/api/v1';
    private const CHARGING_STATIONS_ENDPOINT = '/charging-stations';
    private const HEALTH_CHECK_ENDPOINT = '/health';
    
    public function __construct(HTTP_Helper $http_helper) {
        $this->http_helper = $http_helper;
    }
    
    public function probe(): array {
        // First check if the API is available
        $health_url = self::API_BASE_URL . self::HEALTH_CHECK_ENDPOINT;
        
        try {
            $health_response = $this->http_helper->get($health_url);
            $health_data = json_decode($health_response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from E-Control health check');
            }
            
            if (!isset($health_data['status']) || $health_data['status'] !== 'ok') {
                throw new \RuntimeException('E-Control API health check failed: ' . ($health_data['message'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('E-Control API health check failed: ' . $e->getMessage());
        }
        
        // Get charging stations metadata
        $stations_url = self::API_BASE_URL . self::CHARGING_STATIONS_ENDPOINT;
        
        try {
            // Make a HEAD request to get metadata
            $metadata = $this->http_helper->head($stations_url);
            
            // Get a sample of stations to determine count and structure
            $sample_response = $this->http_helper->get($stations_url . '?limit=1');
            $sample_data = json_decode($sample_response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from E-Control API');
            }
            
            $total_count = $sample_data['total'] ?? 0;
            $last_updated = $sample_data['last_updated'] ?? null;
            
            return [
                'url' => $stations_url,
                'version' => 'E-Control API v1',
                'last_modified' => $this->http_helper->parse_last_modified($metadata['last_modified']) ?? $last_updated,
                'etag' => $this->http_helper->clean_etag($metadata['etag'] ?? ''),
                'filename' => 'at_charging_stations.json',
                'total_count' => $total_count,
                'api_status' => 'healthy',
            ];
            
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to probe E-Control API: ' . $e->getMessage());
        }
    }
    
    public function fetch(): array {
        // For REST APIs, we'll download a sample of data to determine the structure
        // In a real implementation, this would download all data with pagination
        
        $sample_url = self::API_BASE_URL . self::CHARGING_STATIONS_ENDPOINT . '?limit=100';
        
        try {
            $sample_response = $this->http_helper->get($sample_url);
            $sample_data = json_decode($sample_response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from E-Control API');
            }
            
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to fetch sample data from E-Control API: ' . $e->getMessage());
        }
        
        // Create a temporary file with sample data
        $upload_dir = wp_upload_dir();
        $ev_bridge_dir = $upload_dir['basedir'] . '/ev-bridge/' . date('Y-m');
        
        if (!is_dir($ev_bridge_dir)) {
            wp_mkdir_p($ev_bridge_dir);
        }
        
        $filename = 'at_charging_stations_sample_' . date('Y-m-d_H-i-s') . '.json';
        $file_path = $ev_bridge_dir . '/' . $filename;
        
        // Write sample data to file
        $json_content = json_encode($sample_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($file_path, $json_content) === false) {
            throw new \RuntimeException('Failed to write sample data to file');
        }
        
        $file_size = filesize($file_path);
        $sha256 = hash_file('sha256', $file_path);
        
        // Log the import file
        $source_registry = new \EVDataBridge\Sources\Source_Registry();
        $source = $source_registry->get_source('at_econtrol');
        
        if ($source) {
            $source_registry->log_import_file(
                $source->id,
                $sample_url,
                $file_path,
                $file_size,
                $sha256,
                'application/json',
                null,
                null
            );
        }
        
        return [
            'file_path' => $file_path,
            'file_size' => $file_size,
            'sha256' => $sha256,
            'content_type' => 'application/json',
            'etag' => null,
            'last_modified' => null,
            'url' => $sample_url,
        ];
    }
    
    public function get_source_name(): string {
        return 'AT_EControl';
    }
    
    /**
     * Get total charging station count from E-Control API
     */
    public function get_total_count(): int {
        $url = self::API_BASE_URL . self::CHARGING_STATIONS_ENDPOINT . '?limit=1';
        
        try {
            $response = $this->http_helper->get($url);
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response from E-Control API');
            }
            
            return $data['total'] ?? 0;
            
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to get total count from E-Control API: ' . $e->getMessage());
        }
    }
    
    /**
     * Download all charging stations with pagination
     * This method would be used in the next iteration for full data download
     */
    public function download_all_stations(): array {
        $total_count = $this->get_total_count();
        $limit = 100; // API limit per request
        $pages = ceil($total_count / $limit);
        
        $all_stations = [];
        
        for ($page = 0; $page < $pages; $page++) {
            $offset = $page * $limit;
            $url = self::API_BASE_URL . self::CHARGING_STATIONS_ENDPOINT . "?limit={$limit}&offset={$offset}";
            
            try {
                $response = $this->http_helper->get($url);
                $data = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException('Invalid JSON response from E-Control API');
                }
                
                if (isset($data['stations'])) {
                    $all_stations = array_merge($all_stations, $data['stations']);
                }
                
                // Small delay to be respectful to the API
                usleep(100000); // 0.1 second
                
            } catch (\Exception $e) {
                throw new \RuntimeException("Failed to download page {$page} from E-Control API: " . $e->getMessage());
            }
        }
        
        return $all_stations;
    }
}

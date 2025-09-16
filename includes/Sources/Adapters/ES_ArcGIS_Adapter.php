<?php

declare(strict_types=1);

namespace EVDataBridge\Sources\Adapters;

use EVDataBridge\Core\HTTP_Helper;

/**
 * Spanish ArcGIS FeatureServer adapter
 * Queries the Red de recarga (Charging Network) FeatureServer
 */
class ES_ArcGIS_Adapter implements Adapter_Interface {
    
    private HTTP_Helper $http_helper;
    
    // Spanish charging network ArcGIS FeatureServer
    private const FEATURESERVER_URL = 'https://services.arcgis.com/HRPe58bUySqysFmQ/arcgis/rest/services/Red_Recarga/FeatureServer/0';
    
    public function __construct(HTTP_Helper $http_helper) {
        $this->http_helper = $http_helper;
    }
    
    public function probe(): array {
        // Get ArcGIS FeatureServer metadata
        $metadata = $this->http_helper->get_arcgis_metadata(self::FEATURESERVER_URL);
        
        // Extract editing info for last modified date
        $editing_info = $metadata['editingInfo'] ?? [];
        $last_modified = null;
        
        if (isset($editing_info['lastEditDate'])) {
            $last_modified = date('Y-m-d H:i:s', $editing_info['lastEditDate'] / 1000);
        }
        
        // Get feature count
        $feature_count = $metadata['extent'] ?? 0;
        
        return [
            'url' => self::FEATURESERVER_URL,
            'version' => 'ArcGIS FeatureServer',
            'last_modified' => $last_modified,
            'etag' => '', // ArcGIS doesn't provide ETags
            'filename' => 'es_charging_network.json',
            'feature_count' => $feature_count,
            'metadata' => $metadata,
        ];
    }
    
    public function fetch(): array {
        // For ArcGIS, we'll download a sample of features to determine the structure
        // In a real implementation, this would download all features with pagination
        
        $sample_params = [
            'where' => '1=1',
            'outFields' => '*',
            'returnGeometry' => 'true',
            'resultRecordCount' => 100, // Sample size
            'resultOffset' => 0,
        ];
        
        $sample_data = $this->http_helper->query_arcgis_featureserver(
            self::FEATURESERVER_URL,
            $sample_params
        );
        
        // Create a temporary file with sample data
        $upload_dir = wp_upload_dir();
        $ev_bridge_dir = $upload_dir['basedir'] . '/ev-bridge/' . date('Y-m');
        
        if (!is_dir($ev_bridge_dir)) {
            wp_mkdir_p($ev_bridge_dir);
        }
        
        $filename = 'es_charging_network_sample_' . date('Y-m-d_H-i-s') . '.json';
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
        $source = $source_registry->get_source('es_arcgis');
        
        if ($source) {
            $source_registry->log_import_file(
                $source->id,
                self::FEATURESERVER_URL,
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
            'url' => self::FEATURESERVER_URL,
        ];
    }
    
    public function get_source_name(): string {
        return 'ES_ArcGIS';
    }
    
    /**
     * Get total feature count from ArcGIS FeatureServer
     */
    public function get_feature_count(): int {
        $metadata = $this->http_helper->get_arcgis_metadata(self::FEATURESERVER_URL);
        
        // Query with where=1=1 to get total count
        $count_params = [
            'where' => '1=1',
            'returnCountOnly' => 'true',
        ];
        
        $count_data = $this->http_helper->query_arcgis_featureserver(
            self::FEATURESERVER_URL,
            $count_params
        );
        
        return $count_data['count'] ?? 0;
    }
    
    /**
     * Download all features with pagination
     * This method would be used in the next iteration for full data download
     */
    public function download_all_features(): array {
        $total_count = $this->get_feature_count();
        $batch_size = 1000;
        $batches = ceil($total_count / $batch_size);
        
        $all_features = [];
        
        for ($i = 0; $i < $batches; $i++) {
            $offset = $i * $batch_size;
            
            $params = [
                'where' => '1=1',
                'outFields' => '*',
                'returnGeometry' => 'true',
                'resultRecordCount' => $batch_size,
                'resultOffset' => $offset,
            ];
            
            $batch_data = $this->http_helper->query_arcgis_featureserver(
                self::FEATURESERVER_URL,
                $params
            );
            
            if (isset($batch_data['features'])) {
                $all_features = array_merge($all_features, $batch_data['features']);
            }
            
            // Small delay to be respectful to the server
            usleep(100000); // 0.1 second
        }
        
        return $all_features;
    }
}

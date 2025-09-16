<?php

declare(strict_types=1);

namespace EVDataBridge\Sources\Adapters;

use EVDataBridge\Core\HTTP_Helper;

/**
 * French IRVE (Infrastructure de Recharge de Véhicules Électriques) adapter
 * Downloads consolidated dataset from data.gouv.fr
 */
class FR_IRVE_Adapter implements Adapter_Interface {
    
    private HTTP_Helper $http_helper;
    
    // IRVE dataset landing page
    private const LANDING_URL = 'https://www.data.gouv.fr/fr/datasets/fichier-consolide-des-bornes-de-recharge-pour-vehicules-electriques/';
    private const DATASET_PATTERN = '/href="([^"]*\/datasets\/[^"]*irve[^"]*)"/i';
    
    public function __construct(HTTP_Helper $http_helper) {
        $this->http_helper = $http_helper;
    }
    
    public function probe(): array {
        // Get the landing page to find the dataset
        $landing_content = $this->http_helper->get(self::LANDING_URL);
        
        // Look for dataset links
        if (!preg_match_all(self::DATASET_PATTERN, $landing_content, $matches)) {
            throw new \RuntimeException('No IRVE dataset found on data.gouv.fr landing page');
        }
        
        $dataset_urls = $matches[1];
        
        // Find the main dataset URL
        $dataset_url = $this->find_main_dataset_url($dataset_urls);
        
        if (!$dataset_url) {
            throw new \RuntimeException('Could not determine main IRVE dataset URL');
        }
        
        // Get dataset metadata
        $dataset_metadata = $this->get_dataset_metadata($dataset_url);
        
        // Find the latest resource (usually CSV or GeoJSON)
        $latest_resource = $this->find_latest_resource($dataset_metadata);
        
        if (!$latest_resource) {
            throw new \RuntimeException('No suitable resource found in IRVE dataset');
        }
        
        return [
            'url' => $latest_resource['url'],
            'version' => $latest_resource['version'] ?? 'IRVE Consolidated Dataset',
            'last_modified' => $latest_resource['last_modified'],
            'etag' => $latest_resource['etag'] ?? '',
            'filename' => basename($latest_resource['url']),
            'dataset_url' => $dataset_url,
        ];
    }
    
    public function fetch(): array {
        // First probe to get the latest resource URL
        $probe_result = $this->probe();
        $download_url = $probe_result['url'];
        
        // Download the file
        $result = $this->http_helper->download_file($download_url);
        
        // Log the import file
        $source_registry = new \EVDataBridge\Sources\Source_Registry();
        $source = $source_registry->get_source('fr_irve');
        
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
        return 'FR_IRVE';
    }
    
    /**
     * Find the main dataset URL from a list of URLs
     */
    private function find_main_dataset_url(array $urls): ?string {
        foreach ($urls as $url) {
            // Look for the main IRVE dataset
            if (strpos($url, 'irve') !== false && strpos($url, 'consolide') !== false) {
                return $url;
            }
        }
        
        // Return first URL if no specific match found
        return $urls[0] ?? null;
    }
    
    /**
     * Get dataset metadata from data.gouv.fr
     */
    private function get_dataset_metadata(string $dataset_url): array {
        // Get the dataset page content
        $dataset_content = $this->http_helper->get($dataset_url);
        
        // Look for resource links (CSV, GeoJSON, etc.)
        $resource_pattern = '/href="([^"]*\.(csv|geojson|json))"/i';
        preg_match_all($resource_pattern, $dataset_content, $matches);
        
        $resources = [];
        foreach ($matches[1] as $index => $url) {
            $extension = strtolower($matches[2][$index]);
            $resources[] = [
                'url' => $url,
                'extension' => $extension,
                'filename' => basename($url),
            ];
        }
        
        return $resources;
    }
    
    /**
     * Find the latest resource from dataset metadata
     */
    private function find_latest_resource(array $resources): ?array {
        if (empty($resources)) {
            return null;
        }
        
        // Prefer CSV files, then GeoJSON, then JSON
        $priority_order = ['csv', 'geojson', 'json'];
        
        foreach ($priority_order as $extension) {
            foreach ($resources as $resource) {
                if ($resource['extension'] === $extension) {
                    // Get metadata for this resource
                    $metadata = $this->http_helper->head($resource['url']);
                    
                    return [
                        'url' => $resource['url'],
                        'version' => 'IRVE ' . ucfirst($extension) . ' Export',
                        'last_modified' => $this->http_helper->parse_last_modified($metadata['last_modified']),
                        'etag' => $this->http_helper->clean_etag($metadata['etag'] ?? ''),
                        'extension' => $extension,
                    ];
                }
            }
        }
        
        // Return first resource if no preferred format found
        $first_resource = $resources[0];
        $metadata = $this->http_helper->head($first_resource['url']);
        
        return [
            'url' => $first_resource['url'],
            'version' => 'IRVE ' . ucfirst($first_resource['extension']) . ' Export',
            'last_modified' => $this->http_helper->parse_last_modified($metadata['last_modified']),
            'etag' => $this->http_helper->clean_etag($metadata['etag'] ?? ''),
            'extension' => $first_resource['extension'],
        ];
    }
}

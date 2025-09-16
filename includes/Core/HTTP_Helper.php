<?php

declare(strict_types=1);

namespace EVDataBridge\Core;

/**
 * HTTP Helper for making requests and downloading files
 */
class HTTP_Helper {
    
    private const TIMEOUT = 300; // 5 minutes
    private const USER_AGENT = 'EV-Data-Bridge/1.0 (+https://github.com/dobity-baterky/ev-data-bridge)';
    
    /**
     * Make a HEAD request to get metadata
     */
    public function head(string $url, array $headers = []): array {
        $args = [
            'method' => 'HEAD',
            'timeout' => self::TIMEOUT,
            'user-agent' => self::USER_AGENT,
            'headers' => $headers,
        ];
        
        $response = wp_remote_head($url, $args);
        
        if (is_wp_error($response)) {
            throw new \RuntimeException('HEAD request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            throw new \RuntimeException("HEAD request failed with status code: $response_code");
        }
        
        return [
            'content_type' => wp_remote_retrieve_header($response, 'content-type'),
            'content_length' => wp_remote_retrieve_header($response, 'content-length'),
            'etag' => wp_remote_retrieve_header($response, 'etag'),
            'last_modified' => wp_remote_retrieve_header($response, 'last-modified'),
            'response_code' => $response_code,
        ];
    }
    
    /**
     * Download a file to the uploads directory
     */
    public function download_file(string $url, string $filename = null, array $headers = []): array {
        $upload_dir = wp_upload_dir();
        $ev_bridge_dir = $upload_dir['basedir'] . '/ev-bridge/' . date('Y-m');
        
        // Create directory if it doesn't exist
        if (!is_dir($ev_bridge_dir)) {
            wp_mkdir_p($ev_bridge_dir);
        }
        
        // Generate filename if not provided
        if (!$filename) {
            $filename = $this->generate_filename_from_url($url);
        }
        
        $file_path = $ev_bridge_dir . '/' . $filename;
        
        // Download file
        $args = [
            'timeout' => self::TIMEOUT,
            'user-agent' => self::USER_AGENT,
            'headers' => $headers,
            'stream' => true,
            'filename' => $file_path,
        ];
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            throw new \RuntimeException('Download failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            throw new \RuntimeException("Download failed with status code: $response_code");
        }
        
        // Verify file was downloaded
        if (!file_exists($file_path)) {
            throw new \RuntimeException('File was not downloaded to expected location');
        }
        
        $file_size = filesize($file_path);
        $sha256 = hash_file('sha256', $file_path);
        
        return [
            'file_path' => $file_path,
            'file_size' => $file_size,
            'sha256' => $sha256,
            'content_type' => wp_remote_retrieve_header($response, 'content-type'),
            'etag' => wp_remote_retrieve_header($response, 'etag'),
            'last_modified' => wp_remote_retrieve_header($response, 'last-modified'),
            'url' => $url,
        ];
    }
    
    /**
     * Make a GET request and return the response body
     */
    public function get(string $url, array $headers = []): string {
        $args = [
            'timeout' => self::TIMEOUT,
            'user-agent' => self::USER_AGENT,
            'headers' => $headers,
        ];
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            throw new \RuntimeException('GET request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            throw new \RuntimeException("GET request failed with status code: $response_code");
        }
        
        return wp_remote_retrieve_body($response);
    }
    
    /**
     * Make a POST request and return the response body
     */
    public function post(string $url, array $data = [], array $headers = []): string {
        $args = [
            'method' => 'POST',
            'timeout' => self::TIMEOUT,
            'user-agent' => self::USER_AGENT,
            'headers' => $headers,
            'body' => $data,
        ];
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            throw new \RuntimeException('POST request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            throw new \RuntimeException("POST request failed with status code: $response_code");
        }
        
        return wp_remote_retrieve_body($response);
    }
    
    /**
     * Query ArcGIS FeatureServer with pagination support
     */
    public function query_arcgis_featureserver(string $url, array $params = []): array {
        // Ensure required parameters
        $params['f'] = 'json';
        $params['outFields'] = $params['outFields'] ?? '*';
        $params['returnGeometry'] = $params['returnGeometry'] ?? 'true';
        
        $query_url = add_query_arg($params, $url);
        
        $response_body = $this->get($query_url);
        $response_data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response from ArcGIS: ' . json_last_error_msg());
        }
        
        if (isset($response_data['error'])) {
            throw new \RuntimeException('ArcGIS error: ' . ($response_data['error']['message'] ?? 'Unknown error'));
        }
        
        return $response_data;
    }
    
    /**
     * Get ArcGIS FeatureServer metadata
     */
    public function get_arcgis_metadata(string $url): array {
        $metadata_url = add_query_arg(['f' => 'json'], $url);
        $response_body = $this->get($metadata_url);
        $response_data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response from ArcGIS: ' . json_last_error_msg());
        }
        
        if (isset($response_data['error'])) {
            throw new \RuntimeException('ArcGIS error: ' . ($response_data['error']['message'] ?? 'Unknown error'));
        }
        
        return $response_data;
    }
    
    /**
     * Generate a filename from URL
     */
    private function generate_filename_from_url(string $url): string {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '';
        $query = $parsed_url['query'] ?? '';
        
        // Extract filename from path
        $filename = basename($path);
        
        // If no filename in path, use query hash
        if (empty($filename) || $filename === '/') {
            $filename = 'download_' . substr(md5($query), 0, 8);
        }
        
        // Add timestamp to avoid conflicts
        $timestamp = date('Y-m-d_H-i-s');
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        
        if ($extension) {
            return "{$name}_{$timestamp}.{$extension}";
        }
        
        return "{$name}_{$timestamp}";
    }
    
    /**
     * Parse Last-Modified header to timestamp
     */
    public function parse_last_modified(string $last_modified): ?string {
        if (empty($last_modified)) {
            return null;
        }
        
        $timestamp = strtotime($last_modified);
        if ($timestamp === false) {
            return null;
        }
        
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    /**
     * Clean ETag value
     */
    public function clean_etag(string $etag): string {
        // Remove quotes if present
        return trim($etag, '"');
    }
}

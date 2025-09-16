<?php

declare(strict_types=1);

namespace EVDataBridge\Sources\Adapters;

/**
 * Base interface for all data source adapters
 */
interface Adapter_Interface {
    
    /**
     * Probe the data source to check for updates
     * Returns metadata about the latest available data
     */
    public function probe(): array;
    
    /**
     * Fetch data from the source
     * Downloads and returns file information
     */
    public function fetch(): array;
    
    /**
     * Get the source name for logging
     */
    public function get_source_name(): string;
}

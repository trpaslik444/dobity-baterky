# FR IRVE Adapter

**Country**: France  
**Source**: data.gouv.fr / IRVE  
**Type**: CSV/GeoJSON  
**Status**: ✅ Implemented  

## Overview

The FR IRVE adapter downloads the consolidated dataset of EV charging infrastructure (Infrastructure de Recharge de Véhicules Électriques) from the French open data platform.

## Landing Page

**URL**: https://www.data.gouv.fr/fr/datasets/fichier-consolide-des-bornes-de-recharge-pour-vehicules-electriques/  
**Language**: French  
**Update Frequency**: Monthly  

## Data Format

- **File Type**: CSV, GeoJSON, or JSON
- **Encoding**: UTF-8
- **Structure**: Consolidated data from multiple French sources
- **Size**: Typically 5-20MB

## Implementation Details

### Probe Operation

1. **Landing Page Scraping**: Downloads the data.gouv.fr landing page
2. **Dataset Discovery**: Finds IRVE consolidated dataset links
3. **Resource Selection**: Identifies latest available data format
4. **Metadata Retrieval**: Gets file metadata for version detection

### Fetch Operation

1. **Resource Resolution**: Determines latest data file URL
2. **File Download**: Downloads file to local storage
3. **Logging**: Records import file metadata in database
4. **Storage**: Files organized by month in uploads directory

### Dataset Pattern Matching

```php
private const DATASET_PATTERN = '/href="([^"]*\/datasets\/[^"]*irve[^"]*)"/i';
private const RESOURCE_PATTERN = '/href="([^"]*\.(csv|geojson|json))"/i';
```

### Format Priority

1. **CSV**: Preferred format for tabular data
2. **GeoJSON**: Spatial data with geometry
3. **JSON**: Structured data format

## Expected Data Structure

The consolidated dataset typically contains:

- **Station Information**: Name, address, coordinates
- **Technical Details**: Power ratings, connector types
- **Operational Data**: Availability, access restrictions
- **Metadata**: Source attribution, update timestamps

## Configuration

```php
// Source registry entry
[
    'country_code' => 'FR',
    'adapter_key' => 'fr_irve',
    'landing_url' => 'https://www.data.gouv.fr/ (IRVE consolidated dataset)',
    'fetch_type' => 'csv',
    'update_frequency' => 'monthly',
    'enabled' => 1
]
```

## Usage Examples

### WP-CLI Commands

```bash
# Probe for updates
wp ev-bridge probe --source=fr_irve

# Download latest data
wp ev-bridge fetch --source=fr_irve
```

### Programmatic Usage

```php
use EVDataBridge\Sources\Adapters\FR_IRVE_Adapter;
use EVDataBridge\Core\HTTP_Helper;

$http_helper = new HTTP_Helper();
$adapter = new FR_IRVE_Adapter($http_helper);

// Check for updates
$probe_result = $adapter->probe();

// Download data
$fetch_result = $adapter->fetch();
```

## Error Handling

### Common Issues

1. **Dataset Not Found**: Landing page structure may have changed
2. **Resource Selection**: No suitable data format available
3. **Download Failures**: Network issues or file access restrictions

### Troubleshooting

- Verify landing page is accessible
- Check dataset patterns match current page structure
- Ensure proper file permissions for downloads
- Monitor error logs for specific failure reasons

## Future Enhancements

### Planned Features

- **Content Validation**: Verify data structure and content
- **Incremental Updates**: Detect and download only changed files
- **Data Preview**: Sample data extraction for validation
- **Error Recovery**: Automatic retry mechanisms

### Integration Points

- **Transform Layer**: Convert to canonical JSON schema
- **Delta Processing**: Calculate changes for incremental imports
- **Quality Metrics**: Data validation and scoring

## Dependencies

- **HTTP_Helper**: For web requests and file downloads
- **Source_Registry**: For logging and metadata management
- **WordPress**: For file system operations and database access

## Notes

- **Consolidated Data**: Combines multiple French charging networks
- **Open Data Platform**: Official French government data portal
- **Multiple Formats**: Supports CSV, GeoJSON, and JSON
- **Regular Updates**: Monthly consolidated dataset releases

## Related Documentation

- [Main Plugin README](../README.md)
- [Sources Overview](../SOURCES_README.md)
- [Adapter Interface](../Adapter_Interface.md)
- [HTTP Helper](../Core/HTTP_Helper.md)

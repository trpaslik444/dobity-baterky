# CZ MPO Adapter

**Country**: Czech Republic  
**Source**: MPO (Ministry of Industry and Trade)  
**Type**: XLSX export  
**Status**: ✅ Implemented  

## Overview

The CZ MPO adapter downloads the official list of public charging stations from the Czech Ministry of Industry and Trade. This is the primary data source for EV charging infrastructure in the Czech Republic.

## Landing Page

**URL**: https://www.mpo.gov.cz/cz/energetika/statistika/evidence-dobijecich-stanic/  
**Language**: Czech  
**Update Frequency**: Monthly  

## Data Format

- **File Type**: XLSX (Excel)
- **Encoding**: UTF-8
- **Structure**: Tabular data with multiple sheets
- **Size**: Typically 100KB - 1MB

## Implementation Details

### Probe Operation

1. **Landing Page Scraping**: Downloads the MPO landing page
2. **File Detection**: Uses regex pattern to find XLSX file links
3. **Version Extraction**: Parses filename for date information
4. **Metadata Retrieval**: Makes HEAD request to get file metadata

### Fetch Operation

1. **URL Resolution**: Determines latest XLSX file URL
2. **File Download**: Downloads file to local storage
3. **Logging**: Records import file metadata in database
4. **Storage**: Files organized by month in uploads directory

### File Pattern Matching

```php
private const FILE_PATTERN = '/href="([^"]*\.xlsx?)"/i';
```

### Date Extraction

Supports multiple Czech date formats:
- `YYYY-MM-DD` (ISO format)
- `DD.MM.YYYY` (Czech standard)
- `YYYYMMDD` (Compact format)

## Expected Data Structure

The XLSX file typically contains:

- **Sheet 1**: Summary statistics
- **Sheet 2**: Detailed station list
- **Columns**: Station name, address, coordinates, power, connectors, etc.

## Configuration

```php
// Source registry entry
[
    'country_code' => 'CZ',
    'adapter_key' => 'cz_mpo',
    'landing_url' => 'https://mpo.gov.cz/ (sekce statistika/evidence dobíjecích stanic – XLSX)',
    'fetch_type' => 'xlsx',
    'update_frequency' => 'monthly',
    'enabled' => 1
]
```

## Usage Examples

### WP-CLI Commands

```bash
# Probe for updates
wp ev-bridge probe --source=cz_mpo

# Download latest data
wp ev-bridge fetch --source=cz_mpo
```

### Programmatic Usage

```php
use EVDataBridge\Sources\Adapters\CZ_MPO_Adapter;
use EVDataBridge\Core\HTTP_Helper;

$http_helper = new HTTP_Helper();
$adapter = new CZ_MPO_Adapter($http_helper);

// Check for updates
$probe_result = $adapter->probe();

// Download data
$fetch_result = $adapter->fetch();
```

## Error Handling

### Common Issues

1. **No XLSX Files Found**: Landing page structure may have changed
2. **Invalid Date Format**: Filename doesn't match expected patterns
3. **Download Failures**: Network issues or file access restrictions

### Troubleshooting

- Verify landing page is accessible
- Check regex pattern matches current page structure
- Ensure proper file permissions for downloads
- Monitor error logs for specific failure reasons

## Future Enhancements

### Planned Features

- **Content Validation**: Verify XLSX file structure and content
- **Incremental Updates**: Detect and download only changed files
- **Data Preview**: Sample data extraction for validation
- **Error Recovery**: Automatic retry mechanisms

### Integration Points

- **Transform Layer**: Convert XLSX to canonical JSON schema
- **Delta Processing**: Calculate changes for incremental imports
- **Quality Metrics**: Data validation and scoring

## Dependencies

- **HTTP_Helper**: For web requests and file downloads
- **Source_Registry**: For logging and metadata management
- **WordPress**: For file system operations and database access

## Notes

- **Mandatory Source**: CZ operations require this adapter to be functional
- **Czech Language**: Landing page content is in Czech
- **Regular Updates**: MPO provides monthly exports
- **Government Source**: Official data from Czech government ministry

## Related Documentation

- [Main Plugin README](../README.md)
- [Sources Overview](../SOURCES_README.md)
- [Adapter Interface](../Adapter_Interface.md)
- [HTTP Helper](../Core/HTTP_Helper.md)

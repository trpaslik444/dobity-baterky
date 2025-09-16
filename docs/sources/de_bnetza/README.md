# DE BNetzA Adapter

**Country**: Germany  
**Source**: BNetzA (Federal Network Agency)  
**Type**: CSV export  
**Status**: ✅ Implemented  

## Overview

The DE BNetzA adapter downloads the Ladesäulenregister (Charging Station Register) from the German Federal Network Agency. This is the official registry of all public charging stations in Germany.

## Landing Page

**URL**: https://www.bundesnetzagentur.de/DE/Sachgebiete/ElektrizitaetundGas/Unternehmen_Institutionen/HandelundVermarktung/Ladesaeulenregister/Ladesaeulenregister.html  
**Language**: German  
**Update Frequency**: Monthly  

## Data Format

- **File Type**: CSV (Comma-separated values)
- **Encoding**: UTF-8
- **Structure**: Tabular data with German column headers
- **Size**: Typically 2-10MB

## Implementation Details

### Probe Operation

1. **Landing Page Scraping**: Downloads the BNetzA landing page
2. **File Detection**: Uses regex pattern to find CSV file links
3. **Version Extraction**: Parses content for "Stand DD.MM.YYYY" pattern
4. **Metadata Retrieval**: Makes HEAD request to get file metadata

### Fetch Operation

1. **URL Resolution**: Determines latest CSV file URL
2. **File Download**: Downloads file to local storage
3. **Logging**: Records import file metadata in database
4. **Storage**: Files organized by month in uploads directory

### File Pattern Matching

```php
private const FILE_PATTERN = '/href="([^"]*\.csv?)"/i';
private const VERSION_PATTERN = '/Stand\s+(\d{1,2}\.\d{1,2}\.\d{4})/i';
```

### Date Extraction

Supports multiple German date formats:
- `YYYY-MM-DD` (ISO format)
- `DD.MM.YYYY` (German standard)
- `YYYYMMDD` (Compact format)
- `DD_MM_YYYY` (Underscore format)

## Expected Data Structure

The CSV file typically contains columns like:

- **Betreiber**: Operator name
- **Anzahl Ladepunkte**: Number of charging points
- **Anschlussleistung [kW]**: Connection power in kW
- **Steckertypen**: Connector types
- **Koordinaten**: GPS coordinates
- **Adresse**: Address information

## Configuration

```php
// Source registry entry
[
    'country_code' => 'DE',
    'adapter_key' => 'de_bnetza',
    'landing_url' => 'https://www.bundesnetzagentur.de/ (Downloads – CSV/XLSX Ladesäulenregister)',
    'fetch_type' => 'csv',
    'update_frequency' => 'monthly',
    'enabled' => 1
]
```

## Usage Examples

### WP-CLI Commands

```bash
# Probe for updates
wp ev-bridge probe --source=de_bnetza

# Download latest data
wp ev-bridge fetch --source=de_bnetza
```

### Programmatic Usage

```php
use EVDataBridge\Sources\Adapters\DE_BNetzA_Adapter;
use EVDataBridge\Core\HTTP_Helper;

$http_helper = new HTTP_Helper();
$adapter = new DE_BNetzA_Adapter($http_helper);

// Check for updates
$probe_result = $adapter->probe();

// Download data
$fetch_result = $adapter->fetch();
```

## Error Handling

### Common Issues

1. **No CSV Files Found**: Landing page structure may have changed
2. **Version Pattern Mismatch**: "Stand" pattern not found in content
3. **Download Failures**: Network issues or file access restrictions

### Troubleshooting

- Verify landing page is accessible
- Check regex patterns match current page structure
- Ensure proper file permissions for downloads
- Monitor error logs for specific failure reasons

## Future Enhancements

### Planned Features

- **Content Validation**: Verify CSV structure and content
- **Incremental Updates**: Detect and download only changed files
- **Data Preview**: Sample data extraction for validation
- **Error Recovery**: Automatic retry mechanisms

### Integration Points

- **Transform Layer**: Convert CSV to canonical JSON schema
- **Delta Processing**: Calculate changes for incremental imports
- **Quality Metrics**: Data validation and scoring

## Dependencies

- **HTTP_Helper**: For web requests and file downloads
- **Source_Registry**: For logging and metadata management
- **WordPress**: For file system operations and database access

## Notes

- **Official Registry**: Government-mandated charging station database
- **German Language**: Landing page and data in German
- **Regular Updates**: BNetzA provides monthly exports
- **Large Datasets**: CSV files can be several MB in size

## Related Documentation

- [Main Plugin README](../README.md)
- [Sources Overview](../SOURCES_README.md)
- [Adapter Interface](../Adapter_Interface.md)
- [HTTP Helper](../Core/HTTP_Helper.md)

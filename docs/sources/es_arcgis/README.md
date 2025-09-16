# ES ArcGIS Adapter

**Country**: Spain  
**Source**: datos.gob.es  
**Type**: ArcGIS FeatureServer  
**Status**: ✅ Implemented  

## Overview

The ES ArcGIS adapter queries the Spanish "Red de recarga" (Charging Network) FeatureServer to retrieve EV charging station data from the Spanish open data portal.

## Landing Page

**URL**: https://datos.gob.es/  
**Language**: Spanish  
**Update Frequency**: Monthly  

## Data Format

- **File Type**: ArcGIS FeatureServer (JSON response)
- **Encoding**: UTF-8
- **Structure**: Spatial data with attributes and geometry
- **Size**: Variable based on query parameters

## Implementation Details

### Probe Operation

1. **FeatureServer Metadata**: Retrieves ArcGIS FeatureServer metadata
2. **Editing Info**: Extracts lastEditDate for version detection
3. **Feature Count**: Gets total number of available features
4. **Service Status**: Verifies FeatureServer availability

### Fetch Operation

1. **Sample Query**: Downloads sample of features to determine structure
2. **File Creation**: Creates local JSON file with sample data
3. **Logging**: Records import file metadata in database
4. **Storage**: Files organized by month in uploads directory

### ArcGIS Integration

```php
// FeatureServer URL
private const FEATURESERVER_URL = 'https://services.arcgis.com/HRPe58bUySqysFmQ/arcgis/rest/services/Red_Recarga/FeatureServer/0';

// Query parameters
$params = [
    'where' => '1=1',
    'outFields' => '*',
    'returnGeometry' => 'true',
    'resultRecordCount' => 100,
    'resultOffset' => 0
];
```

### Pagination Support

- **Batch Size**: 1000 features per request
- **Offset Handling**: resultOffset parameter for pagination
- **Rate Limiting**: 0.1 second delay between requests

## Expected Data Structure

The FeatureServer typically returns:

- **Features Array**: Individual charging station records
- **Geometry**: Point coordinates (lat/lon)
- **Attributes**: Station metadata and technical details
- **Metadata**: Service information and capabilities

## Configuration

```php
// Source registry entry
[
    'country_code' => 'ES',
    'adapter_key' => 'es_arcgis',
    'landing_url' => 'https://datos.gob.es/ (Red de recarga – ArcGIS)',
    'fetch_type' => 'arcgis',
    'update_frequency' => 'monthly',
    'enabled' => 1
]
```

## Usage Examples

### WP-CLI Commands

```bash
# Probe for updates
wp ev-bridge probe --source=es_arcgis

# Download latest data
wp ev-bridge fetch --source=es_arcgis
```

### Programmatic Usage

```php
use EVDataBridge\Sources\Adapters\ES_ArcGIS_Adapter;
use EVDataBridge\Core\HTTP_Helper;

$http_helper = new HTTP_Helper();
$adapter = new ES_ArcGIS_Adapter($http_helper);

// Check for updates
$probe_result = $adapter->probe();

// Download sample data
$fetch_result = $adapter->fetch();

// Get total feature count
$total_count = $adapter->get_feature_count();

// Download all features (future implementation)
$all_features = $adapter->download_all_features();
```

## Error Handling

### Common Issues

1. **FeatureServer Unavailable**: Service may be down or moved
2. **Query Failures**: Invalid parameters or service restrictions
3. **Large Datasets**: Memory issues with large feature collections

### Troubleshooting

- Verify FeatureServer URL is accessible
- Check query parameters are valid
- Monitor memory usage for large datasets
- Review ArcGIS service status

## Future Enhancements

### Planned Features

- **Full Data Download**: Complete feature collection with pagination
- **Spatial Filtering**: Geographic bounding box queries
- **Attribute Selection**: Custom field selection for efficiency
- **Caching**: Local cache for frequently accessed data

### Integration Points

- **Transform Layer**: Convert ArcGIS features to canonical schema
- **Delta Processing**: Calculate changes for incremental imports
- **Quality Metrics**: Data validation and scoring

## Dependencies

- **HTTP_Helper**: For ArcGIS API requests and metadata
- **Source_Registry**: For logging and metadata management
- **WordPress**: For file system operations and database access

## Notes

- **Spatial Data**: Includes geometry for mapping applications
- **ESRI Platform**: Uses ArcGIS Online FeatureServer
- **Large Datasets**: May contain thousands of charging stations
- **Real-time Updates**: FeatureServer provides current data

## Related Documentation

- [Main Plugin README](../README.md)
- [Sources Overview](../SOURCES_README.md)
- [Adapter Interface](../Adapter_Interface.md)
- [HTTP Helper](../Core/HTTP_Helper.md)

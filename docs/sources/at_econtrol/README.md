# AT E-Control Adapter

**Country**: Austria  
**Source**: E-Control  
**Type**: REST API  
**Status**: ✅ Implemented  

## Overview

The AT E-Control adapter queries the Austrian Ladestellenverzeichnis (Charging Station Directory) REST API to retrieve EV charging station data from the Austrian energy regulator.

## Landing Page

**URL**: https://www.e-control.at/  
**Language**: German  
**Update Frequency**: Monthly  

## Data Format

- **File Type**: REST API (JSON response)
- **Encoding**: UTF-8
- **Structure**: JSON API with pagination support
- **Size**: Variable based on query parameters

## Implementation Details

### Probe Operation

1. **Health Check**: Verifies API availability via health endpoint
2. **API Status**: Checks service health and response format
3. **Metadata Retrieval**: Gets API version and capabilities
4. **Sample Query**: Retrieves sample data for structure analysis

### Fetch Operation

1. **Sample Request**: Downloads sample of stations to determine structure
2. **File Creation**: Creates local JSON file with sample data
3. **Logging**: Records import file metadata in database
4. **Storage**: Files organized by month in uploads directory

### API Integration

```php
// API endpoints
private const API_BASE_URL = 'https://www.e-control.at/api/v1';
private const CHARGING_STATIONS_ENDPOINT = '/charging-stations';
private const HEALTH_CHECK_ENDPOINT = '/health';

// Query parameters
$params = [
    'limit' => 100,
    'offset' => 0
];
```

### Pagination Support

- **Batch Size**: 100 stations per request
- **Offset Handling**: offset parameter for pagination
- **Rate Limiting**: 0.1 second delay between requests

## Expected Data Structure

The API typically returns:

- **Stations Array**: Individual charging station records
- **Metadata**: Total count and pagination info
- **Attributes**: Station details and technical specifications
- **Status**: API response status and error handling

## Configuration

```php
// Source registry entry
[
    'country_code' => 'AT',
    'adapter_key' => 'at_econtrol',
    'landing_url' => 'https://www.e-control.at/ (Ladestellenverzeichnis – API)',
    'fetch_type' => 'rest',
    'update_frequency' => 'monthly',
    'enabled' => 1
]
```

## Usage Examples

### WP-CLI Commands

```bash
# Probe for updates
wp ev-bridge probe --source=at_econtrol

# Download latest data
wp ev-bridge fetch --source=at_econtrol
```

### Programmatic Usage

```php
use EVDataBridge\Sources\Adapters\AT_EControl_Adapter;
use EVDataBridge\Core\HTTP_Helper;

$http_helper = new HTTP_Helper();
$adapter = new AT_EControl_Adapter($http_helper);

// Check for updates
$probe_result = $adapter->probe();

// Download sample data
$fetch_result = $adapter->fetch();

// Get total station count
$total_count = $adapter->get_total_count();

// Download all stations (future implementation)
$all_stations = $adapter->download_all_stations();
```

## Error Handling

### Common Issues

1. **API Unavailable**: Service may be down or moved
2. **Authentication Failures**: API key or access restrictions
3. **Rate Limiting**: Too many requests in short time
4. **Large Datasets**: Memory issues with large station collections

### Troubleshooting

- Verify API endpoints are accessible
- Check authentication and access requirements
- Monitor rate limiting and request frequency
- Review API documentation for changes

## Future Enhancements

### Planned Features

- **Full Data Download**: Complete station collection with pagination
- **Real-time Updates**: Webhook or polling for live updates
- **Data Filtering**: Geographic and attribute-based queries
- **Caching**: Local cache for frequently accessed data

### Integration Points

- **Transform Layer**: Convert API responses to canonical schema
- **Delta Processing**: Calculate changes for incremental imports
- **Quality Metrics**: Data validation and scoring

## Dependencies

- **HTTP_Helper**: For REST API requests and health checks
- **Source_Registry**: For logging and metadata management
- **WordPress**: For file system operations and database access

## Notes

- **Energy Regulator**: Official Austrian government data source
- **German Language**: API responses may include German text
- **Real-time Data**: API provides current charging station information
- **Professional Service**: Designed for commercial and public use

## Related Documentation

- [Main Plugin README](../README.md)
- [Sources Overview](../SOURCES_README.md)
- [Adapter Interface](../Adapter_Interface.md)
- [HTTP Helper](../Core/HTTP_Helper.md)

# SK NAP Adapter

**Country**: Slovakia  
**Source**: odoprave.info (Ministry of Transport)  
**Type**: ArcGIS FeatureServer  
**Status**: 🔄 Planned for v1.2  

## Overview

The SK NAP adapter will query the Slovak National Access Point (NAP) portal to retrieve EV charging station data from the Ministry of Transport infrastructure.

## Landing Page

**URL**: https://www.odoprave.info/  
**Language**: Slovak  
**Update Frequency**: Monthly  

## Data Format

- **File Type**: ArcGIS FeatureServer (planned)
- **Encoding**: UTF-8
- **Structure**: Spatial data with attributes and geometry
- **Size**: TBD

## Implementation Status

### Current Status
- **Adapter**: Not yet implemented
- **Source Registry**: Seeded and enabled
- **Documentation**: This stub document

### Planned Implementation
- **Version**: 1.2
- **Priority**: Medium
- **Dependencies**: ArcGIS HTTP helper methods

## Expected Data Structure

Based on typical NAP portals, the data should include:

- **Features Array**: Individual charging station records
- **Geometry**: Point coordinates (lat/lon)
- **Attributes**: Station metadata and technical details
- **Metadata**: Service information and capabilities

## Configuration

```php
// Source registry entry (already seeded)
[
    'country_code' => 'SK',
    'adapter_key' => 'sk_nap',
    'landing_url' => 'https://www.odoprave.info/ (NAP landing)',
    'fetch_type' => 'arcgis',
    'update_frequency' => 'monthly',
    'enabled' => 1
]
```

## Future Implementation

### Adapter Class
```php
class SK_NAP_Adapter implements Adapter_Interface {
    // Implementation planned for v1.2
}
```

### Features
- ArcGIS FeatureServer integration
- Spatial data handling
- Pagination support
- Error handling and retry logic

## Notes

- **NAP Portal**: National Access Point for transport data
- **Slovak Language**: Portal content in Slovak
- **Government Source**: Official Ministry of Transport data
- **ArcGIS Platform**: Expected to use ESRI technology

## Related Documentation

- [Main Plugin README](../README.md)
- [Sources Overview](../SOURCES_README.md)
- [Adapter Interface](../Adapter_Interface.md)
- [HTTP Helper](../Core/HTTP_Helper.md)

---

*This adapter is planned for future implementation. Check the roadmap for updates.*

# EV Data Bridge - WordPress Plugin

WordPress plugin for importing and normalizing EV charging station data from national sources across EU27+ countries.

## Overview

EV Data Bridge is a comprehensive solution for aggregating electric vehicle charging station data from various national government sources. The plugin provides:

1. **Automated Data Collection**: Monthly checks of state sources (NAP/ministries/registries) by country
2. **Data Normalization**: Converts CSV/XLSX/REST/ArcGIS data into a unified canonical schema
3. **Delta Processing**: Calculates changes using row_hash and applies them idempotently
4. **POI Enrichment**: Fronts POI enhancement (OSM → Google Places) for new/changed locations
5. **Scheduled Operations**: Runs via WP-CLI + OS cron with per-country toggles

## Features

### Current Implementation (v2.0.0) - PRODUCTION READY

- **Plugin Skeleton**: Complete WordPress plugin structure with PSR-4 autoloading
- **Database Schema**: Optimized tables with proper indexes for performance
- **Admin Interface**: Advanced queue management with real-time monitoring
- **Nearby Processing**: Automated batch processing with API quota management
- **Rate Limiting**: Token bucket algorithm for API calls (40 requests/minute)
- **Error Handling**: Comprehensive error handling and logging system
- **Security**: Full nonce validation and capability checks
- **Performance**: Optimized database queries and bulk operations
- **WP-CLI Commands**: `probe` and `fetch` operations for data sources
- **HTTP Helper**: Robust HTTP client with timeout handling and file downloads
- **Source Adapters**: Implemented for CZ, DE, FR, ES, and AT

### Nearby Queue System (NEW in v2.0.0)

The plugin now includes a sophisticated queue management system for processing nearby points:

- **Two-Stage Processing**: 
  1. **Queue Population**: Free, immediate processing of points into queue
  2. **API Processing**: Costly API calls with admin confirmation required

- **Queue Management**:
  - Real-time queue monitoring with pagination
  - Priority-based processing
  - Automatic retry mechanism for failed items
  - Bulk operations for queue management

- **API Quota Management**:
  - OpenRouteService (ORS) integration with rate limiting
  - Token bucket algorithm (40 requests/minute)
  - Automatic quota monitoring via API headers
  - Fallback to OSRM when ORS quota exhausted

- **Admin Interface**:
  - Queue statistics dashboard
  - Real-time quota monitoring
  - Test buttons for API functionality
  - Batch processing controls

### Supported Data Sources

#### Fully Implemented
- **CZ (Czech Republic)**: MPO XLSX export - `cz_mpo`
- **DE (Germany)**: BNetzA Ladesäulenregister CSV - `de_bnetza`
- **FR (France)**: IRVE consolidated dataset - `fr_irve`
- **ES (Spain)**: Red de recarga ArcGIS FeatureServer - `es_arcgis`
- **AT (Austria)**: E-Control Ladestellenverzeichnis REST API - `at_econtrol`

#### Planned for Future Versions
- **SK/SI/HR/RO/BG/GR/CY**: NAP portals (mostly ArcGIS/REST)
- **BE/NL/IT/PL/HU**: National registries and APIs
- **NO/SE**: NOBIL API integration
- **UK**: NCR open data

## Installation

### Requirements

- WordPress 5.8+
- PHP 8.1+
- WP-CLI (for command-line operations)

### Setup

1. Upload the plugin to `/wp-content/plugins/ev-data-bridge/`
2. Activate the plugin through the WordPress admin
3. The plugin will automatically create database tables and seed the source registry

## Usage

### Admin Interface

Navigate to **EV Data Bridge → Sources** in the WordPress admin to view:
- Source status and configuration
- Last successful import dates
- Error messages and troubleshooting info
- Version information for each source

### WP-CLI Commands

#### Probe Sources
Check for updates without downloading data:

```bash
# Probe specific source
wp ev-bridge probe --source=DE

# Probe all enabled sources
wp ev-bridge probe --all

# List all available sources
wp ev-bridge list
```

#### Fetch Data
Download data from sources:

```bash
# Fetch from specific source
wp ev-bridge fetch --source=DE

# Fetch from all enabled sources
wp ev-bridge fetch --all
```

### Cron Integration

Set up automated monthly updates:

```bash
# Add to crontab (runs monthly)
0 2 1 * * cd /path/to/wordpress && wp ev-bridge fetch --all
```

## Architecture

### Core Components

- **Plugin Class**: Main initialization and lifecycle management
- **Admin Manager**: WordPress admin interface and menu
- **CLI Commands**: WP-CLI command implementations
- **HTTP Helper**: HTTP client with specialized methods for different data types
- **Source Registry**: Database management for sources and import logs
- **Adapters**: Source-specific data collection logic

### Database Schema

#### `wp_ev_sources`
Registry of data sources with configuration and status:

```sql
- id: Primary key
- country_code: ISO country code (CZ, DE, FR, etc.)
- adapter_key: Adapter identifier (cz_mpo, de_bnetza, etc.)
- landing_url: Official source landing page
- fetch_type: Data format (xlsx, csv, rest, arcgis)
- update_frequency: Update schedule (monthly)
- enabled: Source activation status
- last_version_label: Latest available version
- last_success_at: Last successful import
- last_error_at: Last error occurrence
- last_error_message: Error details
```

#### `wp_ev_import_files`
Log of downloaded files and metadata:

```sql
- id: Primary key
- source_id: Reference to source
- source_url: URL of downloaded file
- file_path: Local file path
- file_size: File size in bytes
- file_sha256: File hash for deduplication
- content_type: MIME type
- etag: HTTP ETag header
- last_modified: Last-Modified header
- status: Download status
- download_completed_at: Completion timestamp
```

### Adapter Pattern

Each data source implements the `Adapter_Interface`:

```php
interface Adapter_Interface {
    public function probe(): array;    // Check for updates
    public function fetch(): array;    // Download data
    public function get_source_name(): string;
}
```

## Data Flow

1. **Probe**: Check source for updates (version, last-modified, ETag)
2. **Fetch**: Download data files to local storage
3. **Log**: Record file metadata and import status
4. **Transform**: Convert to canonical schema (future implementation)
5. **Delta**: Calculate changes using row_hash (future implementation)
6. **Import**: Apply changes to main plugin (future implementation)

## Configuration

### Source Registry

The plugin automatically seeds a registry of data sources during activation. Each source includes:

- Country code and adapter key
- Landing page URL
- Data format type
- Update frequency
- Initial enabled status

### File Storage

Downloaded files are stored in:
```
wp-content/uploads/ev-bridge/YYYY-MM/
```

Files are organized by month to facilitate cleanup and management.

## Development

### Adding New Sources

1. Create adapter class implementing `Adapter_Interface`
2. Add source to seed data in main plugin class
3. Register adapter in CLI command class
4. Implement probe/fetch logic for the specific data format

### Code Standards

- PHP 8.1+ with strict types
- PSR-4 autoloading
- WordPress coding standards
- Comprehensive error handling
- Logging and monitoring

## Troubleshooting

### Common Issues

1. **Source Not Found**: Verify source is enabled in admin interface
2. **Download Failures**: Check network connectivity and source availability
3. **Permission Errors**: Ensure upload directory is writable
4. **Memory Limits**: Large files may require increased PHP memory

### Debug Mode

Enable WordPress debug logging to troubleshoot issues:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Roadmap

### Version 1.1 (Next)
- Data transformation to canonical schema
- Delta calculation and change detection
- Integration with main plugin database

### Version 1.2
- POI enrichment (OSM → Google Places)
- Geohash clustering for location optimization
- Advanced scheduling and monitoring

### Version 1.3
- Additional country support
- Performance optimizations
- Advanced error handling and retry logic

## Contributing

1. Fork the repository
2. Create feature branch
3. Implement changes with tests
4. Submit pull request

## License

GPL v2 or later

## Support

For issues and questions:
- GitHub Issues: [Repository Issues](https://github.com/dobity-baterky/ev-data-bridge/issues)
- Documentation: See `docs/` directory for detailed guides

## Credits

Developed by the Dobity Baterky Team for the EV charging infrastructure community.

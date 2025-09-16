# EV Data Bridge - Sources Documentation

This directory contains detailed documentation for each data source adapter in the EV Data Bridge plugin.

## Adapter Status

### ✅ Implemented (v1.0.0)
- [CZ MPO](./cz_mpo/README.md) - Czech Republic MPO XLSX export
- [DE BNetzA](./de_bnetza/README.md) - German BNetzA CSV export
- [FR IRVE](./fr_irve/README.md) - French IRVE consolidated dataset
- [ES ArcGIS](./es_arcgis/README.md) - Spanish Red de recarga FeatureServer
- [AT E-Control](./at_econtrol/README.md) - Austrian E-Control REST API

### 🔄 Planned for v1.2
- [SK NAP](./sk_nap/README.md) - Slovakia NAP portal
- [SI NAP](./si_nap/README.md) - Slovenia NAP portal
- [HR NAP](./hr_nap/README.md) - Croatia NAP portal
- [RO NAP](./ro_nap/README.md) - Romania NAP portal
- [BG NAP](./bg_nap/README.md) - Bulgaria NAP portal
- [GR NAP](./gr_nap/README.md) - Greece NAP portal
- [CY NAP](./cy_nap/README.md) - Cyprus NAP portal
- [BE VL Laadinfr](./be_vl_laadinfr/README.md) - Belgium Flanders charging points
- [IT PUN](./it_pun/README.md) - Italy PUN platform
- [PL EIPA](./pl_eipa/README.md) - Poland EIPA registry
- [HU NAP](./hu_nap/README.md) - Hungary NAP portal

### 🔄 Planned for v1.3
- [NO/SE NOBIL](./nordics_nobil/README.md) - Nordic countries NOBIL API
- [UK NCR](./uk_ncr/README.md) - UK National Chargepoint Registry
- [DK NAP](./dk_nap/README.md) - Denmark NAP portal
- [FI NAP](./fi_nap/README.md) - Finland NAP portal
- [EE NAP](./ee_nap/README.md) - Estonia NAP portal
- [LV NAP](./lv_nap/README.md) - Latvia NAP portal
- [LT NAP](./lt_nap/README.md) - Lithuania NAP portal
- [LU NAP](./lu_nap/README.md) - Luxembourg NAP portal
- [MT NAP](./mt_nap/README.md) - Malta NAP portal
- [IE NAP](./ie_nap/README.md) - Ireland NAP portal
- [PT NAP](./pt_nap/README.md) - Portugal NAP portal

### ⚠️ API Key Required
- [NL DOT-NL](./nl_dotnl/README.md) - Netherlands DOT-NL API (requires access key)
- [NO/SE NOBIL](./nordics_nobil/README.md) - Nordic countries NOBIL API (requires API key)

## Documentation Structure

Each adapter documentation follows a consistent structure:

1. **Overview** - High-level description and purpose
2. **Landing Page** - Source URL and language information
3. **Data Format** - File type, encoding, and structure details
4. **Implementation Details** - Technical implementation specifics
5. **Expected Data Structure** - Data schema and field descriptions
6. **Configuration** - Source registry configuration
7. **Usage Examples** - WP-CLI and programmatic usage
8. **Error Handling** - Common issues and troubleshooting
9. **Future Enhancements** - Planned features and improvements
10. **Dependencies** - Required components and libraries
11. **Notes** - Additional context and considerations

## Common Patterns

### NAP Portals
Most European countries use National Access Point (NAP) portals for transport data:
- **ArcGIS FeatureServer**: Common spatial data platform
- **REST APIs**: Standard web service interfaces
- **CKAN**: Open data platform used by many governments

### File Formats
- **CSV**: Tabular data, common for exports
- **XLSX**: Excel format, often used by government agencies
- **GeoJSON**: Spatial data with geometry
- **JSON**: Structured data format for APIs

### Update Frequencies
- **Monthly**: Standard for most government sources
- **Weekly**: Some sources with frequent updates
- **Real-time**: APIs with live data feeds

## Implementation Guidelines

### Adding New Sources

1. **Research Source**: Understand data format and access methods
2. **Create Adapter**: Implement `Adapter_Interface`
3. **Add to Registry**: Update source seed data
4. **Register in CLI**: Add to command class
5. **Document**: Create comprehensive README
6. **Test**: Verify probe and fetch operations

### Code Standards

- **PHP 8.1+**: Use strict types and modern PHP features
- **Error Handling**: Comprehensive exception handling
- **Logging**: Record all operations and errors
- **Documentation**: Inline code documentation
- **Testing**: Unit tests for critical functionality

## Related Documentation

- [Main Plugin README](../README.md) - Plugin overview and installation
- [Sources Overview](../SOURCES_README.md) - Complete source listing and status
- [Adapter Interface](../Adapter_Interface.md) - Interface specification
- [HTTP Helper](../Core/HTTP_Helper.md) - HTTP utility methods

## Contributing

When adding new sources or improving existing ones:

1. **Follow Patterns**: Use existing adapters as templates
2. **Document Everything**: Comprehensive README files
3. **Error Handling**: Robust error handling and logging
4. **Testing**: Test with real data sources
5. **Performance**: Consider memory and network efficiency

## Support

For questions about specific adapters or implementation:

- **GitHub Issues**: Report bugs and request features
- **Documentation**: Check this directory for detailed guides
- **Code Examples**: Review existing adapter implementations
- **Community**: Engage with the EV charging infrastructure community

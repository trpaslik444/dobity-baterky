# EV Data Bridge - Data Sources Overview

This document provides an overview of all planned data sources for the EV Data Bridge plugin, organized by region and implementation status.

## Implementation Status

- ✅ **Implemented**: Adapter exists and is functional
- 🔄 **Planned**: Adapter planned for future versions
- ⚠️ **API Key Required**: Requires registration/API key
- 🚧 **In Progress**: Currently being developed

## Central & Eastern Europe

### Czech Republic (CZ) ✅
- **Source**: MPO (Ministry of Industry and Trade)
- **Type**: XLSX export
- **Adapter**: `cz_mpo`
- **Landing**: https://mpo.gov.cz/ (statistics/charging stations)
- **Status**: Fully implemented - mandatory for CZ operations
- **Notes**: Regular monthly exports, automatic file detection

### Slovakia (SK) 🔄
- **Source**: odoprave.info (Ministry of Transport)
- **Type**: ArcGIS FeatureServer
- **Adapter**: `sk_nap`
- **Landing**: https://www.odoprave.info/
- **Status**: Planned for v1.2
- **Notes**: NAP portal, EV charging points layer

### Slovenia (SI) 🔄
- **Source**: nap.si (NTMC)
- **Type**: ArcGIS/REST
- **Adapter**: `si_nap`
- **Landing**: https://www.nap.si/
- **Status**: Planned for v1.2
- **Notes**: Explicitly mentions EV charging points

### Croatia (HR) 🔄
- **Source**: promet-info.hr
- **Type**: ArcGIS/REST
- **Adapter**: `hr_nap`
- **Landing**: https://www.promet-info.hr/
- **Status**: Planned for v1.2
- **Notes**: NAP portal, transport infrastructure

### Romania (RO) 🔄
- **Source**: pna.cestrin.ro
- **Type**: ArcGIS/REST
- **Adapter**: `ro_nap`
- **Landing**: https://pna.cestrin.ro/
- **Status**: Planned for v1.2
- **Notes**: National transport authority

### Bulgaria (BG) 🔄
- **Source**: MTITC NAP / LIMA
- **Type**: JSON/ArcGIS
- **Adapter**: `bg_nap`
- **Landing**: https://www.mtitc.government.bg/
- **Status**: Planned for v1.2
- **Notes**: Ministry of Transport, Infrastructure and Communications

### Greece (GR) 🔄
- **Source**: nap.gov.gr / nap.imet.gr
- **Type**: ArcGIS/REST
- **Adapter**: `gr_nap`
- **Landing**: https://www.nap.gov.gr/
- **Status**: Planned for v1.2
- **Notes**: National NAP portal

### Cyprus (CY) 🔄
- **Source**: traffic4cyprus.org.cy
- **Type**: CSV/GeoJSON
- **Adapter**: `cy_nap`
- **Landing**: https://www.traffic4cyprus.org.cy/
- **Status**: Planned for v1.2
- **Notes**: CKAN/Hub platform

## Western & Northern Europe

### Germany (DE) ✅
- **Source**: BNetzA (Federal Network Agency)
- **Type**: CSV/XLSX export
- **Adapter**: `de_bnetza`
- **Landing**: https://www.bundesnetzagentur.de/
- **Status**: Fully implemented
- **Notes**: Ladesäulenregister, monthly updates, "Stand DD.MM.YYYY"

### Austria (AT) ✅
- **Source**: E-Control
- **Type**: REST API
- **Adapter**: `at_econtrol`
- **Landing**: https://www.e-control.at/
- **Status**: Fully implemented
- **Notes**: Ladestellenverzeichnis, public API

### France (FR) ✅
- **Source**: data.gouv.fr / IRVE
- **Type**: CSV/GeoJSON
- **Adapter**: `fr_irve`
- **Landing**: https://www.data.gouv.fr/
- **Status**: Fully implemented
- **Notes**: Consolidated IRVE dataset, CKAN platform

### Belgium (BE) 🔄
- **Source**: Vlaanderen datavindplaats
- **Type**: CSV/Geo
- **Adapter**: `be_vl_laadinfr`
- **Landing**: https://www.vlaanderen.be/datavindplaats/
- **Status**: Planned for v1.2
- **Notes**: "Laadpunten voor elektrische voertuigen"

### Netherlands (NL) ⚠️
- **Source**: NDW DOT-NL / LINDA
- **Type**: REST API
- **Adapter**: `nl_dotnl`
- **Landing**: https://docs.ndw.nu/
- **Status**: Planned for v1.2
- **Notes**: Charging Points API, requires access key

### Spain (ES) ✅
- **Source**: datos.gob.es
- **Type**: ArcGIS FeatureServer
- **Adapter**: `es_arcgis`
- **Landing**: https://datos.gob.es/
- **Status**: Fully implemented
- **Notes**: "Red de recarga" FeatureServer

### Italy (IT) 🔄
- **Source**: PUN (Piattaforma Unica Nazionale)
- **Type**: ArcGIS/REST
- **Adapter**: `it_pun`
- **Landing**: https://www.piattaformaunicanazionale.it/
- **Status**: Planned for v1.2
- **Notes**: National unified platform, public map

### Poland (PL) 🔄
- **Source**: EIPA (UDT)
- **Type**: REST/CSV
- **Adapter**: `pl_eipa`
- **Landing**: https://eipa.udt.gov.pl/
- **Status**: Planned for v1.2
- **Notes**: State registry, exports/API, may require registration

### Hungary (HU) 🔄
- **Source**: napportal.kozut.hu
- **Type**: REST/ArcGIS
- **Adapter**: `hu_nap`
- **Landing**: https://napportal.kozut.hu/
- **Status**: Planned for v1.2
- **Notes**: NAP portal, transport infrastructure

## Nordic & Baltic

### Norway/Sweden (NO/SE) ⚠️
- **Source**: NOBIL API
- **Type**: REST API
- **Adapter**: `nordics_nobil`
- **Landing**: https://info.nobil.no/
- **Status**: Planned for v1.2
- **Notes**: Used in NO/FI/DK/SE, requires API key

### Denmark (DK) 🔄
- **Source**: National NAP portal
- **Type**: REST/ArcGIS
- **Adapter**: `dk_nap`
- **Landing**: TBD
- **Status**: Planned for v1.3
- **Notes**: CKAN/REST/ArcGIS platform

### Finland (FI) 🔄
- **Source**: National NAP portal
- **Type**: REST/ArcGIS
- **Adapter**: `fi_nap`
- **Landing**: TBD
- **Status**: Planned for v1.3
- **Notes**: CKAN/REST/ArcGIS platform

### Estonia (EE) 🔄
- **Source**: National NAP portal
- **Type**: REST/ArcGIS
- **Adapter**: `ee_nap`
- **Landing**: TBD
- **Status**: Planned for v1.3
- **Notes**: CKAN/REST/ArcGIS platform

### Latvia (LV) 🔄
- **Source**: National NAP portal
- **Type**: REST/ArcGIS
- **Adapter**: `lv_nap`
- **Landing**: TBD
- **Status**: Planned for v1.3
- **Notes**: CKAN/REST/ArcGIS platform

### Lithuania (LT) 🔄
- **Source**: National NAP portal
- **Type**: REST/ArcGIS
- **Adapter**: `lt_nap`
- **Landing**: TBD
- **Status**: Planned for v1.3
- **Notes**: CKAN/REST/ArcGIS platform

## Other Countries

### Luxembourg (LU) 🔄
- **Source**: National NAP portal
- **Type**: REST/ArcGIS
- **Adapter**: `lu_nap`
- **Landing**: TBD
- **Status**: Planned for v1.3
- **Notes**: CKAN/REST/ArcGIS platform

### Malta (MT) 🔄
- **Source**: National NAP portal
- **Type**: REST/ArcGIS
- **Adapter**: `mt_nap`
- **Landing**: TBD
- **Status**: Planned for v1.3
- **Notes**: CKAN/REST/ArcGIS platform

### Ireland (IE) 🔄
- **Source**: National NAP portal
- **Type**: REST/ArcGIS
- **Adapter**: `ie_nap`
- **Landing**: TBD
- **Status**: Planned for v1.3
- **Notes**: CKAN/REST/ArcGIS platform

### Portugal (PT) 🔄
- **Source**: National NAP portal
- **Type**: REST/ArcGIS
- **Adapter**: `pt_nap`
- **Landing**: TBD
- **Status**: Planned for v1.3
- **Notes**: CKAN/REST/ArcGIS platform

### United Kingdom (UK) 🔄
- **Source**: NCR (National Chargepoint Registry)
- **Type**: CSV/REST
- **Adapter**: `uk_ncr`
- **Landing**: https://data.gov.uk/
- **Status**: Planned for v1.3
- **Notes**: Open data, post-Brexit

## Implementation Priority

### Phase 1 (v1.0.0) ✅
- CZ, DE, FR, ES, AT - Core EU countries with established data sources

### Phase 2 (v1.2) 🔄
- SK, SI, HR, RO, BG, GR, CY - Central/Eastern Europe NAP portals
- BE, NL, IT, PL, HU - Western Europe with complex data structures

### Phase 3 (v1.3) 🔄
- NO/SE (NOBIL) - Nordic countries with API access
- DK, FI, EE, LV, LT - Baltic/Nordic NAP portals
- LU, MT, IE, PT - Smaller EU countries
- UK - Post-Brexit open data

## Data Format Distribution

- **CSV**: 8 sources (CZ, DE, FR, BE, CY, UK, + planned)
- **XLSX**: 2 sources (CZ, DE)
- **REST API**: 6 sources (AT, NL, PL, HU, NO/SE, + planned)
- **ArcGIS**: 12 sources (SK, SI, HR, RO, BG, GR, ES, IT, + planned)
- **GeoJSON**: 3 sources (FR, CY, + planned)

## Notes

1. **NAP Portals**: Most countries use National Access Point portals for transport data
2. **ArcGIS Dominance**: ESRI ArcGIS is the most common platform for spatial data
3. **API Keys**: Some sources (NL, NO/SE) require registration and API keys
4. **Update Frequency**: All sources are configured for monthly updates
5. **Language Support**: Adapters handle local language content and date formats

## Future Considerations

- **Real-time APIs**: Some sources may offer real-time updates
- **WebSocket Support**: For live data feeds
- **Data Quality Metrics**: Validation and quality scoring
- **Geographic Coverage**: Ensure comprehensive EU27+ coverage
- **Performance Optimization**: Batch processing and caching strategies

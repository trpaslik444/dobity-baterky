# Dobitý Baterky – POI Service

TypeScript/Node.js microservice that surfaces nearby points of interest (POI) around GPS coordinates. It prioritizes open data providers, filters by quality (rating ≥ 4.0 by default), and falls back to Google Places only when necessary. The service can run as a standalone Fastify REST API or be reused as a module.

## Tech stack
- Fastify
- Prisma (PostgreSQL)
- TypeScript

## Database schema
Defined in [`prisma/schema.prisma`](./prisma/schema.prisma):
- `Poi` stores normalized POIs including rating, media metadata, category whitelist, and `source_ids` for deduplication.
- `PoiCache` caches nearby lookups (`lat`, `lon`, `radius_m`) with providers used.
- `ApiUsage` tracks provider usage (fallback if WordPress DB is not configured).

### Google Places API Quota Management

Microservice respektuje stejné denní limity jako WordPress plugin (PR #75):
- **Výchozí limit**: 300 požadavků/den (konfigurovatelné přes `MAX_PLACES_REQUESTS_PER_DAY`)
- **Synchronizace**: Pokud je nakonfigurována WordPress MySQL DB, kvóty jsou synchronizované přes tabulku `wp_db_places_usage`
- **Atomické operace**: Používá MySQL transakce s `FOR UPDATE` lock pro prevenci race conditions
- **Fallback**: Pokud WordPress DB není dostupná, používá PostgreSQL `ApiUsage` tabulku
- **Rezervace před voláním**: Kvóta se rezervuje před voláním Google API, ne po

Run migrations with Prisma after configuring `DATABASE_URL` in `.env`.

## Configuration
Environment variables (defaults in `src/config.ts`):
- `DATABASE_URL` - PostgreSQL connection string
- `OPENTRIPMAP_API_KEY`
- `GOOGLE_PLACES_API_KEY`
- `MIN_RATING` (default `4.0`)
- `ALLOW_POIS_WITHOUT_RATING` (default `false`)
- `CACHE_TTL_DAYS` (default `30`)
- `MIN_POIS_BEFORE_GOOGLE` (default `6`)
- `PLACES_ENRICHMENT_ENABLED` (default `true`) - Feature flag pro Google Places
- `MAX_PLACES_REQUESTS_PER_DAY` (default `300`) - Sjednoceno s WordPress (PR #75)

### WordPress MySQL synchronizace kvót (volitelné)
Pro synchronizaci kvót s WordPress pluginem (PR #75):
- `WORDPRESS_DB_HOST` - WordPress MySQL host
- `WORDPRESS_DB_NAME` - WordPress databáze
- `WORDPRESS_DB_USER` - MySQL uživatel
- `WORDPRESS_DB_PASSWORD` - MySQL heslo
- `WORDPRESS_DB_PREFIX` (default `wp_`) - WordPress tabulka prefix

Pokud není WordPress DB nakonfigurována, microservice použije vlastní PostgreSQL `ApiUsage` tabulku.

## Development
```bash
cd poi-service
npm install
npm run dev
```
The server listens on port `3333` by default. Configure `PORT` to override.

### Nearby POIs endpoint
`GET /api/pois/nearby?lat=<number>&lon=<number>&radius=<meters>&minCount=<int>&refresh=<bool>`
- Uses cache → DB → OpenTripMap + Wikidata → Google Places (fallback with rate limit)
- Filters categories by whitelist and ratings by config.

### CSV import
```bash
npm run dev -- src/import/csvImporter.ts data.csv
```
Imports POIs from the provided CSV format, deduplicates within 50 m and similar names, and merges richer metadata (rating priority order is `manual_import > google > tripadvisor > opentripmap > wikidata`).

## Providers
Implemented providers in `src/providers/`:
- `OpenTripMapProvider`
- `WikidataProvider`
- `GooglePlacesProvider` (fallback)
- `ManualProvider` (reads existing DB records first)

## Reuse as a module
The exported `getNearbyPois` function in `src/aggregator.ts` can be imported directly for integration into other apps without running the HTTP server.

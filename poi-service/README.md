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
- `ApiUsage` tracks provider usage (currently Google Places daily limit).

Run migrations with Prisma after configuring `DATABASE_URL` in `.env`.

## Configuration
Environment variables (defaults in `src/config.ts`):
- `DATABASE_URL`
- `OPENTRIPMAP_API_KEY`
- `GOOGLE_PLACES_API_KEY`
- `MIN_RATING` (default `4.0`)
- `ALLOW_POIS_WITHOUT_RATING` (default `false`)
- `CACHE_TTL_DAYS` (default `30`)
- `MIN_POIS_BEFORE_GOOGLE` (default `6`)
- `GOOGLE_PLACES_ENABLED` (default `true`)
- `MAX_GOOGLE_CALLS_PER_DAY` (default `500`)

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

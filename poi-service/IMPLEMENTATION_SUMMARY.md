# Implementace oprav PR #76 - Google API Quota Synchronizace

## Přehled změn

Implementována **Varianta 1: Sdílená databáze** pro synchronizaci Google Places API kvót mezi WordPress a POI microservice.

## Provedené opravy

### 1. ✅ Atomická rezervace kvóty (race condition fix)

**Soubor:** `poi-service/src/quota/wordpressQuota.ts`, `poi-service/src/quota/prismaQuota.ts`

- Implementována atomická operace s MySQL transakcemi a `FOR UPDATE` lock
- Prevence race conditions při souběžných requestech
- Fallback na Prisma PostgreSQL pokud WordPress DB není dostupná

### 2. ✅ Sjednocení kvót s WordPress

**Soubory:** 
- `poi-service/src/config.ts` - přidána konfigurace WordPress MySQL
- `poi-service/src/quota/wordpressQuota.ts` - nový modul pro WordPress quota
- `poi-service/src/quota/index.ts` - unified quota manager

- Výchozí limit změněn z 500 na **300** (sjednoceno s PR #75)
- Používá stejnou tabulku `wp_db_places_usage` jako WordPress
- Automatický fallback na Prisma pokud WordPress DB není nakonfigurována

### 3. ✅ Rezervace kvóty před voláním API

**Soubor:** `poi-service/src/aggregator.ts`

- Kvóta se rezervuje **před** voláním Google API, ne po
- Konzistentní s implementací v PR #75

### 4. ✅ Sjednocení konfiguračních proměnných

**Soubor:** `poi-service/src/config.ts`

- `MAX_GOOGLE_CALLS_PER_DAY` → `MAX_PLACES_REQUESTS_PER_DAY` (sjednoceno s PR #75)
- `GOOGLE_PLACES_ENABLED` → `PLACES_ENRICHMENT_ENABLED` (sjednoceno s PR #75)
- Přidány aliasy pro zpětnou kompatibilitu

### 5. ✅ Error handling pro Google API

**Soubor:** `poi-service/src/providers/googlePlaces.ts`

- Přidáno logování pro HTTP 429 (Rate Limit)
- Přidáno logování pro HTTP 403 (Quota Exceeded)
- Kontrola Google API error status (`OVER_QUERY_LIMIT`, `REQUEST_DENIED`, atd.)

### 6. ✅ Dokumentace

**Soubor:** `poi-service/README.md`

- Přidána sekce o Google Places API Quota Management
- Dokumentace WordPress MySQL synchronizace
- Instrukce pro konfiguraci

## Nové závislosti

- `mysql2` - pro připojení k WordPress MySQL databázi
- `@types/mysql2` - TypeScript typy

## Konfigurace

Pro aktivaci synchronizace s WordPress přidejte do `.env`:

```env
# WordPress MySQL konfigurace (volitelné)
WORDPRESS_DB_HOST=localhost
WORDPRESS_DB_NAME=wordpress_db
WORDPRESS_DB_USER=wp_user
WORDPRESS_DB_PASSWORD=wp_password
WORDPRESS_DB_PREFIX=wp_

# Google Places API limity (sjednoceno s PR #75)
PLACES_ENRICHMENT_ENABLED=true
MAX_PLACES_REQUESTS_PER_DAY=300
```

Pokud WordPress DB není nakonfigurována, microservice automaticky použije PostgreSQL `ApiUsage` tabulku jako fallback.

## Architektura

```
getNearbyPois()
    ↓
reserveGoogleQuota() [ATOMICKÁ OPERACE]
    ↓
    ├─→ WordPress DB (pokud nakonfigurováno)
    │   └─→ wp_db_places_usage tabulka
    │       └─→ SELECT FOR UPDATE + INSERT ... ON DUPLICATE KEY UPDATE
    │
    └─→ Prisma PostgreSQL (fallback)
        └─→ ApiUsage tabulka
            └─→ Prisma transaction s SELECT FOR UPDATE
    ↓
GooglePlacesProvider.searchAround()
```

## Testování

1. **Bez WordPress DB**: Microservice by měl použít Prisma fallback
2. **S WordPress DB**: Kvóty by měly být synchronizované s WordPress
3. **Race condition**: Souběžné requesty by neměly překročit limit
4. **Error handling**: HTTP 429/403 by měly být správně logovány

## Kompatibilita

- ✅ Zpětná kompatibilita zachována (aliasy v CONFIG)
- ✅ Fallback mechanismus pokud WordPress DB není dostupná
- ✅ Žádné breaking changes v API

## Shrnutí

Všechny kritické problémy z review byly opraveny:
- ✅ Race condition opravena (atomické operace)
- ✅ Kvóty synchronizované s WordPress (sdílená databáze)
- ✅ Limity sjednoceny (300 místo 500)
- ✅ Rezervace před voláním API
- ✅ Error handling přidán
- ✅ Dokumentace aktualizována


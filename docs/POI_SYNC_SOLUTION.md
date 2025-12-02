# Řešení synchronizace POI microservice → WordPress

## Přehled

Toto řešení propojuje POI microservice s WordPressem:
1. **Odstranění Google API** z POI microservice (prevence vyčerpání kvót)
2. **Automatická synchronizace** POIs z PostgreSQL do WordPress MySQL
3. **Periodická aktualizace** - jednou za 30 dní zjišťování nových míst

---

## 1. Odstranění Google API z POI microservice

### Změny

- ✅ Odstraněn `GooglePlacesProvider` z `aggregator.ts`
- ✅ Odstraněna logika volání Google API
- ✅ POI microservice používá **pouze free zdroje**:
  - OpenTripMap API
  - Wikidata API
  - Manual (z databáze)

### Důvod

- Riziko vyčerpání Google API kvót
- Free zdroje jsou dostatečné pro většinu případů
- Google API zůstává v WordPressu pro on-demand enrichment

---

## 2. Automatická synchronizace POI → WordPress

### Jak to funguje

1. **POI microservice** stáhne POIs z free zdrojů
2. Uloží do PostgreSQL pomocí `persistIncoming()`
3. **NOVĚ**: Po uložení do PostgreSQL automaticky synchronizuje s WordPressem
4. WordPress vytvoří post type `poi` pro každý POI

### Implementace

#### POI Microservice (`poi-service/src/sync/wordpress.ts`)

```typescript
// Po persistIncoming() se automaticky volá:
await syncPoisToWordPress(newPois, {
  restUrl: CONFIG.wordpressRestUrl,
  restNonce: CONFIG.wordpressRestNonce,
});
```

#### WordPress REST Endpoint (`includes/REST_POI_Sync.php`)

- Endpoint: `POST /wp-json/db/v1/poi-sync`
- Přijímá POI data z microservice
- Vytváří/aktualizuje WordPress post type `poi`
- Deduplikace podle `external_id` nebo GPS + jméno

### Konfigurace

**POI Microservice** (`.env`):
```env
WORDPRESS_REST_URL=https://your-site.com
WORDPRESS_REST_NONCE=your-nonce-here
# Nebo:
WORDPRESS_USERNAME=admin
WORDPRESS_PASSWORD=app-password
```

**WordPress**:
- Endpoint je automaticky registrován
- Pro bezpečnost lze nastavit API key v options: `db_poi_sync_api_key`

---

## 3. Periodická aktualizace (30 dní)

### Jak to funguje

1. **Scheduler** (`poi-service/src/jobs/scheduler.ts`) spouští periodickou aktualizaci
2. Pro každou oblast (grid 50x50 km):
   - Zkontroluje, zda cache není fresh (30 dní)
   - Pokud není fresh → zavolá `getNearbyPois()` s `refresh=true`
   - Stáhne nové POIs z free zdrojů
   - Synchronizuje s WordPressem

### Spuštění

#### Manuálně (CLI):
```bash
cd poi-service
npm run update-periodic
```

#### Automaticky (cron):
```bash
# Přidat do crontab - jednou za 30 dní
0 2 1 * * cd /path/to/poi-service && npm run update-periodic
```

### Konfigurace

**Vlastní oblasti** (`.env`):
```env
# Nebo použít getAreasFromExistingPois() - automaticky vytvoří grid z existujících POIs
```

**Grid velikost**:
- Default: 50 km
- Lze změnit v `scheduler.ts` nebo přes config

---

## Workflow po implementaci

### Scénář 1: Nový POI z free zdrojů

```
1. Volání POI microservice API
   ↓
2. Stáhnutí z OpenTripMap/Wikidata
   ↓
3. Uložení do PostgreSQL
   ↓
4. Automatická synchronizace → WordPress REST API
   ↓
5. Vytvoření WordPress post type 'poi'
   ↓
6. WordPress nearby workflow najde POI v MySQL
```

### Scénář 2: Periodická aktualizace

```
1. Scheduler spustí update-periodic
   ↓
2. Pro každou oblast (grid 50x50 km):
   - Zkontroluje cache (30 dní)
   - Pokud není fresh → refresh=true
   - Stáhne nové POIs z free zdrojů
   - Synchronizuje s WordPressem
   ↓
3. WordPress nearby workflow najde nové POIs
```

---

## Bezpečnost

### WordPress REST API

1. **Nonce verification** - standardní WordPress nonce
2. **API Key** - volitelný API key v options
3. **Basic Auth** - fallback pro externí volání

### Deduplikace

- Podle `external_id` (ID z PostgreSQL)
- Nebo GPS + jméno (50m + 80% podobnost)

---

## Monitoring

### Logy

POI microservice loguje:
- `[WordPress Sync]` - synchronizace s WordPressem
- `[Periodic Update]` - periodická aktualizace
- `[Scheduler]` - scheduler běhy

### WordPress

- Nové POIs se vytváří jako `post_type = 'poi'`
- Meta `_poi_external_id` obsahuje ID z PostgreSQL
- Meta `_poi_source_ids` obsahuje source IDs z providerů

---

## Shrnutí změn

| Komponenta | Změna | Status |
|------------|-------|--------|
| **POI Microservice** | Odstranění Google API | ✅ |
| **POI Microservice** | Přidání WordPress sync | ✅ |
| **POI Microservice** | Přidání periodické aktualizace | ✅ |
| **WordPress** | REST endpoint pro sync | ✅ |
| **WordPress** | Deduplikace POIs | ✅ |

---

## Další kroky

1. ✅ Nastavit `WORDPRESS_REST_URL` v POI microservice `.env`
2. ✅ Nastavit cron job pro periodickou aktualizaci
3. ✅ Otestovat synchronizaci na stagingu
4. ✅ Monitorovat logy pro chyby synchronizace


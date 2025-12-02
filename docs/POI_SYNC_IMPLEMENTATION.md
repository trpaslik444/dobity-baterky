# Implementace synchronizace POI microservice → WordPress

## ✅ Provedené změny

### 1. Odstranění Google API z POI microservice

**Soubor**: `poi-service/src/aggregator.ts`

- ✅ Odstraněn import `GooglePlacesProvider`
- ✅ Odstraněna logika volání Google API
- ✅ POI microservice používá **pouze free zdroje**:
  - OpenTripMap API
  - Wikidata API
  - Manual (z databáze)

**Důvod**: Riziko vyčerpání Google API kvót. Google API zůstává v WordPressu pro on-demand enrichment.

---

### 2. WordPress synchronizace modul

**Soubor**: `poi-service/src/sync/wordpress.ts`

- ✅ Funkce `syncPoiToWordPress()` - synchronizuje jeden POI
- ✅ Funkce `syncPoisToWordPress()` - synchronizuje více POIs po batchi
- ✅ Podpora nonce, Basic Auth, API key

**Integrace**: Automaticky voláno v `persistIncoming()` po vytvoření nových POIs

---

### 3. WordPress REST endpoint

**Soubor**: `includes/REST_POI_Sync.php`

- ✅ Endpoint: `POST /wp-json/db/v1/poi-sync`
- ✅ Přijímá POI data z microservice
- ✅ Vytváří/aktualizuje WordPress post type `poi`
- ✅ Deduplikace podle `external_id` nebo GPS + jméno
- ✅ Bezpečnost: nonce, API key, Basic Auth

**Registrace**: Automaticky v `dobity-baterky.php`

---

### 4. Periodická aktualizace

**Soubory**:
- `poi-service/src/jobs/periodicUpdate.ts` - logika aktualizace
- `poi-service/src/jobs/scheduler.ts` - scheduler a CLI

**Funkce**:
- ✅ Jednou za 30 dní zjišťuje nová místa
- ✅ Automaticky vytváří grid oblastí z existujících POIs
- ✅ Synchronizuje nové POIs s WordPressem
- ✅ CLI příkaz: `npm run update-periodic`

---

### 5. Konfigurace

**Soubor**: `poi-service/src/config.ts`

Přidány nové environment variables:
- `WORDPRESS_REST_URL` - URL WordPress REST API
- `WORDPRESS_REST_NONCE` - WordPress nonce
- `WORDPRESS_USERNAME` - Basic Auth username
- `WORDPRESS_PASSWORD` - Basic Auth password

---

## Workflow po implementaci

### Scénář 1: Nový POI z free zdrojů

```
1. Volání POI microservice API
   GET /api/pois/nearby?lat=50.123&lon=14.456
   ↓
2. Stáhnutí z OpenTripMap/Wikidata
   ↓
3. Uložení do PostgreSQL (persistIncoming)
   ↓
4. Automatická synchronizace → WordPress REST API
   POST /wp-json/db/v1/poi-sync
   ↓
5. Vytvoření WordPress post type 'poi'
   ↓
6. WordPress nearby workflow najde POI v MySQL
   (get_candidates() v Nearby_Recompute_Job.php)
```

### Scénář 2: Periodická aktualizace

```
1. Cron job spustí: npm run update-periodic
   ↓
2. Scheduler vytvoří grid oblastí z existujících POIs
   ↓
3. Pro každou oblast:
   - Zkontroluje cache (30 dní)
   - Pokud není fresh → refresh=true
   - Stáhne nové POIs z free zdrojů
   - Synchronizuje s WordPressem
   ↓
4. WordPress nearby workflow najde nové POIs
```

---

## Nastavení

### POI Microservice (`.env`)

```env
# WordPress REST API
WORDPRESS_REST_URL=https://your-site.com
WORDPRESS_REST_NONCE=your-nonce-here

# Nebo Basic Auth:
WORDPRESS_USERNAME=admin
WORDPRESS_PASSWORD=app-password

# Nebo API Key:
WORDPRESS_API_KEY=your-secret-key
```

### WordPress

**Nastavit API key** (volitelné):
```php
update_option('db_poi_sync_api_key', 'your-secret-key');
```

**Endpoint je automaticky registrován** v `dobity-baterky.php`

---

## Spuštění periodické aktualizace

### Manuálně

```bash
cd poi-service
npm run update-periodic
```

### Automaticky (cron)

```bash
# Přidat do crontab - jednou za 30 dní (1. den v měsíci ve 2:00)
0 2 1 * * cd /path/to/poi-service && npm run update-periodic
```

---

## Testování

### 1. Test synchronizace

```bash
# V POI microservice
curl "http://localhost:3333/api/pois/nearby?lat=50.123&lon=14.456&radius=2000"

# Zkontrolovat WordPress - měl by se vytvořit nový POI post
```

### 2. Test periodické aktualizace

```bash
cd poi-service
npm run update-periodic
```

### 3. Test WordPress endpoint

```bash
curl -X POST "https://your-site.com/wp-json/db/v1/poi-sync" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: your-nonce" \
  -d '{
    "name": "Test POI",
    "lat": 50.123,
    "lon": 14.456,
    "category": "restaurant",
    "external_id": "test-123"
  }'
```

---

## Monitoring

### Logy POI microservice

- `[WordPress Sync]` - synchronizace s WordPressem
- `[Periodic Update]` - periodická aktualizace
- `[Scheduler]` - scheduler běhy

### WordPress

- Nové POIs: `wp_posts` WHERE `post_type = 'poi'`
- Meta `_poi_external_id` - ID z PostgreSQL
- Meta `_poi_source_ids` - source IDs z providerů

---

## Shrnutí

| Komponenta | Změna | Status |
|------------|-------|--------|
| **POI Microservice** | Odstranění Google API | ✅ |
| **POI Microservice** | Přidání WordPress sync | ✅ |
| **POI Microservice** | Přidání periodické aktualizace | ✅ |
| **WordPress** | REST endpoint pro sync | ✅ |
| **WordPress** | Deduplikace POIs | ✅ |

**Výsledek**: POIs z POI microservice se nyní automaticky synchronizují do WordPressu a používají se v nearby workflow!


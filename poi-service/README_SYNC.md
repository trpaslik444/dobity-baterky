# POI Microservice - Synchronizace s WordPressem

## Přehled

POI microservice nyní automaticky synchronizuje POIs z PostgreSQL do WordPress MySQL databáze. Google API bylo odstraněno z microservice (používá se pouze v WordPressu pro on-demand enrichment).

---

## Konfigurace

### 1. WordPress REST API URL

V `.env` souboru POI microservice:

```env
WORDPRESS_REST_URL=https://your-site.com
WORDPRESS_REST_NONCE=your-nonce-here
```

**Nebo** použít Basic Auth:

```env
WORDPRESS_REST_URL=https://your-site.com
WORDPRESS_USERNAME=admin
WORDPRESS_PASSWORD=app-password
```

### 2. WordPress API Key (volitelné)

V WordPressu nastavit API key pro bezpečnost:

```php
update_option('db_poi_sync_api_key', 'your-secret-key');
```

Pak v POI microservice `.env`:

```env
WORDPRESS_REST_URL=https://your-site.com
WORDPRESS_API_KEY=your-secret-key
```

---

## Automatická synchronizace

POIs se automaticky synchronizují s WordPressem při:

1. **Volání POI microservice API** - nové POIs se synchronizují okamžitě
2. **Periodické aktualizaci** - jednou za 30 dní

### Workflow

```
1. POI microservice stáhne POIs z free zdrojů
   ↓
2. Uloží do PostgreSQL
   ↓
3. Automaticky zavolá WordPress REST API
   ↓
4. WordPress vytvoří post type 'poi'
   ↓
5. WordPress nearby workflow najde POI v MySQL
```

---

## Periodická aktualizace

### Spuštění manuálně

```bash
cd poi-service
npm run update-periodic
```

### Spuštění automaticky (cron)

Přidat do crontab:

```bash
# Jednou za 30 dní (1. den v měsíci ve 2:00)
0 2 1 * * cd /path/to/poi-service && npm run update-periodic
```

### Konfigurace oblastí

Periodická aktualizace automaticky vytvoří grid oblastí z existujících POIs v databázi (default: 50x50 km grid).

**Vlastní oblasti** (`.env`):

```env
# Lze upravit v scheduler.ts nebo přidat vlastní config
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

## Troubleshooting

### Synchronizace nefunguje

1. Zkontrolovat `WORDPRESS_REST_URL` v `.env`
2. Zkontrolovat WordPress REST API endpoint: `/wp-json/db/v1/poi-sync`
3. Zkontrolovat logy v POI microservice

### Periodická aktualizace neběží

1. Zkontrolovat cron job
2. Zkontrolovat logy: `npm run update-periodic`
3. Zkontrolovat, zda existují POIs v databázi pro vytvoření gridu


# Průvodce optimalizací produkčního prostředí

## Problémy identifikované v produkčních logách

### 1. Výkonnostní problémy
- **Pomalé API požadavky**: `/wp-json/db/v1/nearby/worker` trvá až 13.2 sekund
- **Pomalé mapové požadavky**: POI a charging_location požadavky trvají 5-15 sekund
- **Cron joby**: `wp-cron.php` trvá až 15.5 sekund

### 2. PHP chyby a varování
- **Rate limiting**: "Skipped X error log messages due to rate limiting" - systém je přetížen
- **Konstanty**: `WP_DEBUG_LOG` a `WP_DEBUG_DISPLAY` jsou definovány vícekrát
- **Plugin inicializace**: Plugin se inicializuje vícekrát současně

### 3. HTTP chyby
- **403 Forbidden**: Help-center API endpointy vracejí 403
- **Pomalé odpovědi**: Mnoho požadavků trvá přes 5 sekund

## Implementované optimalizace

### 1. Databázové optimalizace
- **Nové indexy**: Vytvořeny indexy pro `postmeta` tabulku na lat/lng klíče
- **Optimalizované SQL dotazy**: Přidán bounding box pro rychlejší geografické dotazy
- **Cache tabulka**: Vytvořena specializovaná tabulka pro nearby cache

### 2. Caching systém
- **Response caching**: API odpovědi se cachují na 2-5 minut
- **Query caching**: Databázové dotazy se cachují na 5 minut
- **Cache invalidation**: Automatické vymazání starého cache

### 3. Rate limiting
- **Worker rate limiting**: Maximálně 1 požadavek za 30 sekund
- **Duplicitní inicializace**: Zabráněno vícenásobnému spuštění pluginu
- **PHP warnings**: Opraveny duplicitní definice konstant

## Instrukce pro nasazení

### 1. Spuštění optimalizace
```bash
# Na produkčním serveru
cd /path/to/wordpress/wp-content/plugins/dobity-baterky
php optimize-production.php
```

### 2. Vytvoření databázových indexů
```bash
# Přes WP-CLI
wp db optimize indexes
```

### 3. Monitoring výkonu
```bash
# Zobrazení statistik
wp db optimize stats

# Vyčištění starého cache
wp db optimize cleanup
```

### 4. Kontrola logů
```bash
# Sledování PHP logů
tail -f /path/to/wordpress/wp-content/debug.log

# Sledování web logů
tail -f /var/log/nginx/access.log
```

## Očekávané zlepšení výkonu

### Před optimalizací
- Nearby API: 13.2 sekund
- Mapové požadavky: 5-15 sekund
- Rate limiting chyby: Časté

### Po optimalizaci
- Nearby API: < 2 sekundy (s cache < 0.1 sekundy)
- Mapové požadavky: < 3 sekundy
- Rate limiting chyby: Minimalizovány

## Monitoring a údržba

### 1. Pravidelné úkoly
- **Týdně**: `wp db optimize cleanup` - vyčištění starého cache
- **Měsíčně**: `wp db optimize stats` - kontrola statistik
- **Podle potřeby**: `wp cache flush` - vymazání všech cache

### 2. Alerting
- Sledujte response time pro `/wp-json/db/v1/nearby*` endpointy
- Monitorujte PHP error logy pro rate limiting chyby
- Kontrolujte databázové dotazy přes 5 sekund

### 3. Troubleshooting
- Pokud se výkon zhorší: Spusťte `php optimize-production.php`
- Pokud cache nefunguje: Zkontrolujte `wp_cache_*` funkce
- Pokud indexy chybí: Spusťte `wp db optimize indexes`

## Technické detaily

### Nové soubory
- `includes/Database_Optimizer.php` - Databázové optimalizace
- `includes/CLI/Database_Optimizer_Command.php` - WP-CLI příkazy
- `optimize-production.php` - Script pro optimalizaci

### Upravené soubory
- `includes/Jobs/Nearby_Recompute_Job.php` - Optimalizované SQL dotazy
- `includes/REST_Nearby.php` - Response caching
- `includes/Jobs/Nearby_Worker.php` - Rate limiting
- `dobity-baterky.php` - Duplicitní inicializace

### Cache klíče
- `db_candidates_*` - Cache pro kandidáty (5 minut)
- `db_nearby_response_*` - Cache pro API odpovědi (2 minuty)
- `db_nearby_worker_rate_limit` - Rate limiting (30 sekund)

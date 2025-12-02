# Testování POI synchronizace

## Přehled

Tento dokument popisuje, jak otestovat novou funkcionalitu POI synchronizace mezi POI microservice a WordPressem.

---

## Předpoklady

### 1. POI Microservice běží

```bash
cd poi-service
npm install
npm run dev
# Nebo
npm run build && npm start
```

**Ověření**: 
```bash
curl http://localhost:3333/api/pois/nearby?lat=50.0755&lon=14.4378&radius=2000
```

Mělo by vrátit JSON s POIs.

### 2. WordPress konfigurace

**Nastavit URL POI microservice**:

**Možnost A - Admin rozhraní**:
1. Přejít na `Tools > POI Microservice`
2. Nastavit URL: `http://localhost:3333` (nebo produkční URL)
3. Uložit

**Možnost B - wp-config.php**:
```php
define('DB_POI_SERVICE_URL', 'http://localhost:3333');
```

**Možnost C - WordPress options**:
```php
update_option('db_poi_service_url', 'http://localhost:3333');
```

---

## Testovací scénáře

### Test 1: Základní synchronizace POIs

**Cíl**: Ověřit, že WordPress dokáže získat POIs z microservice a vytvořit posty.

**Kroky**:
1. Otevřít WordPress admin
2. Přejít na `Tools > POI Microservice`
3. Kliknout na "Testovat připojení"
4. Ověřit, že se zobrazí úspěšná zpráva s počtem POIs

**Očekávaný výsledek**:
- ✅ Zobrazí se: "Úspěšně připojeno! Nalezeno X POIs."
- ✅ V databázi se vytvoří WordPress posty typu `poi`
- ✅ Statistiky se aktualizují

**Kontrola v databázi**:
```sql
SELECT COUNT(*) FROM wp_posts WHERE post_type = 'poi';
SELECT * FROM wp_posts WHERE post_type = 'poi' ORDER BY post_date DESC LIMIT 10;
```

---

### Test 2: Automatická synchronizace při nearby výpočtu

**Cíl**: Ověřit, že se POIs synchronizují automaticky při volání `get_candidates()`.

**Kroky**:
1. Vytvořit nebo otevřít existující nabíječku v WordPressu
2. Kliknout na bod na mapě (frontend)
3. Otevřít WordPress debug logy

**Očekávaný výsledek**:
- ✅ V logu se objeví: `[POI Sync] Synced POIs from microservice`
- ✅ POIs se vytvoří v WordPressu
- ✅ Nearby výpočet najde tyto POIs

**Kontrola logů**:
```php
// V wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Logy: `wp-content/debug.log`

**Hledat**:
```
[POI Sync] Synced POIs from microservice
```

---

### Test 3: Retry logika při nedostupnosti microservice

**Cíl**: Ověřit, že retry logika funguje při selhání API.

**Kroky**:
1. Zastavit POI microservice (`Ctrl+C`)
2. V WordPress admin kliknout na "Testovat připojení"
3. Spustit POI microservice znovu
4. Zkusit test znovu

**Očekávaný výsledek**:
- ✅ První pokus selže (microservice není dostupný)
- ✅ Retry logika zkusí znovu (exponential backoff: 1s, 2s, 4s)
- ✅ Pokud microservice běží, druhý/pátý pokus uspěje

**Kontrola**:
- V logu by měly být vidět retry pokusy
- Admin rozhraní by mělo zobrazit chybu nebo úspěch

---

### Test 4: Cache prevence race conditions

**Cíl**: Ověřit, že cache zabraňuje duplicitním API callům.

**Kroky**:
1. Vytvořit nabíječku s GPS souřadnicemi (např. Praha: 50.0755, 14.4378)
2. Otevřít WordPress debug logy
3. Kliknout na bod na mapě (frontend) - **vícekrát rychle za sebou**
4. Zkontrolovat logy

**Očekávaný výsledek**:
- ✅ První request zavolá POI microservice API
- ✅ Další requesty (do 5 minut) použijí cache
- ✅ V logu: `[POI Sync] Using cached sync result`

**Kontrola**:
```sql
-- Zkontrolovat, že se POIs nevytváří duplicitně
SELECT post_title, COUNT(*) as count 
FROM wp_posts 
WHERE post_type = 'poi' 
GROUP BY post_title 
HAVING count > 1;
```

---

### Test 5: Validace dat

**Cíl**: Ověřit, že nevalidní data jsou odmítnuta.

**Kroky**:
1. Upravit POI microservice API response (simulovat nevalidní data)
2. Nebo vytvořit testovací POI s nevalidními daty
3. Zkusit synchronizaci

**Testovací data**:
```json
{
  "pois": [
    {
      "name": "Test POI",
      "lat": 999,  // Nevalidní GPS
      "lon": 14.4378
    },
    {
      "name": "",  // Prázdný název
      "lat": 50.0755,
      "lon": 14.4378
    },
    {
      "name": "Valid POI",
      "lat": 50.0755,
      "lon": 14.4378,
      "rating": 10  // Nevalidní rating (> 5)
    }
  ]
}
```

**Očekávaný výsledek**:
- ✅ Nevalidní POIs jsou odmítnuty
- ✅ V logu: `[POI Microservice Client] Invalid GPS coordinates`
- ✅ V logu: `[POI Microservice Client] Invalid name`
- ✅ V logu: `[POI Microservice Client] Invalid rating`
- ✅ Pouze validní POIs se vytvoří

---

### Test 6: Deduplikace POIs

**Cíl**: Ověřit, že duplicitní POIs se správně deduplikují.

**Kroky**:
1. Synchronizovat POIs pro oblast (např. Praha)
2. Znovu synchronizovat stejnou oblast
3. Zkontrolovat, že se POIs nezdvojují

**Očekávaný výsledek**:
- ✅ POIs se deduplikují podle `external_id` nebo GPS + jméno
- ✅ Existující POIs se aktualizují místo vytvoření nových
- ✅ V logu: `[POI Sync] Synced POIs from microservice` s `synced` a `failed` počty

**Kontrola**:
```sql
-- Zkontrolovat duplicity podle GPS
SELECT 
    pm_lat.meta_value as lat,
    pm_lng.meta_value as lon,
    p.post_title,
    COUNT(*) as count
FROM wp_posts p
INNER JOIN wp_postmeta pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = '_poi_lat'
INNER JOIN wp_postmeta pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = '_poi_lng'
WHERE p.post_type = 'poi'
GROUP BY pm_lat.meta_value, pm_lng.meta_value, p.post_title
HAVING count > 1;
```

---

### Test 7: Statistiky synchronizace

**Cíl**: Ověřit, že statistiky se správně aktualizují.

**Kroky**:
1. Přejít na `Tools > POI Microservice`
2. Zkontrolovat sekci "Statistiky synchronizace"
3. Provedout několik synchronizací
4. Zkontrolovat, že se statistiky aktualizují

**Očekávaný výsledek**:
- ✅ "Celkem synchronizováno POIs" se zvyšuje
- ✅ "Poslední synchronizace" se aktualizuje
- ✅ "Celkem selhalo" se zvyšuje při chybách

**Kontrola**:
```php
$stats = get_option('db_poi_sync_stats');
var_dump($stats);
```

---

### Test 8: Admin rozhraní - konfigurace

**Cíl**: Ověřit, že admin rozhraní funguje správně.

**Kroky**:
1. Přejít na `Tools > POI Microservice`
2. Změnit URL na neplatnou (např. `invalid-url`)
3. Uložit
4. Ověřit, že se zobrazí chybová zpráva
5. Změnit URL zpět na platnou
6. Změnit timeout na 60 sekund
7. Změnit max retries na 5
8. Uložit

**Očekávaný výsledek**:
- ✅ Neplatná URL je odmítnuta s chybovou zprávou
- ✅ Platná URL je uložena
- ✅ Timeout a retries jsou uloženy
- ✅ Změny se projeví v dalších API callách

**Kontrola**:
```php
echo get_option('db_poi_service_url');
echo get_option('db_poi_service_timeout');
echo get_option('db_poi_service_max_retries');
```

---

### Test 9: Konstanta DB_POI_SERVICE_URL

**Cíl**: Ověřit, že konstanta v `wp-config.php` má prioritu.

**Kroky**:
1. Přidat do `wp-config.php`:
   ```php
   define('DB_POI_SERVICE_URL', 'http://localhost:3333');
   ```
2. Přejít na `Tools > POI Microservice`
3. Zkontrolovat, že se zobrazí poznámka o konstantě
4. Zkontrolovat, že URL field je disabled nebo zobrazuje konstantu

**Očekávaný výsledek**:
- ✅ Zobrazí se: "URL je nastaveno pomocí konstanty DB_POI_SERVICE_URL"
- ✅ URL z options je ignorováno
- ✅ Používá se hodnota z konstanty

---

### Test 10: Nearby workflow s POIs

**Cíl**: Ověřit kompletní workflow - od synchronizace po nearby výpočet.

**Kroky**:
1. Vytvořit novou nabíječku v WordPressu (např. Praha: 50.0755, 14.4378)
2. Otevřít mapu na frontendu
3. Kliknout na nabíječku
4. Zkontrolovat, že se zobrazí nearby POIs

**Očekávaný výsledek**:
- ✅ POIs se synchronizují z microservice
- ✅ WordPress najde POIs v MySQL
- ✅ ORS API vypočítá walking distances
- ✅ Nearby POIs se zobrazí na mapě

**Kontrola**:
```sql
-- Zkontrolovat nearby cache
SELECT post_id, meta_key, meta_value 
FROM wp_postmeta 
WHERE meta_key = '_db_nearby_cache_poi_foot' 
ORDER BY meta_id DESC 
LIMIT 1;
```

---

## Debugging

### Zapnout debug logy

**wp-config.php**:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Logy**: `wp-content/debug.log`

### Hledat v logu

```bash
# POI synchronizace
grep "POI Sync" wp-content/debug.log

# POI microservice client
grep "POI Microservice Client" wp-content/debug.log

# Chyby
grep "ERROR\|Error\|error" wp-content/debug.log
```

### WordPress REST API test

```bash
# Test POI microservice API přímo
curl "http://localhost:3333/api/pois/nearby?lat=50.0755&lon=14.4378&radius=2000&minCount=10"

# Test WordPress synchronizace (pokud je REST endpoint)
curl -X POST "http://your-site.com/wp-json/db/v1/poi-sync" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "name": "Test POI",
    "lat": 50.0755,
    "lon": 14.4378,
    "category": "restaurant"
  }'
```

### SQL dotazy pro kontrolu

```sql
-- Počet POIs v WordPressu
SELECT COUNT(*) FROM wp_posts WHERE post_type = 'poi';

-- Poslední vytvořené POIs
SELECT p.ID, p.post_title, 
       pm_lat.meta_value as lat,
       pm_lng.meta_value as lon,
       pm_ext.meta_value as external_id
FROM wp_posts p
LEFT JOIN wp_postmeta pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = '_poi_lat'
LEFT JOIN wp_postmeta pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = '_poi_lng'
LEFT JOIN wp_postmeta pm_ext ON pm_ext.post_id = p.ID AND pm_ext.meta_key = '_poi_external_id'
WHERE p.post_type = 'poi'
ORDER BY p.post_date DESC
LIMIT 20;

-- Statistiky synchronizace
SELECT option_value FROM wp_options WHERE option_name = 'db_poi_sync_stats';

-- Cache synchronizace
SELECT option_name, option_value 
FROM wp_options 
WHERE option_name LIKE '_transient_poi_sync_%';
```

---

## Troubleshooting

### POI microservice není dostupný

**Příznaky**:
- Admin test připojení selže
- V logu: `POI microservice failed after 3 attempts`

**Řešení**:
1. Zkontrolovat, že POI microservice běží: `curl http://localhost:3333/api/pois/nearby?lat=50.0755&lon=14.4378`
2. Zkontrolovat URL v admin rozhraní
3. Zkontrolovat firewall/network

### POIs se nesynchronizují

**Příznaky**:
- Test připojení uspěje, ale POIs se nevytváří
- V logu: `[POI Sync] Failed to sync POIs`

**Řešení**:
1. Zkontrolovat WordPress logy pro chyby
2. Zkontrolovat, zda má uživatel oprávnění `manage_options`
3. Zkontrolovat, zda existuje post type `poi`

### Duplicitní POIs

**Příznaky**:
- Stejný POI se vytváří vícekrát

**Řešení**:
1. Zkontrolovat deduplikaci podle `external_id`
2. Zkontrolovat deduplikaci podle GPS + jméno
3. Zkontrolovat cache (možná je cache disabled)

### Cache nefunguje

**Příznaky**:
- Každý request volá API
- V logu není `[POI Sync] Using cached sync result`

**Řešení**:
1. Zkontrolovat, zda jsou transients povoleny
2. Zkontrolovat cache key
3. Zkontrolovat cache duration (5 minut)

---

## Checklist před nasazením na produkci

- [ ] POI microservice běží a je dostupný
- [ ] WordPress URL je správně nastaveno (produkční URL)
- [ ] Test připojení v admin rozhraní uspěje
- [ ] Statistiky se aktualizují
- [ ] POIs se synchronizují při nearby výpočtu
- [ ] Deduplikace funguje
- [ ] Cache funguje (prevence race conditions)
- [ ] Retry logika funguje při selhání
- [ ] Validace dat funguje
- [ ] Debug logy jsou vypnuté na produkci

---

## Testovací data

### Praha (50.0755, 14.4378)
- Radius: 2000m
- Očekávané POIs: restaurace, kavárny, atrakce

### Brno (49.1951, 16.6068)
- Radius: 2000m
- Očekávané POIs: restaurace, kavárny, atrakce

### Testovací endpoint
```bash
# Praha
curl "http://localhost:3333/api/pois/nearby?lat=50.0755&lon=14.4378&radius=2000&minCount=10"

# Brno
curl "http://localhost:3333/api/pois/nearby?lat=49.1951&lon=16.6068&radius=2000&minCount=10"
```


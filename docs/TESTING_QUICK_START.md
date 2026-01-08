# Rychlý start - Testování POI synchronizace

## 🚀 Rychlý test (5 minut)

### 1. Spustit POI microservice

```bash
cd poi-service
npm install
npm run dev
```

**Ověření**:
```bash
curl http://localhost:3333/api/pois/nearby?lat=50.0755&lon=14.4378&radius=2000
```

Mělo by vrátit JSON s POIs.

---

### 2. Nastavit URL v WordPressu

**Možnost A - Admin**:
1. `Tools > POI Microservice`
2. Nastavit URL: `http://localhost:3333`
3. Kliknout "Uložit změny"

**Možnost B - wp-config.php**:
```php
define('DB_POI_SERVICE_URL', 'http://localhost:3333');
```

---

### 3. Test připojení

1. V admin rozhraní (`Tools > POI Microservice`)
2. Kliknout "Testovat připojení"
3. ✅ Mělo by se zobrazit: "Úspěšně připojeno! Nalezeno X POIs."

---

### 4. Test synchronizace (WP-CLI)

Testování synchronizace lze provést pomocí:
- Admin rozhraní: `Tools > POI Microservice > Testovat připojení`
- WP-CLI: `wp db poi sync --lat=50.0755 --lon=14.4378` (pokud je příkaz dostupný)

**Očekávaný výstup**:
```
=== POI Synchronizace Test ===

Test 1: Základní připojení k POI microservice
✅ Úspěšně připojeno! Nalezeno 10 POIs
   Providers: db, opentripmap, wikidata

Test 2: Synchronizace POIs do WordPressu
✅ Synchronizace úspěšná!
   Synchronizováno: 10
   Selhalo: 0
   Celkem: 10
   Providers: db, opentripmap, wikidata

Test 3: Kontrola vytvořených POIs v WordPressu
   Celkem POIs v WordPressu: 10
   Posledních 5 POIs:
   - Kavárna U Stromu (50.0755, 14.4378)
   - Restaurace U Zlatého Lva (50.0760, 14.4380)
   ...

Test 4: Statistiky synchronizace
   Celkem synchronizováno: 10
   Celkem selhalo: 0
   Poslední synchronizace: 2025-01-20 12:00:00

Test 5: Konfigurace
   URL: http://localhost:3333
   Timeout: 30s
   Max retries: 3

Test 6: Cache synchronizace
   ✅ Cache aktivní (5 minut)

=== Test dokončen ===
```

---

### 5. Test v reálném workflow

1. Vytvořit nebo otevřít nabíječku v WordPressu
2. Nastavit GPS souřadnice (např. Praha: 50.0755, 14.4378)
3. Otevřít mapu na frontendu
4. Kliknout na nabíječku
5. ✅ Měly by se zobrazit nearby POIs

---

## 🔍 Kontrola v databázi

```sql
-- Počet POIs
SELECT COUNT(*) FROM wp_posts WHERE post_type = 'poi';

-- Poslední POIs
SELECT p.ID, p.post_title, 
       pm_lat.meta_value as lat,
       pm_lng.meta_value as lon
FROM wp_posts p
LEFT JOIN wp_postmeta pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = '_poi_lat'
LEFT JOIN wp_postmeta pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = '_poi_lng'
WHERE p.post_type = 'poi'
ORDER BY p.post_date DESC
LIMIT 10;
```

---

## ⚠️ Časté problémy

### POI microservice není dostupný

**Řešení**:
```bash
# Zkontrolovat, že běží
curl http://localhost:3333/api/pois/nearby?lat=50.0755&lon=14.4378

# Pokud neběží, spustit
cd poi-service
npm run dev
```

### WordPress nemůže připojit k microservice

**Řešení**:
1. Zkontrolovat URL v admin rozhraní
2. Zkontrolovat firewall/network
3. Zkontrolovat, že microservice běží na správném portu

### POIs se nevytváří

**Řešení**:
1. Zapnout debug logy (`WP_DEBUG = true`)
2. Zkontrolovat `wp-content/debug.log`
3. Hledat: `[POI Sync]` nebo `[POI Microservice Client]`

---

## 📊 Monitoring

**Admin rozhraní**: `Tools > POI Microservice > Statistiky synchronizace`

**WP-CLI**:
```bash
wp option get db_poi_sync_stats
```

**PHP**:
```php
$stats = get_option('db_poi_sync_stats');
var_dump($stats);
```

---

## ✅ Checklist

- [ ] POI microservice běží
- [ ] URL je nastaveno v WordPressu
- [ ] Test připojení uspěje
- [ ] POIs se synchronizují
- [ ] Statistiky se aktualizují
- [ ] Nearby workflow funguje

---

Více detailů: `docs/TESTING_POI_SYNC.md`


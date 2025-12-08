# Opravy problémů po PR #82 - Mobilní verze PWA

## Analýza problémů

### 1. Fallback ikony na mobilu místo definovaných ikon

**Problém:**
V mobilní verzi PWA se zobrazovaly fallback ikony (🔌, 🚐, 📍) místo definovaných SVG ikon nebo ikon z `icon_slug`.

**Příčina:**
Funkce `getTypeIcon` v `assets/db-map.min.js` kontrolovala `featureCache` pouze pro `charging_location`, ne pro POI. Když se nearby POI načítaly v režimu "db doporučuje", neměly správně nastavené `svg_content` nebo `icon_slug` v `props`, a protože se nekontrolovala `featureCache` pro POI, použil se fallback.

**Oprava:**
- Přidána kontrola `featureCache` pro všechny typy bodů (POI, RV, charging_location)
- Kontrola probíhá před použitím fallback ikon
- Nearby POI se nyní ukládají do `featureCache` při jejich vytvoření v `buildSpecialNearbyDataset` a `buildSpecialNearbyDatasetCached`

**Soubory:**
- `assets/db-map.min.js` - oprava funkce `getTypeIcon`
- `assets/map/core.js` - přidání ukládání do `featureCache` v `buildSpecialNearbyDataset` a `buildSpecialNearbyDatasetCached`

### 2. Nearby POI se nezobrazují při filtru "db doporučuje"

**Problém:**
Když se aktivuje filtr "db doporučuje", zobrazí se pouze charging locations, ale nearby POI se nezobrazují.

**Příčina:**
Nearby POI se načítají přes `buildSpecialNearbyDatasetCached`, ale neukládaly se do `featureCache`, takže při renderování markerů nebo mobilního sheetu nebyly dostupné jejich ikony a metadata.

**Oprava:**
- Nearby POI se nyní ukládají do `featureCache` při jejich vytvoření
- Charging features se také ukládají do `featureCache` při načtení z special endpointu
- Zajištěno, že všechny nearby POI mají správně nastavené `nearby_of` vztahy k charging locations

**Soubory:**
- `assets/map/core.js` - přidání ukládání do `featureCache` v `buildSpecialNearbyDataset`, `buildSpecialNearbyDatasetCached` a při načtení charging features

### 3. Dlouhé načítání bodů na mapu

**Analýza HAR souboru:**
- Celkem 202 requests
- Celkový čas: 18.6s
- Průměrný čas requestu: 92ms
- Nejdelší request: 1767ms (hlavní HTML stránka)

**Pozorování:**
- V HAR souboru nejsou vidět žádné API volání na `/wp-json/db/v1/map` nebo `/wp-json/db/v1/map/special`
- To znamená, že buď:
  1. Data jsou vložená přímo do HTML (server-side rendering)
  2. API volání probíhají později (lazy loading)
  3. HAR soubor nezachytil všechna volání

**Co se fetchuje při načtení mapy:**
1. **Počáteční načtení:**
   - Pokud jsou aktivní speciální filtry (`db doporučuje` nebo `zdarma`):
     - Volání `/wp-json/db/v1/map/special` s parametry `db_recommended=1` nebo `free=1`
     - Pro každou charging location se volá `/wp-json/db/v1/nearby` (s cache, concurrency 4)
   - Pokud nejsou aktivní speciální filtry:
     - Volání `/wp-json/db/v1/map` s parametry `lat`, `lng`, `radius`, `included=charging_location,rv_spot,poi`

2. **On-demand načítání (radius mode):**
   - Fetch se spustí pouze po kliknutí na tlačítko "Načíst další"
   - Volání `/wp-json/db/v1/map` s parametry `lat`, `lng`, `radius`

**Optimalizace:**
- Cache na serveru: 45 sekund pro map endpoint, 10 minut pro special endpoint
- Cache na klientovi: 15 minut pro special dataset (localStorage)
- Nearby POI cache: per charging location ID (frontend cache)
- Concurrency limit: 4 paralelní volání pro nearby POI

**Doporučení pro další optimalizaci:**
1. Zvážit zvýšení cache TTL pro special endpoint (aktuálně 10 minut)
2. Implementovat progressive loading - nejdřív zobrazit charging locations, pak postupně načítat nearby POI
3. Zvážit server-side rendering počátečních dat do HTML pro rychlejší první render
4. Implementovat request deduplication - pokud probíhá fetch pro stejné parametry, počkat na výsledek místo nového volání

## Shrnutí oprav

### Provedené změny:

1. **Oprava fallback ikon na mobilu:**
   - ✅ Přidána kontrola `featureCache` pro všechny typy bodů v `getTypeIcon`
   - ✅ Ukládání nearby POI do `featureCache` při jejich vytvoření

2. **Oprava zobrazování nearby POI při filtru "db doporučuje":**
   - ✅ Ukládání nearby POI do `featureCache` v `buildSpecialNearbyDataset` a `buildSpecialNearbyDatasetCached`
   - ✅ Ukládání charging features do `featureCache` při načtení z special endpointu

3. **Analýza výkonu:**
   - ✅ Analýza HAR souboru
   - ✅ Identifikace co vše se fetchuje
   - ✅ Dokumentace optimalizací

### Testování:

1. **Fallback ikony:**
   - Otevřít mobilní PWA
   - Aktivovat filtr "db doporučuje"
   - Kliknout na charging location
   - Zkontrolovat, že nearby POI mají správné ikony (ne fallback)

2. **Nearby POI při filtru "db doporučuje":**
   - Aktivovat filtr "db doporučuje"
   - Zkontrolovat, že se zobrazují nearby POI k doporučeným charging locations
   - Zkontrolovat, že nearby POI mají správné ikony

3. **Výkon:**
   - Otevřít Network tab v DevTools
   - Načíst mapu s filtrem "db doporučuje"
   - Zkontrolovat počet a čas API volání
   - Ověřit, že cache funguje správně

## Související soubory

- `assets/db-map.min.js` - mobilní sheet s ikonami
- `assets/map/core.js` - hlavní logika mapy a načítání dat
- `includes/REST_Map.php` - map endpoint
- `includes/REST_Nearby.php` - nearby endpoint


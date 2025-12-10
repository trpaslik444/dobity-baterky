# PR #88: Fix startup fetch, detail modal, manifest 404, pinghub WS errors

## 📋 Přehled

Tento PR řeší několik kritických problémů s načítáním mapy, detail modalu a chybami v konzoli:

1. **Startup fetch** - zajištění vždy spuštění i když `map.once('load')` nepřijde
2. **Detail modal** - okamžité otevření s minimálními daty, async načítání detailu
3. **Manifest 404** - odstranění odkazů na neexistující screenshoty
4. **Pinghub WS chyby** - potlačení WordPress.com websocket chyb
5. **Detail endpoint 404** - oprava base URL
6. **POI ikony** - oprava zobrazení ikon v REST payloadu
7. **Externí vyhledávání** - přidání User-Agent headerů, invalidace cache při 403
8. **Ondemand 403** - tichý return při 403/401

## 🔧 Změny

### 1. Startup fetch (`initialDataLoad()`)

**Problém:** Mapa zůstávala prázdná po reloadu, pokud `map.once('load')` event nepřijde.

**Řešení:**
- Přidán debounce flag `initialDataLoadRunning`
- Fallback pro `map.getCenter()` null → použije defaultní centrum [50.08, 14.44] nebo geolokaci
- Vylepšená logika spuštění s `tryInitialDataLoad()` a retry mechanismem
- Zachovány speciální filtry (free/recommended) s `fetchAll()`

### 2. Detail modal (`openDetailModal()`)

**Problém:** Modal se otevíral až po načtení detailu (latence).

**Řešení:**
- Modal se otevírá okamžitě s minimálními daty z `feature.properties`
- Detail fetch probíhá async v pozadí (neblokuje UI)
- Aktualizace modalu po dokončení fetchu s debounce (100ms)
- Flag `isUpdatingModal` zabraňuje rekurzi

### 3. Detail fetch (`fetchFeatureDetail()`)

**Problém:** Chybějící timeout, 404 chyby způsobovaly uncaught errors.

**Řešení:**
- Timeout 4s s AbortController
- Tiché zpracování 404/500 chyb (logování jen v debug módu)
- Oprava base URL - odstranění `/map` z konce (`/wp-json/db/v1/map-detail/...` místo `/wp-json/db/v1/map/map-detail/...`)

### 4. Manifest 404

**Problém:** Manifest odkazoval na neexistující screenshoty → 404 chyby.

**Řešení:**
- Odstraněny screenshoty z `manifest.json`
- Manifest nyní obsahuje pouze existující ikony

### 5. Pinghub WS chyby

**Problém:** WordPress.com pinghub websocket chyby znečišťovaly konzoli.

**Řešení:**
- Globální error handler s konkrétním URL patternem (`wss://public-api.wordpress.com/pinghub`)
- Potlačení v `console.error`, `console.warn`, `window.error`, `unhandledrejection`
- Rozšířená kontrola pro `pinghub`, `wpcom` patterny
- Logování jen v debug módu (`dbMapData.debug`)
- Guard pro console override (zabránění konfliktům s jinými knihovnami)

### 6. POI ikony v REST payloadu

**Problém:** POI ikony se nezobrazovaly - `validateIconSlug()` zahazovala `poi_type-*` slugy.

**Řešení:**
- Opravena `validateIconSlug()` v `Icon_Registry.php` - povoleny `poi_type-*` a `rv_type-*` slugy
- V minimal payload vždy vrátit `svg_content` pro POI pokud dostupné
- POI často nemají `icon_slug`, ale mají `svg_content` z term meta

### 7. Externí vyhledávání (Nominatim)

**Problém:** Nominatim vracel 403 bez User-Agent hlaviček.

**Řešení:**
- Přidány hlavičky `User-Agent` a `Referer` k Nominatim fetch
- Invalidace cache při 403 (neukládá se prázdná cache, fetchnuje se znovu)
- 403 chyby logovat jen v debug módu

### 8. Ondemand 403

**Problém:** 403 na `/wp-json/db/v1/ondemand/process` způsobovaly chyby v konzoli.

**Řešení:**
- Tichý return při 403/401 - uživatel bez oprávnění nevidí chyby
- Logování jen v debug módu

### 9. Search klik "nic nedělá"

**Problém:** Klik ve vyhledávání nefungoval pokud `lastAutocompleteResults` bylo prázdné.

**Řešení:**
- Přidán guard v `doSearch()` - fetchnout autocomplete pokud `lastAutocompleteResults` je null/prázdné
- Po fetchi použít první výsledek a zavolat `handleInternalSelection`/`handleExternalSelection`

## 🧪 Testovací scénáře

### ✅ Test 1: Startup fetch po reloadu
1. Načíst stránku s mapou
2. Ověřit, že se markery načtou do 1-2s (mini dataset)
3. Ověřit, že se plný dataset načte do 3-5s
4. **Očekávaný výsledek:** ✅ Mapa není prázdná

### ✅ Test 2: Detail modal okamžité otevření
1. Kliknout na pin na mapě
2. Ověřit, že modal se otevře okamžitě (< 100ms)
3. Ověřit, že detail se doplní v pozadí
4. **Očekávaný výsledek:** ✅ Modal otevřen okamžitě, detail doplněn později

### ✅ Test 3: Detail endpoint 404
1. Kliknout na pin s neexistujícím ID
2. Ověřit, že modal se otevře s minimálními daty
3. Ověřit, že v konzoli není uncaught error
4. **Očekávaný výsledek:** ✅ Modal otevřen, žádné chyby v konzoli

### ✅ Test 4: Manifest 404
1. Načíst stránku a otevřít Network tab
2. Ověřit, že není 404 na `/wp-content/uploads/pwa/db-screenshot-*.png`
3. **Očekávaný výsledek:** ✅ Žádné 404 na screenshoty

### ✅ Test 5: Pinghub WS chyby
1. Načíst stránku na WordPress.com
2. Otevřít konzoli
3. Ověřit, že pinghub websocket chyby jsou potlačené
4. **Očekávaný výsledek:** ✅ Čistá konzole (max debug logy)

### ✅ Test 6: POI ikony
1. Načíst mapu s POI body
2. Ověřit, že POI piny mají ikony
3. **Očekávaný výsledek:** ✅ POI ikony se zobrazují

### ✅ Test 7: Externí vyhledávání
1. Zadat adresu do vyhledávání
2. Ověřit, že externí výsledky se načtou (nebo jsou prázdné bez 403)
3. Kliknout na výsledek
4. **Očekávaný výsledek:** ✅ Centrování a fetch radius funguje

### ✅ Test 8: Search klik
1. Zadat query do vyhledávání
2. Kliknout na Enter nebo tlačítko
3. Ověřit, že se mapa centruje a fetchnou data
4. **Očekávaný výsledek:** ✅ Klik funguje, centrování a fetch proběhne

## 📊 Metriky změn

- **Soubory změněny:** 3
  - `assets/map/core.js` (+350 řádků, -80 řádků)
  - `assets/manifest.json` (-14 řádků)
  - `includes/Icon_Registry.php` (+5 řádků, -5 řádků)
  - `includes/REST_Map.php` (+15 řádků, -5 řádků)
- **Celkem změn:** +370 řádků, -104 řádků
- **Nové funkce:** 3
  - `isPinghubOrWebsocketError()` - helper pro detekci WS chyb
  - `tryInitialDataLoad()` - robustní inicializace
  - `updateModalWithDetail()` - aktualizace modalu po fetchu

## ✅ Checklist

- [x] Startup fetch funguje i když `map.once('load')` nepřijde
- [x] Detail modal se otevírá okamžitě
- [x] Detail endpoint nevrátí 404
- [x] Manifest neobsahuje neexistující screenshoty
- [x] Pinghub WS chyby jsou potlačené
- [x] POI ikony se zobrazují
- [x] Externí vyhledávání má User-Agent hlavičky
- [x] Ondemand 403 je tichý
- [x] Search klik funguje
- [x] Konzole je čistá (max debug logy)

## 🔗 Související

- Fixuje problémy z PR #87 review
- Navazuje na PR #86 (search refactoring)

## 📝 Poznámky

- Fallback center používá geolokaci z `LocationService` pokud dostupná, pak Praha [50.08, 14.44]
- Detail fetch má timeout 4s s AbortController
- Modal aktualizace má debounce 100ms pro zabránění flickeringu
- Externí vyhledávání cache se invaliduje při 403 (neukládá se prázdná cache)


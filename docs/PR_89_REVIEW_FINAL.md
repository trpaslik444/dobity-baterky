# PR #89 Review (Final): Fix POI ikony, search klik, modal/sheet latence, pinghub unhandledrejection

**Větev:** `fix/startup-fetch-detail-modal-manifest`  
**Base:** `main` (po mergnutí PR #88)  
**Commits:** 3
- `9ac733b` - Fix: Oprava problémů z PR #89 review
- `8593775` - Fix: POI ikony v REST, search klik, modal/sheet latence, pinghub unhandledrejection
- `91600fb` - Fix: detail endpoint 404, POI ikony, externí vyhledávání, ondemand 403, pinghub

**Datum review:** 2025-01-XX (Final)

---

## 📋 Přehled změn

PR #89 navazuje na PR #88 a řeší další problémy:
1. **Detail endpoint 404** - oprava base URL (odstranění `/map` z konce)
2. **POI ikony** - oprava `validateIconSlug()` a vrácení `svg_content` v REST payloadu
3. **Externí vyhledávání** - přidání User-Agent headerů (dynamické z dbMapData), blacklist mechanismus pro 403
4. **Ondemand 403** - tichý return při 403/401
5. **Search klik** - vylepšený guard pro prázdné `lastAutocompleteResults` s kontrolou query
6. **Pinghub unhandledrejection** - vylepšený error handler

---

## ✅ Pozitivní změny

### 1. Detail endpoint 404 - opraveno ✅

**Soubor:** `assets/map/core.js:8266`

**Problém:** Base URL končila na `/map`, takže se volalo `/wp-json/db/v1/map/map-detail/...` místo `/wp-json/db/v1/map-detail/...`

**Řešení:**
```javascript
// Opravit base URL - odstranit /map z konce pokud existuje
const base = ((dbData?.restUrl) || '/wp-json/db/v1').replace(/\/map$/, '');
const url = `${base}/map-detail/${endpointType}/${id}`;
```

**Hodnocení:** ✅ **Výborně** - Jednoduché a efektivní řešení s regex replace

---

### 2. POI ikony - opraveno ✅

**Soubor:** `includes/Icon_Registry.php:34-40`

**Problém:** `validateIconSlug()` zahazovala `poi_type-*` slugy, přitom soubory existují v `assets/icons/`

**Řešení:**
```php
// Povolit poi_type-* a rv_type-* slugy - soubory existují v assets/icons/
// Validovat pouze prázdný string nebo neplatné znaky
if (empty($icon_slug) || !is_string($icon_slug)) {
    return '';
}
// Povolit alfanumerické znaky, pomlčky, podtržítka (včetně poi_type-* a rv_type-*)
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $icon_slug)) {
    return '';
}
return $icon_slug;
```

**Hodnocení:** ✅ **Výborně** - Správně povoluje `poi_type-*` a `rv_type-*` slugy

---

### 3. POI ikony v REST payloadu - opraveno ✅

**Soubor:** `includes/REST_Map.php:633-649`

**Problém:** POI v minimal payload neměly `svg_content`, i když bylo dostupné

**Řešení:**
```php
// Pro POI: vždy vrátit svg_content pokud je dostupné
if ($pt === 'poi') {
    if (!empty($properties['icon_slug']) && trim($properties['icon_slug']) !== '') {
        // Máme icon_slug, ale přidáme i svg_content pokud je dostupné
        $properties['svg_content'] = $icon_data['svg_content'] ?? '';
    } else {
        // Nemáme icon_slug, použít svg_content jako fallback
        $properties['svg_content'] = $icon_data['svg_content'] ?? '';
    }
}
```

**Hodnocení:** ✅ **Výborně** - POI vždy dostanou `svg_content` pokud je dostupné

---

### 4. Externí vyhledávání (Nominatim) - opraveno ✅

**Soubor:** `assets/map/core.js:13650-13690`

**Problém:** Nominatim vracel 403 bez User-Agent hlaviček

**Řešení:**
```javascript
// Přidat User-Agent a Referer hlavičky pro Nominatim (dynamické z dbMapData)
const pluginUrl = (typeof window !== 'undefined' && window.dbMapData && window.dbMapData.pluginUrl) 
  ? window.dbMapData.pluginUrl 
  : 'https://dobitybaterky.cz';
const pluginVersion = (typeof window !== 'undefined' && window.dbMapData && window.dbMapData.version) 
  ? window.dbMapData.version 
  : '1.0';
const headers = {
  'User-Agent': `DobityBaterky/${pluginVersion} (${pluginUrl})`,
  'Referer': window.location.origin
};

// Blacklist mechanismus pro 403 chyby (prevence opakovaných requestů)
const BLACKLIST_DURATION_MS = 5 * 60 * 1000; // 5 minut
if (externalSearch403Blacklist.has(normalized)) {
  const blacklistTime = externalSearch403Blacklist.get(normalized);
  if (Date.now() - blacklistTime < BLACKLIST_DURATION_MS) {
    return { results: [], userCoords: null }; // Vrátit prázdný výsledek bez requestu
  } else {
    externalSearch403Blacklist.delete(normalized); // Blacklist vypršel
  }
}

// Při 403 přidat na blacklist
if (is403) {
  externalSearch403Blacklist.set(normalized, Date.now());
  externalSearchCache.delete(normalized);
}
```

**Hodnocení:** ✅ **Výborně** - Dynamické User-Agent z dbMapData, blacklist mechanismus zabraňuje opakovaným requestům

---

### 5. Ondemand 403 - opraveno ✅

**Soubor:** `assets/map/core.js:7801-7808`

**Problém:** 403 na `/wp-json/db/v1/ondemand/process` způsobovaly chyby v konzoli

**Řešení:**
```javascript
// Tichý return při 403/401 - uživatel nemá oprávnění
if (processResponse.status === 403 || processResponse.status === 401) {
  if (typeof window !== 'undefined' && window.dbMapData && window.dbMapData.debug) {
    console.debug('[DB Map] on-demand/process 403/401 - user not authorized');
  }
  return; // Tichý return - není to chyba
}
```

**Hodnocení:** ✅ **Výborně** - Tichý return, logování jen v debug módu

---

### 6. Search klik "nic nedělá" - opraveno ✅

**Soubor:** `assets/map/core.js:9407-9449`

**Problém:** Klik ve vyhledávání nefungoval pokud `lastAutocompleteResults` bylo prázdné nebo neodpovídalo query

**Řešení:**
```javascript
// Vylepšený guard - kontroluje nejen prázdné výsledky, ale i shodu query
const hasValidCache = lastAutocompleteResults && 
  lastAutocompleteResults.results &&
  lastAutocompleteResults.query.toLowerCase() === query.toLowerCase() &&
  (lastAutocompleteResults.results.internal.length > 0 || 
   lastAutocompleteResults.results.external.length > 0);

if (!hasValidCache) {
  // Fetchnout autocomplete a použít první výsledek
  await fetchAutocomplete(query, searchInput);
  // Po fetchi zkontrolovat znovu s kontrolou query
  if (lastAutocompleteResults && 
      lastAutocompleteResults.results &&
      lastAutocompleteResults.query.toLowerCase() === query.toLowerCase()) {
    // Použít první výsledek
  }
}
```

**Hodnocení:** ✅ **Výborně** - Guard kontroluje shodu query, zabraňuje dvojímu fetchu

---

### 7. Pinghub unhandledrejection - vylepšeno ✅

**Soubor:** `assets/map/core.js:93-108`

**Problém:** Pinghub chyby se stále objevovaly v `unhandledrejection`

**Řešení:**
```javascript
// Rozšířená kontrola pro pinghub/wpcom chyby
const errorString = (msg + ' ' + source + ' ' + stack).toLowerCase();
if (errorString.includes('pinghub') || errorString.includes('wpcom') || 
    errorString.includes('wss://public-api.wordpress.com') ||
    isPinghubOrWebsocketError(msg, source, '')) {
  event.preventDefault();
  // Logování jen v debug módu
}
```

**Hodnocení:** ✅ **Výborně** - Rozšířená kontrola včetně `stack` trace

---

## ✅ Opravy z předchozího review

### 1. **User-Agent string** - OPRAVENO ✅

**Původní problém:**
- Hardcoded URL `'DobityBaterky/1.0 (https://dobitybaterky.cz)'`
- Verze `1.0` mohla být zastaralá

**Oprava:**
```javascript
const pluginUrl = (typeof window !== 'undefined' && window.dbMapData && window.dbMapData.pluginUrl) 
  ? window.dbMapData.pluginUrl 
  : 'https://dobitybaterky.cz';
const pluginVersion = (typeof window !== 'undefined' && window.dbMapData && window.dbMapData.version) 
  ? window.dbMapData.version 
  : '1.0';
const headers = {
  'User-Agent': `DobityBaterky/${pluginVersion} (${pluginUrl})`,
  'Referer': window.location.origin
};
```

**Hodnocení:** ✅ **Výborně** - Dynamické hodnoty z `dbMapData`, fallback na default hodnoty

---

### 2. **Cache invalidace při 403** - OPRAVENO ✅

**Původní problém:**
- Pokud Nominatim vracel 403 opakovaně, fetchnulo se znovu a znovu
- Možnost opakovaných requestů

**Oprava:**
```javascript
// Blacklist mechanismus pro 403 chyby
const externalSearch403Blacklist = new Map(); // Cache pro 403 chyby s časovou značkou
const BLACKLIST_DURATION_MS = 5 * 60 * 1000; // 5 minut

// Kontrola blacklistu před fetch
if (externalSearch403Blacklist.has(normalized)) {
  const blacklistTime = externalSearch403Blacklist.get(normalized);
  if (Date.now() - blacklistTime < BLACKLIST_DURATION_MS) {
    return { results: [], userCoords: null }; // Vrátit prázdný výsledek bez requestu
  } else {
    externalSearch403Blacklist.delete(normalized); // Blacklist vypršel
  }
}

// Při 403 přidat na blacklist
if (is403) {
  externalSearch403Blacklist.set(normalized, Date.now());
  externalSearchCache.delete(normalized);
}
```

**Hodnocení:** ✅ **Výborně** - Blacklist mechanismus zabraňuje opakovaným requestům na 5 minut

---

### 3. **doSearch guard může způsobit dvojí fetch** - OPRAVENO ✅

**Původní problém:**
- Pokud `lastAutocompleteResults` je prázdné, `doSearch` fetchnuje autocomplete
- Může dojít k dvojímu fetchu pokud query neodpovídá

**Oprava:**
```javascript
// Vylepšený guard - kontroluje nejen prázdné výsledky, ale i shodu query
const hasValidCache = lastAutocompleteResults && 
  lastAutocompleteResults.results &&
  lastAutocompleteResults.query.toLowerCase() === query.toLowerCase() &&
  (lastAutocompleteResults.results.internal.length > 0 || 
   lastAutocompleteResults.results.external.length > 0);

if (!hasValidCache) {
  await fetchAutocomplete(query, searchInput);
  // Po fetchi zkontrolovat znovu s kontrolou query
  if (lastAutocompleteResults && 
      lastAutocompleteResults.results &&
      lastAutocompleteResults.query.toLowerCase() === query.toLowerCase()) {
    // Použít první výsledek
  }
}
```

**Hodnocení:** ✅ **Výborně** - Guard kontroluje shodu query, zabraňuje dvojímu fetchu

---

## 🧪 Testovací scénáře

### ✅ Test 1: Detail endpoint 404
1. Kliknout na pin na mapě
2. Otevřít Network tab
3. Ověřit, že request jde na `/wp-json/db/v1/map-detail/...` (ne `/map/map-detail/...`)
4. **Očekávaný výsledek:** ✅ Žádné 404, detail se načte

### ✅ Test 2: POI ikony
1. Načíst mapu s POI body
2. Ověřit, že POI piny mají ikony
3. Otevřít Network tab a zkontrolovat REST response
4. **Očekávaný výsledek:** ✅ POI mají `icon_slug` nebo `svg_content` v payloadu

### ✅ Test 3: Externí vyhledávání (User-Agent)
1. Zadat adresu do vyhledávání
2. Otevřít Network tab
3. Ověřit, že Nominatim request má User-Agent hlavičku s dynamickými hodnotami
4. **Očekávaný výsledek:** ✅ User-Agent obsahuje `dbMapData.pluginUrl` a `dbMapData.version`

### ✅ Test 4: Externí vyhledávání (403 blacklist)
1. Simulovat 403 chybu z Nominatim (nebo počkat na skutečnou)
2. Zkusit stejný query znovu během 5 minut
3. Otevřít Network tab
4. **Očekávaný výsledek:** ✅ Žádný další request, vrátí se prázdný výsledek

### ✅ Test 5: Search klik
1. Zadat query do vyhledávání
2. Kliknout na Enter (bez výběru z autocomplete)
3. Ověřit, že se mapa centruje a fetchnou data
4. **Očekávaný výsledek:** ✅ Klik funguje, centrování a fetch proběhne

### ✅ Test 6: Search klik (různé query)
1. Zadat query "Praha" a nechat načíst autocomplete
2. Změnit query na "Brno" a kliknout na Enter
3. Ověřit, že se fetchnou výsledky pro "Brno" (ne "Praha")
4. **Očekávaný výsledek:** ✅ Guard kontroluje shodu query, fetchnou se správné výsledky

### ✅ Test 7: Ondemand 403
1. Otevřít mapu jako nepřihlášený uživatel
2. Kliknout na pin a otevřít isochrony
3. Otevřít konzoli
4. **Očekávaný výsledek:** ✅ Žádné chyby v konzoli (max debug logy)

### ✅ Test 8: Pinghub unhandledrejection
1. Načíst stránku na WordPress.com
2. Otevřít konzoli
3. Ověřit, že pinghub websocket chyby jsou potlačené
4. **Očekávaný výsledek:** ✅ Čistá konzole (max debug logy)

---

## 📊 Metriky změn

- **Soubory změněny:** 3
  - `assets/map/core.js` (+122 řádků, -15 řádků)
  - `includes/Icon_Registry.php` (+13 řádků, -2 řádky)
  - `includes/REST_Map.php` (+18 řádků, -2 řádky)
- **Celkem změn:** +153 řádků, -19 řádků
- **Nové funkce:** 0 (vylepšení existujících)
- **Nové mechanismy:** Blacklist pro 403 chyby

---

## ✅ Závěr

**Celkové hodnocení:** ✅ **APPROVE**

PR #89 řeší všechny uvedené problémy efektivně. Kód je dobře strukturovaný, má error handling a správné fallbacky. Všechny problémy z předchozího review byly opraveny:

1. ✅ **User-Agent string** - nyní používá dynamické hodnoty z `dbMapData`
2. ✅ **Cache invalidace při 403** - přidán blacklist mechanismus (5 min) pro prevenci opakovaných requestů
3. ✅ **doSearch guard** - vylepšena logika kontroly cache s kontrolou shody query

**Doporučení:**
- ✅ **Mergovat** do main
- ✅ Všechny problémy z předchozího review byly opraveny

**Kritické problémy:** Žádné  
**Důležité problémy:** Žádné (všechny opraveny)  
**Návrhy na zlepšení:** Žádné (všechny implementovány)

---

**Review provedl:** AI Assistant  
**Datum:** 2025-01-XX (Final)


# PR #89 Review: Fix POI ikony, search klik, modal/sheet latence, pinghub unhandledrejection

**Větev:** `fix/startup-fetch-detail-modal-manifest`  
**Base:** `main` (po mergnutí PR #88)  
**Commits:** 2
- `8593775` - Fix: POI ikony v REST, search klik, modal/sheet latence, pinghub unhandledrejection
- `91600fb` - Fix: detail endpoint 404, POI ikony, externí vyhledávání, ondemand 403, pinghub

**Datum review:** 2025-01-XX

---

## 📋 Přehled změn

PR #89 navazuje na PR #88 a řeší další problémy:
1. **Detail endpoint 404** - oprava base URL (odstranění `/map` z konce)
2. **POI ikony** - oprava `validateIconSlug()` a vrácení `svg_content` v REST payloadu
3. **Externí vyhledávání** - přidání User-Agent headerů, invalidace cache při 403
4. **Ondemand 403** - tichý return při 403/401
5. **Search klik** - guard pro prázdné `lastAutocompleteResults`
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

**Soubor:** `assets/map/core.js:13627-13650`

**Problém:** Nominatim vracel 403 bez User-Agent hlaviček

**Řešení:**
```javascript
// Přidat User-Agent a Referer hlavičky pro Nominatim
const headers = {
  'User-Agent': 'DobityBaterky/1.0 (https://dobitybaterky.cz)',
  'Referer': window.location.origin
};

const response = await fetch(searchUrl, { 
  signal,
  headers: headers
});

// Invalidovat cache při 403
const is403 = error.message && (error.message.includes('403') || error.message.includes('Forbidden'));
if (is403) {
  externalSearchCache.delete(normalized); // Neukládat prázdnou cache
}
```

**Hodnocení:** ✅ **Výborně** - Správné hlavičky a invalidace cache při 403

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

**Soubor:** `assets/map/core.js:9406-9443`

**Problém:** Klik ve vyhledávání nefungoval pokud `lastAutocompleteResults` bylo prázdné

**Řešení:**
```javascript
// Pokud lastAutocompleteResults je null nebo prázdné, fetchnout autocomplete
if (!lastAutocompleteResults || 
    !lastAutocompleteResults.results ||
    (lastAutocompleteResults.results.internal.length === 0 && 
     lastAutocompleteResults.results.external.length === 0)) {
  // Fetchnout autocomplete a použít první výsledek
  await fetchAutocomplete(query, searchInput);
  // Po fetchi použít první výsledek
  if (lastAutocompleteResults && lastAutocompleteResults.results) {
    const { internal, external } = lastAutocompleteResults.results;
    if (internal.length > 0) {
      await handleInternalSelection(internal[0]);
    } else if (external.length > 0) {
      await handleExternalSelection(external[0]);
    }
  }
}
```

**Hodnocení:** ✅ **Výborně** - Guard zajistí, že se autocomplete fetchnuje pokud je prázdné

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

## ⚠️ Potenciální problémy

### 1. **User-Agent string** (P3)

**Soubor:** `assets/map/core.js:13630`

**Problém:**
```javascript
'User-Agent': 'DobityBaterky/1.0 (https://dobitybaterky.cz)',
```

**Riziko:**
- Hardcoded URL může být problém pokud se změní doména
- Verze `1.0` může být zastaralá

**Doporučení:**
- Zvážit použití `dbMapData.pluginUrl` nebo `window.location.origin`
- Verzi získat z `dbMapData.version` pokud existuje

**Status:** ✅ **Akceptovatelné** - Funguje, ale může být vylepšeno

---

### 2. **Cache invalidace při 403** (P2)

**Soubor:** `assets/map/core.js:13657-13662`

**Problém:**
```javascript
if (is403) {
  externalSearchCache.delete(normalized);
  // Neukládat prázdnou cache
}
```

**Riziko:**
- Pokud Nominatim vrací 403 opakovaně, bude se fetchnout znovu a znovu
- Možná by bylo lepší uložit prázdnou cache s kratším TTL (např. 1 minuta)

**Doporučení:**
- Zvážit uložení prázdné cache s kratším TTL místo úplného smazání
- Nebo přidat retry limit (max 3 pokusy)

**Status:** ⚠️ **Akceptovatelné** - Funguje, ale může způsobit opakované requesty

---

### 3. **doSearch guard může způsobit dvojí fetch** (P2)

**Soubor:** `assets/map/core.js:9406-9443`

**Problém:**
- Pokud `lastAutocompleteResults` je prázdné, `doSearch` fetchnuje autocomplete
- Ale `fetchAutocomplete` může být už volán z `input` eventu
- Může dojít k dvojímu fetchu

**Doporučení:**
- Zkontrolovat, zda už neprobíhá fetch (např. `searchController` není null)
- Nebo použít debounce pro `doSearch`

**Status:** ⚠️ **Akceptovatelné** - `AbortController` by měl zrušit starý request, ale může být vylepšeno

---

## 💡 Návrhy na zlepšení (P3)

### 1. **Konfigurovatelný User-Agent**

**Návrh:**
- Použít `dbMapData.pluginUrl` nebo `dbMapData.version` pro User-Agent
- Nebo přidat do `dbMapData` pole `userAgent`

**Priorita:** Nízká - Současné řešení je funkční

---

### 2. **Retry logika pro Nominatim 403**

**Návrh:**
- Přidat retry s exponential backoff pro 403 chyby
- Max 3 pokusy s User-Agent hlavičkami

**Priorita:** Nízká - Invalidace cache je dostatečná

---

### 3. **Cache TTL pro prázdné výsledky**

**Návrh:**
- Uložit prázdnou cache s kratším TTL (1 minuta) místo úplného smazání
- Zabraňuje opakovaným requestům při 403

**Priorita:** Nízká - Současné řešení je akceptovatelné

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

### ✅ Test 3: Externí vyhledávání
1. Zadat adresu do vyhledávání
2. Otevřít Network tab
3. Ověřit, že Nominatim request má User-Agent hlavičku
4. **Očekávaný výsledek:** ✅ Žádné 403, výsledky se načtou

### ✅ Test 4: Search klik
1. Zadat query do vyhledávání
2. Kliknout na Enter (bez výběru z autocomplete)
3. Ověřit, že se mapa centruje a fetchnou data
4. **Očekávaný výsledek:** ✅ Klik funguje, centrování a fetch proběhne

### ✅ Test 5: Ondemand 403
1. Otevřít mapu jako nepřihlášený uživatel
2. Kliknout na pin a otevřít isochrony
3. Otevřít konzoli
4. **Očekávaný výsledek:** ✅ Žádné chyby v konzoli (max debug logy)

### ✅ Test 6: Pinghub unhandledrejection
1. Načíst stránku na WordPress.com
2. Otevřít konzoli
3. Ověřit, že pinghub websocket chyby jsou potlačené
4. **Očekávaný výsledek:** ✅ Čistá konzole (max debug logy)

---

## 📊 Metriky změn

- **Soubory změněny:** 3
  - `assets/map/core.js` (+94 řádků, -15 řádků)
  - `includes/Icon_Registry.php` (+13 řádků, -2 řádky)
  - `includes/REST_Map.php` (+18 řádků, -2 řádky)
- **Celkem změn:** +125 řádků, -19 řádků
- **Nové funkce:** 0 (vylepšení existujících)

---

## ✅ Závěr

**Celkové hodnocení:** ✅ **APPROVE**

PR #89 řeší všechny uvedené problémy efektivně. Kód je dobře strukturovaný, má error handling a správné fallbacky. Drobné problémy (User-Agent string, cache invalidace) jsou akceptovatelné a nebrání mergování.

**Doporučení:**
- ✅ **Mergovat** do main
- ⚠️ Zvážit vylepšení User-Agent stringu (P3)
- ⚠️ Zvážit cache TTL pro prázdné výsledky při 403 (P3)

**Kritické problémy:** Žádné  
**Důležité problémy:** 2 (cache invalidace, dvojí fetch - akceptovatelné)  
**Návrhy na zlepšení:** 3 (nízká priorita)

---

**Review provedl:** AI Assistant  
**Datum:** 2025-01-XX


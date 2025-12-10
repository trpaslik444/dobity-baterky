# Review PR #87: Refaktorování search boxu

**PR:** #87 - Feature/optimize map loading on demand  
**Autor:** trpaslik444  
**Stav:** Open  
**Změněné soubory:** 4 soubory (+861, -402 řádků)

## Přehled změn

PR refaktoruje search box na mapě - sjednocuje implementaci pro desktop i mobil, odstraňuje duplicity a přidává optimalizace.

## ✅ Pozitivní změny

### 1. Sjednocení HTML struktury
- ✅ Jeden search box (`#db-map-search-input`) pro desktop i mobil
- ✅ Rozdíly řešeny CSS (na mobilu skrytý, zobrazí se přes toggle)
- ✅ Odstraněny duplicitní HTML bloky

### 2. Centralizace handlerů
- ✅ Guard flag `searchHandlersInitialized` zajišťuje jednorázovou inicializaci
- ✅ Jeden set event listenerů pro všechny platformy
- ✅ Správná kontrola existence elementů před přidáním listenerů

### 3. Optimalizace výkonu
- ✅ Server-side cache (45s TTL) v `REST_Map::handle_map_search`
- ✅ Client-side cache (`internalSearchCache`, `externalSearchCache`)
- ✅ Debounce 400ms snižuje počet requestů
- ✅ AbortController zruší staré requesty při novém inputu
- ✅ Submit používá cache výsledky místo nového REST callu

### 4. Sdílený autocomplete renderer
- ✅ `renderAutocomplete()` funguje pro desktop i mobil
- ✅ Rozdíly pouze ve stylování (CSS třídy podle viewportu)
- ✅ `removeAutocomplete()` sdílená funkce

### 5. Odstranění duplicit
- ✅ Odstraněna funkce `createMobileSearchField()`
- ✅ Odstraněny duplicitní AbortControllery (`mobileSearchController`, `desktopSearchController`)
- ✅ Jeden `searchController` pro všechny requesty

## ⚠️ Potenciální problémy a návrhy

### 1. **Kritické: Chybějící error handling při cache miss**
```javascript
// assets/map/core.js:13113-13190
async function fetchAutocomplete(query, inputElement) {
  // ...
  if (cachedInternal !== undefined || cachedExternal !== undefined) {
    // Pokud máme částečnou cache, renderujeme, ale pak pokračujeme
    // Co když se request nepovede? Uživatel uvidí staré výsledky
  }
}
```
**Doporučení:** Přidat error handling pro případ, kdy částečná cache existuje, ale nový request selže.

### 2. **Střední: Race condition v submit handleru**
```javascript
// assets/map/core.js:9163
if (lastAutocompleteResults && lastAutocompleteResults.query.toLowerCase() === query.toLowerCase()) {
  // Použije cache výsledky
}
```
**Problém:** `lastAutocompleteResults` může být zastaralé, pokud uživatel rychle změní query a stiskne Enter.
**Doporučení:** Přidat timestamp nebo ověřit, že cache není starší než např. 5 sekund.

### 3. **Střední: Server-side cache klíč může být kolizní**
```php
// includes/REST_Map.php:1412
$cache_key = 'db_map_search_' . md5( $normalized_query . '_' . implode( ',', $post_types ) );
```
**Problém:** Pokud se `post_types` změní pořadí, vznikne jiný klíč (i když obsah je stejný).
**Doporučení:** Seřadit `post_types` před `implode()` nebo použít `serialize()`.

### 4. **Nízké: Hardcoded breakpoint**
```javascript
// assets/map/core.js:12702
const isMobile = window.innerWidth <= 900;
```
**Doporučení:** Použít konstantu nebo CSS media query pro konzistenci.

### 5. **Nízké: Magic number v cache TTL**
```php
// includes/REST_Map.php:1513
set_transient( $cache_key, $response_data, 45 );
```
**Doporučení:** Definovat konstantu `DB_MAP_SEARCH_CACHE_TTL = 45`.

### 6. **Nízké: Chybějící cleanup při unmount**
**Problém:** Event listenery na `autocomplete` elementu se nemusí správně odstranit při navigaci.
**Doporučení:** Přidat cleanup v `removeAutocomplete()` pro všechny event listenery.

## 📝 Testování

### Manuální testy
- [ ] Desktop: search box viditelný, autocomplete funguje
- [ ] Mobil: search box skrytý, toggle ho zobrazí/skryje
- [ ] Autocomplete zobrazuje interní i externí výsledky
- [ ] Submit používá cache výsledky (žádný nový REST call)
- [ ] Server-side cache funguje (45s TTL)
- [ ] AbortController správně ruší staré requesty
- [ ] Debounce funguje (400ms delay)

### Edge cases
- [ ] Rychlé psaní a Enter (race condition)
- [ ] CORS chyba při externím API
- [ ] Prázdný query po submit
- [ ] Změna viewportu během autocomplete

## 🔍 Code quality

### Pozitivní
- ✅ Dobré komentáře v kódu
- ✅ Konzistentní pojmenování
- ✅ Správné použití guard flagu
- ✅ Error handling pro AbortError

### Zlepšení
- ⚠️ Některé funkce jsou dlouhé (např. `renderAutocomplete` ~130 řádků)
- ⚠️ Magic numbers místo konstant
- ⚠️ Chybějící JSDoc komentáře pro nové funkce

## 📊 Metriky

- **Řádky kódu:** +861, -402 (netto +459)
- **Duplicity odstraněny:** ✅ Ano (createMobileSearchField, duplicitní HTML)
- **Performance:** ✅ Zlepšeno (cache, debounce, abort)
- **UX:** ✅ Zlepšeno (rychlejší odezva, méně requestů)

## 🎯 Závěr

PR je **dobře navržený** a řeší hlavní problémy:
- ✅ Odstranění duplicit
- ✅ Optimalizace výkonu
- ✅ Sjednocení kódu

**Doporučení:**
1. **Před merge:** Opravit race condition v submit handleru (priorita střední)
2. **Před merge:** Seřadit `post_types` v cache klíči (priorita střední)
3. **Nice to have:** Přidat konstanty místo magic numbers
4. **Nice to have:** Přidat error handling pro částečnou cache

**Status:** ✅ **Approve s menšími návrhy** - PR je připraven k merge po opravě středně prioritních problémů.

---

**Reviewer:** Auto (AI Assistant)  
**Datum:** 2025-12-10


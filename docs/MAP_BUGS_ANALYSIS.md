# Analýza bugů a logických nesrovnalostí v mapových procesech

**Datum analýzy:** 2025-01-27  
**Soubor:** `assets/map/core.js`  
**Problém:** U lokality Pyšely se po kliknutí na "Načíst místa v okolí" body na vteřinu vykreslí, ale cca 90% jich hned zmizí.

---

## Přehled nalezených chyb

### 🔴 KRITICKÉ CHYBY (vysoká pravděpodobnost příčiny "Pyšely bug")

#### 1. **Automatické načítání filtrů z localStorage při prvním volání renderCards**

**Umístění:** `10430:10453:assets/map/core.js`

**Problém:**
```javascript
if (!window.__db_filters_loaded__ && 
    filterState.powerMin === 0 && filterState.powerMax === 400 && 
    filterState.connectors.size === 0 && filterState.amenities.size === 0 && 
    filterState.access.size === 0) {
  loadFilterSettings(); // Načte filtry z localStorage
  window.__db_filters_loaded__ = true;
}
```

**Dopad:**
- Při prvním volání `renderCards()` (např. po kliknutí na "Načíst místa v okolí") se automaticky načtou uložené filtry z localStorage
- Pokud uživatel měl dříve aktivní filtry (např. powerMin > 0, connectors, providers), tyto se aplikují okamžitě
- **Výsledek:** Body se nejdřív vykreslí (mini-fetch bez filtrů), pak se aplikují filtry z localStorage a 90% bodů zmizí

**Riziko:** 🔴 VYSOKÉ - přímo způsobuje popsaný bug

**Fix návrh:**
```javascript
// Načítat filtry z localStorage pouze při explicitní user akci (změna filtru v UI)
// NEBO: Načítat filtry před prvním fetch, ne až při renderCards
// NEBO: Ignorovat uložené filtry do prvního explicitního user inputu
```

---

#### 2. **Progressive loading: mini-fetch renderuje bez filtrů, full-fetch renderuje s filtry**

**Umístění:** `2856:2858:assets/map/core.js` a `2934:2936:assets/map/core.js`

**Problém:**
```javascript
// Mini-fetch (řádek 2856-2858)
features = incoming; // Data bez filtrů (nebo s filtry, které se načtou až v renderCards)
renderCards('', null, false); // Renderuje vše

// Full-fetch (řádek 2934-2936)  
features = incoming; // Stejná data, ale teď už jsou filtry načtené z localStorage
renderCards('', null, false); // Renderuje s filtry → zmizí 90% bodů
```

**Dopad:**
1. Mini-fetch dokončí → renderuje všechny body (filtry ještě nejsou načtené)
2. `renderCards()` se volá → načte filtry z localStorage → aplikuje je → některé body zmizí
3. Full-fetch dokončí → renderuje znovu → aplikuje filtry → další body zmizí

**Riziko:** 🔴 VYSOKÉ - přímo způsobuje popsaný bug

**Fix návrh:**
```javascript
// Načíst filtry PŘED fetch, ne až v renderCards
// NEBO: Neaplikovat filtry z localStorage při prvním renderu po fetch
// NEBO: Použít flag "userHasInteractedWithFilters" a ignorovat localStorage do prvního user inputu
```

---

#### 3. **Power filtr zahazuje body s power=0 (neznámý výkon)**

**Umístění:** `10544:10546:assets/map/core.js`

**Problém:**
```javascript
const maxKw = getStationMaxKw(p);
if (maxKw < filterState.powerMin || maxKw > filterState.powerMax) {
  return false; // Zahodí body s power=0, pokud powerMin > 0
}
```

**Dopad:**
- `getStationMaxKw()` vrací `0` pro body bez výkonu (řádek 5236: `return maxKw || 0;`)
- Pokud je `filterState.powerMin > 0` (např. z localStorage), všechny body s neznámým výkonem (power=0) jsou zahazeny
- **Výsledek:** Mnoho stanic zmizí, i když by měly být zobrazeny

**Riziko:** 🔴 VYSOKÉ - může způsobit zmizení velkého množství bodů

**Fix návrh:**
```javascript
// Neaplikovat powerMin filtr na body s power=0 (neznámý výkon)
const maxKw = getStationMaxKw(p);
if (maxKw === 0) {
  // Neznámý výkon - zobrazit vždy (nebo aplikovat jinou logiku)
  return true;
}
if (maxKw < filterState.powerMin || maxKw > filterState.powerMax) {
  return false;
}
```

---

### 🟡 STŘEDNÍ CHYBY (mohou přispívat k problému)

#### 4. **Filtry amenities a access se posílají do API, ale nikdy se nepoužívají v renderCards**

**Umístění:** 
- Posílání do API: `2699:2702:assets/map/core.js`
- Použití v renderCards: **NENÍ** (hledáno v celém souboru)

**Problém:**
```javascript
// buildRestUrlForRadius - posílá amenities do API
if (filterState.amenities && filterState.amenities.size > 0) {
  const amenitiesArray = Array.from(filterState.amenities);
  url.searchParams.set('amenities', amenitiesArray.join(','));
}

// renderCards - NIKDE se nefiltruje podle amenities
// Stejně pro access - posílá se do localStorage, ale nikde se nepoužívá
```

**Dopad:**
- Filtry amenities a access se ukládají do localStorage
- Posílají se do API (server může filtrovat)
- Ale v `renderCards()` se nikdy neaplikují na client-side
- **Výsledek:** Pokud server nefiltruje správně, body se zobrazí i když by neměly

**Riziko:** 🟡 STŘEDNÍ - může způsobit zobrazení nechtěných bodů

**Fix návrh:**
- Buď přidat filtrování amenities/access v `renderCards()`
- Nebo odstranit tyto filtry z UI, pokud se nepoužívají

---

#### 5. **hasAnyFilter neobsahuje amenities a access, ale tyto filtry se ukládají**

**Umístění:** `10511:10517:assets/map/core.js`

**Problém:**
```javascript
const hasAnyFilter = filterState.powerMin > 0 || 
                     filterState.powerMax < 400 || 
                     (filterState.connectors && filterState.connectors.size > 0) ||
                     (filterState.providers && filterState.providers.size > 0) ||
                     (filterState.poiTypes && filterState.poiTypes.size > 0) ||
                     filterState.free || 
                     showOnlyRecommended;
// CHYBÍ: filterState.amenities a filterState.access
```

**Dopad:**
- Pokud jsou aktivní pouze amenities/access filtry, `hasAnyFilter` je `false`
- To ovlivňuje logiku nearby POI (řádek 10612: `if (!specialModeActive && hasAnyFilter && ...)`)
- **Výsledek:** Nearby POI se nemusí zobrazit správně

**Riziko:** 🟡 STŘEDNÍ - může způsobit nesprávné zobrazení nearby POI

**Fix návrh:**
```javascript
const hasAnyFilter = filterState.powerMin > 0 || 
                     filterState.powerMax < 400 || 
                     (filterState.connectors && filterState.connectors.size > 0) ||
                     (filterState.providers && filterState.providers.size > 0) ||
                     (filterState.poiTypes && filterState.poiTypes.size > 0) ||
                     (filterState.amenities && filterState.amenities.size > 0) || // PŘIDAT
                     (filterState.access && filterState.access.size > 0) || // PŘIDAT
                     filterState.free || 
                     showOnlyRecommended;
```

---

#### 6. **clearMarkers() se volá před každým renderCards v progressive loading**

**Umístění:** `2853:2854:assets/map/core.js` a `2931:2932:assets/map/core.js`

**Problém:**
```javascript
// Mini-fetch
if (typeof clearMarkers === 'function') {
  clearMarkers(); // Vymaže všechny markery
}
renderCards('', null, false); // Vykreslí znovu

// Full-fetch
if (typeof clearMarkers === 'function') {
  clearMarkers(); // Vymaže všechny markery znovu
}
renderCards('', null, false); // Vykreslí znovu
```

**Dopad:**
- Při každém renderu se vymažou všechny markery a vytvoří se znovu
- Pokud je mezi mini a full fetchem zpoždění, uživatel vidí "blikání" markerů
- **Výsledek:** Špatná UX, markery mizí a znovu se objevují

**Riziko:** 🟡 STŘEDNÍ - špatná UX, ale ne přímo bug

**Fix návrh:**
- Použít inteligentní aktualizaci markerů (jako v řádcích 10833-10854)
- Odstraňovat pouze markery, které už nejsou potřeba
- Přidávat pouze nové markery

---

### 🟢 NÍZKÉ CHYBY (logické nesrovnalosti)

#### 7. **specialDatasetActive se nastavuje v renderCards, ale používá se i jinde**

**Umístění:** `10573:10581:assets/map/core.js`

**Problém:**
```javascript
// V renderCards se nastavuje specialDatasetActive
if (hasSpecialFilters && !specialDatasetActive) {
  specialDatasetActive = true;
} else if (!hasSpecialFilters && specialDatasetActive) {
  specialDatasetActive = false;
}

// Ale specialDatasetActive se používá i v jiných funkcích (např. loadNewAreaData)
if (specialDatasetActive || filterState.free || showOnlyRecommended) {
  return; // Blokuje načítání nových dat
}
```

**Dopad:**
- `specialDatasetActive` je globální proměnná, která se mění v `renderCards()`
- Ale používá se i v `loadNewAreaData()` a dalších funkcích
- **Výsledek:** Může dojít k nesouladu stavu

**Riziko:** 🟢 NÍZKÉ - může způsobit drobné problémy

**Fix návrh:**
- Centralizovat správu `specialDatasetActive` do jedné funkce
- Nebo použít computed property místo globální proměnné

---

#### 8. **Duplicitní kontrola specialDatasetActive v loadNewAreaData**

**Umístění:** `12177:12184:assets/map/core.js`

**Problém:**
```javascript
// První kontrola
if (filterState.free || showOnlyRecommended) {
  return;
}

// Druhá kontrola (duplicitní)
if (specialDatasetActive || filterState.free || showOnlyRecommended) {
  return;
}
```

**Dopad:**
- Duplicitní logika - druhá kontrola je zbytečná
- Může způsobit zmatení při čtení kódu

**Riziko:** 🟢 NÍZKÉ - pouze code smell

**Fix návrh:**
- Odstranit první kontrolu, ponechat pouze druhou (kompletní)

---

## Shrnutí - Pravděpodobné příčiny "Pyšely bug"

### Primární příčina (90% pravděpodobnost):

1. **Automatické načítání filtrů z localStorage** (chyba #1)
   - Při prvním volání `renderCards()` se načtou uložené filtry
   - Pokud uživatel měl dříve aktivní filtry, aplikují se okamžitě
   - Body se vykreslí, pak se aplikují filtry → zmizí

2. **Progressive loading s filtry** (chyba #2)
   - Mini-fetch renderuje bez filtrů
   - Full-fetch renderuje s filtry (které se načetly mezitím)
   - Výsledek: dvojí renderování, druhé s filtry → zmizení bodů

3. **Power filtr zahazuje body s power=0** (chyba #3)
   - Pokud je `powerMin > 0` z localStorage, všechny body s neznámým výkonem zmizí
   - To může být velké množství bodů (90% podle popisu)

### Sekundární příčiny (mohou přispívat):

- Filtry amenities/access se neaplikují správně (chyba #4)
- `hasAnyFilter` neobsahuje všechny filtry (chyba #5)

---

## Doporučené fixy (prioritizované)

### 🔴 PRIORITA 1 - Okamžitý fix pro "Pyšely bug"

**Fix 1.1: Ignorovat localStorage filtry do prvního user inputu**

```javascript
// Přidat flag pro user interaction
let userHasInteractedWithFilters = false;

// V renderCards, při načítání filtrů:
if (!window.__db_filters_loaded__ && !userHasInteractedWithFilters) {
  // Nenačítat filtry z localStorage při prvním volání
  // Pouze resetovat na výchozí hodnoty
  filterState.powerMin = 0;
  filterState.powerMax = 400;
  filterState.connectors = new Set();
  filterState.providers = new Set();
  // ... atd.
  window.__db_filters_loaded__ = true;
} else if (!window.__db_filters_loaded__) {
  // Načíst filtry pouze pokud uživatel už interagoval
  loadFilterSettings();
  window.__db_filters_loaded__ = true;
}

// Při jakékoli změně filtru v UI:
userHasInteractedWithFilters = true;
```

**Fix 1.2: Neaplikovat powerMin na body s power=0**

```javascript
// V renderCards, při filtrování podle výkonu:
const maxKw = getStationMaxKw(p);
// Pokud je výkon neznámý (0), zobrazit vždy (nebo použít jinou logiku)
if (maxKw === 0) {
  // Neznámý výkon - zobrazit pokud není explicitně filtrováno
  // Nebo: return true; // Vždy zobrazit neznámé
} else if (maxKw < filterState.powerMin || maxKw > filterState.powerMax) {
  return false;
}
```

**Fix 1.3: Načíst filtry PŘED fetch, ne až v renderCards**

```javascript
// V fetchAndRenderQuickThenFull, před fetch:
if (!window.__db_filters_loaded__) {
  loadFilterSettings(); // Načíst filtry před fetch
  window.__db_filters_loaded__ = true;
}

// V renderCards, odstranit načítání filtrů (nebo pouze jako fallback)
```

### 🟡 PRIORITA 2 - Vylepšení logiky

**Fix 2.1: Přidat amenities a access do hasAnyFilter**

**Fix 2.2: Implementovat filtrování amenities/access v renderCards (nebo odstranit z UI)**

**Fix 2.3: Optimalizovat clearMarkers - používat inteligentní aktualizaci**

### 🟢 PRIORITA 3 - Code cleanup

**Fix 3.1: Centralizovat správu specialDatasetActive**

**Fix 3.2: Odstranit duplicitní kontroly**

---

## Testovací scénáře

### Scénář 1: Reprodukce "Pyšely bug"
1. Otevřít mapu na lokalitě Pyšely
2. Aktivovat nějaký filtr (např. powerMin > 0)
3. Zavřít stránku (filtry se uloží do localStorage)
4. Znovu otevřít mapu na Pyšely
5. Kliknout na "Načíst místa v okolí"
6. **Očekávaný výsledek:** Body se vykreslí a zůstanou
7. **Aktuální výsledek:** Body se vykreslí, pak zmizí (90%)

### Scénář 2: Test s power=0 body
1. Načíst data s body, které mají power=0 (neznámý výkon)
2. Aktivovat filtr powerMin > 0
3. **Očekávaný výsledek:** Body s power=0 by měly zůstat zobrazené (nebo explicitně skryté)
4. **Aktuální výsledek:** Body s power=0 zmizí

### Scénář 3: Test progressive loading
1. Načíst data pomocí "Načíst místa v okolí"
2. Sledovat, kolikrát se volá renderCards
3. **Očekávaný výsledek:** 1x render (nebo 2x, ale bez mizení markerů)
4. **Aktuální výsledek:** 2x render, druhé s filtry → mizení markerů

---

#### 9. **hasAnyFilter způsobuje clearLayers() i když filtry nejsou aktivní z user inputu**

**Umístění:** `10833:10840:assets/map/core.js`

**Problém:**
```javascript
if (hasAnyFilter) {
  // Pokud je aktivní filtr, vyčistit clustery a znovu přidat jen potřebné markery
  [clusterChargers, clusterRV, clusterPOI].forEach(cluster => {
    if (cluster && cluster.clearLayers) {
      cluster.clearLayers(); // Vymaže všechny markery
    }
  });
  currentMarkerIds.clear();
}
```

**Dopad:**
- Pokud se filtry načtou z localStorage, `hasAnyFilter` je `true`
- To způsobí `clearLayers()` - vymaže všechny markery
- Pak se markery znovu přidají podle filtrů
- **Výsledek:** Markery "blikají" - zmizí a znovu se objevují

**Riziko:** 🟡 STŘEDNÍ - špatná UX, může přispívat k "Pyšely bug"

**Fix návrh:**
```javascript
// Použít flag "filtersChangedByUser" místo hasAnyFilter
// Nebo: Neaplikovat clearLayers() pokud filtry nebyly změněny uživatelem
if (hasAnyFilter && userHasInteractedWithFilters) {
  // Pouze pokud uživatel explicitně změnil filtry
  [clusterChargers, clusterRV, clusterPOI].forEach(cluster => {
    if (cluster && cluster.clearLayers) {
      cluster.clearLayers();
    }
  });
}
```

---

## Závěr

Hlavní příčinou "Pyšely bug" je **kombinace automatického načítání filtrů z localStorage a progressive loading**. Body se nejdřív vykreslí bez filtrů, pak se aplikují filtry z localStorage a většina bodů zmizí.

**Doporučený postup:**
1. Implementovat Fix 1.1 (ignorovat localStorage do prvního user inputu)
2. Implementovat Fix 1.2 (neaplikovat powerMin na power=0)
3. Otestovat na lokalitě Pyšely
4. Pokud problém přetrvává, implementovat Fix 1.3 (načítat filtry před fetch)

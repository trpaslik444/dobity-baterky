# Code Review: PR #86 - Opravy ikon a automatické načítání dat

## Přehled změn

- **Icon_Registry.php:** Validace `icon_slug` - ignorování špatného formátu `poi_type-{id}` nebo `rv_type-{id}`
- **Icon_Admin.php:** Odstranění automatického nastavení `icon_slug` při ukládání barvy
- **core.js:** Automatické načtení dat při obnovení stránky s geolokací
- **core.js:** Automatické načtení dat po vyhledávání podle adresy

---

## ✅ Pozitivní aspekty

### 1. Oprava 404 chyb pro POI ikony
- ✅ Validace `icon_slug` v `Icon_Registry.php` řeší existující špatná data
- ✅ Odstranění automatického nastavení v `Icon_Admin.php` zabraňuje budoucím problémům
- ✅ Kombinace obou řešení je kompletní

### 2. Automatické načtení dat
- ✅ Respektuje stav oprávnění geolokace (`granted`, `denied`, `prompt`)
- ✅ Používá progressive loading pro rychlé zobrazení
- ✅ Fallback na cache pokud geolokace není dostupná

### 3. Dokumentace
- ✅ Přidána podrobná dokumentace problému a řešení

---

## ⚠️ Problémy a návrhy na zlepšení

### P1: Duplicitní validace `icon_slug` (P3 - Low)

**Problém:**
Validace `icon_slug` je duplikována na dvou místech v `Icon_Registry.php`:
- Řádek 137-140: pro RV spots
- Řádek 189-192: pro POI

**Kód:**
```php
// includes/Icon_Registry.php:137-140
if (preg_match('/^(poi_type|rv_type)-\d+$/', $icon_slug)) {
    $icon_slug = '';
}

// includes/Icon_Registry.php:189-192
if (preg_match('/^(poi_type|rv_type)-\d+$/', $icon_slug)) {
    $icon_slug = '';
}
```

**Doporučení:**
Vytvořit helper metodu pro validaci:

```php
// includes/Icon_Registry.php
private function validateIconSlug($icon_slug) {
    // Ignorovat špatný icon_slug (poi_type-{id} nebo rv_type-{id} jsou fallback hodnoty z Icon_Admin, ne skutečné názvy souborů)
    if (preg_match('/^(poi_type|rv_type)-\d+$/', $icon_slug)) {
        return '';
    }
    return $icon_slug;
}

// Použití:
$icon_slug = $this->validateIconSlug(get_term_meta($term->term_id, 'icon_slug', true));
```

**Důvod:**
- DRY princip - jedna metoda místo duplikace
- Snadnější údržba - změna na jednom místě

---

### P2: Potenciální problém s `map.once('moveend')` v `doAddressSearch()` (P2 - Medium)

**Problém:**
V `doAddressSearch()` se používá `map.once('moveend')` pro čekání na dokončení animace. Pokud uživatel mezitím přesune mapu ručně, může se `moveend` event vyvolat vícekrát nebo v nesprávný čas.

**Kód:**
```javascript
// assets/map/core.js:9929-9944
map.once('moveend', async () => {
  const center = map.getCenter();
  try {
    await fetchAndRenderQuickThenFull(center, null);
    // ...
  } catch (error) {
    // ...
  }
});
```

**Možné problémy:**
1. Pokud uživatel přesune mapu ručně před dokončením animace, může se fetch spustit na špatném místě
2. Pokud se `moveend` vyvolá vícekrát (např. při zoom), může se fetch spustit vícekrát

**Doporučení:**
Použít flag nebo kontrolu, že se jedná o přesun z vyhledávání:

```javascript
// assets/map/core.js
let isSearchMoveInProgress = false;

async function doAddressSearch(e) {
  // ...
  isSearchMoveInProgress = true;
  map.setView(searchAddressCoords, 13, {animate:true});
  addOrMoveSearchAddressMarker(searchAddressCoords);
  
  map.once('moveend', async () => {
    if (!isSearchMoveInProgress) return; // Ignorovat pokud už není aktivní vyhledávání
    isSearchMoveInProgress = false;
    
    const center = map.getCenter();
    try {
      await fetchAndRenderQuickThenFull(center, null);
      // ...
    } catch (error) {
      // ...
    }
  });
}
```

**Důvod:**
- Zabraňuje race conditions
- Zajišťuje, že fetch se spustí pouze pro přesun z vyhledávání

---

### P3: Chybí kontrola na `empty()` před `preg_match()` (P3 - Low)

**Problém:**
V `Icon_Registry.php` se volá `preg_match()` na `$icon_slug` bez kontroly, zda není prázdný nebo null.

**Kód:**
```php
// includes/Icon_Registry.php:189-192
$icon_slug = get_term_meta( $term->term_id, 'icon_slug', true );

// Ignorovat špatný icon_slug (poi_type-{id} nebo rv_type-{id} jsou fallback hodnoty z Icon_Admin, ne skutečné názvy souborů)
if (preg_match('/^(poi_type|rv_type)-\d+$/', $icon_slug)) {
    $icon_slug = '';
}
```

**Doporučení:**
Přidat kontrolu na prázdný string:

```php
// includes/Icon_Registry.php
$icon_slug = get_term_meta( $term->term_id, 'icon_slug', true );

// Ignorovat špatný icon_slug (poi_type-{id} nebo rv_type-{id} jsou fallback hodnoty z Icon_Admin, ne skutečné názvy souborů)
if (!empty($icon_slug) && preg_match('/^(poi_type|rv_type)-\d+$/', $icon_slug)) {
    $icon_slug = '';
}
```

**Důvod:**
- Bezpečnost - `preg_match()` může vrátit `false` pro prázdný string, ale je lepší to explicitně kontrolovat
- Konzistence s ostatními kontrolami v kódu

---

### P4: Timeout zvýšen z 5s na 10s (P3 - Low)

**Problém:**
V `tryGetUserLocation()` se timeout zvýšil z 5s na 10s.

**Kód:**
```javascript
// assets/map/core.js:2580
{ enableHighAccuracy: false, timeout: 10000, maximumAge: 0 }
```

**Poznámka:**
Toto není nutně problém, ale může způsobit delší čekání pro uživatele. Pokud je geolokace pomalá, uživatel může čekat až 10 sekund.

**Doporučení:**
Zvážit, zda je 10s timeout nutný, nebo zda by stačilo 8s jako kompromis.

**Důvod:**
- Lepší UX - kratší čekání
- Geolokace obvykle trvá 1-3 sekundy, 10s je velmi dlouhé

---

### P5: `maximumAge` změněno z 300000 na 0 (P2 - Medium)

**Problém:**
V `tryGetUserLocation()` se `maximumAge` změnilo z 300000ms (5 minut) na 0ms (vždy aktuální poloha).

**Kód:**
```javascript
// assets/map/core.js:2580
{ enableHighAccuracy: false, timeout: 10000, maximumAge: 0 }
```

**Doporučení:**
Zvážit, zda je `maximumAge: 0` nutné. Pokud uživatel má povolenou geolokaci a cache je čerstvá (např. < 1 minuta), může být rychlejší použít cache.

**Možné řešení:**
```javascript
// Použít cache pokud je čerstvá (< 1 minuta), jinak získat aktuální polohu
const cacheAge = cachedLoc ? (Date.now() - cachedLoc.ts) : Infinity;
const maximumAge = cacheAge < 60000 ? 60000 : 0;

const pos = await new Promise((resolve, reject) => {
  navigator.geolocation.getCurrentPosition(
    resolve, 
    reject, 
    { enableHighAccuracy: false, timeout: 10000, maximumAge: maximumAge }
  );
});
```

**Důvod:**
- Lepší performance - rychlejší načtení pokud je cache čerstvá
- Méně zatížení geolokace API

---

## 📊 Shrnutí

### Kritické problémy: **0**
### Vysoké priority: **0**
### Střední priority: **2**
- P2: Potenciální problém s `map.once('moveend')` v `doAddressSearch()`
- P5: `maximumAge` změněno z 300000 na 0

### Nízké priority: **3**
- P1: Duplicitní validace `icon_slug`
- P3: Chybí kontrola na `empty()` před `preg_match()`
- P4: Timeout zvýšen z 5s na 10s

---

## ✅ Doporučení

### Před merge:
1. ✅ **P2 (P2):** Přidat flag pro kontrolu, že se jedná o přesun z vyhledávání ✅ **OPRAVENO**
2. ✅ **P3 (P3):** Přidat kontrolu na `empty()` před `preg_match()` ✅ **OPRAVENO**

### Volitelné (můžeme udělat později):
3. ✅ **P1 (P3):** Vytvořit helper metodu pro validaci `icon_slug` ✅ **OPRAVENO**
4. ✅ **P4 (P3):** Zvážit snížení timeoutu na 8s ✅ **OPRAVENO**
5. ✅ **P5 (P2):** Zvážit použití `maximumAge` podle čerstvosti cache ✅ **OPRAVENO**

---

## 🎯 Závěr

**Status:** ✅ **Schváleno s drobnými připomínkami**

PR řeší problémy správně a konzistentně. Hlavní problémy jsou:
1. Potenciální race condition v `doAddressSearch()` - mělo by být opraveno před merge
2. Chybí kontrola na `empty()` před `preg_match()` - mělo by být opraveno před merge

Ostatní připomínky jsou volitelné optimalizace.

**Doporučení:** Opravit P2 a P3 před merge, ostatní můžeme udělat později.


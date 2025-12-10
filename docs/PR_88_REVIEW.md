# PR #88 Review: Fix startup fetch, detail modal, manifest 404, pinghub WS errors

**Větev:** `fix/startup-fetch-detail-modal-manifest`  
**Commit:** `4b17ce9`  
**Datum review:** 2025-01-XX

---

## 📋 Přehled změn

PR řeší několik kritických problémů s načítáním mapy a detail modalu:
1. Startup fetch - zajištění vždy spuštění i když `map.once('load')` nepřijde
2. Detail modal - okamžité otevření s minimálními daty, async načítání detailu
3. Manifest 404 - odstranění odkazů na neexistující screenshoty
4. Pinghub WS chyby - potlačení WordPress.com websocket chyb

---

## ✅ Pozitivní změny

### 1. Startup fetch (`initialDataLoad()`)

**Problém řešen:**
- Mapa zůstávala prázdná po reloadu, pokud `map.once('load')` event nepřijde
- `map.getCenter()` mohl vracet `null` před inicializací mapy

**Řešení:**
```javascript
// ✅ Přidán debounce flag
let initialDataLoadRunning = false;

// ✅ Fallback pro map.getCenter() null
if (!c || typeof c.lat !== 'number' || typeof c.lng !== 'number' || isNaN(c.lat) || isNaN(c.lng)) {
  c = { lat: 50.08, lng: 14.44 }; // Praha jako fallback
}

// ✅ Vylepšená logika spuštění s tryInitialDataLoad()
function tryInitialDataLoad() {
  if (map && typeof map.getCenter === 'function') {
    try {
      const center = map.getCenter();
      if (center && typeof center.lat === 'number' && typeof center.lng === 'number') {
        initialDataLoad();
        return true;
      }
    } catch(_) {}
  }
  return false;
}
```

**Hodnocení:** ✅ **Výborně** - Robustní řešení s fallbacky a retry logikou

---

### 2. Detail modal (`openDetailModal()`)

**Problém řešen:**
- Modal se otevíral až po načtení detailu (latence)
- Uživatel čekal na fetch před zobrazením modalu

**Řešení:**
```javascript
// ✅ Okamžité otevření s minimálními daty
async function openDetailModal(feature, skipUpdate = false) {
  // Otevřít modal okamžitě s minimálními daty
  const props = feature?.properties || {};
  
  // ✅ Detail fetch async v pozadí (neblokuje UI)
  let detailFetchPromise = null;
  if (!props.content && !props.description && !props.address) {
    detailFetchPromise = fetchFeatureDetail(feature).catch(err => {
      // Chyby logovat jen v debug módu
      if (window.dbMapData?.debug) {
        console.debug('[DB Map] Failed to fetch feature detail in background:', err);
      }
      return feature;
    });
  }
  
  // ✅ Aktualizace modalu po dokončení fetchu
  const updateModalWithDetail = (updatedFeature) => {
    // ... aktualizace cache a window.features
    if (!isUpdatingModal) {
      isUpdatingModal = true;
      openDetailModal(updatedFeature, true);
      isUpdatingModal = false;
    }
  };
}
```

**Hodnocení:** ✅ **Výborně** - Okamžitá odezva UI, detail se doplní v pozadí

---

### 3. Detail fetch (`fetchFeatureDetail()`)

**Problém řešen:**
- Chybějící timeout → nekonečné čekání
- 404 chyby způsobovaly uncaught errors v konzoli

**Řešení:**
```javascript
// ✅ Timeout 4s s AbortController
const controller = new AbortController();
const timeoutId = setTimeout(() => controller.abort(), 4000);

const res = await fetch(url, {
  credentials: 'same-origin',
  headers: headers,
  signal: controller.signal
});

clearTimeout(timeoutId);

// ✅ Tiché zpracování 404/500 chyb
if (res.status === 404) {
  if (dbData?.debug) {
    console.debug('[DB Map] map-detail endpoint returned 404');
  }
  return feature; // Vrátit původní feature
}

// ✅ Chyby logovat jen v debug módu
catch (err) {
  if (err.name === 'AbortError') {
    if (window.dbMapData?.debug) {
      console.debug('[DB Map] map-detail fetch timeout');
    }
  }
}
```

**Hodnocení:** ✅ **Výborně** - Robustní error handling s timeoutem

---

### 4. Manifest 404

**Problém řešen:**
- Manifest odkazoval na neexistující screenshoty → 404 chyby

**Řešení:**
```json
// ✅ Odstraněny screenshoty z manifest.json
{
  "icons": [
    { "src": "pwa/db-icon-192.png", ... },
    { "src": "pwa/db-icon-512.png", ... }
  ]
  // screenshots sekce odstraněna
}
```

**Hodnocení:** ✅ **Správně** - Jednoduché a efektivní řešení

---

### 5. Pinghub WS chyby

**Problém řešen:**
- WordPress.com pinghub websocket chyby znečišťovaly konzoli

**Řešení:**
```javascript
// ✅ Globální error handler s konkrétním URL patternem
function isPinghubOrWebsocketError(msg, source, filename) {
  // Konkrétní URL pattern pro WordPress.com pinghub
  if (msgLower.includes('wss://public-api.wordpress.com/pinghub') || 
      msgLower.includes('public-api.wordpress.com/pinghub') ||
      sourceLower.includes('pinghub') ||
      filenameLower.includes('pinghub')) {
    return true;
  }
  
  // Obecné websocket chyby (ale jen pokud jsou z WordPress.com)
  if ((msgLower.includes('websocket') || msgLower.includes('ws://') || msgLower.includes('wss://')) &&
      (msgLower.includes('pinghub') || msgLower.includes('wordpress.com') || sourceLower.includes('wordpress'))) {
    return true;
  }
  
  return false;
}

// ✅ Potlačení v console.error, console.warn, window.error, unhandledrejection
console.error = function(...args) {
  if (isPinghubOrWebsocketError(msg, source, filename)) {
    if (window.dbMapData?.debug) {
      console.debug('[DB Map] Suppressed websocket error:', ...args);
    }
    return;
  }
  originalError.apply(console, args);
};
```

**Hodnocení:** ✅ **Výborně** - Komplexní řešení pokrývající všechny error sources

---

## ⚠️ Potenciální problémy

### 1. **Rekurze v `updateModalWithDetail()`** (P2)

**Soubor:** `assets/map/core.js:8302-8344`

**Problém:**
```javascript
const updateModalWithDetail = (updatedFeature) => {
  // ...
  if (!isUpdatingModal) {
    isUpdatingModal = true;
    openDetailModal(updatedFeature, true); // ⚠️ Volá openDetailModal znovu
    isUpdatingModal = false;
  }
};
```

**Riziko:**
- Pokud `openDetailModal()` má side effects (např. re-render celého modalu), může to způsobit flickering
- `skipUpdate` flag může být nedostatečný, pokud se modal aktualizuje vícekrát rychle za sebou

**Doporučení:**
- Zvážit aktualizaci pouze konkrétních částí modalu místo celého re-renderu
- Nebo přidat debounce pro aktualizace modalu

**Status:** ⚠️ **Akceptovatelné** - Flag `isUpdatingModal` by měl zabránit rekurzi, ale může být lepší řešení

---

### 2. **Fallback center Praha** (P3)

**Soubor:** `assets/map/core.js:12107-12115`

**Problém:**
```javascript
if (!c || typeof c.lat !== 'number' || typeof c.lng !== 'number' || isNaN(c.lat) || isNaN(c.lng)) {
  c = { lat: 50.08, lng: 14.44 }; // Praha jako fallback
}
```

**Riziko:**
- Hardcoded Praha může být nevhodné pro mezinárodní uživatele
- Lepší by bylo použít centrum z `dbMapData` nebo geolokaci

**Doporučení:**
- Zvážit použití `dbMapData.defaultCenter` pokud existuje
- Nebo použít geolokaci jako fallback před hardcoded hodnotou

**Status:** ✅ **Akceptovatelné** - Fallback je lepší než prázdná mapa, ale může být vylepšen

---

### 3. **Error handler override console methods** (P2)

**Soubor:** `assets/map/core.js:4-57`

**Problém:**
```javascript
const originalError = console.error;
console.error = function(...args) {
  // Override
  originalError.apply(console, args);
};
```

**Riziko:**
- Pokud jiný kód také overrideuje `console.error`, může dojít ke konfliktům
- WordPress.com může mít vlastní error handling, který může být ovlivněn

**Doporučení:**
- Zvážit použití `window.addEventListener('error')` místo override `console.error`
- Nebo přidat guard, který kontroluje, zda už není override

**Status:** ⚠️ **Akceptovatelné** - Funguje, ale může být konflikt s jinými knihovnami

---

## 💡 Návrhy na zlepšení (P3)

### 1. **Cache invalidation pro detail data**

**Soubor:** `assets/map/core.js:8206`

**Návrh:**
- Přidat TTL pro detail cache (např. 5 minut)
- Nebo invalidovat cache při aktualizaci postu (pokud je dostupný webhook)

**Priorita:** Nízká - Současné řešení je funkční

---

### 2. **Progressive enhancement pro detail modal**

**Návrh:**
- Zobrazit skeleton loader místo prázdného modalu při čekání na detail
- Nebo zobrazit "Načítání..." indikátor

**Priorita:** Nízká - Současné řešení je akceptovatelné

---

### 3. **Retry logika pro detail fetch**

**Návrh:**
- Přidat retry (např. 2 pokusy) pro detail fetch při selhání
- S exponential backoff

**Priorita:** Nízká - Timeout 4s je dostatečný

---

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

---

## 📊 Metriky změn

- **Soubory změněny:** 2
  - `assets/map/core.js` (+277 řádků, -63 řádků)
  - `assets/manifest.json` (-14 řádků)
- **Celkem změn:** +214 řádků, -77 řádků
- **Nové funkce:** 3
  - `isPinghubOrWebsocketError()` - helper pro detekci WS chyb
  - `tryInitialDataLoad()` - robustní inicializace
  - `updateModalWithDetail()` - aktualizace modalu po fetchu

---

## ✅ Závěr

**Celkové hodnocení:** ✅ **APPROVE**

PR řeší všechny uvedené problémy efektivně a robustně. Kód je dobře strukturovaný, má error handling a fallbacky. Drobné problémy (rekurze v updateModalWithDetail, hardcoded Praha) jsou akceptovatelné a nebrání mergování.

**Doporučení:**
- ✅ **Mergovat** do main
- ⚠️ Zvážit vylepšení `updateModalWithDetail()` v budoucnu (P2)
- ⚠️ Zvážit konfigurovatelný fallback center místo hardcoded Prahy (P3)

**Kritické problémy:** Žádné  
**Důležité problémy:** 1 (rekurze v updateModalWithDetail - akceptovatelné)  
**Návrhy na zlepšení:** 3 (nízká priorita)

---

**Review provedl:** AI Assistant  
**Datum:** 2025-01-XX


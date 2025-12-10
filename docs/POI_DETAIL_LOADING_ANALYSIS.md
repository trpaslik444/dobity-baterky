# Analýza: Pomalé načítání detailu POI

## Problém

Při kliknutí na POI "Kebab 4 you" (ID: 37651) trvá velmi dlouho než se zobrazí modal okno.

## Analýza z HAR souboru

### Sekvence requestů při kliknutí na POI:
1. `/wp-json/db/v1/nearby?origin_id=37651&type=poi&limit=1` - **1516ms** (duplicitní)
2. `/wp-json/db/v1/nearby?origin_id=37651&type=poi&limit=1` - **2177ms** (duplicitní)
3. `/wp-json/db/v1/ondemand/status/37651?type=poi` - **2169ms** (duplicitní)
4. `/wp-json/db/v1/ondemand/status/37651?type=poi` - **1557ms** (duplicitní)
5. `/wp-json/db/v1/nearby?origin_id=37651&type=poi&limit=9` - **1428ms**
6. `/wp-json/db/v1/nearby?origin_id=37651&type=poi&limit=3` - **1333ms**
7. `/wp-json/db/v1/ondemand/process` - **5567ms** ⚠️ **NEJPOMALEJŠÍ**

### Problémy:

1. **404 na map-detail endpoint**
   - V console je chyba: `GET /wp-json/db/v1/map/map-detail/poi/37651 404`
   - V HAR souboru není žádný request na map-detail
   - URL v kódu: `/wp-json/db/v1/map-detail/poi/37651` (správně)
   - Možná problém: endpointType je 'poi', ale endpoint očekává možná jiný formát

2. **Pomalé načítání**
   - `/ondemand/process` trvá **5567ms** (5.5 sekundy!) - wait time je 5510ms
   - `/nearby` requesty trvají 1.3-2.1 sekundy
   - `/ondemand/status` trvá 1.5-2.1 sekundy

3. **Duplicitní requesty**
   - `/nearby?origin_id=37651&type=poi&limit=1` se volá 2x
   - `/ondemand/status` se volá 2x

4. **Sekvence při kliknutí na POI**
   - `fetchFeatureDetail()` se volá před otevřením modalu/sheetu - může být pomalý nebo vracet 404
   - `loadIsochronesForFeature()` se volá synchronně před otevřením sheetu - to způsobuje zpoždění
   - `enrichPOIFeature()` se volá v `openDetailModal()` a `openMobileSheet()` - může být pomalý

## Řešení

### 1. Opravit 404 na map-detail endpoint

**Problém:** Endpoint vrací 404, možná špatná URL nebo endpointType.

**Kontrola:**
- Endpoint je registrován jako: `/db/v1/map-detail/(?P<type>[a-z_]+)/(?P<id>\d+)`
- URL v kódu: `/wp-json/db/v1/map-detail/poi/37651`
- EndpointType: `'poi'` (správně)

**Možné příčiny:**
- Endpoint neexistuje nebo není správně registrován
- Permission callback vrací false
- Post s ID 37651 neexistuje nebo není publish

**Řešení:**
- Přidat error handling v `fetchFeatureDetail()` - pokud vrací 404, použít fallback
- Zkontrolovat, zda endpoint správně zpracovává POI typy

---

### 2. Optimalizovat sekvenci načítání

**Problém:** `loadIsochronesForFeature()` se volá synchronně před otevřením sheetu, což způsobuje zpoždění.

**Aktuální sekvence:**
```javascript
// assets/map/core.js:10630-10648
let currentFeature = f;
const currentProps = currentFeature?.properties || {};
if (!currentProps.content && !currentProps.description && !currentProps.address) {
  currentFeature = await fetchFeatureDetail(currentFeature); // ⏱️ Čeká na detail
}
// ...
try {
  loadIsochronesForFeature(currentFeature); // ⏱️ Čeká na isochrony (5.5s!)
} catch (_) {}
// ...
openMobileSheet(currentFeature); // ⏱️ Otevře sheet až po načtení isochronů
```

**Řešení:**
- Otevřít sheet/modál **ihned** s dostupnými daty
- Načíst detail a isochrony **asynchronně v pozadí**
- Aktualizovat UI po načtení dat

**Implementace:**
```javascript
// Otevřít sheet/modál okamžitě
openMobileSheet(currentFeature);

// Načíst detail a isochrony asynchronně v pozadí
(async () => {
  try {
    // Načíst detail pokud chybí
    if (!currentProps.content && !currentProps.description && !currentProps.address) {
      const detailedFeature = await fetchFeatureDetail(currentFeature);
      if (detailedFeature && detailedFeature !== currentFeature) {
        // Aktualizovat sheet s novými daty
        updateMobileSheet(detailedFeature);
      }
    }
    
    // Načíst isochrony v pozadí (neblokuje UI)
    loadIsochronesForFeature(currentFeature);
  } catch (error) {
    // Silent fail - uživatel už vidí sheet
  }
})();
```

---

### 3. Optimalizovat duplicitní requesty

**Problém:** `/nearby` a `/ondemand/status` se volají vícekrát.

**Možné příčiny:**
- `loadIsochronesForFeature()` volá `/nearby` vícekrát
- `fetchNearby()` může být volán vícekrát
- Chybí debounce nebo cache

**Řešení:**
- Přidat debounce pro duplicitní requesty
- Použít cache pro `/nearby` requesty
- Zkontrolovat, zda se `loadIsochronesForFeature()` nevolá vícekrát

---

### 4. Optimalizovat ondemand/process

**Problém:** `/ondemand/process` trvá 5.5 sekundy.

**Možné příčiny:**
- Server zpracovává request synchronně (ORS API call, databázové operace)
- Chybí cache nebo rate limiting
- Procesor je pomalý

**Řešení:**
- Spustit `/ondemand/process` **asynchronně v pozadí** (neblokuje UI)
- Použít cache pro výsledky
- Zobrazit loading indicator během zpracování
- Použít queue systém pro dlouhé operace

---

## Doporučené změny

### Priorita 1 (Kritické):
1. ✅ **Otevřít sheet/modál okamžitě** - nečekat na načtení detailu a isochronů
2. ✅ **Načíst detail a isochrony asynchronně** - v pozadí po otevření sheetu
3. ✅ **Opravit 404 na map-detail** - přidat error handling a fallback

### Priorita 2 (Vysoká):
4. **Optimalizovat duplicitní requesty** - přidat debounce a cache
5. **Optimalizovat ondemand/process** - spustit asynchronně v pozadí

### Priorita 3 (Střední):
6. **Přidat loading indicator** - během načítání detailu a isochronů
7. **Optimalizovat nearby API** - cache a debounce


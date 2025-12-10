# Analýza: Pomalé načítání modalu při kliknutí na POI

## Problém

Při kliknutí na POI (např. "Kebab 4 you" - ID 37651) trvá velmi dlouho než se zobrazí modal okno.

## Analýza z HAR souboru

### Sekvence requestů při kliknutí na POI:

1. **`/wp-json/db/v1/map-detail/poi/37651`** - ❌ **404 Not Found**
   - Endpoint neexistuje nebo má špatnou URL
   - Frontend čeká na tento request před zobrazením sheetu

2. **`/wp-json/db/v1/nearby?origin_id=37651&type=poi&limit=...`** - ⏱️ **1.3-2.2 sekundy**
   - Wait time: 1328-2168ms
   - Volá se po selhání map-detail

3. **`/wp-json/db/v1/ondemand/status/37651?type=poi`** - ⏱️ **1.5-2.2 sekundy**
   - Wait time: 1556-2167ms
   - Volá se pro isochrony

### Celková doba načítání:
- **Map-detail (404):** ~timeout (pravděpodobně 10s)
- **Nearby:** 1.3-2.2s
- **Ondemand:** 1.5-2.2s
- **Celkem:** ~3-5 sekund před zobrazením sheetu

## Příčiny problému

### 1. map-detail endpoint vrací 404

**Problém:**
- Frontend volá `/wp-json/db/v1/map-detail/poi/37651`
- Endpoint je registrovaný jako `/map-detail/(?P<type>[a-z_]+)/(?P<id>\d+)`
- Ale možná má problém s routingem nebo autentizací

**Kontrola:**
- Endpoint je registrovaný v `REST_Map.php` řádek 108
- URL pattern: `/map-detail/(?P<type>[a-z_]+)/(?P<id>\d+)`
- Permission callback vyžaduje `db_user_can_see_map()`

### 2. openMobileSheet() čeká na fetchFeatureDetail()

**Problém:**
- `openMobileSheet()` volá `await fetchFeatureDetail()` před zobrazením sheetu (řádek 5820)
- Pokud `fetchFeatureDetail()` selže (404), čeká se na timeout
- Sheet se zobrazí až po dokončení fetchu

**Kód:**
```javascript
// assets/map/core.js:5817-5821
const props = feature?.properties || {};
if (!props.content && !props.description && !props.address) {
  // Minimal payload - načíst detail
  feature = await fetchFeatureDetail(feature); // ❌ Blokuje UI
}
```

### 3. Pomalé endpointy (server-side)

**Nearby endpoint:**
- Wait time: 1.3-2.2 sekundy
- Možné příčiny: N+1 queries, pomalé dotazy, chybějící indexy

**Ondemand endpoint:**
- Wait time: 1.5-2.2 sekundy
- Možné příčiny: Externí API volání, pomalé zpracování

## Řešení

### Řešení 1: Zobrazit sheet okamžitě, načíst detail v pozadí (doporučeno)

**Koncept:** Zobrazit sheet s dostupnými daty okamžitě, načíst detail a nearby data asynchronně v pozadí.

**Implementace:**
```javascript
// assets/map/core.js:openMobileSheet()
async function openMobileSheet(feature) {
  if (window.innerWidth > 900) return;

  // Zobrazit sheet okamžitě s dostupnými daty
  const p = feature.properties || {};
  // ... render sheet HTML ...
  sheetContentEl.innerHTML = finalHTML;
  mobileSheet.classList.add('open');
  
  // Načíst detail a nearby data asynchronně v pozadí
  (async () => {
    try {
      // Načíst detail pokud chybí
      const props = feature?.properties || {};
      if (!props.content && !props.description && !props.address) {
        const enrichedFeature = await fetchFeatureDetail(feature);
        if (enrichedFeature && enrichedFeature !== feature) {
          // Aktualizovat sheet s novými daty
          updateMobileSheetContent(enrichedFeature);
        }
      }
      
      // Načíst nearby data
      loadNearbyForMobileSheet(feature);
    } catch (error) {
      // Silent fail - uživatel už vidí sheet
      console.debug('[DB Map] Error loading detail/nearby:', error);
    }
  })();
}
```

**Výhody:**
- ✅ Okamžité zobrazení sheetu (< 100ms)
- ✅ Lepší UX - uživatel vidí obsah okamžitě
- ✅ Detail se načte v pozadí a aktualizuje sheet

---

### Řešení 2: Opravit map-detail endpoint

**Koncept:** Zkontrolovat a opravit map-detail endpoint, aby správně vracel data.

**Možné problémy:**
1. Endpoint není správně registrovaný
2. Permission callback zamítá request
3. URL pattern neodpovídá frontend volání

**Kontrola:**
- Zkontrolovat registraci endpointu
- Zkontrolovat permission callback
- Zkontrolovat URL pattern

---

### Řešení 3: Optimalizovat nearby a ondemand endpointy (server-side)

**Koncept:** Optimalizovat server-side endpointy pro rychlejší odpověď.

**Možné optimalizace:**
- Batch loading meta values
- Optimalizace dotazů
- Přidání indexů
- Cache

---

## Doporučení

**Doporučuji Řešení 1:** Zobrazit sheet okamžitě, načíst detail v pozadí.

**Důvody:**
- ✅ Okamžité zobrazení (< 100ms místo 3-5s)
- ✅ Lepší UX
- ✅ Funguje i když endpoint selže
- ✅ Nevyžaduje změny server-side

**Doplňkově:** Opravit map-detail endpoint (Řešení 2) pro správné načítání detailů.


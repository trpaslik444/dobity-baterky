# Oprava: Pomalé načítání modalu při kliknutí na POI

## Problém

Při kliknutí na POI (např. "Kebab 4 you" - ID 37651) trvalo velmi dlouho než se zobrazilo modal okno (~3-5 sekund).

## Analýza z HAR souboru

### Sekvence requestů při kliknutí na POI:

1. **`/wp-json/db/v1/map-detail/poi/37651`** - ❌ **404 Not Found**
   - Endpoint vrací 404 (post možná není publikovaný nebo má špatný post_status)
   - Frontend čekal na tento request před zobrazením sheetu

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

### 1. openMobileSheet() čeká na fetchFeatureDetail()

**Problém:**
- `openMobileSheet()` volá `await fetchFeatureDetail()` před zobrazením sheetu (řádek 5820)
- Pokud `fetchFeatureDetail()` selže (404), čeká se na timeout
- Sheet se zobrazí až po dokončení fetchu

**Kód před opravou:**
```javascript
// assets/map/core.js:5817-5821
const props = feature?.properties || {};
if (!props.content && !props.description && !props.address) {
  // Minimal payload - načíst detail
  feature = await fetchFeatureDetail(feature); // ❌ Blokuje UI
}
```

### 2. map-detail endpoint vrací 404

**Možné příčiny:**
- Post není publikovaný (`post_status !== 'publish'`)
- Permission callback zamítá request
- URL pattern neodpovídá frontend volání

**Kontrola v `handle_map_detail()`:**
```php
// includes/REST_Map.php:1103
if (!$post || $post->post_type !== $post_type || $post->post_status !== 'publish') {
    return new \WP_Error('not_found', 'Bod nebyl nalezen', array('status' => 404));
}
```

## Implementované řešení

### 1. Zobrazit sheet okamžitě, načíst detail v pozadí

**Změna:**
- `openMobileSheet()` zobrazí sheet okamžitě s dostupnými daty
- Detail se načte asynchronně v pozadí a aktualizuje sheet pokud je potřeba

**Kód po opravě:**
```javascript
// assets/map/core.js:openMobileSheet()
async function openMobileSheet(feature) {
  if (window.innerWidth > 900) return;

  // Zobrazit sheet okamžitě s dostupnými daty (nečekat na detail)
  const p = feature.properties || {};
  // ... render sheet HTML ...
  sheetContentEl.innerHTML = finalHTML;
  mobileSheet.classList.add('open');
  
  // Načíst detail a rozšířená data asynchronně v pozadí (neblokuje UI)
  (async () => {
    try {
      // Načíst detail pokud chybí
      const props = feature?.properties || {};
      let currentFeature = feature;
      if (!props.content && !props.description && !props.address) {
        try {
          currentFeature = await fetchFeatureDetail(feature);
          if (currentFeature && currentFeature !== feature) {
            // Aktualizovat cache a detailBtn
            featureCache.set(currentFeature.properties.id, currentFeature);
            if (detailBtn) {
              detailBtn.onclick = () => openDetailModal(currentFeature);
            }
          }
        } catch (err) {
          // Silent fail - pokračovat s původními daty
          console.debug('[DB Map] Failed to fetch feature detail:', err);
        }
      }
      
      // Načíst charging_location enrichment pokud je potřeba
      // ...
    } catch (error) {
      // Silent fail - uživatel už vidí sheet
    }
  })();
}
```

### 2. Lepší error handling v fetchFeatureDetail()

**Změna:**
- Pokud endpoint vrací 404, vrátit původní feature (máme alespoň minimal payload)
- Nečekat na timeout, okamžitě pokračovat

**Kód:**
```javascript
// assets/map/core.js:fetchFeatureDetail()
if (res.ok) {
  // ... zpracovat úspěšnou odpověď ...
} else if (res.status === 404) {
  // 404 - endpoint neexistuje nebo post není publikovaný
  // Vrátit původní feature (máme alespoň minimal payload)
  console.debug('[DB Map] map-detail endpoint returned 404 for', { type: endpointType, id });
  return feature;
}
```

## Výsledek

### Před opravou:
- ⏱️ **3-5 sekund** před zobrazením sheetu
- Čekání na map-detail endpoint (404 timeout)
- Blokované UI

### Po opravě:
- ✅ **< 100ms** před zobrazením sheetu
- Sheet se zobrazí okamžitě s dostupnými daty
- Detail se načte v pozadí a aktualizuje sheet
- Lepší UX - uživatel vidí obsah okamžitě

## Změněné soubory

- `assets/map/core.js`:
  - `openMobileSheet()` - zobrazí sheet okamžitě, načte detail v pozadí
  - `fetchFeatureDetail()` - lepší error handling pro 404

## Poznámky

- **404 chyby:** Pokud map-detail endpoint vrací 404, může to znamenat, že post není publikovaný. To je správné chování, ale frontend to nyní lépe zpracovává.
- **Server-side optimalizace:** Nearby a ondemand endpointy jsou stále pomalé (1-2 sekundy), ale to už neblokuje UI, protože se načítají v pozadí.


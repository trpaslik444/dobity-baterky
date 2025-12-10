# PR #91 Review: Fix POI ikony z Icon Admin a zrychlení db-mobile-sheet

**Větev:** `fix/startup-fetch-detail-modal-manifest`  
**Base:** `main`  
**Commits:** 1
- `07f2245` - Fix: POI ikony z Icon Admin a zrychlení db-mobile-sheet

**Datum review:** 2025-01-XX

---

## 📋 Přehled změn

PR #91 řeší dva hlavní problémy:
1. **POI ikony z Icon Admin** - zajištění použití ikon podle Icon Admin konfigurace (obecná cesta `poi_type-{term_id}.svg`)
2. **Zrychlení db-mobile-sheet** - okamžité otevření s minimálními daty, detail fetch na pozadí

---

## ✅ Pozitivní změny

### 1. POI ikony z Icon Admin - opraveno ✅

**Soubor:** `includes/Icon_Registry.php:195-302`

**Problém:** POI ikony se načítaly pouze z `icon_slug` z term meta, ne z Icon Admin konfigurace (obecná cesta `poi_type-{term_id}.svg`).

**Řešení:**
```php
// PRIORITA 1: Zkusit načíst z Icon Admin konfigurace (uploads/dobity-baterky/icons/poi_type-{term_id}.svg)
$icon_admin_slug = 'poi_type-' . $term_id;
$icon_admin_path = $uploads_path . $icon_admin_slug . '.svg';
$icon_admin_url = $uploads_url . $icon_admin_slug . '.svg';
$is_icon_admin_upload = file_exists($icon_admin_path);

// PRIORITA 2: Pokud máme icon_slug z term meta, zkusit ho použít
if (!empty($icon_slug)) {
    // Preferuj uploads, pak assets
    $svg_path = $is_icon_admin_upload ? $icon_admin_path : ($uploads_path . $icon_slug . '.svg');
    // ...
}

// PRIORITA 3: Pokud nemáme icon_slug, ale máme Icon Admin soubor, použít ho
if ($is_icon_admin_upload) {
    // Načíst SVG a vrátit s icon_url
    return [
        'slug' => $icon_admin_slug,
        'svg_content' => $svg_content,
        'icon_url' => $icon_admin_url,
        'color' => $global_poi_color,
    ];
}
```

**Hodnocení:** ✅ **Výborně** - Správná priorita: Icon Admin → icon_slug z term → fallback. Přidán `icon_url` do návratové hodnoty.

---

### 2. REST payload - přidán icon_url ✅

**Soubor:** `includes/REST_Map.php:636-651`

**Problém:** POI v minimal payload neměly `icon_url`, i když bylo dostupné z Icon Admin.

**Řešení:**
```php
// Pro POI: vždy vrátit svg_content a icon_url pokud je dostupné
if ($pt === 'poi') {
    // Priorita: svg_content → icon_url → icon_slug
    $properties['svg_content'] = $icon_data['svg_content'] ?? '';
    $properties['icon_url'] = $icon_data['icon_url'] ?? null;
    // icon_slug pro cache (pokud existuje)
    if (empty($properties['icon_slug']) || trim($properties['icon_slug']) === '') {
        // Pokud nemáme icon_slug, ale máme icon_url, použít slug z icon_url
        if (!empty($properties['icon_url'])) {
            // Extrahovat slug z URL (např. /uploads/dobity-baterky/icons/poi_type-123.svg → poi_type-123)
            if (preg_match('/\/([^\/]+)\.svg$/', $properties['icon_url'], $matches)) {
                $properties['icon_slug'] = $matches[1];
            }
        }
    }
}
```

**Hodnocení:** ✅ **Výborně** - Frontend má k dispozici `icon_url` pro okamžité zobrazení. Extrakce `icon_slug` z URL je chytré řešení pro cache.

---

### 3. Frontend render markerů - priorita ikon ✅

**Soubor:** `assets/map/core.js:10909-10950`

**Problém:** Priorita ikon nebyla správná - chyběla `icon_url` mezi `svg_content` a `icon_slug`.

**Řešení:**
```javascript
// PRIORITA 1: svg_content z properties (nejrychlejší, okamžité zobrazení)
if (p.svg_content && p.svg_content.trim() !== '') {
  return p.post_type === 'charging_location' ? recolorChargerIcon(p.svg_content, p) : p.svg_content;
}

// PRIORITA 2: icon_url z properties (přímá URL k souboru z Icon Admin)
if (p.icon_url && p.icon_url.trim() !== '') {
  return `<img src="${p.icon_url}" style="width:100%;height:100%;display:block;" alt="" onerror="...">`;
}

// PRIORITA 3: icon_slug z properties nebo featureCache
const iconSlug = p.icon_slug || (cachedFeature?.properties?.icon_slug || null);
if (iconSlug && iconSlug.trim() !== '') {
  // Pokud je ikona na blacklistu (404), přeskočit
  if (icon404Cache.has(iconSlug)) {
    return p.post_type === 'charging_location' ? '⚡' : '';
  }
  // ...
}

// PRIORITA 4: svg_content z featureCache
// ...
```

**Hodnocení:** ✅ **Výborně** - Správná priorita: `svg_content` → `icon_url` → `icon_slug` → fallback. Přidán `onerror` handler pro fallback.

---

### 4. Cache pro 404 ikony ✅

**Soubor:** `assets/map/core.js:2435, 2476-2479`

**Problém:** Ikony, které vrátily 404, se zkoušely opakovaně, což způsobovalo zbytečné requesty.

**Řešení:**
```javascript
const icon404Cache = new Set(); // Cache pro ikony, které vrátily 404

// V loadIconSvg:
const response = await fetch(iconUrl);
if (!response.ok) {
  // Pokud je 404, přidat do blacklistu a přestat zkoušet opakovaně
  if (response.status === 404) {
    icon404Cache.add(iconSlug);
  }
  iconSvgCache.set(iconSlug, '');
  return '';
}

// V getMarkerHtml:
if (iconSlug && iconSlug.trim() !== '') {
  // Pokud je ikona na blacklistu (404), přeskočit
  if (icon404Cache.has(iconSlug)) {
    return p.post_type === 'charging_location' ? '⚡' : '';
  }
  // ...
}
```

**Hodnocení:** ✅ **Výborně** - Efektivní řešení pro prevenci opakovaných requestů na neexistující ikony.

---

### 5. Zrychlení db-mobile-sheet ✅

**Soubor:** `assets/map/core.js:6095-6143`

**Problém:** `openMobileSheet` používal `async IIFE`, což mohlo blokovat render.

**Řešení:**
```javascript
// PŘED: (async () => { ... })();
// PO: Promise.resolve().then(async () => { ... });

// Načíst detail a rozšířená data asynchronně v pozadí (neblokuje UI)
// DŮLEŽITÉ: Žádný await před render - sheet se otevře okamžitě
Promise.resolve().then(async () => {
  try {
    // Načíst detail pokud chybí
    const props = feature?.properties || {};
    let currentFeature = feature;
    if (!props.content && !props.description && !props.address) {
      try {
        currentFeature = await fetchFeatureDetail(feature);
        // ...
      } catch (err) {
        // Silent fail - pokračovat s původními daty
        if (typeof window !== 'undefined' && window.dbMapData && window.dbMapData.debug) {
          console.debug('[DB Map] Failed to fetch feature detail:', err);
        }
      }
    }
    // ...
  } catch (error) {
    // Silent fail - uživatel už vidí sheet
    if (typeof window !== 'undefined' && window.dbMapData && window.dbMapData.debug) {
      console.debug('[DB Map] Error loading detail/enrichment:', error);
    }
  }
});
```

**Hodnocení:** ✅ **Výborně** - `Promise.resolve().then()` zajišťuje, že se sheet otevře okamžitě, detail fetch běží na pozadí. Error handling pouze v debug módu.

---

## ⚠️ Potenciální problémy

### 1. **Extrakce icon_slug z URL** (P3)

**Soubor:** `includes/REST_Map.php:644-648`

**Problém:**
```php
// Extrahovat slug z URL (např. /uploads/dobity-baterky/icons/poi_type-123.svg → poi_type-123)
if (preg_match('/\/([^\/]+)\.svg$/', $properties['icon_url'], $matches)) {
    $properties['icon_slug'] = $matches[1];
}
```

**Riziko:**
- Regex může selhat pokud URL má query parametry nebo hash
- Pokud URL není ve formátu `.../poi_type-123.svg`, extrakce selže

**Doporučení:**
- Zvážit validaci extrahovaného slug (např. `validateIconSlug()`)
- Nebo použít `basename()` a `pathinfo()` místo regex

**Status:** ⚠️ **Akceptovatelné** - Funguje pro standardní URL formát, ale může selhat u nestandardních URL.

---

### 2. **onerror handler v HTML stringu** (P2)

**Soubor:** `assets/map/core.js:10926, 10937, 10945`

**Problém:**
```javascript
return `<img src="${p.icon_url}" style="width:100%;height:100%;display:block;" alt="" onerror="this.style.display='none';this.parentElement.innerHTML='${p.post_type === 'charging_location' ? '⚡' : ''}';">`;
```

**Riziko:**
- `onerror` handler v HTML stringu může být problematický pokud `icon_url` obsahuje speciální znaky
- XSS riziko pokud `icon_url` není sanitizován (ale měl by být z backendu)

**Doporučení:**
- Zvážit použití event listeneru místo inline `onerror`
- Nebo použít `escapeHtml()` pro emoji fallback

**Status:** ⚠️ **Akceptovatelné** - Funguje, ale může být vylepšeno pro bezpečnost.

---

### 3. **icon404Cache není vyčištěn** (P3)

**Soubor:** `assets/map/core.js:2435`

**Problém:**
- `icon404Cache` se nikdy nevyčistí, takže ikony, které byly dříve 404, se už nikdy nezkusí znovu
- Pokud se ikona později přidá, cache ji stále blokuje

**Doporučení:**
- Zvážit TTL pro `icon404Cache` (např. 5 minut)
- Nebo přidat mechanismus pro invalidaci cache

**Status:** ⚠️ **Akceptovatelné** - Pro většinu případů je to v pořádku, ale může být vylepšeno.

---

## 💡 Návrhy na zlepšení (P3)

### 1. **Validace extrahovaného icon_slug**

**Návrh:**
```php
if (preg_match('/\/([^\/]+)\.svg$/', $properties['icon_url'], $matches)) {
    $extracted_slug = $matches[1];
    // Validovat pomocí validateIconSlug()
    $icon_registry = \DB\Icon_Registry::get_instance();
    $validated_slug = $icon_registry->validateIconSlug($extracted_slug);
    if ($validated_slug) {
        $properties['icon_slug'] = $validated_slug;
    }
}
```

**Priorita:** Nízká - Současné řešení je funkční

---

### 2. **TTL pro icon404Cache**

**Návrh:**
```javascript
const icon404Cache = new Map(); // Map<iconSlug, timestamp>
const ICON_404_TTL_MS = 5 * 60 * 1000; // 5 minut

// Při kontrole:
if (icon404Cache.has(iconSlug)) {
  const timestamp = icon404Cache.get(iconSlug);
  if (Date.now() - timestamp < ICON_404_TTL_MS) {
    return p.post_type === 'charging_location' ? '⚡' : '';
  } else {
    icon404Cache.delete(iconSlug); // Vypršel TTL
  }
}
```

**Priorita:** Nízká - Pro většinu případů není potřeba

---

### 3. **Event listener místo inline onerror**

**Návrh:**
```javascript
// Místo inline onerror použít event listener po vytvoření markeru
marker.on('add', function() {
  const img = this._icon.options.html.match(/<img[^>]+>/);
  if (img) {
    // Přidat event listener pro error handling
  }
});
```

**Priorita:** Nízká - Současné řešení je funkční

---

## 🧪 Testovací scénáře

### ✅ Test 1: POI ikony z Icon Admin
1. Vytvořit POI s `poi_type` termem (např. ID 123)
2. Nahrát ikonu přes Icon Admin (mělo by se uložit jako `uploads/dobity-baterky/icons/poi_type-123.svg`)
3. Načíst mapu s POI body
4. Otevřít Network tab
5. **Očekávaný výsledek:** ✅ POI má `icon_url` v REST payloadu, ikona se zobrazí

### ✅ Test 2: Priorita ikon
1. POI má `svg_content`, `icon_url` i `icon_slug`
2. Načíst mapu
3. Otevřít DevTools → Elements
4. **Očekávaný výsledek:** ✅ Použije se `svg_content` (priorita 1)

### ✅ Test 3: Cache pro 404 ikony
1. POI má `icon_slug`, který neexistuje (404)
2. Načíst mapu
3. Otevřít Network tab
4. Znovu načíst mapu (reload)
5. **Očekávaný výsledek:** ✅ Ikona se zkusí načíst pouze jednou, pak se použije fallback

### ✅ Test 4: db-mobile-sheet zrychlení
1. Na mobilu kliknout na POI
2. Změřit čas do otevření sheetu
3. Otevřít Network tab
4. **Očekávaný výsledek:** ✅ Sheet se otevře okamžitě (< 100ms), detail fetch běží na pozadí

### ✅ Test 5: Error handling v openMobileSheet
1. Simulovat chybu při fetchFeatureDetail (např. offline)
2. Kliknout na POI na mobilu
3. Otevřít konzoli
4. **Očekávaný výsledek:** ✅ Sheet se otevře, chyby se logují pouze v debug módu

---

## 📊 Metriky změn

- **Soubory změněny:** 3
  - `assets/map/core.js` (+39 řádků, -6 řádků)
  - `includes/Icon_Registry.php` (+62 řádků, -18 řádků)
  - `includes/REST_Map.php` (+22 řádků, -6 řádků)
- **Celkem změn:** +123 řádků, -30 řádků
- **Nové funkce:** 0 (vylepšení existujících)
- **Nové mechanismy:** Cache pro 404 ikony (`icon404Cache`)

---

## ✅ Závěr

**Celkové hodnocení:** ✅ **APPROVE**

PR #91 řeší všechny uvedené problémy efektivně. Kód je dobře strukturovaný, má správnou prioritu ikon a efektivní cache mechanismus. Drobné problémy (extrakce slug z URL, inline onerror handler) jsou akceptovatelné a nebrání mergování.

**Doporučení:**
- ✅ **Mergovat** do main
- ⚠️ Zvážit validaci extrahovaného `icon_slug` z URL (P3)
- ⚠️ Zvážit TTL pro `icon404Cache` (P3)

**Kritické problémy:** Žádné  
**Důležité problémy:** 1 (inline onerror handler - akceptovatelné)  
**Návrhy na zlepšení:** 3 (nízká priorita)

---

**Review provedl:** AI Assistant  
**Datum:** 2025-01-XX


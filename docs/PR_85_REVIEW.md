# Code Review: PR #85 - Oprava zobrazování ikon na pinech na mapě

## Přehled změn

- **Server:** `includes/REST_Map.php` - vrací `svg_content` jako fallback pokud není `icon_slug`
- **Frontend:** `assets/map/core.js` - přidána kontrola `svg_content` z properties i `featureCache` v `getMarkerHtml()`
- **Frontend:** `assets/map/core.js` - přidán `await preloadIconsFromFeatures()` v `fetchAndRenderRadiusInternal()`
- **Dokumentace:** Přidány dva nové dokumenty s analýzou problému

---

## ✅ Pozitivní aspekty

### 1. Konzistentní logika s nearby items
- ✅ Piny na mapě nyní používají stejnou hierarchii jako nearby items
- ✅ Konzistentní chování napříč aplikací

### 2. Kompromisní řešení
- ✅ Server vrací `svg_content` pouze pokud není `icon_slug`
- ✅ Nezvětšuje response size pro features s `icon_slug` (cache optimalizace)
- ✅ Fallback pro POI bez `icon_slug` (většina POI)

### 3. Dokumentace
- ✅ Přidána podrobná analýza problému
- ✅ Dokumentace nearby items workflow

---

## ⚠️ Problémy a návrhy na zlepšení

### P1: Nekonzistence v `build_minimal_properties()` (P2 - Medium)

**Problém:**
V `build_minimal_properties()` (řádek 3811) se vrací `svg_content` vždy, ale v `handle_map()` (řádek 636-638) se vrací pouze pokud není `icon_slug`.

**Kód:**
```php
// includes/REST_Map.php:3811
'svg_content' => (!empty($icon_data['svg_content']) ? $icon_data['svg_content'] : ''),
```

vs.

```php
// includes/REST_Map.php:636-638
if (empty($properties['icon_slug'])) {
    $properties['svg_content'] = $icon_data['svg_content'] ?? '';
}
```

**Doporučení:**
Sjednotit logiku - v `build_minimal_properties()` také vracet `svg_content` pouze pokud není `icon_slug`:

```php
// includes/REST_Map.php:3809-3811
'icon_slug' => (!empty($icon_data['slug']) ? $icon_data['slug'] : get_post_meta($post->ID, '_icon_slug', true)),
'icon_color' => (!empty($icon_data['color']) ? $icon_data['color'] : get_post_meta($post->ID, '_icon_color', true)),
// Pokud není icon_slug, vrátit svg_content jako fallback (stejně jako v handle_map)
if (empty($properties['icon_slug'])) {
    $properties['svg_content'] = (!empty($icon_data['svg_content']) ? $icon_data['svg_content'] : '');
}
```

**Důvod:**
- Konzistence mezi `handle_map()` a `build_minimal_properties()`
- Stejná optimalizace response size

---

### P2: Duplicitní kontrola `featureCache` (P3 - Low)

**Problém:**
V `getMarkerHtml()` se `featureCache` kontroluje dvakrát:
1. Řádek 10472: `featureCache.get(p.id)?.properties?.icon_slug`
2. Řádek 10485: `featureCache.get(p.id)` znovu pro `svg_content`

**Kód:**
```javascript
// assets/map/core.js:10472
const iconSlug = p.icon_slug || (typeof featureCache !== 'undefined' ? featureCache.get(p.id)?.properties?.icon_slug : null);

// assets/map/core.js:10485
const cachedFeature = typeof featureCache !== 'undefined' ? featureCache.get(p.id) : null;
```

**Doporučení:**
Optimalizovat - získat `cachedFeature` jednou na začátku:

```javascript
// assets/map/core.js
${(() => {
  // PRIORITA 1: svg_content z properties (pokud je - fallback pokud není icon_slug)
  if (p.svg_content && p.svg_content.trim() !== '') {
    return p.post_type === 'charging_location' ? recolorChargerIcon(p.svg_content, p) : p.svg_content;
  }
  
  // Získat cachedFeature jednou
  const cachedFeature = typeof featureCache !== 'undefined' ? featureCache.get(p.id) : null;
  
  // PRIORITA 2: icon_slug z properties nebo featureCache (pro cache optimalizaci)
  const iconSlug = p.icon_slug || (cachedFeature?.properties?.icon_slug || null);
  
  if (iconSlug && iconSlug.trim() !== '') {
    const cachedSvg = iconSvgCache.get(iconSlug);
    if (cachedSvg) {
      return p.post_type === 'charging_location' ? recolorChargerIcon(cachedSvg, p) : cachedSvg;
    }
    // Pokud ještě není v cache, použít fallback na obrázek (ikona se možná ještě načítá)
    const iconUrl = getIconUrl(iconSlug);
    return iconUrl ? `<img src="${iconUrl}" style="width:100%;height:100%;display:block;" alt="">` : '';
  }
  
  // PRIORITA 3: svg_content z featureCache (jako nearby items - pro konzistenci)
  if (cachedFeature && cachedFeature.properties) {
    const cachedProps = cachedFeature.properties;
    if (cachedProps.svg_content && cachedProps.svg_content.trim() !== '') {
      return p.post_type === 'charging_location' ? recolorChargerIcon(cachedProps.svg_content, p) : cachedProps.svg_content;
    }
    if (cachedProps.icon_slug && cachedProps.icon_slug.trim() !== '') {
      const iconUrl = getIconUrl(cachedProps.icon_slug);
      return iconUrl ? `<img src="${iconUrl}" style="width:100%;height:100%;display:block;" alt="">` : '';
    }
  }
  
  // Fallback podle typu
  return p.post_type === 'charging_location' ? '⚡' : '';
})()}
```

**Důvod:**
- Menší overhead (jedna kontrola `featureCache` místo dvou)
- Čitelnější kód

---

### P3: Chybí kontrola na `empty()` vs `trim()` (P3 - Low)

**Problém:**
V PHP se používá `empty($properties['icon_slug'])`, ale v JavaScriptu se kontroluje `iconSlug && iconSlug.trim() !== ''`.

**Kód:**
```php
// includes/REST_Map.php:636
if (empty($properties['icon_slug'])) {
```

vs.

```javascript
// assets/map/core.js:10474
if (iconSlug && iconSlug.trim() !== '') {
```

**Doporučení:**
V PHP by měla být kontrola konzistentnější - `empty()` v PHP považuje `'0'` za empty, ale `trim()` v JS ne. Pro konzistenci použít:

```php
// includes/REST_Map.php:636
if (empty($properties['icon_slug']) || trim($properties['icon_slug']) === '') {
```

**Důvod:**
- Konzistence mezi PHP a JavaScript logikou
- Lepší handling whitespace-only hodnot

---

### P4: Chybí await v `preloadIconsFromFeatures()` (P2 - Medium)

**Problém:**
V `fetchAndRenderRadiusInternal()` (řádek 3032) se přidalo `await preloadIconsFromFeatures(incoming)`, ale v `fetchAndRenderQuickThenFull()` (řádky 2843, 2907) už bylo `await preloadIconsFromFeatures(incoming)`.

**Kontrola:**
- ✅ Řádek 2843: `await preloadIconsFromFeatures(incoming);` - OK
- ✅ Řádek 2907: `await preloadIconsFromFeatures(incoming);` - OK
- ✅ Řádek 3032: `await preloadIconsFromFeatures(incoming);` - OK (nově přidáno)

**Závěr:**
✅ Všechny volání mají `await` - žádný problém.

---

### P5: Duplicitní komentář odstraněn (P4 - Trivial)

**Pozitivní:**
✅ Odstraněn duplicitní komentář na konci souboru (řádek 13795).

---

## 📊 Shrnutí

### Kritické problémy: **0**
### Vysoké priority: **0**
### Střední priority: **1**
- P1: Nekonzistence v `build_minimal_properties()`

### Nízké priority: **2**
- P2: Duplicitní kontrola `featureCache`
- P3: Chybí kontrola na `empty()` vs `trim()`

### Triviální: **1**
- P5: Duplicitní komentář odstraněn ✅

---

## ✅ Doporučení

### Před merge:
1. ✅ **P1 (P2):** Opravit nekonzistenci v `build_minimal_properties()` - použít stejnou logiku jako v `handle_map()` ✅ **OPRAVENO**

### Volitelné (můžeme udělat později):
2. ✅ **P2 (P3):** Optimalizovat duplicitní kontrolu `featureCache` ✅ **OPRAVENO**
3. ✅ **P3 (P3):** Sjednotit kontrolu `empty()` vs `trim()` ✅ **OPRAVENO**

---

## 🎯 Závěr

**Status:** ✅ **Schváleno s drobnými připomínkami**

PR řeší problém správně a konzistentně. Jediný problém je nekonzistence v `build_minimal_properties()`, která by měla být opravena před merge. Ostatní připomínky jsou volitelné optimalizace.

**Doporučení:** Opravit P1 před merge, ostatní můžeme udělat později.


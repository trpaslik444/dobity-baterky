# Analýza problému: Špatné ikony na pinech na mapě

## Problém

- ✅ **Nearby items mají správné ikony** - fungují správně
- ❌ **Piny na mapě mají špatné ikony** - zobrazují se fallback ikony nebo prázdné

## Analýza z HAR souboru

### API Response:
- **Celkem features:** 300
- **S `icon_slug`:** pouze 3 z 20 (15%)
- **S `svg_content`:** 0 z 20 (0%) - **ODSTRANĚNO v PR #84**
- **Bez obou:** 17 z 20 (85%)

### Problém:
Většina features **NEMÁ** `icon_slug`, takže:
1. `preloadIconsFromFeatures()` nenajde žádné ikony k načtení
2. `iconSvgCache` zůstane prázdný
3. Piny na mapě použijí fallback (⚡ nebo prázdné)

## Porovnání logiky

### Nearby items (fungují správně) ✅

**Funkce:** `getItemIcon()` (řádek 6036)

**Hierarchie:**
1. ✅ `props.svg_content` - **PRIORITA 1** - SVG přímo z properties
2. `props.icon_slug` - načtení ze souboru
3. ✅ `featureCache.get(props.id)?.properties?.svg_content` - **PRIORITA 3** - SVG z cache
4. `featureCache.get(props.id)?.properties?.icon_slug` - icon_slug z cache
5. Fallback emoji

**Výsledek:** ✅ Funguje, protože kontroluje `svg_content` z `featureCache`

---

### Piny na mapě (nefungují správně) ❌

**Funkce:** `getMarkerHtml()` (řádek 10465)

**Hierarchie:**
1. ❌ `iconSvgCache.get(iconSlug)` - **POUZE pokud je icon_slug**
2. Fallback na `<img>` pokud není v cache
3. Fallback emoji

**Problém:**
- ❌ **NEKONTROLUJE** `p.svg_content` z properties
- ❌ **NEKONTROLUJE** `featureCache` pro `svg_content`
- ❌ Používá pouze `icon_slug`, který většina features nemá

**Výsledek:** ❌ Fallback ikony nebo prázdné, protože `icon_slug` není dostupný

---

## Příčina problému

### 1. Server nevrací `svg_content` v minimal payload
- V PR #84 jsme odstranili `svg_content` z minimal payload
- Server vrací pouze `icon_slug` (který většina features nemá)

### 2. Piny na mapě nepoužívají `featureCache` pro `svg_content`
- Nearby items kontrolují `featureCache` pro `svg_content`
- Piny na mapě kontrolují pouze `iconSvgCache` podle `icon_slug`

### 3. `preloadIconsFromFeatures()` nenajde ikony k načtení
- Načítá pouze podle `icon_slug`
- Pokud features nemají `icon_slug`, ikony se nenačtou

---

## Řešení

### Řešení 1: Použít stejnou logiku jako nearby items (doporučeno)

**Koncept:** Piny na mapě by měly používat stejnou hierarchii jako nearby items.

**Implementace:**
```javascript
// V getMarkerHtml() funkci
${(() => {
  // PRIORITA 1: svg_content z properties (pokud je)
  if (p.svg_content && p.svg_content.trim() !== '') {
    return p.post_type === 'charging_location' ? recolorChargerIcon(p.svg_content, p) : p.svg_content;
  }
  
  // PRIORITA 2: icon_slug z properties nebo featureCache
  const iconSlug = p.icon_slug || (typeof featureCache !== 'undefined' ? featureCache.get(p.id)?.properties?.icon_slug : null);
  
  if (iconSlug && iconSlug.trim() !== '') {
    const cachedSvg = iconSvgCache.get(iconSlug);
    if (cachedSvg) {
      return p.post_type === 'charging_location' ? recolorChargerIcon(cachedSvg, p) : cachedSvg;
    }
    // Fallback na obrázek pokud není v cache
    const iconUrl = getIconUrl(iconSlug);
    return iconUrl ? `<img src="${iconUrl}" style="width:100%;height:100%;display:block;" alt="">` : '';
  }
  
  // PRIORITA 3: svg_content z featureCache (jako nearby items)
  const cachedFeature = typeof featureCache !== 'undefined' ? featureCache.get(p.id) : null;
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

---

### Řešení 2: Vrátit `svg_content` do minimal payload

**Koncept:** Vrátit `svg_content` do minimal payload (zrušit změnu z PR #84).

**Problém:** 
- Zvětší response size
- Duplikace SVG v response

**Výhoda:**
- Jednodušší řešení
- Ikony budou fungovat okamžitě

---

### Řešení 3: Kombinace - `svg_content` pro ikony bez `icon_slug`

**Koncept:** Vrátit `svg_content` pouze pokud není `icon_slug`.

**Implementace:**
```php
// V REST_Map.php
if (empty($properties['icon_slug'])) {
    // Pokud není icon_slug, vrátit svg_content pro fallback
    $properties['svg_content'] = $icon_data['svg_content'] ?? '';
}
```

---

## Doporučení

**Doporučuji Řešení 1:** Použít stejnou logiku jako nearby items.

**Důvody:**
- ✅ Konzistentní s nearby items
- ✅ Používá `featureCache` pro `svg_content`
- ✅ Nezvětšuje response size
- ✅ Funguje i když není `icon_slug`

**Alternativa:** Řešení 3 - vrátit `svg_content` pouze pokud není `icon_slug` (kompromis mezi velikostí response a funkcionalitou).


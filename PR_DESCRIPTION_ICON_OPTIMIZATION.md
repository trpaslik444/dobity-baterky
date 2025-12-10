# Optimalizace ikon - deduplikace SVG a frontend cache

## Problém

Z analýzy HAR souboru:
- ✅ **Výkon se zrychlil:** Map API volání trvá **3048ms** místo 9+ sekund (70% rychlejší!)
- ❌ **Ikony se nezobrazovaly:** V minimal payload chyběl `svg_content`
- ❌ **Duplikace SVG:** Pokud má 100 POI stejnou ikonu, SVG se vrací 100x v JSON (50KB zbytečných dat)

## Řešení

### 1. Oprava chybějícího svg_content v minimal payload
- Přidán `svg_content` do minimal payload v `REST_Map.php`
- Ikony se nyní zobrazují správně na pinech

### 2. Optimalizace: Deduplikace SVG v response
- **Server:** V minimal payload vrací pouze `icon_slug` (ne `svg_content`)
- **Frontend:** Načítá všechny unikátní ikony paralelně po fetch dat
- **Cache:** Ikony se načítají jednou podle `icon_slug` a používají pro všechny markery

## Implementace

### Server (`includes/REST_Map.php`)
```php
// V minimal payload vracíme pouze icon_slug - frontend načte SVG jednou a použije cache
$properties['icon_slug'] = $icon_data['slug'] ?: get_post_meta($post->ID, '_icon_slug', true);
$properties['icon_color'] = $icon_data['color'] ?: get_post_meta($post->ID, '_icon_color', true);
// svg_content se nevrací v minimal payload - frontend načte podle icon_slug
```

### Frontend (`assets/map/core.js`)

#### Nové funkce:
1. **`preloadIconsFromFeatures(features)`** - Načte všechny unikátní ikony paralelně
2. **`loadIconSvg(iconSlug)`** - Načte SVG podle icon_slug s cache
3. **`iconSvgCache`** - Cache pro SVG ikony podle icon_slug

#### Workflow:
1. Po fetch dat se zavolá `preloadIconsFromFeatures()`
2. Získá všechny unikátní `icon_slug` z features
3. Načte je paralelně pomocí `Promise.allSettled()`
4. Uloží do `iconSvgCache`
5. Při renderování markerů použije cached SVG

## Výhody

- ✅ **Menší JSON response:** Z ~101.9 KB na ~30-40 KB (60-70% úspora)
- ✅ **Rychlejší přenos dat:** Méně duplikovaných dat
- ✅ **Ikony se načítají pouze jednou:** Browser cache funguje pro SVG soubory
- ✅ **Paralelní načítání:** Všechny ikony se načítají najednou
- ✅ **Žádná duplikace v DOM:** Cached SVG se používá pro všechny markery

## Očekávané zlepšení

### Response size:
- **Před:** ~101.9 KB (300 features, každý s vlastním SVG)
- **Po:** ~30-40 KB (300 features, pouze icon_slug)
- **Úspora:** 60-70%

### Výkon:
- Rychlejší přenos dat
- Méně paměti na frontendu
- Rychlejší renderování markerů

## Testování

1. ✅ Ověřit, že ikony se zobrazují správně na pinech
2. ✅ Ověřit, že response size je menší
3. ✅ Ověřit, že ikony se načítají pouze jednou (Network tab)
4. ✅ Ověřit, že výkon zůstává rychlý (3048ms)

## Změněné soubory

- `includes/REST_Map.php` - odstraněn svg_content z minimal payload
- `assets/map/core.js` - přidána logika pro načítání a cache ikon
- `docs/ICON_FIX_MINIMAL_PAYLOAD.md` - dokumentace opravy
- `docs/ICON_DEDUPLICATION_OPTIMIZATION.md` - dokumentace optimalizace

## Související PR

Tento PR navazuje na PR #83, který řešil:
- Opravy ikon a optimalizace výkonu mapy
- Progressive loading pro rychlejší vnímaný výkon
- Batch loading a cache na serveru


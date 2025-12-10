# Oprava: Špatné ikony na pinech na mapě

## Problém

- ✅ **Nearby items mají správné ikony** - fungují správně
- ❌ **Piny na mapě mají špatné ikony** - zobrazují se fallback ikony nebo prázdné

## Analýza

### Z HAR souboru:
- Většina features **NEMÁ** `icon_slug` (pouze 15% má icon_slug)
- V PR #84 jsme odstranili `svg_content` z minimal payload
- Piny na mapě používaly pouze `iconSvgCache` podle `icon_slug`
- Pokud není `icon_slug`, ikony se nenačítají → fallback ikony

### Porovnání logiky:

**Nearby items (fungují):**
1. ✅ `props.svg_content` - SVG přímo z properties
2. ✅ `featureCache.get(props.id)?.properties?.svg_content` - SVG z cache
3. Fallback emoji

**Piny na mapě (nefungovaly):**
1. ❌ Pouze `iconSvgCache.get(iconSlug)` - pokud není icon_slug, nefunguje
2. Fallback emoji

## Řešení

### 1. Server (`REST_Map.php`)
- Vrací `svg_content` **pouze pokud není `icon_slug`**
- Kompromis: POI bez `icon_slug` dostanou `svg_content`, ostatní používají cache podle `icon_slug`

### 2. Frontend (`core.js` - `getMarkerHtml()`)
- **Stejná hierarchie jako nearby items:**
  1. PRIORITA 1: `svg_content` z properties (pokud je)
  2. PRIORITA 2: `icon_slug` → `iconSvgCache` (pro cache optimalizaci)
  3. PRIORITA 3: `svg_content` z `featureCache` (jako nearby items)
  4. Fallback emoji

## Výsledek

- ✅ Piny na mapě mají stejnou logiku jako nearby items
- ✅ Ikony se zobrazují správně i když není `icon_slug`
- ✅ Cache optimalizace zůstává pro features s `icon_slug`
- ✅ Konzistentní chování napříč aplikací

## Změněné soubory

- `includes/REST_Map.php` - vrací svg_content jako fallback pokud není icon_slug
- `assets/map/core.js` - přidána kontrola svg_content z properties i featureCache
- `docs/MAP_PIN_ICON_PROBLEM_ANALYSIS.md` - analýza problému
- `docs/NEARBY_ITEM_ICON_RENDERING.md` - dokumentace nearby items

## Testování

1. ✅ Ověřit, že ikony se zobrazují správně na pinech na mapě
2. ✅ Ověřit, že nearby items stále fungují správně
3. ✅ Ověřit, že cache optimalizace funguje pro features s icon_slug


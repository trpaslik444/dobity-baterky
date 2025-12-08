# Optimalizace: Deduplikace SVG ikon v API response

## Aktuální stav

### Server-side cache ✅
- `Icon_Registry::$svg_cache` - SVG se načítá ze souboru pouze jednou na serveru
- Cache klíč: `post_type + icon_slug + color`
- **Problém:** I když je SVG v cache, vrací se v každém feature zvlášť v API response

### API response ❌
- Pokud má 100 POI stejnou ikonu (např. `poi_type-768414503`), SVG se vrací 100x v JSON
- Příklad: SVG o velikosti 500 znaků × 100 features = 50KB zbytečných dat
- Response size: 101.9 KB (300 features) - mohlo by být menší

### Frontend ❌
- Frontend používá `svg_content` přímo z properties
- Žádná cache pro SVG podle `icon_slug`
- Každý marker má vlastní kopii SVG v HTML

## Problém

**Duplikace SVG v response:**
```
Feature 1: { svg_content: "<svg>...</svg>" }  // 500 znaků
Feature 2: { svg_content: "<svg>...</svg>" }  // stejných 500 znaků
Feature 3: { svg_content: "<svg>...</svg>" }  // stejných 500 znaků
...
Feature 100: { svg_content: "<svg>...</svg>" }  // stejných 500 znaků
```

**Celkem:** 50KB zbytečných dat místo 500 znaků

## Možná řešení

### Řešení 1: Deduplikace na serveru (doporučeno)

**Koncept:** Vrátit SVG pouze jednou v response a odkazovat se na něj pomocí ID.

**Implementace:**
```php
// V REST_Map.php
$icon_svg_map = []; // Mapování icon_slug -> SVG

foreach ($posts as $post) {
    $icon_data = $icon_registry->get_icon($post);
    $icon_slug = $icon_data['slug'] ?: get_post_meta($post->ID, '_icon_slug', true);
    
    // Přidat SVG do mapy pouze jednou
    if (!isset($icon_svg_map[$icon_slug])) {
        $icon_svg_map[$icon_slug] = $icon_data['svg_content'] ?? '';
    }
    
    $properties['icon_slug'] = $icon_slug;
    // NENÍ svg_content v properties
}

// Na konci response přidat mapu SVG
$response = [
    'features' => $features,
    'icons' => $icon_svg_map  // Mapování icon_slug -> SVG
];
```

**Frontend:**
```javascript
// Cache SVG podle icon_slug
const iconSvgCache = new Map();

function getSvgForIcon(iconSlug) {
    if (!iconSlug) return '';
    if (iconSvgCache.has(iconSlug)) {
        return iconSvgCache.get(iconSlug);
    }
    // Načíst z response.icons
    if (window.mapResponse && window.mapResponse.icons && window.mapResponse.icons[iconSlug]) {
        const svg = window.mapResponse.icons[iconSlug];
        iconSvgCache.set(iconSlug, svg);
        return svg;
    }
    return '';
}

// V getMarkerHtml:
const svg = getSvgForIcon(p.icon_slug);
```

**Výhody:**
- ✅ Snížení velikosti response (např. z 101.9 KB na ~30-40 KB)
- ✅ Rychlejší přenos dat
- ✅ Méně paměti na frontendu

**Nevýhody:**
- ⚠️ Vyžaduje změnu frontendu
- ⚠️ Složitější logika

---

### Řešení 2: Pouze icon_slug v minimal payload

**Koncept:** Vracet pouze `icon_slug` a nechat frontend načíst SVG jednou pomocí cache.

**Implementace:**
```php
// V REST_Map.php - minimal payload
$properties['icon_slug'] = $icon_data['slug'] ?: get_post_meta($post->ID, '_icon_slug', true);
// NENÍ svg_content v minimal payload
```

**Frontend:**
```javascript
// Cache SVG podle icon_slug
const iconSvgCache = new Map();

async function loadSvgForIcon(iconSlug) {
    if (!iconSlug) return '';
    if (iconSvgCache.has(iconSlug)) {
        return iconSvgCache.get(iconSlug);
    }
    
    // Načíst SVG ze souboru nebo API
    const iconUrl = getIconUrl(iconSlug);
    if (iconUrl) {
        try {
            const response = await fetch(iconUrl);
            const svg = await response.text();
            iconSvgCache.set(iconSlug, svg);
            return svg;
        } catch (err) {
            console.warn('Failed to load icon:', iconSlug);
            return '';
        }
    }
    return '';
}
```

**Výhody:**
- ✅ Nejmenší response size
- ✅ SVG se načítá pouze jednou na frontendu
- ✅ Browser cache pro SVG soubory

**Nevýhody:**
- ⚠️ Vyžaduje další HTTP requesty pro SVG
- ⚠️ Slower initial render (ale může být paralelní)
- ⚠️ Vyžaduje změnu frontendu

---

### Řešení 3: Frontend cache pro SVG (nejjednodušší)

**Koncept:** Zachovat současný stav, ale přidat cache na frontendu.

**Implementace:**
```javascript
// Cache SVG podle icon_slug
const iconSvgCache = new Map();

function getCachedSvg(iconSlug, svgContent) {
    if (!iconSlug) return svgContent || '';
    
    // Pokud už máme SVG v cache, použít ho
    if (iconSvgCache.has(iconSlug)) {
        return iconSvgCache.get(iconSlug);
    }
    
    // Uložit do cache
    if (svgContent) {
        iconSvgCache.set(iconSlug, svgContent);
        return svgContent;
    }
    
    return '';
}

// V getMarkerHtml:
const svg = getCachedSvg(p.icon_slug, p.svg_content);
```

**Výhody:**
- ✅ Jednoduchá implementace
- ✅ Žádné změny na serveru
- ✅ Méně duplikace v DOM

**Nevýhody:**
- ⚠️ Stále velká response size
- ⚠️ SVG se stále vrací v každém feature

---

## Doporučení

**Krátkodobě:** Řešení 3 (frontend cache) - rychlá implementace, okamžité zlepšení

**Dlouhodobě:** Řešení 1 (deduplikace na serveru) - největší úspora dat, ale vyžaduje více práce

## Odhadované zlepšení

**Aktuální stav:**
- Response: 101.9 KB (300 features)
- Pokud má 100 POI stejnou ikonu (500 znaků SVG):
  - Duplikace: 100 × 500 = 50KB zbytečných dat

**Po deduplikaci (Řešení 1):**
- Response: ~50-60 KB (50% úspora)
- Rychlejší přenos dat
- Méně paměti na frontendu

## Implementace

Doporučuji začít s **Řešením 3** (frontend cache) jako rychlou opravu, pak implementovat **Řešení 1** (deduplikace na serveru) pro dlouhodobou optimalizaci.


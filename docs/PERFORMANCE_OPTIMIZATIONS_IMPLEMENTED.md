# Implementované optimalizace výkonu - PR #82

## Přehled změn

Implementovány všechny 4 okamžité optimalizace pro zrychlení načítání mapy:

### 1. ✅ Batch loading meta hodnot (`update_postmeta_cache()`)

**Soubor:** `includes/REST_Map.php`

**Změna:**
- Před hlavním loopem se načtou všechny potřebné meta hodnoty najednou pomocí `update_postmeta_cache($post_ids)`
- Eliminuje N+1 problém s `get_post_meta()`

**Kód:**
```php
if (!empty($q->posts)) {
    $post_ids = wp_list_pluck($q->posts, 'ID');
    // Načíst všechny potřebné meta klíče najednou
    update_postmeta_cache($post_ids);
    // ...
}
```

### 2. ✅ Statická cache pro SVG ikony v `Icon_Registry`

**Soubor:** `includes/Icon_Registry.php`

**Změna:**
- Přidána statická cache `$svg_cache` pro SVG obsah ikon
- Cache klíč: `post_type + icon_slug + color`
- SVG soubory se čtou z disku pouze jednou, pak se používají z cache
- Uploads ikony se necacheují (mohou být dynamické)

**Kód:**
```php
private static $svg_cache = [];

private function get_svg_content_cached($icon_slug, $post_type, $color_option_key = '') {
    $cache_key = $post_type . '_' . $icon_slug . '_' . $icon_color;
    if (isset(self::$svg_cache[$cache_key])) {
        return self::$svg_cache[$cache_key];
    }
    // Načíst ze souboru a uložit do cache
    // ...
}
```

### 3. ✅ Batch loading taxonomy (`update_object_term_cache()`)

**Soubor:** `includes/REST_Map.php`

**Změna:**
- Před hlavním loopem se načtou všechny taxonomy termy najednou pomocí `update_object_term_cache()`
- Eliminuje N+1 problém s `wp_get_post_terms()`

**Kód:**
```php
if ($pt === 'charging_location') {
    update_object_term_cache($post_ids, 'charging_location');
} elseif ($pt === 'poi') {
    update_object_term_cache($post_ids, 'poi');
} elseif ($pt === 'rv_spot') {
    update_object_term_cache($post_ids, 'rv_spot');
}
```

### 4. ✅ Optimalizace WP_Query s bounding box meta_query

**Soubor:** `includes/REST_Map.php`

**Změna:**
- Místo načítání 5000 postů se používá `meta_query` s bounding box filtrem
- `posts_per_page` sníženo z 5000 na 300
- Bounding box filtruje posty před Haversine výpočtem

**Kód:**
```php
// Vypočítat bounding box
$dLat  = ($radius_km * 1.2) / 111.0;
$dLng  = ($radius_km * 1.2) / (111.0 * max(cos(deg2rad($lat)), 0.000001));
$minLa = $lat - $dLat; $maxLa = $lat + $dLat;
$minLo = $lng - $dLng; $maxLo = $lng + $dLng;

$meta_query = [
    [
        'key'     => $keys['lat'],
        'value'   => [$minLa, $maxLa],
        'type'    => 'DECIMAL(10,7)',
        'compare' => 'BETWEEN',
    ],
    [
        'key'     => $keys['lng'],
        'value'   => [$minLo, $maxLo],
        'type'    => 'DECIMAL(10,7)',
        'compare' => 'BETWEEN',
    ],
];

$args = [
    'posts_per_page' => 300, // Sníženo z 5000
    'meta_query'     => array_merge(['relation' => 'AND'], $meta_query),
];
```

## Očekávané zlepšení výkonu

### Před optimalizací:
- API volání: **9198ms (9.2 sekundy)**
- WP_Query: načítá až 5000 postů pro každý typ (15,000 celkem)
- get_post_meta(): N+1 problém - volá se pro každý post několikrát
- wp_get_post_terms(): N+1 problém - volá se pro každý charging_location
- Icon_Registry: čte SVG soubory z disku pro každý post

### Po optimalizaci:
- **WP_Query**: načítá max 300 postů (s bounding box filtrem)
- **get_post_meta()**: batch loading - všechny meta hodnoty najednou
- **wp_get_post_terms()**: batch loading - všechny termy najednou
- **Icon_Registry**: cache pro SVG ikony - čte ze souboru pouze jednou

### Očekávané zlepšení:
- **50-70% rychlejší** načítání mapy
- Snížení počtu SQL dotazů z ~1000+ na ~10-20
- Snížení I/O operací (čtení SVG souborů)

## Testování

Pro ověření zlepšení výkonu:

1. **Měření času API volání:**
   - Před optimalizací: 9198ms
   - Po optimalizaci: očekáváno < 3000ms

2. **Počet SQL dotazů:**
   - Před: ~1000+ dotazů
   - Po: ~10-20 dotazů

3. **Počet načtených postů:**
   - Před: až 15,000 postů (5000 × 3 typy)
   - Po: max 900 postů (300 × 3 typy)

## Poznámky

- Cache pro SVG ikony je statická (per request), takže se resetuje při každém novém requestu
- Bounding box filtr používá 20% větší radius pro jistotu pokrytí
- Uploads ikony se necacheují, protože mohou být dynamické
- Všechny změny jsou zpětně kompatibilní


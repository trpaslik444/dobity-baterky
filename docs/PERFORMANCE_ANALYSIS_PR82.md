# Analýza výkonu a optimalizace - PR #82

## Problém

API volání na `/wp-json/db/v1/map` trvá **9198ms (9.2 sekundy)** a vrací 300 features (88KB response).

## Analýza

### Co se děje při volání map endpointu:

1. **WP_Query pro každý post_type:**
   - `charging_location`: až 5000 postů
   - `poi`: až 5000 postů  
   - `rv_spot`: až 5000 postů
   - Celkem: až 15,000 postů před filtrováním

2. **Pro každý post se volá:**
   - `get_post_meta($post->ID, '_db_lat', true)` - lat
   - `get_post_meta($post->ID, '_db_lng', true)` - lng
   - `get_post_meta($post->ID, '_icon_slug', true)` - icon_slug
   - `get_post_meta($post->ID, '_icon_color', true)` - icon_color
   - `get_post_meta($post->ID, '_db_recommended', true)` - db_recommended
   - `Icon_Registry::get_icon($post)` - který může číst SVG soubory z disku
   - `wp_get_post_terms($post->ID, 'provider')` - pro charging_location
   - `wp_get_post_terms($post->ID, 'charger_type')` - pro charging_location

3. **Haversine výpočet:**
   - Pro každý post se počítá vzdálenost od středu mapy
   - Filtruje se podle radius_km
   - Seřadí se podle vzdálenosti
   - Ořízne se na limit (300)

### Hlavní problémy výkonu:

1. **N+1 problém s `get_post_meta()`:**
   - Pro každý post se volá `get_post_meta()` několikrát
   - WordPress cache pomáhá, ale stále je to pomalé

2. **Čtení SVG souborů z disku:**
   - `Icon_Registry::get_icon()` čte SVG soubory z disku pro každý post
   - Žádná cache pro SVG obsah

3. **Taxonomy queries:**
   - `wp_get_post_terms()` se volá pro každý charging_location
   - Může být optimalizováno pomocí `update_object_term_cache()`

4. **WP_Query načítá příliš mnoho postů:**
   - 5000 postů pro každý typ před filtrováním
   - Haversine se počítá pro všechny, i když většina bude vyřazena

## Navrhované optimalizace

### 1. Batch loading meta hodnot

**Současný stav:**
```php
foreach ($q->posts as $post) {
    $laV = get_post_meta($post->ID, $keys['lat'], true);
    $loV = get_post_meta($post->ID, $keys['lng'], true);
    // ...
}
```

**Optimalizace:**
```php
// Načíst všechny meta hodnoty najednou
$post_ids = wp_list_pluck($q->posts, 'ID');
update_postmeta_cache($post_ids);

// Nebo použít get_post_meta() s více klíči najednou
$meta_keys = [$keys['lat'], $keys['lng'], '_icon_slug', '_icon_color', '_db_recommended'];
foreach ($meta_keys as $key) {
    update_postmeta_cache($post_ids, $key);
}
```

### 2. Cache pro SVG ikony

**Současný stav:**
```php
$icon_registry = \DB\Icon_Registry::get_instance();
$icon_data = $icon_registry->get_icon($post);
// Čte SVG soubor z disku pro každý post
```

**Optimalizace:**
- Přidat statickou cache do `Icon_Registry`
- Cache klíč: `icon_slug + post_type + color`
- TTL: permanent (SVG soubory se nemění často)

### 3. Optimalizace taxonomy queries

**Současný stav:**
```php
$provider_terms = wp_get_post_terms($post->ID, 'provider');
$charger_terms = wp_get_post_terms($post->ID, 'charger_type');
```

**Optimalizace:**
```php
// Načíst všechny taxonomy termy najednou
update_object_term_cache($post_ids, ['charging_location']);
```

### 4. Optimalizace WP_Query s bounding box

**Současný stav:**
- WP_Query načítá až 5000 postů
- Pak se filtruje Haversine

**Optimalizace:**
- Přidat meta_query pro bounding box před Haversine
- Snížit `posts_per_page` na rozumnější hodnotu (např. 1000)
- Použít `meta_query` pro lat/lng bounding box

### 5. Přidat `svg_content` do minimal payload

**Současný stav:**
- `fields_mode='minimal'` nevrací `svg_content`
- Frontend musí načítat ikony z `icon_slug` nebo použít fallback

**Optimalizace:**
- Přidat `svg_content` do minimal payload (pokud je k dispozici)
- Ušetří frontend requesty na ikony

## Otázky pro uživatele

1. **Kolik je celkem postů v databázi?**
   - `charging_location`: ?
   - `poi`: ?
   - `rv_spot`: ?

2. **Jaký je typický radius při načítání mapy?**
   - 50km (max)?
   - Nebo menší?

3. **Kolik postů je typicky v radiusu 50km?**
   - Kolik charging_location?
   - Kolik POI?
   - Kolik RV?

4. **Máme přístup k server logs?**
   - Můžeme zjistit, kde přesně trvá nejdéle?
   - WP_Query?
   - get_post_meta()?
   - Icon_Registry?
   - Haversine výpočet?

5. **Používá se `fields_mode='minimal'` nebo `'full'`?**
   - Z HAR souboru není vidět parametr `fields`
   - Pokud není specifikován, default je `'minimal'`

6. **Je možné použít database indexy?**
   - Máme indexy na `_db_lat`, `_db_lng`?
   - Máme indexy na `_poi_lat`, `_poi_lng`?
   - Máme indexy na `_rv_lat`, `_rv_lng`?

## Doporučené kroky

1. **Okamžitá oprava:**
   - Přidat `update_postmeta_cache()` před loop
   - Přidat cache do `Icon_Registry`
   - Přidat `update_object_term_cache()` pro taxonomy

2. **Střednědobá optimalizace:**
   - Optimalizovat WP_Query s bounding box
   - Přidat `svg_content` do minimal payload
   - Snížit `posts_per_page` na rozumnější hodnotu

3. **Dlouhodobá optimalizace:**
   - Database indexy na lat/lng meta klíče
   - Možná použít spatial index (MySQL 5.7+)
   - Zvážit Redis cache pro map endpoint

## Měření výkonu

Pro testování výkonu potřebujeme:

1. **Server-side timing:**
   ```php
   $start = microtime(true);
   // ... kód ...
   $elapsed = microtime(true) - $start;
   error_log("Map endpoint timing: {$elapsed}s");
   ```

2. **Breakdown timing:**
   - WP_Query čas
   - get_post_meta() čas
   - Icon_Registry čas
   - Haversine čas
   - Serialization čas

3. **Database queries:**
   - Počet SQL dotazů
   - Čas každého dotazu
   - Slow query log


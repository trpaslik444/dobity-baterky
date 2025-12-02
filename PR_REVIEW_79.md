# PR Review #79: Simplify POI fetching - Direct Wikidata integration

## 📋 Přehled

PR zjednodušuje stahování POIs z free zdrojů (Wikidata, OpenTripMap) přímo v WordPressu, bez potřeby samostatného Node.js microservice.

---

## ✅ Pozitivní změny

### 1. **Jednoduchá architektura**
- ✅ Vše v PHP, bez potřeby Node.js microservice
- ✅ Automatické stahování při potřebě
- ✅ Wikidata vždy dostupné (bez API key)

### 2. **Dobrá dokumentace**
- ✅ `POI_FETCHING_WORKFLOW.md` - detailní workflow dokumentace
- ✅ Komentáře v kódu vysvětlují logiku

### 3. **Cache mechanismus**
- ✅ 1 hodina cache pro stahování POIs
- ✅ Prevence duplicitních API callů

---

## 🔴 Kritické problémy (P1)

### P1.1: Chybějící error handling pro Wikidata SPARQL query

**Soubor:** `includes/Providers/Wikidata_Provider.php:27-73`

**Problém:**
- Wikidata SPARQL query může selhat (syntax error, timeout, atd.)
- Chybí validace response struktury
- Chybí logování chyb

**Doporučení:**
```php
public function search_around($lat, $lng, $radius, $categories = array()) {
    $pois = array();
    
    try {
        $query = $this->build_query($lat, $lng, $radius);
        
        $response = wp_remote_post($this->api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/sparql-results+json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'DobityBaterky/1.0 (https://dobitybaterky.cz)',
            ),
            'body' => http_build_query(array(
                'query' => $query,
                'format' => 'json',
            )),
        ));
        
        if (is_wp_error($response)) {
            error_log('[Wikidata Provider] Error: ' . $response->get_error_message());
            return array();
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            error_log('[Wikidata Provider] HTTP ' . $status_code . ': ' . substr($body, 0, 200));
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[Wikidata Provider] JSON decode error: ' . json_last_error_msg());
            return array();
        }
        
        if (!isset($data['results']['bindings']) || !is_array($data['results']['bindings'])) {
            error_log('[Wikidata Provider] Invalid response structure');
            return array();
        }
        
        // ... rest of the code
    } catch (\Exception $e) {
        error_log('[Wikidata Provider] Exception: ' . $e->getMessage());
        return array();
    }
}
```

---

### P1.2: Chybějící validace GPS souřadnic v build_query()

**Soubor:** `includes/Providers/Wikidata_Provider.php:78-120`

**Problém:**
- `$lat`, `$lng`, `$radius` nejsou validovány před použitím v SPARQL query
- Může vést k SQL injection-like útokům (SPARQL injection)

**Doporučení:**
```php
private function build_query($lat, $lng, $radius) {
    // Validace vstupů
    $lat = (float) $lat;
    $lng = (float) $lng;
    $radius = (int) $radius;
    
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        throw new \InvalidArgumentException('Invalid GPS coordinates');
    }
    
    if ($radius < 100 || $radius > 50000) {
        throw new \InvalidArgumentException('Invalid radius (100-50000 meters)');
    }
    
    // Převést radius z metrů na kilometry pro Wikidata
    $radius_km = $radius / 1000;
    
    // Escape hodnoty pro SPARQL (i když jsou to čísla, je to bezpečnější)
    $lat_escaped = esc_sql($lat);
    $lng_escaped = esc_sql($lng);
    $radius_km_escaped = esc_sql($radius_km);
    
    // ... rest of the query
}
```

---

### P1.3: Race condition při vytváření POI postů

**Soubor:** `includes/Jobs/Nearby_Recompute_Job.php:920-1000`

**Problém:**
- `find_existing_poi_post()` a `create_poi_post()` nejsou v transakci
- Může dojít k duplicitním POI postům při paralelním zpracování

**Doporučení:**
```php
private function create_or_update_poi_post($poi) {
    global $wpdb;
    
    if (!post_type_exists('poi')) {
        return false;
    }
    
    // Validace
    if (!isset($poi['name']) || !isset($poi['lat']) || !isset($poi['lon'])) {
        return false;
    }
    
    $lat = (float) $poi['lat'];
    $lng = (float) $poi['lon'];
    $name = sanitize_text_field($poi['name']);
    
    // Validace GPS
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return false;
    }
    
    // START TRANSACTION
    $wpdb->query('START TRANSACTION');
    
    try {
        // Najít existující POI (s FOR UPDATE lock)
        $existing_id = $this->find_existing_poi_post($poi, $lat, $lng, $name);
        
        if ($existing_id) {
            // Aktualizovat
            wp_update_post(array(
                'ID' => $existing_id,
                'post_title' => $name,
            ));
            $this->update_poi_post_meta($existing_id, $poi);
            $wpdb->query('COMMIT');
            return $existing_id;
        } else {
            // Vytvořit nový
            $post_id = wp_insert_post(array(
                'post_type' => 'poi',
                'post_title' => $name,
                'post_status' => 'publish',
            ), true);
            
            if (is_wp_error($post_id)) {
                $wpdb->query('ROLLBACK');
                return false;
            }
            
            $this->update_poi_post_meta($post_id, $poi);
            $wpdb->query('COMMIT');
            return $post_id;
        }
    } catch (\Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('[POI Fetch] Transaction failed: ' . $e->getMessage());
        return false;
    }
}

private function find_existing_poi_post($poi, $lat, $lng, $name) {
    global $wpdb;
    
    // Zkusit podle source_id (s FOR UPDATE lock)
    if (isset($poi['source']) && isset($poi['source_id'])) {
        $external_id = $poi['source'] . ':' . $poi['source_id'];
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_poi_external_id' AND meta_value = %s 
             LIMIT 1 FOR UPDATE",
            $external_id
        ));
        if ($post_id) {
            return (int) $post_id;
        }
    }
    
    // Zkusit podle GPS + jméno (s FOR UPDATE lock)
    $candidates = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, 
                pm_lat.meta_value+0 AS lat,
                pm_lng.meta_value+0 AS lon,
                p.post_title
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = '_poi_lat'
         INNER JOIN {$wpdb->postmeta} pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = '_poi_lng'
         WHERE p.post_type = 'poi' 
         AND p.post_status = 'publish'
         AND (
             6371 * ACOS(
                 COS(RADIANS(%f)) * COS(RADIANS(pm_lat.meta_value+0)) *
                 COS(RADIANS(pm_lng.meta_value+0) - RADIANS(%f)) +
                 SIN(RADIANS(%f)) * SIN(RADIANS(pm_lat.meta_value+0))
             )
         ) <= 0.05
         LIMIT 10 FOR UPDATE",
        $lat, $lng, $lat
    ));
    
    foreach ($candidates as $candidate) {
        $distance = $this->haversine_km($lat, $lng, (float) $candidate->lat, (float) $candidate->lon);
        if ($distance <= 0.05) { // 50 metrů
            $similarity = $this->name_similarity($name, $candidate->post_title);
            if ($similarity > 0.8) { // 80% podobnost
                return (int) $candidate->ID;
            }
        }
    }
    
    return null;
}
```

---

## 🟡 Vysoké priority (P2)

### P2.1: Chybějící rate limiting pro Wikidata API

**Soubor:** `includes/Providers/Wikidata_Provider.php`

**Problém:**
- Wikidata má rate limiting (max 60 requests/min)
- Při batch processingu může dojít k překročení limitu

**Doporučení:**
```php
class Wikidata_Provider {
    private $api_url = 'https://query.wikidata.org/sparql';
    private static $last_request_time = 0;
    private static $request_count = 0;
    private const MIN_REQUEST_INTERVAL = 1; // 1 sekunda mezi requesty
    private const MAX_REQUESTS_PER_MINUTE = 50; // Bezpečný limit
    
    public function search_around($lat, $lng, $radius, $categories = array()) {
        // Rate limiting
        $now = time();
        if ($now - self::$last_request_time < self::MIN_REQUEST_INTERVAL) {
            sleep(self::MIN_REQUEST_INTERVAL);
        }
        
        if (self::$request_count >= self::MAX_REQUESTS_PER_MINUTE) {
            error_log('[Wikidata Provider] Rate limit reached, waiting...');
            sleep(60);
            self::$request_count = 0;
        }
        
        self::$last_request_time = time();
        self::$request_count++;
        
        // ... rest of the code
    }
}
```

---

### P2.2: Chybějící timeout handling pro OpenTripMap

**Soubor:** `includes/Providers/OpenTripMap_Provider.php:32-70`

**Problém:**
- Timeout je 10 sekund, ale není handling pro timeout
- Při timeoutu se vrátí prázdné pole, ale není logování

**Doporučení:**
```php
$response = wp_remote_get($url, array(
    'timeout' => 10,
));

if (is_wp_error($response)) {
    $error_code = $response->get_error_code();
    if ($error_code === 'http_request_failed' || $error_code === 'timeout') {
        error_log('[OpenTripMap Provider] Timeout or connection error: ' . $response->get_error_message());
    }
    continue;
}

$status_code = wp_remote_retrieve_response_code($response);
if ($status_code !== 200) {
    $body = wp_remote_retrieve_body($response);
    error_log('[OpenTripMap Provider] HTTP ' . $status_code . ': ' . substr($body, 0, 200));
    continue;
}
```

---

### P2.3: Chybějící validace kategorie v normalize_poi()

**Soubor:** `includes/Providers/OpenTripMap_Provider.php:120-160`

**Problém:**
- `pick_category()` může vrátit kategorii, která není v `ALLOWED_CATEGORIES`
- POI se vytvoří s neplatnou kategorií

**Doporučení:**
```php
private function normalize_poi($item) {
    // ... existing code ...
    
    $category = $this->pick_category($item);
    if (empty($category)) {
        return null;
    }
    
    // Validace kategorie
    $allowed = array('restaurant', 'cafe', 'bar', 'pub', 'fast_food', 'bakery', 'park', 'playground', 
                    'garden', 'sports_centre', 'swimming_pool', 'beach', 'tourist_attraction', 
                    'viewpoint', 'museum', 'gallery', 'zoo', 'aquarium', 'shopping_mall', 
                    'supermarket', 'marketplace');
    
    if (!in_array($category, $allowed, true)) {
        error_log('[OpenTripMap Provider] Invalid category: ' . $category);
        return null;
    }
    
    // ... rest of the code
}
```

---

## 🟢 Nízké priority (P3)

### P3.1: Chybějící monitoring/statistiky

**Doporučení:**
- Přidat statistiky pro počet stažených POIs z každého provideru
- Ukládat do WordPress options pro zobrazení v admin rozhraní

### P3.2: Chybějící unit testy

**Doporučení:**
- Přidat unit testy pro `OpenTripMap_Provider` a `Wikidata_Provider`
- Testovat normalizaci, validaci, error handling

### P3.3: Chybějící dokumentace pro SPARQL query

**Doporučení:**
- Přidat komentáře k SPARQL query v `build_query()`
- Vysvětlit, proč jsou použity konkrétní Wikidata typy

---

## 📝 Shrnutí

### Kritické problémy (P1): 3
- P1.1: Chybějící error handling pro Wikidata SPARQL query
- P1.2: Chybějící validace GPS souřadnic v build_query()
- P1.3: Race condition při vytváření POI postů

### Vysoké priority (P2): 3
- P2.1: Chybějící rate limiting pro Wikidata API
- P2.2: Chybějící timeout handling pro OpenTripMap
- P2.3: Chybějící validace kategorie v normalize_poi()

### Nízké priority (P3): 3
- P3.1: Chybějící monitoring/statistiky
- P3.2: Chybějící unit testy
- P3.3: Chybějící dokumentace pro SPARQL query

---

## ✅ Doporučení

**Před merge:**
1. ✅ Opravit P1 problémy (kritické)
2. ✅ Opravit P2 problémy (doporučeno)
3. ⚠️ P3 problémy lze opravit později

**Celkové hodnocení:** ⚠️ **Potřebuje opravy před merge** (P1 problémy jsou kritické)


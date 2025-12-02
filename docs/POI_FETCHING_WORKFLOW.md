# Workflow stahování POIs z Wikidata pro existující nabíječky

## 📋 Přehled

POIs z Wikidata se stahují a vytvářejí **automaticky** při hledání nearby POIs pro nabíječky. Pro existující nabíječky se to stane při jejich zpracování ve frontě.

---

## 🔄 Kdy se POIs stahují z Wikidata?

### 1. **Při kontrole kandidátů (před zařazením do fronty)**

Když se nabíječka zařazuje do fronty pro nearby recompute:

```php
// includes/Jobs/Nearby_Queue_Manager.php:52-70
public function enqueue($origin_id, $origin_type, $priority = 0) {
    // Zkontrolovat, zda má bod kandidáty v okolí
    if (!$this->has_candidates_in_area($origin_id, $origin_type)) {
        return false; // Nemá kandidáty, nezařadit do fronty
    }
    // ...
}
```

**Co se stane:**
1. Zavolá se `has_candidates_in_area()` → `get_candidates()` s `type='poi'`
2. `get_candidates()` zavolá `fetch_pois_from_providers()` 
3. **POIs se stáhnou z Wikidata** a vytvoří se WordPress posty
4. Pak se zkontroluje, zda jsou nějaké POIs v databázi

**Důležité:** POIs se stáhnou **před** zařazením do fronty, aby se zjistilo, zda má smysl nabíječku zpracovávat.

---

### 2. **Při zpracování nearby recompute jobu**

Když se zpracovává položka z fronty:

```php
// includes/Jobs/Nearby_Recompute_Job.php:298
$candidates = $this->get_candidates($lat, $lng, $type, $radiusKm, $maxCand);
```

**Co se stane:**
1. Zavolá se `get_candidates()` s `type='poi'`
2. `get_candidates()` zavolá `fetch_pois_from_providers()`
3. **POIs se stáhnou z Wikidata** (pokud ještě nejsou v cache)
4. Vytvoří se WordPress posty typu `poi`
5. Pak se najdou nearby POIs z databáze

---

## 🎯 Pro existující nabíječky

### Scénář 1: Automaticky při uložení/aktualizaci

**Kdy:** Při uložení nebo aktualizaci `charging_location`:

```php
// includes/Jobs/Nearby_Queue_Manager.php:679-709
add_action('save_post', 'handle_post_save');
```

**Workflow:**
1. Uloží se/aktualizuje se `charging_location`
2. Zavolá se `handle_post_save()`
3. Zavolá se `enqueue($post_id, 'poi', 1)` - zařadí do fronty pro nearby POIs
4. `enqueue()` zkontroluje `has_candidates_in_area()` → **stáhne POIs z Wikidata**
5. Pokud jsou POIs, zařadí do fronty
6. Batch processor zpracuje frontu → **znovu stáhne POIs** (s cache check)

---

### Scénář 2: Manuálně - zařazení všech bodů do fronty

**Kdy:** Manuálně v admin rozhraní nebo přes WP-CLI:

```php
// includes/Jobs/Nearby_Queue_Manager.php:544
$queue_manager->enqueue_all_points();
```

**Workflow:**
1. Projde všechny `charging_location` posty
2. Pro každou zavolá `enqueue($location->ID, 'poi', 1)`
3. `enqueue()` zkontroluje `has_candidates_in_area()` → **stáhne POIs z Wikidata**
4. Zařadí do fronty
5. Batch processor zpracuje → **znovu stáhne POIs** (s cache check)

---

### Scénář 3: On-demand (při kliknutí na mapě)

**Kdy:** Uživatel klikne na nabíječku na mapě:

```php
// includes/Jobs/On_Demand_Processor.php
// Frontend volá POST /wp-json/db/v1/ondemand/process
```

**Workflow:**
1. Frontend volá on-demand endpoint
2. Zavolá se `recompute_nearby_for_origin()` → `get_candidates()`
3. `get_candidates()` zavolá `fetch_pois_from_providers()`
4. **POIs se stáhnou z Wikidata** (s cache check - 1 hodina)
5. Vytvoří se WordPress posty
6. Najdou se nearby POIs z databáze
7. Vypočítají se vzdálenosti pomocí ORS API

---

## ⚙️ Cache mechanismus

### Cache pro stahování POIs

```php
// includes/Jobs/Nearby_Recompute_Job.php:824
$cache_key = 'poi_fetch_' . md5($lat . '_' . $lng . '_' . $radiusMeters);
$cache_duration = 3600; // 1 hodina
```

**Jak to funguje:**
- Cache klíč: `poi_fetch_{lat}_{lng}_{radius}`
- Délka: **1 hodina**
- Pokud je cache fresh, POIs se **nestáhnou znovu**

**Důležité:** I když je cache fresh, POIs se **najdou v databázi** (WordPress posty typu `poi`).

---

## 📊 Shrnutí workflow

### Pro existující nabíječky:

1. **Zařazení do fronty:**
   - Při uložení/aktualizaci → automaticky
   - Manuálně → `enqueue_all_points()`
   - Při kontrole → `has_candidates_in_area()` → **stáhne POIs z Wikidata**

2. **Zpracování fronty:**
   - Batch processor zpracuje položku
   - Zavolá `get_candidates()` → `fetch_pois_from_providers()`
   - **Stáhne POIs z Wikidata** (pokud není cache fresh)
   - Vytvoří WordPress posty typu `poi`
   - Najde nearby POIs z databáze

3. **On-demand (při kliknutí):**
   - Frontend volá on-demand endpoint
   - **Stáhne POIs z Wikidata** (pokud není cache fresh)
   - Vypočítá vzdálenosti pomocí ORS API

---

## 🔍 Důležité poznámky

### 1. POIs se stahují **před** zařazením do fronty

**Proč:** Aby se zjistilo, zda má smysl nabíječku zpracovávat. Pokud v okolí nejsou žádné POIs, nabíječka se nezařadí do fronty.

### 2. POIs se stahují **znovu** při zpracování fronty

**Proč:** Cache může být stará, nebo mohly přibýt nové POIs. Ale s cache check (1 hodina) se nestáhnou znovu, pokud jsou fresh.

### 3. POIs se ukládají jako **WordPress posty**

**Kde:** `wp_posts` s `post_type = 'poi'`
**Meta data:** `_poi_lat`, `_poi_lng`, `_poi_external_id`, atd.

### 4. Wikidata nevyžaduje API key

**Výhoda:** Funguje vždy, bez registrace. OpenTripMap je volitelný (vyžaduje API key).

---

## 🚀 Jak spustit pro existující nabíječky?

### Možnost 1: Automaticky (doporučeno)

**Nic nedělat** - POIs se stáhnou automaticky při:
- Uložení/aktualizaci nabíječky
- Kliknutí na nabíječku na mapě

### Možnost 2: Manuálně - zařadit všechny do fronty

**V admin rozhraní:**
- `Tools > Nearby Queue > Enqueue All Points`

**Nebo přes WP-CLI:**
```bash
wp db nearby-queue enqueue-all
```

### Možnost 3: Manuálně - zpracovat frontu

**V admin rozhraní:**
- `Tools > Nearby Queue > Process Batch`

**Nebo přes WP-CLI:**
```bash
wp db nearby-queue process-batch
```

---

## ✅ Závěr

**POIs z Wikidata se stahují:**
1. ✅ Při kontrole kandidátů (před zařazením do fronty)
2. ✅ Při zpracování nearby recompute jobu (s cache check)
3. ✅ Při on-demand requestu (při kliknutí na mapě)

**Pro existující nabíječky:**
- Zařaďte je do fronty (`enqueue_all_points()`)
- Nebo počkejte na automatické zpracování při uložení/aktualizaci
- Nebo klikněte na ně na mapě (on-demand)

**Cache:** 1 hodina - POIs se nestáhnou znovu, pokud je cache fresh.


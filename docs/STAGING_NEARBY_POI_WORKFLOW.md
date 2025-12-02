# Workflow vytváření Nearby POIs po nahrání na staging

## Přehled

Po nahrání změn na staging se nearby POIs (body zájmu v okolí nabíječek) vytváří **automaticky** při uložení nebo aktualizaci nabíječek. Systém používá ORS (OpenRouteService) API pro výpočet vzdáleností a ukládá výsledky do cache.

## Kdy se nearby POIs vytváří?

### 1. **Automaticky při uložení/aktualizaci nabíječky**

Když se uloží nebo aktualizuje `charging_location` s validními souřadnicemi:

```php
// includes/Jobs/Nearby_Queue_Manager.php:679-709
add_action('save_post', 'handle_post_save');
```

**Co se stane:**
1. ✅ Automaticky se zařadí do fronty `nearby_queue` pro výpočet nearby POIs
2. ✅ Pokud je zapnutý auto-processing (`db_nearby_auto_enabled`), worker se spustí automaticky
3. ✅ Všechny body v okruhu 5 km se také zařadí do fronty pro aktualizaci jejich nearby dat

### 2. **Automaticky při uložení/aktualizaci POI**

Když se uloží nebo aktualizuje `poi`:
- Automaticky se zařadí do fronty pro výpočet nearby nabíječek
- Okolní nabíječky se zařadí do fronty pro aktualizaci jejich nearby POIs

### 3. **Manuálně přes admin rozhraní**

V admin rozhraní lze:
- Spustit batch processing fronty
- Recompute konkrétní nabíječky
- Zkontrolovat stav fronty

## Jak to funguje?

### Krok 1: Zařazení do fronty

```php
// Když se uloží charging_location:
$nearby_type = 'poi'; // Hledáme POI v okolí
$this->enqueue($post_id, $nearby_type, 1); // Priorita 1 = zdarma
```

**Kontrola:**
- ✅ Má bod validní souřadnice?
- ✅ Jsou v okruhu 10 km nějaké POI kandidáti?
- ✅ Není už ve frontě?

### Krok 2: Batch processing

Worker zpracovává frontu po dávkách:

```php
// includes/Jobs/Nearby_Batch_Processor.php
$batch_processor->process_batch(1); // 1 item najednou
```

**Pro každý item:**
1. Zkontroluje, zda už není fresh cache (30 dní)
2. Spočítá kandidáty v okruhu 10 km (pouze databázový dotaz, BEZ volání ORS API)
3. ✅ **ORS API se NEVOLÁ automaticky** - volá se pouze on-demand při kliknutí na bod
4. Označí jako "potřebuje zpracování" - ORS API se zavolá až při user interakci

### Krok 3: On-demand výpočet vzdáleností (při kliknutí)

**DŮLEŽITÉ:** ORS API se **NEVOLÁ automaticky** v batch processoru. Volá se pouze:

1. **Při kliknutí na bod na mapě** - frontend volá `/wp-json/db/v1/ondemand/process`
2. **On-Demand Processor** zkontroluje cache
3. Pokud cache není fresh nebo přibylo nové POI, zavolá ORS API

```php
// includes/Jobs/On_Demand_Processor.php
// Volá se pouze při user interakci
$nearby_result = $this->nearby_job->recompute_nearby_for_origin($point_id, $point_type);
```

**Parametry:**
- **Radius**: 10 km (10000 metrů)
- **Profile**: `foot-walking` (pěší chůze)
- **Batch size**: 24 kandidátů najednou (konfigurovatelné)
- **Max kandidátů**: 40 (ORS API limit)

### Krok 4: Uložení výsledků

Výsledky se ukládají do post meta:
- `_db_nearby_cache_poi_foot` - nearby POIs pro charging_location
- `_db_nearby_cache_charger_foot` - nearby nabíječky pro POI

**Formát:**
```json
{
  "items": [
    {
      "id": 123,
      "distance_m": 450,
      "duration_s": 360,
      "direct_line": false
    }
  ],
  "generated_at": "2025-01-20T10:00:00Z",
  "cached_until": "2025-01-27T10:00:00Z"
}
```

## Časování

### Okamžité (při uložení)
- ✅ Zařazení do fronty
- ✅ Spuštění workeru (pokud je auto-processing zapnutý)

### Zpožděné (background processing)
- ⏱️ **Worker běží každou minutu** (pokud je `db_nearby_auto_enabled = true`)
- ⏱️ **Rate limiting**: Maximálně 1 request za 30 sekund
- ⏱️ **ORS API quota**: Token bucket (40 requests/minute)

### Cache expirace
- ⏱️ **Fresh cache**: 7 dní
- ⏱️ Pokud jsou data starší než 7 dní, automaticky se přepočítají

## Co očekávat po nahrání na staging?

### 1. **První spuštění**

Po nahrání změn:
- ✅ Fronta se začne plnit při prvním uložení nabíječky
- ✅ Worker se spustí automaticky (pokud je auto-processing zapnutý)
- ⚠️ **ORS API se NEVOLÁ automaticky** - pouze kontrola cache a detekce nových POI
- ⏱️ První nearby POIs se vytvoří **při kliknutí na bod na mapě** (on-demand)

### 2. **Během prvních hodin**

- ⏱️ Worker zpracovává frontu postupně (1 item/minutu)
- ✅ Worker pouze kontroluje cache a detekuje nové POI (BEZ volání ORS API)
- ✅ Pokud je ve frontě 392 nabíječek → všechny se zpracují rychle (pouze kontrola cache)
- ✅ Všechny nové/aktualizované nabíječky se automaticky zařadí do fronty

### 3. **Při kliknutí na bod na mapě**

- ✅ Frontend volá `/wp-json/db/v1/ondemand/process`
- ✅ On-Demand Processor zkontroluje cache
- ✅ Pokud cache není fresh nebo přibylo nové POI → **zavolá ORS API**
- ✅ Výsledky se uloží do cache (30 dní TTL)
- ✅ Data jsou okamžitě dostupná v mapě

## Konfigurace

### Zapnutí auto-processing

```php
// V admin rozhraní nebo přes WP-CLI:
update_option('db_nearby_auto_enabled', true);
```

### Kontrola stavu fronty

```php
$queue = new \DB\Jobs\Nearby_Queue_Manager();
$pending = $queue->count_by_status('pending');
$processing = $queue->count_by_status('processing');
```

### Manuální spuštění workeru

```php
\DB\Jobs\Nearby_Worker::dispatch();
```

## Monitoring

### Logy

Systém loguje do `Nearby_Logger`:
- `[BATCH]` - batch processing
- `[RECOMPUTE]` - recompute operace
- `[QUOTA]` - quota management
- `[CRON]` - cron scheduling

### Admin rozhraní

V admin rozhraní lze zkontrolovat:
- Počet položek ve frontě
- Poslední zpracované položky
- Chyby při zpracování

## Co se stane s existujícími nabíječkami?

### ⚠️ DŮLEŽITÉ: Existující nabíječky se NEAUTOMATICKY nezařadí do fronty

Po nahrání změn na staging se **existující nabíječky automaticky nezařadí do fronty**. Systém zařazuje do fronty pouze:
- ✅ Nově vytvořené/aktualizované nabíječky (při uložení)
- ✅ Nabíječky, které mají v okruhu 10 km nějaké POI kandidáty

### Jak zařadit existující nabíječky do fronty?

#### Varianta 1: Přes admin rozhraní (doporučeno)

1. Jděte do **WordPress Admin → Charging Locations → Nearby Queue**
2. Klikněte na tlačítko **"Zařadit všechny body"**
3. Systém zařadí všechny existující nabíječky, POI a RV spoty do fronty

**Co se stane:**
- ✅ Všechny publikované `charging_location` se zařadí pro nearby POIs
- ✅ Všechny publikované `poi` se zařadí pro nearby nabíječky
- ✅ Všechny publikované `rv_spot` se zařadí pro nearby nabíječky a POI
- ⚠️ **Poznámka**: Zařadí se pouze body, které mají v okruhu 10 km nějaké kandidáty

#### Varianta 2: Přes REST API

```bash
# Zařadit všechny body
POST /wp-json/db/v1/nearby/enqueue
{
  "origin_ids": [],  // prázdné = všechny
  "target_type": ""  // prázdné = automaticky podle typu
}
```

#### Varianta 3: Přes WP-CLI (pokud existuje)

```bash
# Zkontrolovat, zda existuje WP-CLI příkaz
wp db nearby enqueue-all
```

### Kontrola, zda má nabíječka kandidáty

Systém automaticky kontroluje, zda má nabíječka v okruhu 10 km nějaké POI kandidáty:

```php
// includes/Jobs/Nearby_Queue_Manager.php:68
if (!$this->has_candidates_in_area($origin_id, $origin_type)) {
    return false; // Nemá kandidáty, nezařadit do fronty
}
```

**Pokud nemá kandidáty:**
- ❌ Nebude zařazena do fronty
- ❌ Nearby POIs se nebudou počítat
- ✅ Při uložení/aktualizaci se zkontroluje znovu

### Očekávaný čas pro existující nabíječky

Po zařazení všech existujících nabíječek do fronty:

| Počet nabíječek | Očekávaný čas zpracování |
|-----------------|--------------------------|
| 100 | ~100 minut (1.5 hodiny) |
| 500 | ~500 minut (8 hodin) |
| 1000 | ~1000 minut (16 hodin) |

**Poznámka:** Worker zpracovává 1 položku za běh, s rate limitingem 1 request/30s.

### Doporučený postup po nahrání na staging

1. ✅ **Zkontrolujte, zda je auto-processing zapnutý:**
   ```php
   get_option('db_nearby_auto_enabled', false); // Mělo by být true
   ```

2. ✅ **Zařaďte všechny existující body do fronty:**
   - Přes admin rozhraní (tlačítko "Zařadit všechny body")
   - Nebo přes REST API

3. ✅ **Spusťte worker manuálně (pokud není auto-processing):**
   ```php
   \DB\Jobs\Nearby_Worker::dispatch();
   ```

4. ✅ **Monitorujte frontu:**
   - V admin rozhraní můžete vidět počet pending/processing/completed
   - Worker běží každou minutu (pokud je auto-processing zapnutý)

5. ✅ **Počkejte na zpracování:**
   - Worker zpracovává postupně
   - První nearby POIs budou dostupné během několika minut
   - Všechny existující nabíječky budou zpracovány během několika hodin

## Google API vs. ORS API - důležité rozlišení

### ✅ Nearby recompute workflow NEPOUŽÍVÁ Google API

**Důležité:** Nearby recompute workflow (výpočet nearby POIs v okolí nabíječek) **NEPOUŽÍVÁ Google Places API**. Používá pouze:

- **ORS (OpenRouteService) API** - pro výpočet walking distance mezi body
- **Databázové dotazy** - pro nalezení kandidátů v okruhu

**Google API se používá pouze pro:**
1. **POI Enrichment** (`Places_Enrichment_Service`) - když uživatel klikne na POI na frontendu
2. **POI Discovery** (`POI_Discovery_Worker`) - automatické hledání Google Place ID pro POI
3. **Charging Discovery** - hledání Google Place ID pro nabíječky

### ✅ Žádné riziko překročení Google API limitů při nearby recompute

Při zpracování existujících nabíječek:
- ❌ **NEPOUŽÍVÁ se Google API**
- ✅ Používá se pouze ORS API (má vlastní quota management)
- ✅ Google API se volá pouze při user interakci (kliknutí na POI) nebo při POI Discovery

**Závěr:** Můžete bezpečně zařadit všechny existující nabíječky do fronty - nehrozí překročení Google API limitů.

## Důležité poznámky

### ⚠️ ORS API quota

- **Limit**: 40 requests/minute (token bucket)
- **Rate limiting**: Worker má vlastní rate limiting (1 request/30s)
- **DŮLEŽITÉ**: ORS API se volá pouze on-demand při kliknutí na bod, ne automaticky
- Pokud je quota vyčerpána, systém počká a zkusí znovu při dalším kliknutí

### ⚠️ CSV import

Během CSV importu POI se nearby recompute **přeskočí** pro optimalizaci:
```php
if (db_is_poi_import_running()) {
    return; // Přeskočit nearby recompute
}
```

Po importu je potřeba manuálně spustit recompute pro všechny nabíječky.

### ⚠️ První spuštění

Při prvním spuštění na stagingu:
1. Zkontrolujte, zda je `db_nearby_auto_enabled = true`
2. Pokud ne, zapněte ho nebo spusťte worker manuálně
3. Fronta se začne plnit při prvním uložení nabíječky

## Shrnutí

| Událost | Kdy | Co se stane |
|---------|-----|-------------|
| Uložení nabíječky | Okamžitě | Zařazení do fronty pro nearby POIs |
| Worker běží | Každou minutu | Kontrola cache a detekce nových POI (BEZ ORS API) |
| Výpočet vzdáleností | **Při kliknutí na bod** | ORS Matrix API call (on-demand) |
| Cache | 30 dní | Data jsou fresh 30 dní |
| Aktualizace | Při změnách | Automatické zařazení do fronty |

**Očekávaný čas pro první nearby POIs:** 
- ⚠️ **NE automaticky** - pouze kontrola cache
- ✅ **Při kliknutí na bod na mapě** - ORS API se zavolá on-demand a data budou dostupná během několika sekund


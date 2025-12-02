# Zjednodušený workflow pro Nearby POIs

## Přehled

Tento dokument vysvětluje **zjednodušený workflow** pro obohacování nabíječek o nearby POIs. Workflow se skládá ze **dvou samostatných procesů**:

1. **Vytvoření a uložení POIs do databáze** (POI microservice)
2. **Výpočet nearby POIs pro nabíječky** (WordPress plugin)

---

## Proces 1: Vytvoření a uložení POIs do databáze

### ⚠️ DŮLEŽITÉ: POI microservice a WordPress jsou DVA SAMOSTATNÉ SYSTÉMY

**POI microservice** ukládá POIs do **PostgreSQL**, ale **WordPress nearby workflow hledá POIs v WordPress MySQL**. Tyto systémy momentálně **NEKOMUNIKUJÍ**!

### Kde se to děje?

**POI microservice** (`poi-service/`) - samostatný Node.js/TypeScript service s PostgreSQL databází.

### Kdy se to děje?

POIs se stahují a ukládají do databáze **při volání POI microservice API**:

```
GET /api/pois/nearby?lat=50.123&lon=14.456&radius=2000&minCount=10
```

**POZNÁMKA**: Toto API se momentálně **NEVOLÁ z WordPressu** při nearby výpočtu!

### Jak to funguje?

1. **Cache check** - zkontroluje `PoiCache` tabulku v PostgreSQL
2. **DB query** - zkontroluje, zda už máme POIs v `Poi` tabulce v PostgreSQL
3. **Pokud nemáme dostatek POIs:**
   - Stáhne z **free zdrojů** (OpenTripMap, Wikidata)
   - Pokud free zdroje nestačí → volá Google Places API (fallback)
   - **Uloží do PostgreSQL** pomocí `persistIncoming()`:
     ```typescript
     // poi-service/src/aggregator.ts:135-159
     const created = await prisma.poi.create({ data: normalizedToPoiData(poi) });
     ```

### Kde se POIs ukládají?

**PostgreSQL databáze** (`poi-service/prisma/schema.prisma`):
- Tabulka `Poi` - všechny POIs
- Tabulka `PoiCache` - cache dotazů
- Tabulka `ApiUsage` - quota tracking

**⚠️ DŮLEŽITÉ**: POIs se ukládají **POUZE do PostgreSQL**, **NE do WordPress MySQL**!

### Důležité poznámky

- **GPS se nezaokrouhlují** - používají se originální hodnoty z providerů
- **Deduplikace** - duplicitní POIs (50m + podobné jméno) se slučují
- **Rating filter** - pouze POIs s ratingem ≥ 4.0 (pokud rating existuje)
- **Kategorie** - pouze POIs z `ALLOWED_CATEGORIES` whitelistu

---

## Proces 2: Výpočet nearby POIs pro nabíječky

### Kde se to děje?

**WordPress plugin** (`includes/Jobs/`) - PHP kód v WordPressu.

### Kdy se to děje?

1. **Automaticky** při uložení/aktualizaci nabíječky (`save_post` hook)
2. **On-demand** při kliknutí na bod na mapě (frontend volá REST API)
3. **Manuálně** přes admin rozhraní nebo WP-CLI

### Jak to funguje?

1. **Zařazení do fronty** (`Nearby_Queue_Manager`):
   - Kontrola, zda má nabíječka validní souřadnice
   - Kontrola, zda má v okruhu 10 km nějaké POI kandidáty
   - Zařazení do `wp_nearby_queue` tabulky

2. **Batch processing** (`Nearby_Batch_Processor`):
   - Zpracovává frontu po dávkách (default: **3 položky** pro testování)
   - Kontroluje cache (30 dní TTL)
   - **Nevolá ORS API automaticky** - pouze kontroluje cache a detekuje nové POI

3. **On-demand výpočet** (`On_Demand_Processor`):
   - Volá se **pouze při kliknutí na bod na mapě**
   - Zkontroluje cache
   - Pokud cache není fresh nebo přibylo nové POI → zavolá ORS API
   - Výpočet walking distance mezi nabíječkou a POIs
   - Uložení výsledků do cache

### ⚠️ DŮLEŽITÉ: Kde WordPress hledá POIs?

**WordPress MySQL databáze** (`wp_posts` WHERE `post_type = 'poi'`):

```php
// includes/Jobs/Nearby_Recompute_Job.php:820-897
public function get_candidates($lat, $lng, $type, $radiusKm, $limit) {
    // SQL dotaz na wp_posts WHERE post_type = 'poi'
    // Hledá POIs v okruhu radiusKm km
}
```

**WordPress NEPOUŽÍVÁ POI microservice!** Hledá pouze POIs, které jsou:
- Vytvořené manuálně v WordPressu
- Importované přes CSV
- Vytvořené jiným způsobem v WordPressu

**POIs z POI microservice (PostgreSQL) se NEPOUŽÍVAJÍ v nearby workflow!**

### Kde se nearby data ukládají?

**WordPress post meta**:
- `_db_nearby_cache_poi_foot` - nearby POIs pro charging_location
- `_db_nearby_cache_charger_foot` - nearby nabíječky pro POI

### Důležité poznámky

- **ORS API se volá pouze on-demand** - ne automaticky v batch processoru
- **Rate limiting** - 40 requests/minute (token bucket)
- **Cache TTL** - 30 dní
- **GPS se nezaokrouhlují** - používají se originální hodnoty

---

## Jak spolu procesy spolupracují?

### ⚠️ Scénář 1: Nová nabíječka (AKTUÁLNÍ STAV)

1. Uživatel vytvoří novou nabíječku v WordPressu
2. `save_post` hook → zařadí do `nearby_queue`
3. Batch processor zkontroluje cache (není fresh)
4. **Při kliknutí na bod na mapě**:
   - Frontend volá `/wp-json/db/v1/ondemand/process`
   - On-Demand Processor zavolá `get_candidates()`
   - **`get_candidates()` hledá POIs v WordPress MySQL** (`wp_posts` WHERE `post_type = 'poi'`)
   - ❌ **NEPOUŽÍVÁ POI microservice** - hledá pouze POIs z WordPressu
   - Pokud najde kandidáty → zavolá ORS API pro výpočet vzdáleností
   - Výsledky se uloží do WordPress post meta

**⚠️ PROBLÉM**: POIs z POI microservice (PostgreSQL) se **NEPOUŽÍVAJÍ**!

### ⚠️ Scénář 2: Existující nabíječka (AKTUÁLNÍ STAV)

1. Uživatel klikne na existující nabíječku na mapě
2. Frontend zkontroluje cache
3. Pokud cache není fresh nebo přibylo nové POI:
   - On-Demand Processor zavolá `get_candidates()`
   - **`get_candidates()` hledá POIs v WordPress MySQL** (`wp_posts` WHERE `post_type = 'poi'`)
   - ❌ **NEPOUŽÍVÁ POI microservice** - hledá pouze POIs z WordPressu
   - Pokud najde nové kandidáty → zavolá ORS API pro výpočet vzdáleností
   - Aktualizuje cache

**⚠️ PROBLÉM**: POIs z POI microservice (PostgreSQL) se **NEPOUŽÍVAJÍ**!

---

## Minimalizace Google API calls

### Kdy se Google API volá?

Google Places API se volá **pouze pokud**:

1. **Nemáme dostatek POIs celkem** (< `minCount`)
2. **NEBO nemáme dostatek POIs s kompletními informacemi**:
   - Název ✓
   - GPS souřadnice ✓
   - Fotka (bonus)

### Kontrola kompletních informací

```typescript
function hasCompleteInfo(poi: Poi | NormalizedPoi): boolean {
  const hasName = !!(poi.name && poi.name.trim() !== '');
  const hasGps = typeof poi.lat === 'number' && typeof poi.lon === 'number' && 
                 !isNaN(poi.lat) && !isNaN(poi.lon) &&
                 poi.lat !== 0 && poi.lon !== 0;
  const hasPhoto = !!(poi.photo_url || (poi as any).photoUrl);
  
  return hasName && hasGps; // Minimálně název + GPS
}
```

### Příklad

Pokud máme 10 POIs z free zdrojů, ale pouze 5 má kompletní informace (název + GPS):
- ✅ Google API se **nevolá** - máme dostatek POIs celkem
- ✅ Použijeme POIs z free zdrojů

Pokud máme 5 POIs z free zdrojů, ale všechny mají kompletní informace:
- ✅ Google API se **nevolá** - máme dostatek kompletních POIs
- ✅ Použijeme POIs z free zdrojů

Pokud máme 3 POIs z free zdrojů a žádný nemá kompletní informace:
- ⚠️ Google API se **volá** - nemáme dostatek POIs ani kompletních informací

---

## Batch processing - menší dávka pro testování

### Default limit

**3 položky** (místo původních 10) pro snadnější testování:

```php
// includes/Jobs/Charging_Discovery_Batch_Processor.php
public function process_batch(int $limit = 3): array {
    // Menší default dávka pro testování (3 místo 10)
    ...
}
```

### Jak změnit limit?

1. **V kódu** - změnit default hodnotu v `process_batch()`
2. **Při volání** - předat jiný limit:
   ```php
   $processor->process_batch(10); // 10 položek
   ```

---

## Shrnutí

| Proces | Kde | Kdy | Co dělá |
|--------|-----|-----|---------|
| **POI Creation** | POI microservice | Při volání API | Stahuje POIs z free zdrojů, ukládá do **PostgreSQL** |
| **Nearby Calculation** | WordPress plugin | On-demand (kliknutí) | Hledá POIs v **WordPress MySQL**, počítá walking distance pomocí ORS API |

## ⚠️ DŮLEŽITÉ PROBLÉM

**POI microservice a WordPress jsou DVA SAMOSTATNÉ SYSTÉMY, které spolu NEKOMUNIKUJÍ!**

- ✅ POI microservice ukládá POIs do **PostgreSQL**
- ✅ WordPress nearby workflow hledá POIs v **WordPress MySQL**
- ❌ **POIs z POI microservice se NEPOUŽÍVAJÍ v nearby workflow!**

**Pro propojení je potřeba implementovat synchronizaci** - viz `docs/POI_STORAGE_AND_SYNC.md`

### Důležité principy

1. ✅ **Free zdroje prioritně** - OpenTripMap, Wikidata
2. ✅ **Google API jako fallback** - pouze pokud free zdroje nestačí
3. ✅ **Minimalizace Google API** - kontrola kompletních informací před voláním
4. ✅ **GPS nezaokrouhlovat** - používat originální hodnoty
5. ✅ **On-demand processing** - ORS API se volá pouze při kliknutí
6. ✅ **Menší dávky** - 3 položky pro testování


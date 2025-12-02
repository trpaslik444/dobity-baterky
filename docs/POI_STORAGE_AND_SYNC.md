# Kde a kdy se POIs stahují a ukládají do databáze

## ⚠️ DŮLEŽITÉ: Dva samostatné systémy

V projektu existují **DVA SAMOSTATNÉ SYSTÉMY** pro práci s POIs:

1. **POI Microservice** (`poi-service/`) - Node.js/TypeScript service s PostgreSQL
2. **WordPress Plugin** - PHP kód v WordPressu s MySQL

**Tyto systémy spolu momentálně NEKOMUNIKUJÍ!**

---

## Systém 1: POI Microservice (PostgreSQL)

### Kde se POIs ukládají?

**PostgreSQL databáze** (`poi-service/prisma/schema.prisma`):
- Tabulka `Poi` - všechny POIs
- Tabulka `PoiCache` - cache dotazů
- Tabulka `ApiUsage` - quota tracking

### Kdy se POIs stahují a ukládají?

**Při volání POI microservice API:**

```
GET /api/pois/nearby?lat=50.123&lon=14.456&radius=2000&minCount=10
```

### Workflow:

1. **Cache check** - zkontroluje `PoiCache` tabulku
2. **DB query** - zkontroluje, zda už máme POIs v `Poi` tabulce
3. **Pokud nemáme dostatek POIs:**
   - Stáhne z **free zdrojů** (OpenTripMap, Wikidata)
   - Pokud free zdroje nestačí → volá Google Places API (fallback)
   - **Uloží do PostgreSQL** pomocí `persistIncoming()`:
     ```typescript
     // poi-service/src/aggregator.ts:135-159
     async function persistIncoming(incoming: NormalizedPoi[]) {
       // Filtruje podle ALLOWED_CATEGORIES a ratingu
       // Deduplikuje (50m + podobné jméno)
       // Ukládá do PostgreSQL pomocí prisma.poi.create()
     }
     ```

### Kde se to děje?

- **Soubor**: `poi-service/src/aggregator.ts`
- **Funkce**: `getNearbyPois()` → `persistIncoming()`
- **Databáze**: PostgreSQL (samostatná databáze)

---

## Systém 2: WordPress Plugin (MySQL)

### Kde WordPress hledá POIs?

**WordPress MySQL databáze**:
- Tabulka `wp_posts` s `post_type = 'poi'`
- Meta data: `_poi_lat`, `_poi_lng`, `_poi_address`, atd.

### Kdy WordPress hledá POIs?

**Při výpočtu nearby POIs pro nabíječky:**

```php
// includes/Jobs/Nearby_Recompute_Job.php:820-897
public function get_candidates($lat, $lng, $type, $radiusKm, $limit) {
    // SQL dotaz na wp_posts WHERE post_type = 'poi'
    // Hledá POIs v okruhu radiusKm km
}
```

### Workflow:

1. **Zařazení do fronty** - při uložení nabíječky
2. **Batch processing** - kontroluje cache
3. **On-demand výpočet** - při kliknutí na bod na mapě:
   - Zavolá `get_candidates()` - **hledá v WordPress MySQL**
   - Pokud najde kandidáty → zavolá ORS API pro výpočet vzdáleností
   - Uloží výsledky do WordPress post meta

### Kde se to děje?

- **Soubor**: `includes/Jobs/Nearby_Recompute_Job.php`
- **Funkce**: `get_candidates()` - SQL dotaz na `wp_posts`
- **Databáze**: WordPress MySQL

---

## ⚠️ PROBLÉM: Systémy spolu nekomunikují

### Aktuální stav:

1. **POI Microservice**:
   - ✅ Stahuje POIs z free zdrojů
   - ✅ Ukládá do PostgreSQL
   - ❌ **NIKDY neukládá do WordPressu**

2. **WordPress Plugin**:
   - ✅ Hledá POIs v WordPress MySQL (`post_type = 'poi'`)
   - ❌ **NIKDY nečte z POI microservice**

### Důsledek:

**POIs z POI microservice se NIKDY nedostanou do WordPressu!**

WordPress nearby workflow hledá pouze POIs, které jsou:
- Vytvořené manuálně v WordPressu
- Importované přes CSV
- Vytvořené jiným způsobem v WordPressu

**POIs z POI microservice (OpenTripMap, Wikidata, Google) se nepoužívají v nearby workflow!**

---

## Jak by to mělo fungovat?

### Varianta 1: Synchronizace POI microservice → WordPress

**Kdy**: Při volání POI microservice API

**Workflow**:
1. POI microservice stáhne POIs z free zdrojů
2. Uloží do PostgreSQL
3. **NOVĚ**: Vytvoří WordPress post type `poi` pro každý POI
4. WordPress nearby workflow najde tyto POIs

**Implementace**:
- Přidat WordPress REST API endpoint do POI microservice
- Po `persistIncoming()` zavolat WordPress API pro vytvoření postu

### Varianta 2: WordPress čte z POI microservice

**Kdy**: Při výpočtu nearby POIs

**Workflow**:
1. WordPress `get_candidates()` zavolá POI microservice API
2. POI microservice vrátí POIs z PostgreSQL
3. WordPress použije tyto POIs pro nearby výpočet

**Implementace**:
- Upravit `get_candidates()` aby volal POI microservice API
- Kombinovat výsledky z WordPress MySQL a POI microservice

### Varianta 3: Hybridní přístup

**Kdy**: Při výpočtu nearby POIs

**Workflow**:
1. WordPress nejdříve hledá v WordPress MySQL
2. Pokud nemá dostatek POIs → zavolá POI microservice API
3. POI microservice vrátí POIs z PostgreSQL
4. WordPress použije kombinaci obou zdrojů

**Implementace**:
- Upravit `get_candidates()` aby volal POI microservice jako fallback
- Kombinovat výsledky z obou zdrojů

---

## Aktuální workflow (jak to funguje teď)

### Proces 1: Vytvoření POIs v POI microservice

```
1. Volání POI microservice API
   ↓
2. Stáhnutí z free zdrojů (OpenTripMap, Wikidata)
   ↓
3. Uložení do PostgreSQL (Poi tabulka)
   ↓
4. ❌ KONEC - POIs se NIKDY nedostanou do WordPressu
```

### Proces 2: Vytvoření nearby POIs v WordPressu

```
1. Uložení nabíječky
   ↓
2. Zařazení do nearby_queue
   ↓
3. Batch processing
   ↓
4. get_candidates() - SQL dotaz na wp_posts WHERE post_type = 'poi'
   ↓
5. ❌ Najde pouze POIs z WordPress MySQL (manuálně vytvořené)
   ↓
6. ORS API výpočet vzdáleností
   ↓
7. Uložení do WordPress post meta
```

---

## Shrnutí

| Otázka | Odpověď |
|--------|---------|
| **Kde se POIs ukládají z POI microservice?** | PostgreSQL databáze (`Poi` tabulka) |
| **Kdy se POIs stahují z free zdrojů?** | Při volání POI microservice API (`/api/pois/nearby`) |
| **Kde WordPress hledá POIs?** | WordPress MySQL (`wp_posts` WHERE `post_type = 'poi'`) |
| **Komunikují systémy spolu?** | ❌ NE - jsou to dva samostatné systémy |
| **Dostanou se POIs z microservice do WordPressu?** | ❌ NE - momentálně se nesynchronizují |

---

## Doporučení

Pro propojení systémů je potřeba implementovat **synchronizaci**:

1. **POI microservice → WordPress**: Po uložení do PostgreSQL vytvořit WordPress post
2. **Nebo WordPress → POI microservice**: Při nearby výpočtu číst z POI microservice API

**Aktuálně nearby workflow používá pouze POIs z WordPress MySQL, ne z POI microservice!**


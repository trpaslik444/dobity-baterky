# Code Review Report - PR #76: Add Fastify-based POI microservice with provider fallbacks

## Přehled
Tento PR přidává nový TypeScript/Node.js microservice pro správu POI dat s Fastify REST API. Microservice implementuje fallback strategii mezi různými poskytovateli dat (Manual, OpenTripMap, Wikidata, Google Places) a obsahuje CSV import funkcionalitu.

## ✅ Pozitivní aspekty

1. **Dobrá architektura** - Čistá separace providerů, použití TypeScript pro type safety
2. **Fallback strategie** - Logická priorita providerů (cache → DB → free APIs → Google)
3. **Deduplikace POI** - Implementace merge logiky pro duplicitní záznamy
4. **Caching** - Cache vrstva pro optimalizaci výkonu
5. **Prisma ORM** - Moderní přístup k databázi s type safety

## 🔴 KRITICKÉ PROBLÉMY (P1) - Nesoulad s PR #75 a Google API kvóty

### 1. **Nesynchronizované kvóty mezi WordPress a microservice**

**Problém:**
PR #76 implementuje vlastní systém kvót pro Google Places API, který **NENÍ synchronizovaný** s existujícím systémem z PR #75:

- **PR #75** (`Places_Enrichment_Service`):
  - Databáze: WordPress MySQL (`wp_db_places_usage`)
  - Výchozí limit: **300 požadavků/den**
  - Konfigurace: `MAX_PLACES_REQUESTS_PER_DAY`
  - Používá transakce s `FOR UPDATE` lock pro atomické operace

- **PR #76** (POI microservice):
  - Databáze: PostgreSQL (`ApiUsage` tabulka)
  - Výchozí limit: **500 požadavků/den**
  - Konfigurace: `MAX_GOOGLE_CALLS_PER_DAY`
  - Používá Prisma upsert bez atomického check-before-increment

**Důsledky:**
- **Celkový limit může být překročen**: WordPress může použít 300 požadavků a microservice dalších 500, což dohromady dává 800 požadavků/den místo plánovaných 300
- **Různé databáze**: MySQL vs PostgreSQL - nemohou sdílet stejnou tabulku pro synchronizaci
- **Různé výchozí limity**: 300 vs 500 - nekonzistentní

**Soubor:** `poi-service/src/aggregator.ts:202-216`, `poi-service/src/config.ts:13`

**Doporučení:**
1. **Sdílená databáze pro kvóty**: Použít stejnou databázi (WordPress MySQL) pro oba systémy, nebo implementovat synchronizaci přes API
2. **Sjednotit limity**: Použít stejný výchozí limit (300) a stejný název konfigurační proměnné
3. **Centralizovaný quota manager**: Vytvořit sdílenou službu pro správu kvót, kterou budou používat oba systémy

### 2. **Race condition v quota checku**

**Problém:**
V `poi-service/src/aggregator.ts` jsou `canUseGoogle()` a `incrementGoogleUsage()` volány **separátně** bez atomické operace:

```typescript
// Řádek 64-71
if (merged.length < googleThreshold && CONFIG.GOOGLE_PLACES_ENABLED && (await canUseGoogle())) {
  const googleProvider = new GooglePlacesProvider();
  const google = await googleProvider.searchAround(lat, lon, radiusMeters, normalizedCategories);
  if (google.length) {
    await incrementGoogleUsage(); // ⚠️ Race condition zde!
    // ...
  }
}
```

**Scénář race condition:**
1. Request A: `canUseGoogle()` → vrací `true` (limit je 499/500)
2. Request B: `canUseGoogle()` → vrací `true` (limit je stále 499/500)
3. Request A: `incrementGoogleUsage()` → limit je nyní 500/500
4. Request B: `incrementGoogleUsage()` → limit je nyní 501/500 ⚠️ **PŘEKROČENO**

**Porovnání s PR #75:**
PR #75 řeší tento problém pomocí transakce s `FOR UPDATE` lock:
```php
$wpdb->query('START TRANSACTION');
$row = $wpdb->get_row("SELECT ... FOR UPDATE"); // Lock
if ($current_count >= $limit) {
    $wpdb->query('ROLLBACK');
    return new WP_Error(...);
}
// Atomický increment
$wpdb->query("INSERT ... ON DUPLICATE KEY UPDATE ...");
$wpdb->query('COMMIT');
```

**Soubor:** `poi-service/src/aggregator.ts:64-71, 202-216`

**Doporučení:**
1. **Atomická operace**: Použít Prisma transaction s `SELECT FOR UPDATE` nebo použít PostgreSQL advisory locks
2. **Check-and-increment v jedné operaci**: Implementovat funkci, která atomicky zkontroluje limit a inkrementuje counter
3. **Optimistic locking**: Použít version field nebo conditional update

**Příklad řešení:**
```typescript
async function reserveGoogleQuota(): Promise<boolean> {
  const today = startOfToday();
  return await prisma.$transaction(async (tx) => {
    const usage = await tx.apiUsage.findUnique({
      where: { provider_date: { provider: 'google', date: today } },
    });
    const current = usage?.count ?? 0;
    if (current >= CONFIG.MAX_GOOGLE_CALLS_PER_DAY) {
      return false;
    }
    await tx.apiUsage.upsert({
      where: { provider_date: { provider: 'google', date: today } },
      create: { provider: 'google', date: today, count: 1 },
      update: { count: { increment: 1 } },
    });
    return true;
  });
}
```

### 3. **Kvóta se inkrementuje až po úspěšném volání API**

**Problém:**
V `poi-service/src/aggregator.ts:64-71` se `incrementGoogleUsage()` volá **až po** úspěšném volání Google API:

```typescript
const google = await googleProvider.searchAround(...);
if (google.length) {
  await incrementGoogleUsage(); // ⚠️ Inkrementuje se až po volání
}
```

**Důsledky:**
- Pokud API volání selže (např. network error), kvóta se neinkrementuje, ale může dojít k částečnému spotřebování kvóty na straně Google
- Pokud API volání vrátí prázdný výsledek (`google.length === 0`), kvóta se neinkrementuje, ale Google API může stále počítat požadavek

**Porovnání s PR #75:**
PR #75 rezervuje kvótu **před** voláním API:
```php
$quotaCheck = $this->reserve_quota($endpoint); // Před voláním
if (is_wp_error($quotaCheck)) {
    return array('enriched' => false, 'reason' => 'quota_exceeded');
}
$response = $this->call_google_place_details($placeId); // Po rezervaci
```

**Doporučení:**
1. **Rezervovat kvótu před voláním**: Přesunout `incrementGoogleUsage()` před `googleProvider.searchAround()`
2. **Rollback při chybě**: Pokud API volání selže, zvážit rollback kvóty (nebo ponechat jako "spotřebovanou")

### 4. **Chybí kontrola kvóty před každým API voláním**

**Problém:**
Google Places API může být voláno vícekrát v rámci jednoho requestu (např. při paginaci výsledků), ale kvóta se kontroluje pouze jednou na začátku.

**Soubor:** `poi-service/src/providers/googlePlaces.ts:21-39`

**Doporučení:**
- Pokud Google Places API podporuje paginaci, je nutné kontrolovat kvótu před každým dalším požadavkem
- Dokumentovat, zda `searchAround()` může provést více API volání

## ⚠️ DŮLEŽITÉ PROBLÉMY (P2)

### 5. **Nekonzistentní názvy konfiguračních proměnných**

**Problém:**
- PR #75: `MAX_PLACES_REQUESTS_PER_DAY`
- PR #76: `MAX_GOOGLE_CALLS_PER_DAY`

**Doporučení:**
Sjednotit názvy pro konzistenci napříč celou aplikací.

### 6. **Chybí error handling pro Google API chyby**

**Problém:**
V `poi-service/src/providers/googlePlaces.ts:32-33`:
```typescript
const response = await fetch(url);
if (!response.ok) return []; // ⚠️ Tichá chyba
```

**Důsledky:**
- HTTP 429 (Rate Limit) je ignorováno
- HTTP 403 (Quota Exceeded) je ignorováno
- Network chyby jsou ignorovány

**Doporučení:**
- Logovat chyby
- Rozlišit různé typy chyb (rate limit, quota exceeded, network error)
- Vracet informace o chybě pro lepší debugging

### 7. **Chybí validace API klíče**

**Problém:**
V `poi-service/src/providers/googlePlaces.ts:27` se kontroluje pouze existence klíče, ne jeho validita.

**Doporučení:**
- Validovat formát API klíče (pokud je to možné)
- Zkontrolovat, zda klíč není prázdný string

### 8. **Datum formátování může způsobit problémy s časovými pásmy**

**Problém:**
V `poi-service/src/aggregator.ts:218-221`:
```typescript
function startOfToday(): Date {
  const now = new Date();
  return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
}
```

**Porovnání s PR #75:**
PR #75 používá `gmdate('Y-m-d')` pro konzistentní UTC datum.

**Doporučení:**
- Zajistit, že oba systémy používají stejný formát data (UTC)
- Dokumentovat časové pásmo pro quota reset

## 💡 NÁVRHY NA ZLEPŠENÍ (P3)

### 9. **Dokumentace integrace s WordPress**

**Doporučení:**
- Dokumentovat, jak microservice komunikuje s WordPress
- Vysvětlit, jak jsou synchronizovány kvóty (pokud vůbec)
- Přidat diagram architektury

### 10. **Monitoring a alerting**

**Doporučení:**
- Přidat metriky pro quota usage
- Alerting při blížícím se limitu (např. 80% jako v PR #75)
- Logging všech Google API volání

### 11. **Testy**

**Doporučení:**
- Unit testy pro quota management
- Testy race conditions
- Integration testy s Google API (mock)

### 12. **Feature flag konzistence**

**Problém:**
- PR #75: `PLACES_ENRICHMENT_ENABLED`
- PR #76: `GOOGLE_PLACES_ENABLED`

**Doporučení:**
Sjednotit názvy feature flagů.

## 📊 SHRNUTÍ NESOULADŮ S PR #75

| Aspekt | PR #75 | PR #76 | Status |
|--------|--------|--------|--------|
| Databáze | WordPress MySQL | PostgreSQL | ❌ Různé |
| Tabulka | `wp_db_places_usage` | `ApiUsage` | ❌ Různé |
| Výchozí limit | 300/den | 500/den | ❌ Různé |
| Konfigurace | `MAX_PLACES_REQUESTS_PER_DAY` | `MAX_GOOGLE_CALLS_PER_DAY` | ❌ Různé |
| Atomický check | ✅ Transakce + FOR UPDATE | ❌ Separátní operace | ❌ Race condition |
| Rezervace před voláním | ✅ Ano | ❌ Ne | ❌ Inkonzistentní |
| Feature flag | `PLACES_ENRICHMENT_ENABLED` | `GOOGLE_PLACES_ENABLED` | ❌ Různé |
| Error handling | ✅ WP_Error | ❌ Tichá chyba | ⚠️ Méně robustní |

## 🎯 PRIORITNÍ OPRAVY PŘED MERGE

### P1 - Kritické (blokující merge):
1. ✅ **Implementovat synchronizaci kvót** mezi WordPress a microservice
2. ✅ **Opravit race condition** v quota checku (atomická operace)
3. ✅ **Rezervovat kvótu před voláním API** místo po volání
4. ✅ **Sjednotit výchozí limity** (300 místo 500)

### P2 - Důležité (doporučeno před merge):
5. ⚠️ **Sjednotit názvy konfiguračních proměnných**
6. ⚠️ **Přidat error handling** pro Google API chyby
7. ⚠️ **Dokumentovat integraci** s WordPress a synchronizaci kvót

### P3 - Vylepšení (může být po merge):
8. 💡 Přidat monitoring a alerting
9. 💡 Přidat testy
10. 💡 Sjednotit feature flagy

## 📝 DOPORUČENÉ ŘEŠENÍ

### Varianta 1: Sdílená databáze (doporučeno)
- Microservice přistupuje k WordPress MySQL databázi pro quota management
- Použije stejnou tabulku `wp_db_places_usage` jako PR #75
- Vyžaduje konfiguraci MySQL připojení v microservice
- **Výhody**: Jednoduchá implementace, atomické operace přes MySQL transakce
- **Nevýhody**: Microservice závislý na WordPress databázi

### Varianta 2: API synchronizace
- Vytvořit WordPress REST API endpoint pro quota management
- Microservice volá tento endpoint před každým Google API voláním
- Vyžaduje síťovou komunikaci, ale zachová separaci databází
- **Výhody**: Loose coupling, microservice nezávislý na WordPress DB
- **Nevýhody**: Latence, nutnost error handlingu pro síťové chyby

### Varianta 3: Centralizovaný quota service
- Vytvořit samostatný quota service (např. Redis-based)
- Oba systémy (WordPress i microservice) používají tento service
- Nejpružnější řešení, ale vyžaduje další infrastrukturu
- **Výhody**: Nezávislost, škálovatelnost, možnost rozšíření
- **Nevýhody**: Další komponenta v architektuře

### Varianta 4: Pouze jeden systém používá Google API
- Microservice NEPOUŽÍVÁ Google Places API přímo
- Místo toho volá WordPress REST API endpoint `/db/v1/poi-external/{id}`
- WordPress endpoint používá `Places_Enrichment_Service` s centralizovanými kvótami
- **Výhody**: Jednoduché, žádná synchronizace potřeba
- **Nevýhody**: Microservice závislý na WordPress API

## 🔍 DALŠÍ POZNATKY

### Integrace s WordPress
- WordPress má vlastní REST API endpointy pro POI (`/db/v1/poi-external/{id}`, `/db/v1/poi-discovery/`)
- Microservice běží na portu 3333 s endpointem `/api/pois/nearby`
- **Není jasné, zda jsou tyto systémy integrované** - microservice vypadá jako samostatný systém
- Pokud oba systémy běží současně a používají stejný Google API klíč, **problém s kvótami je ještě závažnější**

### CSV Import
- CSV import v `poi-service/src/import/csvImporter.ts` **nepoužívá Google API**, takže neovlivňuje kvóty
- Import pouze ukládá data do databáze a provádí deduplikaci

### ✅ Kontrola automatických volání Google API

**Zjištění:**
1. **Frontend volání** (`assets/map/core.js:5717`):
   - `enrichPOIFeature()` volá `/wp-json/db/v1/poi-external/{id}` **pouze po kliknutí uživatele na POI**
   - ✅ **OK** - volá se pouze po user interakci

2. **WordPress REST endpoint** (`includes/REST_Map.php:2253`):
   - `handle_poi_external()` volá Google API **pouze když je volán z frontendu**
   - ✅ **OK** - triggerováno user interakcí

3. **POI Discovery Worker** (`includes/Jobs/POI_Discovery_Worker.php`):
   - Spouští se automaticky při publikaci POI (`publish_poi` hook)
   - Worker se automaticky re-dispatchuje po zpracování batch (`self::dispatch(5)`)
   - ⚠️ **PROBLÉM**: Volá Google API automaticky bez přímé user interakce na frontendu
   - **Nicméně**: Publikace POI je user akce v adminu, takže není úplně automatické

4. **Admin akce** (`includes/Admin/POI_Discovery_Admin.php`):
   - `ajax_enqueue_all()` a `ajax_enqueue_ten()` - volají Google API, ale pouze po kliknutí admina
   - ✅ **OK** - user interakce v adminu

**Závěr:**
- ✅ Frontend volání jsou v pořádku - volají se pouze po user interakci
- ⚠️ POI Discovery Worker volá Google API automaticky při publikaci POI, ale to je user akce v adminu
- ✅ Žádné automatické cron joby nebo scheduled tasks, které by volaly Google API bez user interakce

## Shrnutí

**Celkové hodnocení:** ❌ **Neschváleno - vyžaduje opravy**

**Hlavní důvody:**
1. **Kritický bezpečnostní problém**: Nesynchronizované kvóty mohou vést k překročení celkového limitu Google Places API (300 + 500 = 800 místo 300)
2. **Race condition**: Možnost překročení limitu při souběžných requestech v microservice
3. **Nekonzistence s PR #75**: Různé limity (300 vs 500), databáze (MySQL vs PostgreSQL) a implementace
4. **Chybí dokumentace integrace**: Není jasné, jak microservice komunikuje s WordPress

**Doporučení:**
PR #76 by měl být upraven tak, aby:
- ✅ Respektoval stejné kvóty jako PR #75 (300/den místo 500/den)
- ✅ Používal atomické operace pro quota management (transakce s lock)
- ✅ Byl synchronizovaný s WordPress systémem kvót (jedna z navržených variant)
- ✅ Rezervoval kvótu před voláním API, ne po
- ✅ Přidal error handling pro Google API chyby (429, 403, atd.)
- ✅ Dokumentoval integraci s WordPress a synchronizaci kvót

**Priorita oprav:**
1. **P1 - Kritické (blokující merge)**: Opravit synchronizaci kvót a race condition
2. **P2 - Důležité**: Sjednotit limity, přidat error handling
3. **P3 - Vylepšení**: Dokumentace, monitoring, testy

---

*Review provedeno: 2025-01-20*
*Reviewer: AI Code Review Assistant*
*Související PR: #75 (Places Enrichment Guardrails)*
*Branch: `codex/implement-poi-microservice-with-csv-import`*


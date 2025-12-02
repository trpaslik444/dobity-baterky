# PR Review: Remove Google API from POI microservice and implement WordPress client sync

## 📋 Přehled změn

### Hlavní změny
1. **Odstranění Google API z POI microservice** - používá pouze free zdroje
2. **WordPress Client řešení** - WordPress volá POI microservice API a vytváří posty sám
3. **Periodická aktualizace** - scheduler pro aktualizaci POIs (30 dní)

---

## ✅ Pozitivní změny

### 1. Architektura
- ✅ **Správné oddělení zodpovědností** - WordPress má kontrolu nad vytvářením svých postů
- ✅ **Bezpečnost** - POI microservice nemá přístup k WordPress databázi
- ✅ **Jednoduchost** - méně konfigurace, jasný workflow

### 2. Kód
- ✅ **POI_Microservice_Client** - dobře strukturovaný klient s error handlingem
- ✅ **Deduplikace** - správná logika pro hledání existujících POIs
- ✅ **Logging** - debug logy pro sledování synchronizace

---

## ⚠️ Potenciální problémy

### 1. **Kritické (P1)**

#### 1.1 Chybí konfigurace POI microservice URL
**Soubor**: `includes/Services/POI_Microservice_Client.php:18`

```php
$this->api_url = get_option('db_poi_service_url', 'http://localhost:3333');
```

**Problém**: 
- Default hodnota `http://localhost:3333` není vhodná pro produkci
- Není jasné, kde a jak se má tato option nastavit
- Chybí validace URL

**Doporučení**:
- Přidat admin rozhraní pro nastavení URL
- Nebo použít konstantu `DB_POI_SERVICE_URL` v `wp-config.php`
- Přidat validaci URL

#### 1.2 Chybí error handling při nedostupnosti POI microservice
**Soubor**: `includes/Services/POI_Microservice_Client.php:35-50`

**Problém**:
- Pokud POI microservice není dostupný, `get_nearby_pois()` vrátí `WP_Error`
- `sync_nearby_pois_to_wordpress()` vrátí error, ale `get_candidates()` pokračuje
- Může dojít k tichému selhání synchronizace

**Doporučení**:
- Přidat retry logiku s exponential backoff
- Logovat chyby do WordPress logu
- Možnost fallback na existující POIs v WordPressu

#### 1.3 Race condition při synchronizaci
**Soubor**: `includes/Jobs/Nearby_Recompute_Job.php:823-826`

**Problém**:
- `sync_pois_from_microservice()` se volá při každém `get_candidates()`
- Pokud více requestů současně volá `get_candidates()`, může dojít k duplicitním API callům
- Chybí cache/rate limiting

**Doporučení**:
- Přidat transient cache pro synchronizaci (např. 5 minut)
- Nebo použít WordPress action scheduler pro asynchronní synchronizaci

### 2. **Vysoká priorita (P2)**

#### 2.1 Chybí validace dat z POI microservice
**Soubor**: `includes/Services/POI_Microservice_Client.php:95-120`

**Problém**:
- `create_or_update_poi()` kontroluje pouze `lat`, `lon`, `name`
- Chybí validace dalších polí (rating, category, atd.)
- Může dojít k uložení nevalidních dat

**Doporučení**:
- Přidat validaci všech polí podle WordPress POI struktury
- Sanitizovat všechna vstupní data

#### 2.2 Chybí timeout pro HTTP requesty
**Soubor**: `includes/Services/POI_Microservice_Client.php:35-50`

**Problém**:
- `wp_remote_get()` má timeout 30 sekund, ale není explicitně nastaven
- Při pomalém POI microservice může dojít k timeoutu

**Doporučení**:
- Explicitně nastavit timeout
- Možnost konfigurovat timeout v options

#### 2.3 Chybí monitoring/statistiky
**Soubor**: `includes/Services/POI_Microservice_Client.php`

**Problém**:
- Chybí sledování úspěšnosti synchronizace
- Chybí metriky (počet synchronizovaných POIs, chyby, atd.)

**Doporučení**:
- Přidat WordPress options pro statistiky
- Nebo použít WordPress hooks pro logging

### 3. **Střední priorita (P3)**

#### 3.1 Zbytečné soubory v repo
**Soubory**: 
- `poi-service/src/sync/wordpress.ts`
- `poi-service/src/sync/wordpressDirect.ts`
- `includes/REST_POI_Sync.php`

**Problém**:
- Tyto soubory nejsou použity v novém řešení
- Můžou způsobit zmatek

**Doporučení**:
- Odstranit nebo označit jako deprecated
- Nebo použít pro budoucí funkcionalitu

#### 3.2 Chybí dokumentace pro konfiguraci
**Soubor**: `docs/POI_SYNC_WORDPRESS_CLIENT.md`

**Problém**:
- Chybí instrukce, jak nastavit `db_poi_service_url`
- Chybí příklady konfigurace pro různé prostředí

**Doporučení**:
- Přidat sekci o konfiguraci
- Přidat příklady pro staging/produkci

#### 3.3 Periodická aktualizace není integrována
**Soubor**: `poi-service/src/jobs/scheduler.ts`

**Problém**:
- Scheduler existuje, ale není jasné, jak se spouští
- Chybí integrace s WordPress cron nebo externím schedulerem

**Doporučení**:
- Přidat dokumentaci o nastavení cron jobu
- Nebo vytvořit WordPress cron hook pro volání scheduleru

---

## 🔍 Detailní review souborů

### `includes/Services/POI_Microservice_Client.php`

**Pozitivní**:
- ✅ Dobrá struktura třídy
- ✅ Singleton pattern
- ✅ Error handling s `WP_Error`
- ✅ Deduplikace POIs

**Problémy**:
- ⚠️ Chybí validace URL (řádek 18)
- ⚠️ Chybí retry logika
- ⚠️ Chybí cache pro API responses
- ⚠️ `name_similarity()` může být pomalá pro velké množství kandidátů

**Doporučení**:
```php
// Přidat validaci URL
private function validate_api_url($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

// Přidat cache
private function get_cached_response($cache_key) {
    return get_transient($cache_key);
}

private function set_cached_response($cache_key, $data, $expiration = 300) {
    set_transient($cache_key, $data, $expiration);
}
```

### `includes/Jobs/Nearby_Recompute_Job.php`

**Pozitivní**:
- ✅ Integrace s existujícím kódem
- ✅ Logging pomocí `debug_log()`

**Problémy**:
- ⚠️ `sync_pois_from_microservice()` se volá při každém `get_candidates()`
- ⚠️ Chybí kontrola, zda je POI microservice dostupný
- ⚠️ Chybí rate limiting

**Doporučení**:
```php
private function sync_pois_from_microservice($lat, $lng, $radiusMeters) {
    // Cache check
    $cache_key = 'poi_sync_' . md5($lat . '_' . $lng . '_' . $radiusMeters);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return; // Již synchronizováno nedávno
    }
    
    // ... existing code ...
    
    // Set cache (5 minut)
    set_transient($cache_key, true, 300);
}
```

### `poi-service/src/aggregator.ts`

**Pozitivní**:
- ✅ Odstranění Google API
- ✅ Použití pouze free zdrojů

**Problémy**:
- ⚠️ Zůstaly importy pro WordPress sync (řádek 5-6), ale nejsou použity
- ⚠️ Chybí komentář, proč se WordPress sync neprovádí

**Doporučení**:
- Odstranit nepotřebné importy
- Přidat komentář vysvětlující nový workflow

### `poi-service/src/config.ts`

**Pozitivní**:
- ✅ Konfigurace je čistá

**Problémy**:
- ⚠️ Zůstaly WordPress DB konfigurace, které nejsou použity
- ⚠️ Může způsobit zmatek

**Doporučení**:
- Odstranit nebo označit jako deprecated
- Nebo použít pro budoucí funkcionalitu

---

## 📝 Testovací scénáře

### Scénář 1: Nová nabíječka
1. ✅ Vytvoření nabíječky v WordPressu
2. ✅ Volání `get_candidates()` pro POIs
3. ✅ Synchronizace z POI microservice
4. ✅ Vytvoření WordPress POI postů
5. ✅ Výpočet nearby POIs pomocí ORS

### Scénář 2: POI microservice není dostupný
1. ⚠️ **Chybí test** - co se stane, když POI microservice není dostupný?
2. ⚠️ **Chybí fallback** - měl by WordPress použít existující POIs?

### Scénář 3: Duplicitní POIs
1. ✅ Deduplikace podle `external_id`
2. ✅ Deduplikace podle GPS + jméno
3. ⚠️ **Chybí test** - co když má POI více `source_ids`?

### Scénář 4: Periodická aktualizace
1. ⚠️ **Chybí test** - jak se spouští scheduler?
2. ⚠️ **Chybí integrace** - jak se výsledky dostanou do WordPressu?

---

## 🎯 Doporučení před merge

### Povinné (P1)
1. ✅ Přidat konfiguraci POI microservice URL (admin rozhraní nebo konstanta)
2. ✅ Přidat error handling a retry logiku
3. ✅ Přidat cache pro synchronizaci (prevence race conditions)
4. ✅ Přidat validaci dat z POI microservice

### Doporučené (P2)
1. ✅ Přidat monitoring/statistiky
2. ✅ Přidat timeout konfiguraci
3. ✅ Odstranit nepotřebné soubory nebo označit jako deprecated
4. ✅ Přidat dokumentaci pro konfiguraci

### Volitelné (P3)
1. ✅ Přidat unit testy
2. ✅ Přidat integrační testy
3. ✅ Přidat performance testy

---

## 📊 Shrnutí

### Celkové hodnocení: ⚠️ **Potřebuje úpravy před merge**

**Pozitivní**:
- ✅ Správná architektura
- ✅ Bezpečnostní zlepšení
- ✅ Čistý kód

**Negativní**:
- ⚠️ Chybí konfigurace a error handling
- ⚠️ Potenciální race conditions
- ⚠️ Chybí testy

**Doporučení**: Opravit P1 problémy před merge, P2 a P3 lze řešit v následujících PR.

---

## 🔗 Související soubory

- `includes/Services/POI_Microservice_Client.php` - hlavní klient
- `includes/Jobs/Nearby_Recompute_Job.php` - integrace
- `poi-service/src/aggregator.ts` - POI microservice logika
- `docs/POI_SYNC_WORDPRESS_CLIENT.md` - dokumentace


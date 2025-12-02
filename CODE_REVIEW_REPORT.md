# Code Review Report - PR #75: Places Enrichment Guardrails

## Přehled
Tento PR přidává centralizovanou službu pro Google Places enrichment s denními limity, feature flagem a ochranou proti opakovanému obohacování.

## ✅ Pozitivní aspekty

1. **Atomický increment** - Race condition v quota counteru je správně řešena pomocí `INSERT ... ON DUPLICATE KEY UPDATE`
2. **Optimalizace dbDelta** - Tabulka se kontroluje pouze jednou za request
3. **Správné HTTP status kódy** - HTTP 429 je správně vraceno při překročení kvóty
4. **Dobrá struktura** - Singleton pattern, separace zodpovědností

## 🔴 Kritické problémy (P1)

### 1. Missing null check for $result in call_google_place_details()

**Soubor:** `includes/Util/Places_Enrichment_Service.php:194`

**Problém:**
```php
$result = $data['result'];
```
Pokud `$data['result']` neexistuje nebo je null, následující přístupy k `$result['place_id']` atd. způsobí PHP warning/error.

**Doporučení:**
```php
if (!isset($data['result']) || !is_array($data['result'])) {
    return new WP_Error('google_api_error', 'Neplatná odpověď Google API: chybí result', array('status' => 500));
}
$result = $data['result'];
```

### 2. Memory leak in inFlight cache

**Soubor:** `includes/Util/Places_Enrichment_Service.php:74-76, 100`

**Problém:**
`$this->inFlight` array roste nekonečně během životnosti instance (singleton). Při vysokém provozu může způsobit memory leak.

**Doporučení:**
- Přidat limit na velikost cache (např. max 1000 záznamů)
- Implementovat LRU cache nebo TTL
- Nebo použít WordPress transients pro per-request deduplication

### 3. Transaction rollback missing on INSERT failure

**Soubor:** `includes/Util/Places_Enrichment_Service.php:140-148`

**Problém:**
Pokud `INSERT ... ON DUPLICATE KEY UPDATE` selže, transakce není rollbackována a zůstane otevřená.

**Doporučení:**
```php
$result = $wpdb->query($wpdb->prepare(...));
if ($result === false) {
    $wpdb->query('ROLLBACK');
    return new WP_Error('quota_error', 'Chyba při rezervaci kvóty: ' . $wpdb->last_error);
}
```

## ⚠️ Důležité problémy (P2)

### 4. Inconsistent error handling for quota exceeded

**Soubor:** `includes/Util/Places_Enrichment_Service.php:79-85`

**Problém:**
`reserve_quota()` vrací `WP_Error`, ale `request_place_details()` ho konvertuje na array. To je pak znovu konvertováno na `WP_Error` v REST handlerech. Lepší by bylo vrátit `WP_Error` přímo.

**Doporučení:**
Zvážit vrácení `WP_Error` přímo z `request_place_details()` místo konverze na array.

### 5. Missing validation for placeId parameter

**Soubor:** `includes/Util/Places_Enrichment_Service.php:55`

**Problém:**
`$placeId` není validován - může být prázdný string, příliš dlouhý, nebo obsahovat neplatné znaky.

**Doporučení:**
```php
if (empty($placeId) || !is_string($placeId) || strlen($placeId) > 255) {
    return new WP_Error('invalid_place_id', 'Neplatné Place ID', array('status' => 400));
}
```

### 6. Hardcoded error messages in Czech

**Soubor:** `includes/Util/Places_Enrichment_Service.php` (více míst)

**Problém:**
Chybové zprávy jsou pouze v češtině, což není vhodné pro mezinárodní použití.

**Doporučení:**
Použít WordPress i18n funkce (`__()`, `_e()`) nebo alespoň anglické zprávy.

### 7. Potential SQL injection in table name

**Soubor:** `includes/Util/Places_Enrichment_Service.php:278-280`

**Problém:**
`$table_name` je interpolován do SQL dotazu bez escapování (i když je z `$wpdb->prefix`).

**Poznámka:**
Toto je obvykle bezpečné, protože `$wpdb->prefix` je kontrolováno WordPressem, ale pro jistotu by bylo lepší použít `$wpdb->_escape()` nebo `esc_sql()`.

## 💡 Návrhy na zlepšení (P3)

### 8. Logging sensitive data

**Soubor:** `includes/Util/Places_Enrichment_Service.php:180`

**Problém:**
Celá API response je logována do error_log, což může obsahovat citlivá data.

**Doporučení:**
Logovat pouze status a error messages, ne celou response.

### 9. Magic numbers

**Soubor:** `includes/Util/Places_Enrichment_Service.php:14-15`

**Problém:**
DEFAULT_MAX_REQUESTS a DEFAULT_RECENT_DAYS jsou magic numbers bez dokumentace.

**Doporučení:**
Přidat PHPDoc komentáře vysvětlující, proč jsou tyto hodnoty zvoleny.

### 10. Missing error handling for dbDelta

**Soubor:** `includes/Util/Places_Enrichment_Service.php:299`

**Problém:**
`dbDelta()` může selhat, ale chyba není kontrolována.

**Doporučení:**
Zkontrolovat výsledek `dbDelta()` a logovat případné chyby.

### 11. Race condition in tableChecked flag

**Soubor:** `includes/Util/Places_Enrichment_Service.php:23, 270-302`

**Poznámka:**
`$tableChecked` je instance property, takže v singletonu je sdílená mezi všemi requesty. To je v pořádku, ale mělo by to být zdokumentováno.

### 12. Inconsistent use of current_time vs gmdate

**Soubor:** `includes/Util/Places_Enrichment_Service.php:112` vs `includes/REST_Map.php:2161`

**Problém:**
V `Places_Enrichment_Service` se používá `gmdate()`, zatímco v `REST_Map` se používá `current_time()`. Mělo by to být konzistentní.

## 📝 Poznámky k testům

- Testy jsou přidány, což je skvělé
- Měly by pokrývat edge cases (null result, failed transactions, atd.)

## Shrnutí

**Celkové hodnocení:** ✅ **Schváleno s podmínkami**

**Prioritní opravy před merge:**
1. Přidat null check pro `$data['result']` (P1)
2. Opravit memory leak v `inFlight` cache (P1)
3. Přidat error handling pro failed INSERT (P1)
4. Validovat `placeId` parameter (P2)

**Doporučené opravy:**
- Zlepšit error handling a validaci
- Přidat i18n podporu
- Zlepšit logging

---

*Review provedeno: 2025-12-02*
*Reviewer: AI Code Review Assistant*


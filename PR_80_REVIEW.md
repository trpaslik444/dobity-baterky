# Code Review: PR #80 - Centralizovaná ochrana Google Places API kvót

## Přehled
PR implementuje centralizovaný systém ochrany Google Places API kvót, který zabraňuje přečerpání kvót při importu POI a dalších operacích.

## ✅ Pozitivní aspekty

### 1. Centralizovaný Google_Quota_Manager
- ✅ Nová třída `Google_Quota_Manager` správně implementuje měsíční a denní limity
- ✅ Podporuje bezpečnostní buffer
- ✅ Atomická operace `reserve_quota()` kontroluje a rezervuje kvótu v jednom kroku
- ✅ Správné logování odmítnutí kvót s detaily

### 2. Aplikace kvót na všechna Google volání
- ✅ `REST_Map.php` - všechny Google API endpointy jsou chráněny
- ✅ `Charging_Discovery.php` - používá `Places_Enrichment_Service`, který je upraven
- ✅ `POI_Discovery.php` - discovery metody jsou chráněny
- ✅ `Places_Enrichment_Service.php` - používá centralizovaný `Google_Quota_Manager`
- ✅ Batch processory jsou upraveny

### 3. Bezpečnost
- ✅ Google API klíč odstraněn z frontendu (`dobity-baterky.php`)
- ✅ Permission checks přidány na veřejné endpointy (`charging-external`, worker endpointy)
- ✅ Chybějící API klíč vrací HTTP 503 (správný status kód)

### 4. Admin UI
- ✅ Zobrazení stavu kvót v `Icon_Admin.php`
- ✅ Formulář pro úpravu limitů
- ✅ Vizuální indikátory využití

## ⚠️ Potenciální problémy a doporučení

### 1. Race condition v `reserve_quota()`
**Problém:** Metoda `reserve_quota()` kontroluje kvótu a pak ji rezervuje, ale mezi těmito operacemi může dojít k race condition při souběžných requestech.

**Aktuální implementace:**
```php
public function reserve_quota(int $count = 1): \WP_Error|bool {
    if (!$this->can_use_google()) {
        // ... odmítnutí
    }
    $this->record_google($count); // Rezervace
    return true;
}
```

**Doporučení:** Implementovat atomickou operaci pomocí WordPress transients nebo databázových locků:
```php
public function reserve_quota(int $count = 1): \WP_Error|bool {
    $lock_key = 'db_google_quota_lock';
    $lock = get_transient($lock_key);
    if ($lock !== false) {
        // Počkat nebo vrátit chybu
        return new \WP_Error('quota_locked', 'Kvóta je právě kontrolována', array('status' => 429));
    }
    
    set_transient($lock_key, true, 1); // 1 sekunda lock
    
    try {
        if (!$this->can_use_google()) {
            // ... odmítnutí
        }
        $this->record_google($count);
        return true;
    } finally {
        delete_transient($lock_key);
    }
}
```

**Priorita:** Střední - může způsobit mírné přečerpání při vysoké zátěži

### 2. Duplicitní kvótové systémy
**Problém:** Stále existují `POI_Quota_Manager` a `Charging_Quota_Manager`, které mají vlastní Google kvóty.

**Doporučení:** 
- Migrovat data ze starých quota managerů do `Google_Quota_Manager`
- Postupně odstranit Google kvóty ze starých managerů
- Nechat pouze pro Tripadvisor/OCM

**Priorita:** Nízká - funkční, ale může být matoucí

### 3. Chybějící kontrola API klíče v některých místech
**Problém:** V `Charging_Discovery.php` metoda `fetchGooglePlaceDetails()` stále volá Google API přímo pro `evChargeOptions` bez kontroly, zda API klíč existuje.

**Aktuální kód:**
```php
$apiKey = (string) get_option('db_google_api_key');
if ($apiKey !== '') {
    // Volání API
}
```

**Doporučení:** Přidat kontrolu a vracet 503:
```php
$apiKey = (string) get_option('db_google_api_key');
if ($apiKey === '') {
    return $payload; // Vrátit bez konektorů
}
```

**Priorita:** Nízká - funkční, ale nekonzistentní

### 4. Logování odmítnutí kvót
**Pozitivum:** ✅ Logování je implementováno správně s detaily

**Doporučení:** Zvážit přidání do WordPress debug logu místo pouze error_log:
```php
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log(...);
}
```

**Priorita:** Velmi nízká

### 5. Admin UI - validace formuláře
**Pozitivum:** ✅ Formulář pro úpravu kvót je implementován

**Doporučení:** Přidat validaci, že denní limit není větší než měsíční:
```php
if ($daily_total > 0 && $daily_total > $monthly_total) {
    echo '<div class="notice notice-error"><p>Denní limit nemůže být větší než měsíční limit.</p></div>';
    return;
}
```

**Priorita:** Nízká - UX improvement

### 6. Testování
**Doporučení:** Přidat unit testy pro:
- Atomickou operaci `reserve_quota()`
- Kontrolu měsíčních a denních limitů
- Buffer logiku
- Edge cases (současné requesty, přechod měsíce/dne)

**Priorita:** Střední

## 📋 Checklist implementace

- [x] Centralizovaný Google_Quota_Manager vytvořen
- [x] Měsíční limity implementovány
- [x] Denní limity implementovány (volitelné)
- [x] Buffer implementován
- [x] Kvóty aplikovány na REST_Map.php
- [x] Kvóty aplikovány na Charging_Discovery.php
- [x] Kvóty aplikovány na POI_Discovery.php
- [x] Kvóty aplikovány na Places_Enrichment_Service.php
- [x] Kvóty aplikovány na batch processory
- [x] Google API klíč odstraněn z frontendu
- [x] Permission checks přidány
- [x] Chybějící API klíč vrací 503
- [x] Logování implementováno
- [x] Admin UI přidáno

## 🎯 Závěr

PR je **připraven k merge** s následujícími doporučeními:

1. **Vysoká priorita:** Žádné kritické problémy
2. **Střední priorita:** Zvážit implementaci atomické operace pro `reserve_quota()` (race condition)
3. **Nízká priorita:** 
   - Migrace ze starých quota managerů
   - Validace formuláře v admin UI
   - Konzistentní kontrola API klíče

**Celkové hodnocení:** ✅ **APPROVE** s doporučeními

Implementace je solidní a splňuje požadavky. Hlavní funkčnost je správně implementována a ochrana před přečerpáním kvót je zajištěna.


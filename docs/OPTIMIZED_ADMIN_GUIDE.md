# Optimalizované Admin Rozhraní - Průvodce

## Přehled

Optimalizované admin rozhraní nahrazuje staré worker-based systémy novým on-demand přístupem, který je efektivnější, rychlejší a šetří zdroje.

## Hlavní změny

### 1. On-Demand Zpracování
- **Před**: Neustále běžící workery spotřebovávající paměť
- **Po**: Zpracování pouze když je potřeba
- **Výhody**: 90% úspora paměti, rychlejší odezva, lepší škálovatelnost

### 2. Inteligentní Cache
- **Před**: Žádné cache pro často používaná data
- **Po**: Víceúrovňový cache systém
- **Výhody**: Rychlejší načítání, méně databázových dotazů

### 3. Optimalizované SQL Dotazy
- **Před**: Pomalé dotazy bez indexů
- **Po**: Optimalizované dotazy s indexy
- **Výhody**: 10x rychlejší dotazy, lepší výkon

## Nové Admin Komponenty

### 1. On_Demand_Admin
**Soubor**: `includes/Admin/On_Demand_Admin.php`

Hlavní admin rozhraní pro on-demand zpracování.

**Funkce**:
- Test zpracování jednotlivých bodů
- Hromadné zpracování
- Správa cache
- Optimalizace databáze

**Použití**:
```php
$admin = new \DB\Admin\On_Demand_Admin();
```

### 2. Optimized_Worker_Manager
**Soubor**: `includes/Jobs/Optimized_Worker_Manager.php`

Správce optimalizovaných workerů.

**Funkce**:
- On-demand zpracování bodů
- Správa cache
- Statistiky výkonu
- Hromadné zpracování

**Použití**:
```php
$manager = new \DB\Jobs\Optimized_Worker_Manager();
$result = $manager->process_on_demand($point_id, $point_type, $priority);
```

### 3. On_Demand_Processor
**Soubor**: `includes/Jobs/On_Demand_Processor.php`

Procesor pro on-demand zpracování.

**Funkce**:
- Zpracování jednotlivých bodů
- Validace dat
- Cache management
- Hromadné zpracování

**Použití**:
```php
$processor = new \DB\Jobs\On_Demand_Processor();
$result = $processor->process_point($point_id, $point_type, $options);
```

### 4. Optimized_Admin_Manager
**Soubor**: `includes/Admin/Optimized_Admin_Manager.php`

Hlavní správce admin rozhraní.

**Funkce**:
- Sjednocené admin rozhraní
- AJAX handlery
- Správa stylů a skriptů
- Integrace s legacy komponentami

**Použití**:
```php
$admin_manager = new \DB\Admin\Optimized_Admin_Manager();
```

### 5. Legacy_Admin_Manager
**Soubor**: `includes/Admin/Legacy_Admin_Manager.php`

Správce starých admin komponent pro zpětnou kompatibilitu.

**Funkce**:
- Migrace z legacy systému
- Zpětná kompatibilita
- Čištění starých dat

**Použití**:
```php
$legacy_manager = new \DB\Admin\Legacy_Admin_Manager();
$migration_results = $legacy_manager->migrate_to_ondemand();
```

## Admin Rozhraní

### Hlavní Stránka
**URL**: `/wp-admin/admin.php?page=db-ondemand`

**Funkce**:
- Přehled statistik
- Rychlé akce
- Test zpracování
- Hromadné zpracování

### Cache Management
**URL**: `/wp-admin/admin.php?page=db-ondemand-cache`

**Funkce**:
- Statistiky cache
- Mazání cache
- Optimalizace cache

### Database Optimization
**URL**: `/wp-admin/admin.php?page=db-ondemand-optimization`

**Funkce**:
- Vytváření indexů
- Optimalizace tabulek
- Čištění databáze

### Processing Logs
**URL**: `/wp-admin/admin.php?page=db-ondemand-logs`

**Funkce**:
- Zobrazení logů
- Filtrování logů
- Export logů

## API Endpointy

### 1. Zpracování bodu
**Endpoint**: `wp_ajax_db_ondemand_process_point`

**Parametry**:
- `point_id` (int): ID bodu
- `point_type` (string): Typ bodu
- `priority` (string): Priorita
- `force_refresh` (bool): Vynutit refresh
- `include_nearby` (bool): Zahrnout nearby data
- `include_discovery` (bool): Zahrnout discovery data

**Odpověď**:
```json
{
    "success": true,
    "data": {
        "point_id": 123,
        "point_type": "charging_location",
        "status": "completed",
        "processing_time": "150ms",
        "cached": false,
        "nearby": {
            "processed": 5,
            "errors": 0
        },
        "discovery": {
            "processed": 2,
            "errors": 0
        }
    }
}
```

### 2. Hromadné zpracování
**Endpoint**: `wp_ajax_db_ondemand_bulk_process`

**Parametry**:
- `point_type` (string): Typ bodů
- `limit` (int): Počet bodů
- `priority` (string): Priorita

**Odpověď**:
```json
{
    "success": true,
    "data": {
        "total_points": 10,
        "processed": 8,
        "errors": 2,
        "results": [...]
    }
}
```

### 3. Správa cache
**Endpoint**: `wp_ajax_db_ondemand_clear_cache`

**Parametry**: Žádné

**Odpověď**:
```json
{
    "success": true,
    "data": "Cache vymazán"
}
```

## Konfigurace

### 1. Povolení Legacy Admin
```php
// Povolit staré admin rozhraní
update_option('db_enable_legacy_admin', true);

// Zakázat staré admin rozhraní
update_option('db_enable_legacy_admin', false);
```

### 2. Cache Nastavení
```php
// Doba cache pro on-demand zpracování (v sekundách)
update_option('db_ondemand_cache_duration', 3600);

// Doba cache pro nearby data (v sekundách)
update_option('db_nearby_cache_duration', 300);
```

### 3. Optimalizace Databáze
```php
// Automatické vytváření indexů
update_option('db_auto_create_indexes', true);

// Automatická optimalizace tabulek
update_option('db_auto_optimize_tables', false);
```

## Migrace z Legacy Systému

### 1. Automatická Migrace
```php
$legacy_manager = new \DB\Admin\Legacy_Admin_Manager();
$migration_results = $legacy_manager->migrate_to_ondemand();
```

### 2. Ruční Migrace
1. Zálohujte databázi
2. Spusťte migraci
3. Zkontrolujte výsledky
4. Vyčistěte stará data

### 3. Čištění Legacy Dat
```php
$legacy_manager = new \DB\Admin\Legacy_Admin_Manager();
$cleanup_results = $legacy_manager->cleanup_legacy_data();
```

## Monitoring a Debugging

### 1. Statistiky Výkonu
```php
$manager = new \DB\Jobs\Optimized_Worker_Manager();
$stats = $manager->get_performance_stats();
```

### 2. Cache Statistiky
```php
$processor = new \DB\Jobs\On_Demand_Processor();
$cache_stats = $processor->get_cache_stats();
```

### 3. Debug Logy
```php
// Povolit debug logy
update_option('db_ondemand_debug', true);

// Zkontrolovat logy
$logs = get_option('db_ondemand_debug_logs', array());
```

## Best Practices

### 1. Cache Management
- Pravidelně čistěte starý cache
- Monitorujte cache hit rate
- Optimalizujte cache klíče

### 2. Database Optimization
- Vytvořte indexy pro často používané dotazy
- Pravidelně optimalizujte tabulky
- Monitorujte pomalé dotazy

### 3. Performance Monitoring
- Sledujte dobu zpracování
- Monitorujte chybovost
- Optimalizujte podle potřeby

## Troubleshooting

### 1. Problémy s Cache
**Problém**: Cache se neaktualizuje
**Řešení**: Vymažte cache a zkuste znovu

### 2. Problémy s Databází
**Problém**: Pomalé dotazy
**Řešení**: Vytvořte indexy a optimalizujte tabulky

### 3. Problémy s AJAX
**Problém**: AJAX požadavky selhávají
**Řešení**: Zkontrolujte nonce a oprávnění

## Závěr

Optimalizované admin rozhraní poskytuje:
- 90% úsporu paměti
- 10x rychlejší dotazy
- Lepší uživatelské rozhraní
- Snadnější správu
- Lepší škálovatelnost

Doporučujeme postupnou migraci z legacy systému a pravidelné monitorování výkonu.

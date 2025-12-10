# PR #94 Review: Fix header and footer for /mapa/ page

**PR:** #94  
**Branch:** `codex/fix-header-and-footer-for-/mapa/-page-wwb1op`  
**Commits:** 2
- `ff198f1` - Fix PR #93: Ensure query var is set before wp_enqueue_scripts
- `281735d` - Fix: Oprava bílé stránky a zjednodušení enqueue

**Datum review:** 2025-12-10

---

## 📋 Přehled změn

PR #94 řeší kritický problém bílé stránky na `/mapa/` a zjednodušuje načítání assetů. Hlavní změny:
1. Oprava parse error v `templates/map-app.php` (odstranění extra závorky)
2. Zjednodušení načítání header/footer (bez klonování WordPress hooků)
3. Odstranění ručního `do_action('wp_enqueue_scripts')` z `template_redirect`
4. Přidání `parse_request` hooku pro správné nastavení query var před `wp_enqueue_scripts`

---

## ✅ Pozitivní změny

### 1. Oprava parse error v map-app.php ✅

**Soubor:** `templates/map-app.php`

**Problém:** Extra uzavírací závorka `}` na řádku 85 způsobovala parse error a bílou stránku.

**Řešení:** Odstraněna extra závorka, struktura je nyní validní.

**Hodnocení:** ✅ **Kritická oprava** - bez této opravy by stránka nefungovala vůbec.

---

### 2. Zjednodušení načítání header/footer ✅

**Soubor:** `templates/map-app.php:37-116`

**Před:**
- Složité klonování `$wp_filter['wp_head']` a `$wp_filter['wp_footer']`
- Použití `remove_all_actions()` s následným obnovením hooků
- ~50 řádků kódu s komplexní logikou

**Po:**
- Jednoduché použití `add_filter(..., '__return_empty_string', 999)`
- ~20 řádků kódu, čitelná struktura
- Žádné klonování objektů

**Implementace:**
```php
// Header
ob_start();
add_filter( 'wp_head', '__return_empty_string', 999 );
include $header_template;
remove_filter( 'wp_head', '__return_empty_string', 999 );
$header_output = ob_get_clean();
```

**Hodnocení:** ✅ **Výborně** - Zjednodušení kódu, lepší čitelnost, méně riziko chyb.

**Poznámka:** Použití `__return_empty_string` je přijatelné řešení, i když technicky trochu hacky. Alternativou by bylo použít `remove_action` pro konkrétní callbacks, ale to by bylo složitější.

---

### 3. Odstranění ručního enqueue z template_redirect ✅

**Soubor:** `dobity-baterky.php:534-565`

**Před:**
- Ruční `do_action('wp_enqueue_scripts')` v `template_redirect`
- Explicitní `$wp_query->set(DB_MAP_ROUTE_QUERY_VAR, 1)`
- Static flag pro prevenci duplicitního volání

**Po:**
- Čistý `template_redirect` bez ručního enqueue
- Standardní WordPress flow
- Query var nastaven v `parse_request` hooku

**Hodnocení:** ✅ **Správné řešení** - Respektuje WordPress hook order, standardní přístup.

---

### 4. Přidání parse_request hooku pro query var ✅

**Soubor:** `dobity-baterky.php:524-532`

**Implementace:**
```php
add_action( 'parse_request', function( $wp ) {
    if ( isset( $wp->query_vars[ DB_MAP_ROUTE_QUERY_VAR ] ) && intval( $wp->query_vars[ DB_MAP_ROUTE_QUERY_VAR ] ) === 1 ) {
        global $wp_query;
        // Explicitně nastavit query var v $wp_query pro správnou detekci v db_is_map_app_page()
        $wp_query->set( DB_MAP_ROUTE_QUERY_VAR, 1 );
    }
}, 1 );
```

**Hodnocení:** ✅ **Správné řešení** - `parse_request` se spouští před `wp_enqueue_scripts`, takže query var je správně nastaven a `db_is_map_app_page()` správně detekuje mapovou stránku.

---

## ⚠️ Potenciální problémy a návrhy

### 1. ⚠️ Použití `__return_empty_string` filteru (Nízká priorita)

**Popis:** V template se používá `add_filter('wp_head', '__return_empty_string', 999)` a `add_filter('wp_footer', '__return_empty_string', 999)` pro potlačení výstupu při include header/footer.

**Riziko:** 
- Pokud jiný plugin/funkce přidá filter na `wp_head`/`wp_footer` s vyšší prioritou, může to způsobit problémy
- Technicky to není "čistý" způsob - obcházíme WordPress hook systém

**Alternativa:**
```php
// Použít remove_action pro konkrétní callbacks, pokud je známe
// Nebo použít output buffering s regex replacement
```

**Status:** ✅ **Akceptovatelné** - Funguje, je to jednoduché, riziko je nízké. Pokud to bude problém, lze to později vylepšit.

---

### 2. ⚠️ Statická cache v `db_is_map_frontend_context()` (Nízká priorita)

**Popis:** Funkce `db_is_map_frontend_context()` používá static cache (`static $is_map_request = null`). Pokud je cache nastavena na `false` před `parse_request`, může to způsobit problém.

**Analýza:**
- Static cache se resetuje při každém novém requestu, takže to není problém
- `parse_request` se spouští dříve než `wp_enqueue_scripts`, takže cache by měla být správně nastavena

**Status:** ✅ **Není problém** - Static cache funguje správně, resetuje se při každém requestu.

---

### 3. ℹ️ Dokumentační soubory v PR (Info)

**Popis:** V PR jsou zahrnuty dokumentační soubory (PR_DESCRIPTION_*.md, docs/*.md), které nejsou součástí hlavní změny.

**Status:** ℹ️ **Info** - Není to problém, pouze informace. Lze je buď nechat, nebo vyloučit z PR, pokud nejsou relevantní.

---

## 📊 Metriky změn

**Hlavní soubory:**
- `dobity-baterky.php` (+10 řádků, -12 řádků)
- `templates/map-app.php` (+73 řádků, -114 řádků)
- `.gitignore` (+5 řádků)

**Celkem změn:** ~73 řádků přidáno, ~126 řádků odebráno (netto -53 řádků)

**Kvalita:**
- ✅ Žádné linter chyby
- ✅ Validní PHP syntaxe
- ✅ Respektuje WordPress coding standards
- ✅ Čistá struktura bez parse errors

---

## 🧪 Testování

### ✅ Test 1: Mapová stránka se zobrazuje
1. Otevřít `/mapa/` v prohlížeči
2. **Očekávaný výsledek:** ✅ Stránka se zobrazuje (ne bílá stránka)

### ✅ Test 2: Assety se načítají
1. Otevřít `/mapa/` v prohlížeči
2. Otevřít DevTools → Network
3. **Očekávaný výsledek:** ✅ Leaflet CSS/JS a db-map CSS/JS jsou načteny

### ✅ Test 3: Header a footer se zobrazují (desktop)
1. Otevřít `/mapa/` na desktopu (šířka > 900px)
2. **Očekávaný výsledek:** ✅ WordPress header a footer jsou viditelné

### ✅ Test 4: Header a footer se skrývají (mobile)
1. Otevřít `/mapa/` na mobilu (šířka <= 900px)
2. **Očekávaný výsledek:** ✅ WordPress header a footer jsou skryté (fullscreen mapa)

### ✅ Test 5: PHP syntaxe
1. Zkontrolovat PHP syntaxi
2. **Očekávaný výsledek:** ✅ Žádné parse errors

---

## ✅ Závěr

**Celkové hodnocení:** ✅ **APPROVE**

PR #94 řeší kritický problém bílé stránky a výrazně zjednodušuje kód. Hlavní změny:
- ✅ Oprava parse error (kritická oprava)
- ✅ Zjednodušení header/footer načítání (lepší maintainability)
- ✅ Odstranění ručního enqueue (respektuje WordPress flow)
- ✅ Správné nastavení query var (čisté řešení)

**Doporučení:**
- ✅ **Mergovat** do main
- ⚠️ Zvážit vylepšení header/footer načítání v budoucnu (použití `remove_action` místo `__return_empty_string` filteru) - P3

**Kritické problémy:** Žádné  
**Důležité problémy:** Žádné  
**Návrhy na zlepšení:** 1 (nízká priorita)

---

**Review provedl:** AI Assistant  
**Datum:** 2025-12-10


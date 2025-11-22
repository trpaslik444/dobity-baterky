# Duplicity POI a řešení

## 🔍 Problém: Duplicitní POI na staging

**Příčina**: Safe import skript (`safe-import-csv-staging.php`) **vždy vytváří nové POI**, pokud CSV neobsahuje ID nebo ID neexistuje.

---

## 📊 Rozdíl mezi Admin Importer a Safe Import

### Admin Importer (`import_from_stream` v `POI_Admin.php`)

**Logika detekce duplicit:**
1. ✅ Zkusí najít podle **ID** (pokud existuje v CSV)
2. ✅ Zkusí najít podle **názvu + koordinátů** (pokud jsou v CSV)
3. ✅ Zkusí najít podle **názvu** (pokud není koordinátů)
4. ✅ Pokud nic nenajde → vytvoří nový POI

**Výhody:**
- ✅ Detekuje duplicity podle názvu a koordinátů
- ✅ Aktualizuje existující POI místo vytváření duplicit
- ✅ Bezpečnější pro produkci

**Nevýhody:**
- ⚠️ Může aktualizovat POI, které nechceš aktualizovat (pokud má stejný název/koordináty)

---

### Safe Import (`safe-import-csv-staging.php`)

**Logika:**
1. ✅ Zkusí najít podle **ID** (pokud existuje v CSV a není `--force-new`)
2. ❌ **Nekontroluje duplicity** podle názvu nebo koordinátů
3. ✅ Pokud není ID → **vždy vytvoří nový POI**

**Výhody:**
- ✅ Bezpečnější - neaktualizuje existující POI nechtěně
- ✅ Vhodné pro import nových dat

**Nevýhody:**
- ❌ **Vytváří duplicity**, pokud POI s tímto názvem/koordináty už existuje
- ❌ Nekontroluje duplicity

---

## 🚨 Proč jsou duplicity na staging?

**Možné příčiny:**

1. **Safe import byl spuštěn vícekrát** - každý běh vytvořil nové POI
2. **CSV neobsahuje ID** - safe import nemůže najít existující POI
3. **Kombinace admin import + safe import** - různé logiky

---

## ✅ Řešení

### 1. Použít Admin Importer místo Safe Import

**Pro produkci použij admin importer** - má lepší detekci duplicit:

```bash
# Přes WP-CLI (používá admin importer logiku)
wp db-poi import_csv /tmp/poi_import.csv --log-every=1000
```

**Nebo přes admin rozhraní:**
- WordPress Admin → POI → Import CSV
- Automaticky detekuje duplicity

---

### 2. Vyčistit duplicity na staging

Vytvoř skript pro vyčištění duplicit:

```php
// Najít duplicity podle názvu
$duplicates = $wpdb->get_results("
    SELECT post_title, COUNT(*) as count, GROUP_CONCAT(ID) as ids
    FROM {$wpdb->posts}
    WHERE post_type = 'poi' AND post_status = 'publish'
    GROUP BY post_title
    HAVING count > 1
");

// Smazat duplicity (ponechat nejnovější)
foreach ($duplicates as $dup) {
    $ids = explode(',', $dup->ids);
    array_pop($ids); // Ponechat poslední (nejnovější)
    foreach ($ids as $id) {
        wp_delete_post($id, true);
    }
}
```

---

### 3. Upravit Safe Import pro detekci duplicit

Můžu upravit `safe-import-csv-staging.php`, aby kontroloval duplicity podle názvu/koordinátů (jako admin importer).

---

## 📋 Doporučení

### Pro produkci:

1. ✅ **Použít Admin Importer** (přes WP-CLI nebo admin rozhraní)
   - Lepší detekce duplicit
   - Bezpečnější

2. ✅ **Nebo upravit Safe Import** - přidat kontrolu duplicit

3. ✅ **Vyčistit duplicity** před novým importem

---

## 🔧 Rychlé řešení

### Zkontrolovat, jestli admin importer funguje:

```bash
# Na staging
cd /srv/htdocs/wp-content/plugins/dobity-baterky
php -d memory_limit=1024M -r "
require_once '../../../wp-load.php';
\$admin = \DB\POI_Admin::get_instance();
\$handle = fopen('/tmp/test.csv', 'r');
\$result = \$admin->import_from_stream(\$handle, ['log_every' => 100]);
print_r(\$result);
"
```

---

*Dokument vytvořen pro řešení problému s duplicitami.*


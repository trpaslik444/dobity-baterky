# 📋 PRŮVODCE PRO CHATGPT CODEX - LOKÁLNÍ LOGY A WP-CLI

## 🎯 **ZÁKLADNÍ INFORMACE O PROSTŘEDÍ**

### **Struktura projektu:**
```
/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/
├── wp-content/
│   ├── plugins/dobity-baterky/          # Hlavní plugin
│   └── debug.log                        # WordPress debug log
├── wp-cli.phar                          # WP-CLI nástroj
└── wp-load.php                          # WordPress bootstrap
```

### **Klíčové cesty:**
- **Plugin root:** `/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky/`
- **WordPress root:** `/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/`
- **Debug log:** `/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/debug.log`

---

## 🔧 **WP-CLI PŘÍKAZY**

### **Základní použití:**
```bash
# Navigace do WordPress root
cd /Users/ondraplas/Local\ Sites/dobity-baterky-dev/app/public

# Spuštění WP-CLI příkazu
php wp-cli.phar [příkaz]
```

### **Nejčastější příkazy:**
```bash
# Kontrola pluginů
php wp-cli.phar plugin status dobity-baterky

# Získání WordPress option
php wp-cli.phar option get db_nearby_config

# Aktualizace WordPress option
php wp-cli.phar option update db_nearby_config '{"key":"value"}'

# Spuštění PHP kódu v WordPress kontextu
php wp-cli.phar eval "echo get_option('db_nearby_config');"

# Kontrola databáze
php wp-cli.phar db query "SELECT COUNT(*) FROM wp_nearby_queue WHERE status='pending';"
```

---

## 📊 **PRÁCE S LOGY**

### **WordPress debug log:**
```bash
# Zobrazení posledních 50 řádků
tail -50 wp-content/debug.log

# Sledování logů v reálném čase
tail -f wp-content/debug.log

# Filtrování podle klíčových slov
tail -100 wp-content/debug.log | grep "DB Nearby"

# Hledání specifických chyb
grep -i "error\|exception" wp-content/debug.log | tail -20
```

### **Plugin specifické logy:**
```bash
# Nearby debug log (pokud existuje)
tail -f wp-content/uploads/db-nearby-debug.log

# Kontrola všech log souborů
find wp-content/ -name "*.log" -type f
```

---

## 🐛 **DEBUGGING POSTUPY**

### **1. Kontrola stavu systému:**
```bash
cd /Users/ondraplas/Local\ Sites/dobity-baterky-dev/app/public

# Kontrola pluginů
php wp-cli.phar plugin status dobity-baterky

# Kontrola WordPress cron jobs
php wp-cli.phar cron event list

# Kontrola databáze
php wp-cli.phar db query "SELECT * FROM wp_nearby_queue LIMIT 5;"
```

### **2. Testování PHP kódu:**
```bash
# Spuštění PHP kódu v WordPress kontextu
php wp-cli.phar eval "
global \$wpdb;
echo 'Pending items: ' . \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}nearby_queue WHERE status='pending'\");
"
```

### **3. Kontrola logů:**
```bash
# Zobrazení posledních chyb
tail -20 wp-content/debug.log

# Hledání specifických zpráv
grep "DB Nearby" wp-content/debug.log | tail -10
```

---

## 🔍 **TYPICKÉ DEBUGGING SCÉNÁŘE**

### **Scénář 1: Automatizace nefunguje**
```bash
# 1. Kontrola stavu automatizace
php wp-cli.phar eval "echo get_option('db_nearby_auto_enabled') ? 'ENABLED' : 'DISABLED';"

# 2. Kontrola naplánovaných cron jobs
php wp-cli.phar cron event list | grep "db_nearby"

# 3. Kontrola logů
tail -50 wp-content/debug.log | grep -i "auto\|nearby"
```

### **Scénář 2: Fronta se nezpracovává**
```bash
# 1. Kontrola fronty
php wp-cli.phar eval "
global \$wpdb;
echo 'Pending: ' . \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}nearby_queue WHERE status='pending'\");
echo 'Processing: ' . \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}nearby_queue WHERE status='processing'\");
"

# 2. Kontrola API kvót
php wp-cli.phar eval "
\$quota = new DB\Jobs\API_Quota_Manager();
echo 'Can process: ' . (\$quota->can_process_queue() ? 'YES' : 'NO');
"

# 3. Kontrola logů
tail -100 wp-content/debug.log | grep -E "(BATCH|QUOTA|RECOMPUTE)"
```

### **Scénář 3: Chyby v API voláních**
```bash
# 1. Kontrola ORS headers
php wp-cli.phar eval "
\$matrix = [
    'remaining' => get_transient('db_ors_matrix_remaining_day'),
    'reset' => get_transient('db_ors_matrix_reset_epoch'),
    'retry_until' => get_transient('db_ors_matrix_retry_until'),
];
\$iso = [
    'remaining' => get_transient('db_ors_iso_remaining_day'),
    'reset' => get_transient('db_ors_iso_reset_epoch'),
    'retry_until' => get_transient('db_ors_iso_retry_until'),
];
echo 'Matrix quota: ' . json_encode(\$matrix) . "\n";
echo 'Iso quota: ' . json_encode(\$iso) . "\n";
"

# 2. Kontrola token bucket
php wp-cli.phar eval "
\$matrix_tokens = get_transient('db_ors_matrix_token_bucket');
\$iso_tokens = get_transient('db_ors_isochrones_token_bucket');
echo 'Matrix tokens: ' . json_encode(\$matrix_tokens) . "\n";
echo 'Isochrones tokens: ' . json_encode(\$iso_tokens) . "\n";
"

# 3. Kontrola logů
tail -50 wp-content/debug.log | grep -E "(ORS|API|HTTP|429|401)"
```

---

## 🛠️ **POMOCNÉ SKRIPTY**

### **Manuální spuštění automatizace:**
```bash
cd /Users/ondraplas/Local\ Sites/dobity-baterky-dev/app/public
php run-automation.php
```

### **Monitoring stavu:**
```bash
cd /Users/ondraplas/Local\ Sites/dobity-baterky-dev/app/public
php monitor-automation.php
```

### **Reset token bucket:**
```bash
php wp-cli.phar eval "
\$quota = new DB\Jobs\API_Quota_Manager();
\$quota->reset_minute_bucket('matrix');
\$quota->reset_minute_bucket('isochrones');
echo 'Token bucket resetován';
"
```

---

## 📝 **KLÍČOVÉ LOG ZPRÁVY**

### **Úspěšné zpracování:**
```
[DB Nearby][BATCH] Item processed successfully
[DB Nearby][RECOMPUTE] [Matrix] response received | {"http_code":200}
[DB Nearby][RECOMPUTE] [Isochrones] cached | {"features":3}
```

### **Chyby a varování:**
```
[DB Nearby][QUOTA] Token bucket blocked
[DB Nearby][BATCH] Recompute failed | {"error":"Lokální minutový limit"}
[DB Nearby][RECOMPUTE] [Matrix] response received | {"http_code":429}
```

### **Automatizace:**
```
[DB Nearby Auto] Zpracováno: 1, chyb: 0
[DB Nearby Auto] Naplánován další běh na 2025-09-29 15:44:44
```

---

## 🎯 **TIPY PRO EFEKTIVNÍ DEBUGGING**

### **1. Používejte paralelní terminály:**
```bash
# Terminal 1: Sledování logů
tail -f wp-content/debug.log

# Terminal 2: WP-CLI příkazy
php wp-cli.phar eval "..."

# Terminal 3: Manuální testy
php run-automation.php
```

### **2. Filtrujte logy podle kontextu:**
```bash
# Pouze Nearby logy
tail -f wp-content/debug.log | grep "DB Nearby"

# Pouze chyby
tail -f wp-content/debug.log | grep -i "error\|exception\|failed"

# Pouze API volání
tail -f wp-content/debug.log | grep -E "(Matrix|Isochrones|ORS)"
```

### **3. Používejte JSON výstup:**
```bash
# Strukturovaný výstup
php wp-cli.phar eval "echo json_encode(get_option('db_nearby_config'));"
```

---

## 🚨 **ČASTÉ PROBLÉMY A ŘEŠENÍ**

### **Problém: WP-CLI nefunguje**
```bash
# Řešení: Použijte plnou cestu
cd /Users/ondraplas/Local\ Sites/dobity-baterky-dev/app/public
php wp-cli.phar --info
```

### **Problém: Logy se nezobrazují**
```bash
# Řešení: Kontrola WP_DEBUG
php wp-cli.phar eval "echo WP_DEBUG ? 'DEBUG ON' : 'DEBUG OFF';"

# Aktivace debug módu
php wp-cli.phar config set WP_DEBUG true
```

### **Problém: Automatizace se nespouští**
```bash
# Řešení: Manuální spuštění
php run-automation.php

# Kontrola cron jobs
php wp-cli.phar cron event list | grep "db_nearby"
```

---

## 📚 **DOPORUČENÉ POSTUPY**

1. **Vždy začněte s `tail -f wp-content/debug.log`**
2. **Používejte `php wp-cli.phar eval` pro testování kódu**
3. **Kontrolujte stav fronty před každým testem**
4. **Resetujte token bucket před testováním**
5. **Používejte strukturované log zprávy pro lepší debugging**

---

## 🔗 **SOUVISEJÍCÍ DOKUMENTACE**

- [README.md](README.md) - Základní informace o pluginu
- [WORKFLOW.md](WORKFLOW.md) - Workflow pro vývoj
- [SOURCES_README.md](SOURCES_README.md) - Dokumentace zdrojů dat
- [AUTOMATION_SETUP.md](AUTOMATION_SETUP.md) - Nastavení automatizace

---

**Tento průvodce by měl ChatGPT Codex pomoci efektivně pracovat s lokálním prostředím a debugovat ORS funkcionalitu.**

# Ruční import CSV na staging - Instrukce

**Problém**: Import padl kvůli memory limitu (512MB nestačilo)  
**Řešení**: Spustit ručně s vyšším memory limitem  

---

## 📋 Postup krok za krokem

### KROK 1: Připojit se na staging přes SSH

Otevři terminál a spusť:

```bash
ssh -i ~/.ssh/id_ed25519_wpcom staging-f576-dobitybaterky.wordpress.com@ssh.wp.com
```

**Poznámka**: Budeš požádán o passphrase pro SSH klíč. Zadej ji.

---

### KROK 2: Najít CSV soubor na staging

Po připojení najdi nejnovější CSV soubor:

```bash
ls -lht /tmp/poi_import_*.csv | head -1
```

Výstup bude něco jako:
```
-rw-r--r-- 1 user user 1510K Nov 21 22:27 /tmp/poi_import_20251121222712.csv
```

**Zapiš si cestu k souboru** - budeš ji potřebovat v dalším kroku.

---

### KROK 3: Spustit import s vyšším memory limitem

Spusť WP-CLI import s **1GB memory limitem** (místo výchozích 512MB):

```bash
php -d memory_limit=1024M $(which wp) db-poi import_csv /tmp/poi_import_20251121222712.csv --log-every=1000
```

**Nebo pokud `wp` není v PATH:**

```bash
cd /srv/htdocs
php -d memory_limit=1024M wp db-poi import_csv /tmp/poi_import_20251121222712.csv --log-every=1000
```

**Nebo ještě jednodušeji** - najdi wp příkaz:

```bash
which wp
# Výstup: /path/to/wp

php -d memory_limit=1024M /path/to/wp db-poi import_csv /tmp/poi_import_20251121222712.csv --log-every=1000
```

---

## 📊 Co uvidíš během importu

Import bude vypisovat průběh každých 1000 řádků:

```
Řádek 1000 | nové: XXX | aktualizované: XXX | chyby: 0 | prázdné: 0
Řádek 2000 | nové: XXX | aktualizované: XXX | chyby: 0 | prázdné: 0
Řádek 3000 | nové: XXX | aktualizované: XXX | chyby: 0 | prázdné: 0
...
```

Po dokončení uvidíš finální shrnutí.

---

## ⚙️ Alternativní řešení

### Pokud stále padá kvůli memory limitu

Zkus ještě vyšší limit (2GB):

```bash
php -d memory_limit=2048M wp db-poi import_csv /tmp/poi_import_XXXXXXXX.csv --log-every=1000
```

### Nebo použít safe-import-csv-staging.php přímo

```bash
cd /srv/htdocs/wp-content/plugins/dobity-baterky
php -d memory_limit=1024M safe-import-csv-staging.php /tmp/poi_import_XXXXXXXX.csv --log-every=1000
```

---

## 📝 Kompletní příklad (kopírovat a vložit)

```bash
# 1. Připojit se
ssh -i ~/.ssh/id_ed25519_wpcom staging-f576-dobitybaterky.wordpress.com@ssh.wp.com

# 2. Najít CSV (zadej passphrase když se zeptá)
ls -lht /tmp/poi_import_*.csv | head -1

# 3. Spustit import (nahraď NÁZEV_SOUBORU skutečným názvem z kroku 2)
cd /srv/htdocs
php -d memory_limit=1024M wp db-poi import_csv /tmp/poi_import_XXXXXXXX.csv --log-every=1000
```

---

## ✅ Očekávaný výsledek

Po úspěšném dokončení uvidíš:

```
Success: Import completed
Total: 24223
New: XXX
Updated: XXX
Errors: X
```

---

## 🚨 Co dělat, pokud něco nefunguje

1. **WP-CLI není nalezeno:**
   ```bash
   find ~ -name "wp" -type f 2>/dev/null | grep -v ".git"
   ```

2. **WordPress root není nalezen:**
   ```bash
   find ~ -name "wp-config.php" -type f 2>/dev/null | head -1
   cd $(dirname /cesta/k/wp-config.php)
   ```

3. **CSV soubor není nalezen:**
   - Zkontroluj, zda se nahrál: `ls -lh /tmp/poi_import_*.csv`
   - Pokud ne, musíš ho nahrát přes SFTP

---

*Dokument vytvořen po pádu importu kvůli memory limitu.*


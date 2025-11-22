# Proč je import na staging složitější než lokálně?

## 📍 Lokálně vs. Staging

### ✅ Lokálně (Local by Flywheel)

**Jak to běží:**
```bash
cd /Users/ondraplas/Local\ Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky
php safe-import-csv-staging.php exported_pois_staging_complete.csv
```

**Proč je to jednoduché:**
1. ✅ **Přímý přístup** - přímo v shellu, bez SSH
2. ✅ **Známá cesta** - WordPress je na známém místě
3. ✅ **Žádná autentizace** - žádné heslo/passphrase
4. ✅ **Rychlé připojení** - lokální databáze, žádná síť
5. ✅ **Jednoduché debugování** - přímo vidíte výstup

---

### ⚠️ Staging (WordPress.com hosting)

**Jak to běží:**
```bash
# 1. Nahrát CSV na staging přes SFTP
sftp staging-server
put exported_pois_staging_complete.csv /tmp/poi_import.csv

# 2. Nahrát import skript do plugin directory
cd wp-content/plugins/dobity-baterky
put safe-import-csv-staging.php

# 3. Spustit import přes SSH
ssh staging-server
cd /srv/htdocs/wp-content/plugins/dobity-baterky
php safe-import-csv-staging.php /tmp/poi_import.csv
```

**Proč je to složitější:**
1. ⚠️ **SSH autentizace** - potřeba passphrase pro SSH klíč
2. ⚠️ **SFTP nahrání** - soubory musí být nahrány na server
3. ⚠️ **Neznaná cesta** - různé cesty na různých hostingech (`/srv/htdocs/`, `~/public_html/`)
4. ⚠️ **Síťové omezení** - timeouty, pomalé připojení
5. ⚠️ **Bezpečnostní politiky** - omezení na vzdáleném serveru

---

## 🔍 Detailní rozdíly

### 1. Autentizace

**Lokálně:**
- Žádná autentizace potřebná
- Přímo v shellu

**Staging:**
- SSH klíč s passphrase
- SFTP autentizace
- Potřeba `.env` souboru s `STAGING_PASS`

### 2. Cesty k souborům

**Lokálně:**
```
/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky/
```
- ✅ Vždy stejná cesta
- ✅ Jednoduchá

**Staging:**
```
/srv/htdocs/wp-content/plugins/dobity-baterky/
```
nebo
```
~/public_html/wp-content/plugins/dobity-baterky/
```
- ⚠️ Různé podle hostingu
- ⚠️ Musí se najít

### 3. Nahrávání souborů

**Lokálně:**
- Soubory jsou přímo dostupné
- Žádné nahrávání potřeba

**Staging:**
- CSV soubor musí být nahrán (`/tmp/poi_import.csv`)
- Import skript musí být nahrán do plugin directory
- Dva SFTP příkazy

### 4. Spuštění skriptu

**Lokálně:**
```bash
php safe-import-csv-staging.php file.csv
```
- ✅ Přímo v shellu

**Staging:**
```bash
ssh staging-server "cd /srv/htdocs/wp-content/plugins/dobity-baterky && php safe-import-csv-staging.php /tmp/poi_import.csv"
```
- ⚠️ Přes SSH tunnel
- ⚠️ Potřeba escape znaků
- ⚠️ Timeouty

---

## 💡 Možná zjednodušení pro budoucnost

### 1. Použít WP-CLI přímo (nejjednodušší)

```bash
ssh staging-server
cd /srv/htdocs
wp db-poi import-csv /tmp/poi_import.csv --log-every=1000
```

**Výhody:**
- WP-CLI zná správnou cestu k WordPressu
- Jednodušší než hledat cesty ručně
- Už je v pluginu dostupné (`wp db-poi import-csv`)

### 2. Zjednodušit wrapper skript

Můžeme vytvořit jednodušší skript, který:
- Automaticky najde správnou cestu
- Použije WP-CLI pokud je dostupné
- Fallback na `safe-import-csv-staging.php`

### 3. Nahrát import skript jako součást deploy

Přidat `safe-import-csv-staging.php` do build procesu, aby byl vždy na staging.

---

## 🚀 Doporučení

**Pro současnost:**
- Použít existující wrapper skript (`import-csv-staging.sh`)
- Automaticky řeší SSH, SFTP, nahrání souborů

**Pro budoucnost:**
1. ✅ **Použít WP-CLI** - pokud je dostupné, je to nejjednodušší
2. ✅ **Nahrát import skript do deploy** - aby byl vždy na staging
3. ✅ **Zjednodušit wrapper** - automatické hledání cest

---

## 📊 Porovnání

| Aspekt | Lokálně | Staging |
|--------|---------|---------|
| **Autentizace** | ❌ Není | ✅ SSH klíč + passphrase |
| **Nahrávání** | ❌ Není | ✅ SFTP (CSV + skript) |
| **Cesty** | ✅ Známé | ⚠️ Různé podle hostingu |
| **Spuštění** | ✅ `php script.php` | ⚠️ `ssh "php script.php"` |
| **Debugování** | ✅ Přímo vidíte | ⚠️ Přes SSH tunnel |
| **Timeouty** | ❌ Není | ✅ 60 minut limit |

---

*Dokument vysvětluje rozdíly mezi lokálním a staging prostředím pro CSV import.*


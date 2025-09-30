# Zadání pro AI Agenta - Staging Deployment Setup

## Úkol
Nastav kompletní staging deployment systém pro WordPress plugin podle vzoru z `dobity-baterky` pluginu.

## Co máš k dispozici
- `STAGING_DEPLOYMENT_GUIDE.md` - kompletní dokumentace
- `FILES_TO_COPY.md` - seznam souborů k zkopírování
- `build-simple.php` - build skript
- `scripts/` složka s deploy skripty
- `.env` soubor s proměnnými

## Požadované kroky

### 1. Zkontroluj dostupnost souborů
Nejdřív ověř, že máš všechny potřebné soubory:
- `build-simple.php`
- `scripts/deploy-staging.sh`
- `scripts/deploy-staging.expect`
- `scripts/deploy-production.sh`
- `scripts/deploy-production.expect`
- `scripts/load-env.sh`
- `.env`

**Pokud nějaký soubor chybí, požádej mě o něj nebo ho vytvoř podle dokumentace.**

### 2. Uprav build-simple.php
Najdi v `build-simple.php` metodu `get_version()` a změň:
```php
// ZMĚŇ TENTO ŘÁDEK:
$plugin_file = $this->plugin_dir . '/dobity-baterky.php';

// NA:
$plugin_file = $this->plugin_dir . '/TVUJ-PLUGIN-SOUBOR.php';
```
Kde `TVUJ-PLUGIN-SOUBOR.php` je název hlavního souboru tvého pluginu.

### 3. Uprav .env soubor
Zkontroluj, že `.env` obsahuje:
```bash
STAGING_PASS=DBwpgitHub
PROD_PASS=DBwpgitHub
STAGING_PASSWORD=vV5hsVTNKgfdoUeIxq4J
```

**Pokud .env neexistuje, vytvoř ho s těmito hodnotami.**

### 4. Uprav deploy skripty
V `scripts/deploy-staging.expect` a `scripts/deploy-production.expect` najdi a změň:

```tcl
# ZMĚŇ TENTO ŘÁDEK:
set user "staging-f576-dobitybaterky.wordpress.com"

# NA:
set user "TVUJ-STAGING-SITE.wordpress.com"
```

A v SFTP příkazech:
```tcl
# ZMĚŇ TYTO ŘÁDKY:
send "rename dobity-baterky $backup_dir\r"
send "mkdir dobity-baterky\r"
send "put -r * dobity-baterky/\r"

# NA:
send "rename tvuj-plugin-nazev $backup_dir\r"
send "mkdir tvuj-plugin-nazev\r"
send "put -r * tvuj-plugin-nazev/\r"
```

### 5. Uprav exclude patterns (volitelné)
V `build-simple.php` v metodě `copy_files_rsync()` najdi `$exclude_patterns` a uprav podle potřeby:
```php
$exclude_patterns = [
    'build/', 'build.php', 'build-simple.php',
    // ... existující patterns ...
    // PŘIDEJ specifické soubory pro tvůj plugin
    'tvuj-specificky-soubor.php',
    'tvuj-test-slozka/',
];
```

### 6. Otestuj build
Spusť test build:
```bash
php build-simple.php
```

**Pokud build selže, oprav chyby a zkus znovu.**

### 7. Otestuj staging deploy
```bash
source scripts/load-env.sh
./scripts/deploy-staging.sh
```

**Pokud deploy selže, zkontroluj:**
- SSH klíč `~/.ssh/id_ed25519_wpcom` existuje
- Passphrase v `.env` je správný
- Staging URL je správný
- SFTP přístup funguje

## Co dělat při problémech

### Chybějící soubory
Pokud nějaký soubor chybí, požádej mě o něj nebo ho vytvoř podle `STAGING_DEPLOYMENT_GUIDE.md`.

### Build chyby
- Zkontroluj PHP verzi a ZipArchive extension
- Ověř, že rsync je nainstalován
- Zkontroluj cesty k souborům

### Deploy chyby
- Ověř SSH klíč a passphrase
- Zkontroluj staging URL a přístup
- Zkontroluj SFTP příkazy v expect skriptech

### SFTP chyby
- Ověř, že `expect` je nainstalován
- Zkontroluj SSH klíč permissions
- Ověř, že passphrase je správný

## Očekávaný výsledek
Po úspěšném dokončení bys měl mít:
- ✅ Funkční build systém
- ✅ Automatizovaný staging deploy
- ✅ Automatizovaný production deploy
- ✅ Zálohování předchozích verzí
- ✅ Vyčištěný debug kód v buildu

## Kontrolní seznam
- [ ] Všechny soubory jsou dostupné
- [ ] build-simple.php je upraven pro nový plugin
- [ ] .env obsahuje správné hodnoty
- [ ] Deploy skripty jsou upraveny pro nový plugin
- [ ] Build funguje bez chyb
- [ ] Staging deploy funguje
- [ ] Plugin se aktivuje na staging bez chyb

## Poznámky
- Vždy testuj na staging před production
- Ulož passphrases do .env (ne do kódu)
- Pravidelně zálohuj před deploy
- Sleduj logy při deploy

**Pokud potřebuješ pomoc s nějakým krokem nebo narazíš na chybu, zeptej se mě!**

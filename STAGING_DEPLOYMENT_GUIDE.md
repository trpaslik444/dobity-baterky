# Staging Deployment Guide pro WordPress Pluginy

Tento dokument popisuje kompletní setup pro automatické nasazování WordPress pluginů na staging prostředí pomocí SFTP a expect skriptů.

## Přehled architektury

```
plugin-root/
├── scripts/
│   ├── deploy-staging.sh      # Hlavní deploy skript
│   ├── deploy-staging.expect  # SFTP automatizace
│   ├── deploy-production.sh   # Produkční deploy
│   ├── deploy-production.expect
│   └── load-env.sh           # Načítání .env proměnných
├── build-simple.php          # Build skript
├── .env                      # Environment proměnné
└── .ssh/
    └── id_ed25519_wpcom      # SSH klíč pro WordPress.com
```

## Požadavky

### 1. SSH klíč pro WordPress.com
- Vytvoř ed25519 SSH klíč: `ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_wpcom`
- Nahraj veřejný klíč do WordPress.com SFTP nastavení
- Ulož passphrase do `.env` souboru

### 2. Environment proměnné (.env)
```bash
# SSH passphrases
STAGING_PASS=your_staging_passphrase
PROD_PASS=your_production_passphrase

# WordPress credentials (volitelné)
STAGING_PASSWORD=your_wp_password
PROD_PASSWORD=your_wp_password
```

### 3. Build systém
- PHP s ZipArchive extension
- rsync (pro spolehlivé kopírování)
- expect (pro automatizaci SFTP)

## Build proces

### build-simple.php
- Vytváří produkční build s vyčištěným debug kódem
- Excluduje vývojové soubory (.git, testy, dokumentaci)
- Generuje ZIP distribuci pro WordPress
- Odstraňuje console.log z JS souborů

### Excludované soubory/složky
```
build/, build.php, build-simple.php
*.log, .git/, .gitignore
*.md, *.backup
test-*.php, debug-*.php, check-*.php
fix-*.php, clear-*.php, cleanup-*.php
force-*.php, update-*.sh, wp-update-*.sh
activate-*.php, uninstall.php
tests/, attachements/, *.json.backup
.DS_Store, *.zip, Sites/
```

## Deployment proces

### 1. Staging Deploy
```bash
# Načti environment proměnné
source scripts/load-env.sh

# Spusť staging deploy
./scripts/deploy-staging.sh
```

**Co se děje:**
1. Spustí se `build-simple.php` pro vytvoření build
2. Připojí se na SFTP server `sftp.wp.com`
3. Zazálohuje současnou verzi (`dobity-baterky.backup-TIMESTAMP`)
4. Nahraje novou verzi do `wp-content/plugins/dobity-baterky/`

### 2. Production Deploy
```bash
# Načti environment proměnné
source scripts/load-env.sh

# Spusť production deploy
./scripts/deploy-production.sh
```

## Konfigurace pro nový plugin

### 1. Zkopíruj soubory
Zkopíruj tyto soubory do kořene nového pluginu:
- `scripts/` (celá složka)
- `build-simple.php`
- `.env` (uprav podle potřeby)

### 2. Uprav build-simple.php
```php
// Změň název pluginu v get_version() metodě
private function get_version() {
    $plugin_file = $this->plugin_dir . '/VASE-PLUGIN-SOUBOR.php';
    // ... zbytek kódu
}

// Uprav exclude patterns podle potřeby
$exclude_patterns = [
    'build/', 'build.php', 'build-simple.php',
    // ... přidej specifické soubory pro tvůj plugin
];
```

### 3. Uprav deploy skripty
V `deploy-staging.expect` a `deploy-production.expect`:
```tcl
# Změň uživatelské jméno a host
set user "your-staging-site.wordpress.com"
set host "sftp.wp.com"
set identity "~/.ssh/id_ed25519_wpcom"

# Změň název pluginu v SFTP příkazech
send "rename your-plugin-name $backup_dir\r"
send "mkdir your-plugin-name\r"
send "put -r * your-plugin-name/\r"
```

### 4. Nastav .env
```bash
STAGING_PASS=your_staging_passphrase
PROD_PASS=your_production_passphrase
```

## Troubleshooting

### Časté problémy

**1. "STAGING_PASS není nastaven"**
- Zkontroluj, že `.env` soubor existuje
- Spusť `source scripts/load-env.sh` před deploy

**2. "No such file or directory" při rename**
- Normální chování - složka neexistovala
- Deploy pokračuje bez problémů

**3. "Connection reset by peer"**
- Normální konec SFTP session
- Deploy byl úspěšný

**4. Build selhává**
- Zkontroluj PHP verzi a ZipArchive extension
- Ověř, že rsync je nainstalován

### Debug módy

**Verbose SFTP:**
```bash
# V deploy-staging.expect přidej -v flag
spawn sftp -v -oIdentityFile=$identity -oIdentitiesOnly=yes ${user}@${host}
```

**Build debug:**
```php
// V build-simple.php přidej debug výpisy
echo "Debug: Kopíruji z $src do $dest\n";
```

## Bezpečnost

### SSH klíče
- Používej ed25519 klíče (bezpečnější než RSA)
- Ulož passphrase do `.env` (ne do kódu)
- `.env` soubor přidej do `.gitignore`

### SFTP přístup
- Používej specifické SSH klíče pro každé prostředí
- Pravidelně rotuj passphrases
- Omez SFTP přístup pouze na potřebné složky

## Monitoring a logy

### Build logy
- Build výstup se zobrazuje v konzoli
- Velikost ZIP souboru se zobrazuje po build

### Deploy logy
- SFTP výstup se zobrazuje v konzoli
- Záloha se vytvoří s timestampem

### Ověření deploy
- Zkontroluj staging URL po deploy
- Ověř, že plugin se aktivuje bez chyb
- Zkontroluj, že všechny funkce fungují

## Rozšíření

### Automatické testy
```bash
# Přidej do deploy-staging.sh před SFTP
echo "🧪 Spouštím testy..."
php tests/run-tests.php
if [ $? -ne 0 ]; then
    echo "❌ Testy selhaly, deploy zrušen"
    exit 1
fi
```

### E-mail notifikace
```bash
# Přidej na konec deploy-staging.sh
echo "📧 Posílám notifikaci..."
mail -s "Plugin deployed to staging" admin@example.com <<< "Deploy dokončen: $(date)"
```

### Rollback
```bash
# Přidej rollback skript
./scripts/rollback-staging.sh dobity-baterky.backup-20250926141906
```

## Závěr

Tento systém poskytuje:
- ✅ Automatizovaný build a deploy
- ✅ Zálohování předchozích verzí
- ✅ Bezpečné SFTP připojení
- ✅ Snadné rozšíření pro nové pluginy
- ✅ Debug a troubleshooting nástroje

Pro další dotazy nebo problémy se obrať na vývojový tým.

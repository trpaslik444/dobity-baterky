# Soubory k zkopírování pro nový plugin

## Povinné soubory

### 1. Build systém
```
build-simple.php                    # Hlavní build skript
```

### 2. Deploy skripty (celá složka)
```
scripts/
├── deploy-staging.sh              # Staging deploy
├── deploy-staging.expect          # SFTP automatizace pro staging
├── deploy-production.sh           # Production deploy  
├── deploy-production.expect       # SFTP automatizace pro production
└── load-env.sh                   # Načítání .env proměnných
```

### 3. Environment konfigurace
```
.env                              # Environment proměnné (uprav podle potřeby)
```

## Volitelné soubory (doporučené)

### 4. Dokumentace
```
STAGING_DEPLOYMENT_GUIDE.md       # Tento návod
FILES_TO_COPY.md                  # Tento seznam
```

### 5. Git konfigurace
```
.gitignore                        # Pokud obsahuje .env a build/ excludy
```

## Struktura po zkopírování

```
novy-plugin/
├── scripts/                      # ← ZKOPÍROVAT
│   ├── deploy-staging.sh
│   ├── deploy-staging.expect
│   ├── deploy-production.sh
│   ├── deploy-production.expect
│   └── load-env.sh
├── build-simple.php              # ← ZKOPÍROVAT
├── .env                          # ← ZKOPÍROVAT (upravit)
├── STAGING_DEPLOYMENT_GUIDE.md   # ← ZKOPÍROVAT
├── FILES_TO_COPY.md              # ← ZKOPÍROVAT
├── .gitignore                    # ← ZKOPÍROVAT (pokud existuje)
└── tvuj-plugin-soubor.php        # Tvůj plugin
```

## Kroky po zkopírování

### 1. Uprav build-simple.php
```php
// Změň cestu k hlavnímu souboru pluginu
private function get_version() {
    $plugin_file = $this->plugin_dir . '/TVUJ-PLUGIN-SOUBOR.php';
    // ... zbytek kódu zůstává stejný
}
```

### 2. Uprav .env
```bash
STAGING_PASS=your_staging_passphrase
PROD_PASS=your_production_passphrase
```

### 3. Uprav deploy skripty
V `scripts/deploy-staging.expect` a `scripts/deploy-production.expect`:
```tcl
# Změň uživatelské jméno
set user "your-staging-site.wordpress.com"

# Změň název pluginu v SFTP příkazech
send "rename your-plugin-name $backup_dir\r"
send "mkdir your-plugin-name\r" 
send "put -r * your-plugin-name/\r"
```

### 4. Otestuj
```bash
# Načti environment
source scripts/load-env.sh

# Spusť test build
php build-simple.php

# Spusť staging deploy
./scripts/deploy-staging.sh
```

## Poznámky

- **SSH klíče**: Musíš mít nastavené SSH klíče pro WordPress.com SFTP
- **Passphrases**: Ulož do `.env` souboru (ne do kódu)
- **Názvy**: Uprav všechny názvy pluginu v deploy skriptech
- **Excludy**: Uprav `$exclude_patterns` v `build-simple.php` podle potřeby
- **Testování**: Vždy otestuj na staging před production deploy

## Rychlý start

1. Zkopíruj všechny soubory z tohoto seznamu
2. Uprav `build-simple.php` - změň cestu k hlavnímu souboru
3. Uprav `.env` - nastav passphrases
4. Uprav deploy skripty - změň názvy a uživatele
5. Spusť `source scripts/load-env.sh && ./scripts/deploy-staging.sh`

Hotovo! 🚀

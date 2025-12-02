# POI Microservice - Nasazení a konfigurace

## Přehled

POI microservice musí běžet na serveru a být dostupný pro WordPress. Tento dokument popisuje, jak správně nakonfigurovat URL pro různé prostředí.

---

## Nasazení POI Microservice

### Možnosti nasazení

#### 1. Na stejném serveru jako WordPress (doporučeno)

POI microservice běží na stejném serveru, ale na jiném portu nebo jako subdoména.

**Příklad**:
- WordPress: `https://dobitybaterky.cz`
- POI microservice: `https://dobitybaterky.cz:3333` nebo `https://poi-api.dobitybaterky.cz`

#### 2. Na jiném serveru

POI microservice běží na samostatném serveru.

**Příklad**:
- WordPress: `https://dobitybaterky.cz`
- POI microservice: `https://poi-service.dobitybaterky.cz`

---

## Konfigurace URL v WordPressu

### Možnost 1: Konstanta v wp-config.php (doporučeno pro produkci)

**wp-config.php**:
```php
// Staging
define('DB_POI_SERVICE_URL', 'https://staging-f576-dobitybaterky.wpcomstaging.com:3333');

// Produkce
define('DB_POI_SERVICE_URL', 'https://dobitybaterky.cz:3333');
```

**Výhody**:
- ✅ Bezpečnější (není v databázi)
- ✅ Snadná změna mezi prostředími
- ✅ Nelze změnit z admin rozhraní (ochrana)

---

### Možnost 2: Admin rozhraní

1. Přejít na `Tools > POI Microservice`
2. Nastavit URL (např. `https://dobitybaterky.cz:3333`)
3. Uložit

**Poznámka**: Pokud je nastavena konstanta `DB_POI_SERVICE_URL`, admin rozhraní ji zobrazí jako read-only.

---

### Možnost 3: Auto-detekce (fallback)

Pokud není nastavena konstanta ani option, WordPress zkusí auto-detekci:

- **Staging/produkce**: Použije stejný host jako WordPress + port 3333
  - Např. WordPress: `https://dobitybaterky.cz` → POI service: `https://dobitybaterky.cz:3333`
- **Development**: `http://localhost:3333`

**⚠️ POZOR**: Auto-detekce je pouze fallback. Pro produkci vždy nastavte explicitně!

---

## Konfigurace pro různá prostředí

### Development (lokální)

**wp-config.php**:
```php
define('DB_POI_SERVICE_URL', 'http://localhost:3333');
```

**Nebo admin rozhraní**: `http://localhost:3333`

---

### Staging

**wp-config.php**:
```php
define('DB_POI_SERVICE_URL', 'https://staging-f576-dobitybaterky.wpcomstaging.com:3333');
```

**Nebo admin rozhraní**: `https://staging-f576-dobitybaterky.wpcomstaging.com:3333`

---

### Produkce

**wp-config.php**:
```php
define('DB_POI_SERVICE_URL', 'https://dobitybaterky.cz:3333');
```

**Nebo admin rozhraní**: `https://dobitybaterky.cz:3333`

---

## Ověření konfigurace

### 1. Admin rozhraní

1. Přejít na `Tools > POI Microservice`
2. Kliknout "Testovat připojení"
3. ✅ Mělo by se zobrazit: "Úspěšně připojeno! Nalezeno X POIs."

### 2. WP-CLI

```bash
wp eval-file scripts/test-poi-sync.php
```

### 3. Manuální test

```bash
# Zkontrolovat, že POI microservice běží
curl https://your-site.com:3333/api/pois/nearby?lat=50.0755&lon=14.4378&radius=2000
```

---

## Troubleshooting

### Chyba: "POI microservice URL není nakonfigurováno"

**Příčina**: URL není nastaveno.

**Řešení**:
1. Nastavit `DB_POI_SERVICE_URL` v `wp-config.php`
2. Nebo nastavit URL v admin rozhraní (`Tools > POI Microservice`)

---

### Chyba: "Failed to connect to localhost port 3333"

**Příčina**: WordPress se snaží připojit k localhost, ale POI microservice běží jinde.

**Řešení**:
1. Zkontrolovat, kde POI microservice skutečně běží
2. Nastavit správnou URL v `wp-config.php` nebo admin rozhraní
3. **Nepoužívat localhost na staging/produkci!**

---

### Chyba: "Connection refused" nebo timeout

**Příčina**: POI microservice není dostupný na zadané URL.

**Řešení**:
1. Zkontrolovat, že POI microservice běží
2. Zkontrolovat firewall/network
3. Zkontrolovat, že port 3333 je otevřený
4. Zkontrolovat, že URL je správná (včetně protokolu http/https)

---

## Doporučená konfigurace

### Produkce

**wp-config.php**:
```php
// POI Microservice URL
if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'production') {
    define('DB_POI_SERVICE_URL', 'https://dobitybaterky.cz:3333');
} elseif (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'staging') {
    define('DB_POI_SERVICE_URL', 'https://staging-f576-dobitybaterky.wpcomstaging.com:3333');
} else {
    define('DB_POI_SERVICE_URL', 'http://localhost:3333');
}
```

---

## Bezpečnost

### Doporučení

1. ✅ **Použít konstantu v wp-config.php** místo admin rozhraní pro produkci
2. ✅ **Použít HTTPS** pro staging/produkci
3. ✅ **Nepoužívat localhost** na staging/produkci
4. ✅ **Omezit přístup** k POI microservice (firewall, autentizace)

---

## Monitoring

**Admin rozhraní**: `Tools > POI Microservice > Statistiky synchronizace`

**Kontrola konfigurace**:
```php
$client = \DB\Services\POI_Microservice_Client::get_instance();
if ($client->is_configured()) {
    echo "POI microservice je nakonfigurován";
} else {
    echo "POI microservice NENÍ nakonfigurován";
}
```


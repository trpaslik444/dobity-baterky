# POI Synchronizace - WordPress Client Řešení

## ✅ Správné řešení

**WordPress sám volá POI microservice API a vytváří posty.** POI microservice **NEPOTŘEBUJE** přístup k WordPress databázi!

---

## Jak to funguje

### 1. WordPress Client

**Soubor**: `includes/Services/POI_Microservice_Client.php`

- WordPress klient pro komunikaci s POI microservice API
- Volá `GET /api/pois/nearby` endpoint
- Získané POIs vytváří jako WordPress posty

### 2. Automatická synchronizace

**Soubor**: `includes/Jobs/Nearby_Recompute_Job.php`

Při volání `get_candidates()` pro POIs:
```php
// Pokud hledáme POIs, nejdříve zkusit synchronizovat z POI microservice
if ($type === 'poi') {
    $this->sync_pois_from_microservice($lat, $lng, $radiusKm * 1000);
}
```

### 3. POI Microservice

**Soubor**: `poi-service/src/aggregator.ts`

- Pouze vrací POIs přes REST API
- **NEMUSÍ** mít přístup k WordPress databázi
- Ukládá POIs pouze do PostgreSQL

---

## Workflow

```
1. WordPress potřebuje nearby POIs pro nabíječku
   ↓
2. get_candidates() zavolá sync_pois_from_microservice()
   ↓
3. POI_Microservice_Client zavolá POI microservice API
   GET /api/pois/nearby?lat=50.123&lon=14.456&radius=2000
   ↓
4. POI microservice vrátí POIs z PostgreSQL (nebo stáhne z free zdrojů)
   ↓
5. WordPress vytvoří posty pro každý POI
   ↓
6. get_candidates() najde POIs v WordPress MySQL
   ↓
7. ORS API vypočítá walking distances
```

---

## Konfigurace

### WordPress (`.env` nebo options)

```php
// Nastavit URL POI microservice
update_option('db_poi_service_url', 'http://localhost:3333');
```

### POI Microservice (`.env`)

```env
# Pouze PostgreSQL, žádné WordPress přihlašovací údaje!
DATABASE_URL=postgresql://user:pass@localhost:5432/pois
OPENTRIPMAP_API_KEY=your-key
```

**To je vše!** POI microservice nepotřebuje přístup k WordPress databázi.

---

## Výhody

✅ **Bezpečnější** - POI microservice nemá přístup k WordPress databázi  
✅ **Jednodušší** - WordPress má kontrolu nad vytvářením svých postů  
✅ **Flexibilnější** - WordPress může rozhodnout, kdy synchronizovat  
✅ **Méně konfigurace** - POI microservice nepotřebuje WordPress přihlašovací údaje  

---

## Shrnutí změn

| Komponenta | Před | Po |
|------------|------|-----|
| **POI Microservice** | Přístup k WordPress MySQL | Pouze REST API |
| **WordPress** | Hledá pouze v MySQL | Volá POI microservice API |
| **Konfigurace** | WordPress DB přihlašovací údaje | Pouze POI service URL |

**Výsledek**: WordPress má kontrolu, POI microservice je jednodušší! 🎉


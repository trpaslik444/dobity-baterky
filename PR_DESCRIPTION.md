# POI Microservice: WordPress Client Sync and Configuration Improvements

## 📋 Přehled změn

### Hlavní změny
- ✅ Implementace WordPress Client pro synchronizaci POIs z POI microservice
- ✅ Odstranění Google API z POI microservice (prevence vyčerpání kvót)
- ✅ Zjednodušení konfigurace URL (bez detekce prostředí)
- ✅ Lepší error handling a retry logika
- ✅ Testovací dokumentace a skripty

---

## 🔧 Technické změny

### Nové soubory
- `includes/Services/POI_Microservice_Client.php` - WordPress klient pro komunikaci s POI microservice API
- `includes/Admin/POI_Service_Admin.php` - Admin rozhraní pro konfiguraci
- `scripts/test-poi-sync.php` - WP-CLI testovací skript
- `docs/POI_WORKFLOW_EXPLAINED.md` - Kompletní vysvětlení workflow
- `docs/TESTING_POI_SYNC.md` - Testovací scénáře
- `docs/TESTING_QUICK_START.md` - Rychlý start
- `docs/POI_SERVICE_DEPLOYMENT.md` - Nasazení a konfigurace

### Upravené soubory
- `includes/Jobs/Nearby_Recompute_Job.php` - Automatická synchronizace POIs při nearby výpočtu
- `poi-service/src/aggregator.ts` - Odstranění Google API
- `dobity-baterky.php` - Registrace POI_Service_Admin

---

## 🎯 Funkcionalita

### WordPress Client
- Volá POI microservice API (`GET /api/pois/nearby`)
- Vytváří WordPress posty typu `poi` z výsledků
- Deduplikace podle `external_id` nebo GPS + jméno
- Retry logika s exponential backoff
- Validace dat (GPS, název, rating, kategorie)

### Admin rozhraní
- Konfigurace POI microservice URL
- Konfigurace timeout a max retries
- Test připojení
- Statistiky synchronizace

### Automatická synchronizace
- Při volání `get_candidates()` pro POIs se automaticky synchronizují z microservice
- Cache prevence race conditions (5 minut)
- Transient cache pro duplicitní API callů

---

## ⚠️ Breaking Changes

1. **POI microservice URL musí být explicitně nastaveno**
   - Není auto-detekce prostředí
   - Nastavit v `wp-config.php`: `define('DB_POI_SERVICE_URL', '...');`
   - Nebo v admin rozhraní: `Tools > POI Microservice`

2. **Google API bylo odstraněno z POI microservice**
   - Používá pouze free zdroje (OpenTripMap, Wikidata)
   - Google API se používá pouze v WordPressu pro on-demand enrichment

---

## 📚 Dokumentace

### Nová dokumentace
- `docs/POI_WORKFLOW_EXPLAINED.md` - Kompletní vysvětlení workflow s diagramy
- `docs/TESTING_POI_SYNC.md` - 10 testovacích scénářů
- `docs/TESTING_QUICK_START.md` - Rychlý start (5 minut)
- `docs/POI_SERVICE_DEPLOYMENT.md` - Nasazení a konfigurace

### Aktualizovaná dokumentace
- `docs/POI_WORKFLOW_SIMPLIFIED.md` - Aktualizováno s novým workflow
- `docs/POI_STORAGE_AND_SYNC.md` - Aktualizováno s WordPress Client řešením

---

## 🧪 Testování

### Admin rozhraní
1. Přejít na `Tools > POI Microservice`
2. Nastavit URL POI microservice
3. Kliknout "Testovat připojení"

### WP-CLI
```bash
wp eval-file scripts/test-poi-sync.php
```

### Testovací scénáře
Viz `docs/TESTING_POI_SYNC.md` pro kompletní seznam testovacích scénářů.

---

## 🔄 Workflow

```
1. Uživatel klikne na nabíječku na mapě
   ↓
2. WordPress: "Potřebuji nearby POIs"
   ↓
3. WordPress: "Mám už POIs v MySQL?"
   NE → Musím je získat z POI microservice
   ↓
4. WordPress zavolá POI microservice API
   GET https://poi-api.your-site.com/api/pois/nearby?lat=50.123&lon=14.456
   ↓
5. POI microservice: "Mám už POIs v PostgreSQL?"
   NE → Stáhnu z free zdrojů (OpenTripMap, Wikidata)
   ↓
6. POI microservice uloží do PostgreSQL
   ↓
7. POI microservice vrátí JSON s POIs
   ↓
8. WordPress dostane JSON
   ↓
9. WordPress vytvoří posty (post_type='poi')
   ↓
10. WordPress uloží do MySQL
   ↓
11. WordPress najde POIs v MySQL
   ↓
12. WordPress vypočítá walking distances (ORS API)
   ↓
13. Frontend zobrazí nearby POIs
```

---

## ✅ Checklist

- [x] Kód je commitnutý
- [x] Dokumentace je aktualizovaná
- [x] Testovací scénáře jsou připravené
- [x] Error handling je implementován
- [x] Retry logika je implementována
- [x] Validace dat je implementována
- [ ] Testováno na staging
- [ ] Review dokončeno

---

## 📝 Poznámky

- POI microservice musí běžet a být dostupný na zadané URL
- Pro staging/produkci použít HTTPS URL (ne localhost)
- Port 3333 je pouze pro lokální vývoj
- Google API bylo odstraněno z POI microservice kvůli riziku vyčerpání kvót


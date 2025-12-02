# POI Workflow - Jak to funguje

## 🎯 Proč existuje POI microservice?

**Problém**: WordPress potřebuje zobrazit "blízká místa" (POIs) kolem nabíječek, ale:
- WordPress nechce stahovat data z externích API při každém requestu (pomalé, drahé)
- Potřebujeme cache a deduplikaci
- Chceme používat free zdroje (OpenTripMap, Wikidata) místo placených Google API

**Řešení**: POI microservice
- Běží jako samostatná služba (Node.js/Fastify)
- Má vlastní databázi (PostgreSQL) s POIs
- Stahuje POIs z free zdrojů a ukládá je
- WordPress si z něj POIs "půjčuje" přes API

---

## 📊 Dvě databáze

### 1. POI Microservice (PostgreSQL)
- **Kde**: Samostatná služba, vlastní databáze
- **Co obsahuje**: Všechny POIs z free zdrojů (OpenTripMap, Wikidata)
- **Kdy se naplní**: 
  - Při volání API (`/api/pois/nearby`)
  - Při periodické aktualizaci (jednou za 30 dní)

### 2. WordPress (MySQL)
- **Kde**: WordPress databáze
- **Co obsahuje**: WordPress posty typu `poi`
- **Kdy se naplní**: Když WordPress zavolá POI microservice a vytvoří posty

---

## 🔄 Workflow - Krok za krokem

### Scénář: Uživatel klikne na nabíječku na mapě

```
1. Frontend (mapa)
   ↓
   "Potřebuji nearby POIs pro tuto nabíječku"
   ↓
2. WordPress (PHP)
   ↓
   get_candidates(lat, lng, type='poi')
   ↓
3. WordPress zkontroluje: "Mám už POIs v MySQL?"
   ↓
   NE → Musím je získat z POI microservice
   ↓
4. WordPress zavolá POI microservice API
   ↓
   GET https://poi-api.your-site.com/api/pois/nearby?lat=50.123&lon=14.456
   ↓
5. POI microservice (Node.js)
   ↓
   "Mám už POIs v PostgreSQL?"
   ↓
   NE → Stáhnu z free zdrojů (OpenTripMap, Wikidata)
   ↓
   Uložím do PostgreSQL
   ↓
   Vrátím JSON s POIs
   ↓
6. WordPress dostane JSON s POIs
   ↓
   Pro každý POI vytvoří WordPress post (post_type='poi')
   ↓
   Uloží do MySQL
   ↓
7. WordPress najde POIs v MySQL
   ↓
   Vypočítá walking distances (ORS API)
   ↓
   Vrátí frontendu
   ↓
8. Frontend zobrazí nearby POIs na mapě
```

---

## 🔗 Proč je důležitá URL?

**URL = Adresa, kde běží POI microservice**

WordPress potřebuje vědět, **kde** má volat API:

```php
// WordPress potřebuje vědět:
$poi_service_url = 'https://poi-api.your-site.com';

// Pak zavolá:
GET $poi_service_url/api/pois/nearby?lat=50.123&lon=14.456
```

**Bez URL**: WordPress neví, kam volat → 502 Bad Gateway

---

## 📍 Kde se POIs ukládají?

### Krok 1: POI microservice stáhne POIs
```
OpenTripMap API → POI microservice → PostgreSQL
```

**PostgreSQL tabulka `pois`**:
- `id`, `name`, `lat`, `lon`, `category`, `rating`, ...
- Uloženo v POI microservice databázi

### Krok 2: WordPress získá POIs z microservice
```
WordPress → POI microservice API → WordPress dostane JSON
```

### Krok 3: WordPress vytvoří posty
```
WordPress → Vytvoří posty typu 'poi' → MySQL
```

**MySQL tabulka `wp_posts`**:
- `post_type = 'poi'`
- `post_title = 'Kavárna U Stromu'`
- Meta data: `_poi_lat`, `_poi_lon`, `_poi_category`, ...

---

## ⚙️ Kdy se co děje?

### Při prvním kliknutí na nabíječku

1. **POI microservice** (pokud nemá POIs v PostgreSQL):
   - Stáhne z OpenTripMap
   - Stáhne z Wikidata
   - Uloží do PostgreSQL
   - Vrátí JSON

2. **WordPress** (pokud nemá POIs v MySQL):
   - Zavolá POI microservice API
   - Dostane JSON s POIs
   - Vytvoří WordPress posty
   - Uloží do MySQL

3. **WordPress** (vždy):
   - Najde POIs v MySQL
   - Vypočítá walking distances (ORS API)
   - Vrátí frontendu

### Při dalším kliknutí (stejná oblast)

1. **POI microservice**:
   - Má POIs v PostgreSQL (cache)
   - Vrátí z cache (rychle)

2. **WordPress**:
   - Má POIs v MySQL
   - Vypočítá walking distances
   - Vrátí frontendu

---

## 🔄 Periodická aktualizace

**POI microservice** jednou za 30 dní:
- Projde všechny oblasti
- Zkontroluje, jestli nejsou nové POIs
- Aktualizuje PostgreSQL

**WordPress**:
- Při dalším kliknutí získá aktualizované POIs
- Vytvoří nové posty pro nové POIs

---

## 💡 Proč dvě databáze?

### PostgreSQL (POI microservice)
- ✅ Optimalizovaná pro geografické dotazy
- ✅ Cache pro rychlé odpovědi
- ✅ Deduplikace napříč zdroji
- ✅ Periodická aktualizace

### MySQL (WordPress)
- ✅ WordPress posty (standardní WordPress workflow)
- ✅ Integrace s WordPress funkcionalitou
- ✅ Meta data, taxonomie, atd.

---

## 🎯 Shrnutí

1. **POI microservice** = Samostatná služba, která stahuje a ukládá POIs
2. **WordPress** = Získá POIs z microservice a vytvoří posty
3. **URL** = Adresa, kde běží POI microservice (WordPress potřebuje vědět, kam volat)
4. **Dvě databáze** = PostgreSQL (microservice) + MySQL (WordPress)
5. **Workflow** = Microservice stáhne → WordPress získá → WordPress vytvoří posty → Frontend zobrazí

---

## ❓ Časté otázky

### Proč ne jen jedna databáze?

- POI microservice je samostatná služba (může běžet jinde)
- PostgreSQL je lepší pro geografické dotazy
- WordPress potřebuje WordPress posty (MySQL)

### Proč WordPress nevolá API přímo?

- WordPress volá API, ale pak vytváří posty sám
- Má kontrolu nad svými daty
- Může přidat WordPress funkcionalitu (meta, taxonomie, atd.)

### Co když POI microservice není dostupný?

- WordPress zobrazí chybu (502 Bad Gateway)
- Může použít existující POIs v MySQL (pokud jsou)
- Ale nové POIs nezíská

### Kde se nastaví URL?

**Možnost 1**: `wp-config.php`
```php
define('DB_POI_SERVICE_URL', 'https://poi-api.your-site.com');
```

**Možnost 2**: Admin rozhraní
```
Tools > POI Microservice > Nastavit URL
```


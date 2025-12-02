# POI Synchronizace - Přímý přístup k WordPress MySQL

## ✅ Zjednodušené řešení

Místo REST API endpointu používáme **přímý přístup k WordPress MySQL databázi**. POI microservice už má přístup k WordPress MySQL (pro synchronizaci kvót), takže může přímo vytvářet WordPress posty pomocí SQL dotazů.

---

## Jak to funguje

### 1. Přímý přístup k WordPress MySQL

**Soubor**: `poi-service/src/sync/wordpressDirect.ts`

- Používá stejné připojení jako quota manager (`mysql2/promise`)
- Vytváří WordPress posty přímo pomocí SQL dotazů
- **Nevyžaduje REST API endpoint**

### 2. Automatická synchronizace

**Soubor**: `poi-service/src/aggregator.ts`

Po vytvoření nových POIs v PostgreSQL:
```typescript
// Automaticky synchronizuje s WordPressem
if (newPois.length > 0 && CONFIG.wordpressDbHost && CONFIG.wordpressDbName) {
  const { syncPoisToWordPress } = await import('./sync/wordpressDirect');
  await syncPoisToWordPress(newPois, 10);
}
```

### 3. Periodická aktualizace

**Soubor**: `poi-service/src/jobs/periodicUpdate.ts`

Při periodické aktualizaci (30 dní) se nové POIs automaticky synchronizují s WordPressem.

---

## Konfigurace

### POI Microservice (`.env`)

```env
# WordPress MySQL (stejné jako pro quota synchronizaci)
WORDPRESS_DB_HOST=localhost
WORDPRESS_DB_NAME=wordpress_db
WORDPRESS_DB_USER=wordpress_user
WORDPRESS_DB_PASSWORD=wordpress_password
WORDPRESS_DB_PREFIX=wp_
```

**To je vše!** Žádné REST API URL, žádné nonce, žádné API key.

---

## Workflow

```
1. POI microservice stáhne POIs z free zdrojů
   ↓
2. Uloží do PostgreSQL
   ↓
3. Automaticky vytvoří WordPress posty přímo v MySQL
   (pomocí SQL dotazů)
   ↓
4. WordPress nearby workflow najde POI v MySQL
```

---

## Výhody

✅ **Jednodušší** - žádné REST API endpointy  
✅ **Rychlejší** - přímý SQL přístup  
✅ **Bezpečnější** - používá stejné připojení jako quota manager  
✅ **Méně konfigurace** - stačí MySQL přihlašovací údaje  

---

## Deduplikace

POI microservice automaticky kontroluje duplicity:
- Podle `external_id` (ID z PostgreSQL)
- Nebo GPS + jméno (50m + 80% podobnost)

Pokud POI už existuje, aktualizuje ho místo vytvoření nového.

---

## WordPress Post Type

Vytvořené POIs mají:
- `post_type = 'poi'`
- `post_status = 'publish'`
- Meta data: `_poi_lat`, `_poi_lng`, `_poi_external_id`, atd.
- Taxonomy: `poi_type` (kategorie)

---

## REST API Endpoint (volitelný)

REST API endpoint (`includes/REST_POI_Sync.php`) je stále k dispozici, ale **není potřeba** pro synchronizaci z POI microservice. Může být užitečný pro:
- Externí integrace
- Manuální synchronizaci
- Debugging

---

## Shrnutí změn

| Komponenta | Před | Po |
|------------|------|-----|
| **Synchronizace** | REST API endpoint | Přímý SQL přístup |
| **Konfigurace** | REST URL + nonce/key | Pouze MySQL přihlašovací údaje |
| **Složitost** | Vysoká | Nízká |
| **Výkon** | HTTP requesty | Přímé SQL dotazy |

**Výsledek**: Jednodušší, rychlejší a bezpečnější řešení! 🎉


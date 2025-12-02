# Návrhy oprav pro PR #76 - POI Microservice

## 1. Oprava race condition v quota managementu

### Soubor: `poi-service/src/aggregator.ts`

**Problém:**
`canUseGoogle()` a `incrementGoogleUsage()` nejsou atomické, což může vést k překročení limitu.

**Řešení:**
```typescript
// Nahradit funkce canUseGoogle() a incrementGoogleUsage() atomickou funkcí
async function reserveGoogleQuota(): Promise<boolean> {
  const today = startOfToday();
  return await prisma.$transaction(async (tx) => {
    // Použít SELECT FOR UPDATE pro lock
    const usage = await tx.$queryRaw<Array<{ count: number }>>`
      SELECT count FROM "ApiUsage" 
      WHERE provider = 'google' AND date = ${today}
      FOR UPDATE
    `;
    
    const current = usage[0]?.count ?? 0;
    if (current >= CONFIG.MAX_GOOGLE_CALLS_PER_DAY) {
      return false;
    }
    
    // Atomický upsert
    await tx.apiUsage.upsert({
      where: { provider_date: { provider: 'google', date: today } },
      create: { provider: 'google', date: today, count: 1 },
      update: { count: { increment: 1 } },
    });
    
    return true;
  });
}

// Upravit getNearbyPois() funkci:
const googleThreshold = Math.max(minCount, CONFIG.MIN_POIS_BEFORE_GOOGLE);
if (merged.length < googleThreshold && CONFIG.GOOGLE_PLACES_ENABLED) {
  const canUse = await reserveGoogleQuota(); // Atomická rezervace
  if (canUse) {
    const googleProvider = new GooglePlacesProvider();
    const google = await googleProvider.searchAround(lat, lon, radiusMeters, normalizedCategories);
    if (google.length) {
      merged = await persistIncoming([...mergedToNormalized(merged), ...google], lat, lon, radiusMeters);
      providersUsed.push('google');
    }
    // Poznámka: Kvóta už byla rezervována v reserveGoogleQuota()
  }
}
```

## 2. Sjednocení kvót s WordPress

### Varianta A: Sdílená databáze (doporučeno)

**Soubor:** `poi-service/src/config.ts`, `poi-service/src/aggregator.ts`

**Kroky:**
1. Přidat konfiguraci pro WordPress MySQL připojení
2. Použít stejnou tabulku `wp_db_places_usage` jako PR #75
3. Sjednotit výchozí limit na 300

```typescript
// config.ts
const configSchema = z.object({
  // ... existující konfigurace
  WORDPRESS_DB_HOST: z.string().optional(),
  WORDPRESS_DB_NAME: z.string().optional(),
  WORDPRESS_DB_USER: z.string().optional(),
  WORDPRESS_DB_PASSWORD: z.string().optional(),
  WORDPRESS_DB_PREFIX: z.string().default('wp_'),
  MAX_GOOGLE_CALLS_PER_DAY: z.coerce.number().default(300), // Změna z 500 na 300
});

// aggregator.ts - nová funkce pro WordPress quota
import mysql from 'mysql2/promise';

async function reserveGoogleQuotaWordPress(): Promise<boolean> {
  if (!CONFIG.wordpressDbHost) {
    // Fallback na Prisma, pokud není WordPress DB nakonfigurována
    return await reserveGoogleQuota();
  }
  
  const connection = await mysql.createConnection({
    host: CONFIG.wordpressDbHost,
    database: CONFIG.wordpressDbName,
    user: CONFIG.wordpressDbUser,
    password: CONFIG.wordpressDbPassword,
  });
  
  try {
    await connection.beginTransaction();
    
    const today = new Date().toISOString().split('T')[0];
    const tableName = `${CONFIG.wordpressDbPrefix}db_places_usage`;
    
    // SELECT FOR UPDATE pro lock
    const [rows] = await connection.execute(
      `SELECT request_count FROM ${tableName} 
       WHERE usage_date = ? AND api_name = ? 
       FOR UPDATE`,
      [today, 'places_details']
    );
    
    const current = (rows as any[])[0]?.request_count ?? 0;
    if (current >= CONFIG.MAX_GOOGLE_CALLS_PER_DAY) {
      await connection.rollback();
      return false;
    }
    
    // Atomický upsert
    await connection.execute(
      `INSERT INTO ${tableName} (usage_date, api_name, request_count) 
       VALUES (?, ?, 1)
       ON DUPLICATE KEY UPDATE request_count = request_count + 1`,
      [today, 'places_details']
    );
    
    await connection.commit();
    return true;
  } catch (error) {
    await connection.rollback();
    console.error('WordPress quota reservation failed:', error);
    return false;
  } finally {
    await connection.end();
  }
}
```

### Varianta B: API synchronizace

**Soubor:** `poi-service/src/aggregator.ts`

Vytvořit WordPress REST API endpoint pro quota management a volat ho z microservice:

```typescript
async function reserveGoogleQuotaViaAPI(): Promise<boolean> {
  const wpApiUrl = process.env.WORDPRESS_API_URL || 'https://your-site.com';
  const wpApiKey = process.env.WORDPRESS_API_KEY; // API key pro autentizaci
  
  try {
    const response = await fetch(`${wpApiUrl}/wp-json/db/v1/quota/reserve`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-API-Key': wpApiKey,
      },
      body: JSON.stringify({ provider: 'google' }),
    });
    
    if (!response.ok) {
      return false;
    }
    
    const data = await response.json();
    return data.reserved === true;
  } catch (error) {
    console.error('WordPress quota API call failed:', error);
    return false;
  }
}
```

**WordPress endpoint** (přidat do `includes/REST_Map.php` nebo nový soubor):
```php
register_rest_route('db/v1', '/quota/reserve', array(
    'methods' => 'POST',
    'callback' => array($this, 'handle_quota_reserve'),
    'permission_callback' => function($request) {
        $api_key = $request->get_header('X-API-Key');
        return $api_key === get_option('db_microservice_api_key');
    },
));

public function handle_quota_reserve($request) {
    $provider = $request->get_param('provider');
    if ($provider !== 'google') {
        return new \WP_Error('invalid_provider', 'Invalid provider', array('status' => 400));
    }
    
    $service = \DB\Util\Places_Enrichment_Service::get_instance();
    $result = $service->reserve_quota('places_details');
    
    if (is_wp_error($result)) {
        return rest_ensure_response(array('reserved' => false, 'error' => $result->get_error_message()));
    }
    
    return rest_ensure_response(array('reserved' => true));
}
```

## 3. Rezervace kvóty před voláním API

**Soubor:** `poi-service/src/aggregator.ts`

**Aktuální kód:**
```typescript
if (merged.length < googleThreshold && CONFIG.GOOGLE_PLACES_ENABLED && (await canUseGoogle())) {
  const googleProvider = new GooglePlacesProvider();
  const google = await googleProvider.searchAround(...);
  if (google.length) {
    await incrementGoogleUsage(); // ⚠️ Inkrementuje se až po volání
  }
}
```

**Opravený kód:**
```typescript
if (merged.length < googleThreshold && CONFIG.GOOGLE_PLACES_ENABLED) {
  const quotaReserved = await reserveGoogleQuota(); // Rezervace PŘED voláním
  if (quotaReserved) {
    const googleProvider = new GooglePlacesProvider();
    const google = await googleProvider.searchAround(lat, lon, radiusMeters, normalizedCategories);
    if (google.length) {
      merged = await persistIncoming([...mergedToNormalized(merged), ...google], lat, lon, radiusMeters);
      providersUsed.push('google');
    }
    // Poznámka: Kvóta už byla rezervována v reserveGoogleQuota()
  }
}
```

## 4. Error handling pro Google API

**Soubor:** `poi-service/src/providers/googlePlaces.ts`

**Aktuální kód:**
```typescript
const response = await fetch(url);
if (!response.ok) return [];
```

**Opravený kód:**
```typescript
const response = await fetch(url);
if (!response.ok) {
  // Logovat chyby pro debugging
  if (response.status === 429) {
    console.warn('[GooglePlaces] Rate limit exceeded');
    // Může být vráceno do aggregatoru pro lepší error handling
  } else if (response.status === 403) {
    console.error('[GooglePlaces] API key invalid or quota exceeded');
  } else {
    console.error(`[GooglePlaces] API error: ${response.status} ${response.statusText}`);
  }
  return [];
}

const data = await response.json();
if (data.status === 'OVER_QUERY_LIMIT') {
  console.warn('[GooglePlaces] Over query limit');
  return [];
}
if (data.status === 'REQUEST_DENIED') {
  console.error('[GooglePlaces] Request denied:', data.error_message);
  return [];
}
```

## 5. Sjednocení konfiguračních proměnných

**Soubor:** `poi-service/src/config.ts`

**Změna:**
```typescript
// Původní
MAX_GOOGLE_CALLS_PER_DAY: z.coerce.number().default(500),

// Opravené
MAX_PLACES_REQUESTS_PER_DAY: z.coerce.number().default(300), // Sjednoceno s PR #75
```

**Aktualizovat všechny reference:**
```typescript
// aggregator.ts
if (current >= CONFIG.MAX_PLACES_REQUESTS_PER_DAY) {
  // ...
}
```

## 6. Sjednocení feature flagů

**Soubor:** `poi-service/src/config.ts`

**Změna:**
```typescript
// Původní
GOOGLE_PLACES_ENABLED: z.coerce.boolean().default(true),

// Opravené (volitelné - pokud chceme sjednotit s WordPress)
PLACES_ENRICHMENT_ENABLED: z.coerce.boolean().default(true),
```

## 7. Dokumentace

**Soubor:** `poi-service/README.md`

Přidat sekci:
```markdown
## Google Places API Quota Management

Microservice respektuje stejné denní limity jako WordPress plugin (PR #75):
- Výchozí limit: 300 požadavků/den (konfigurovatelné přes `MAX_PLACES_REQUESTS_PER_DAY`)
- Kvóty jsou synchronizované s WordPress pomocí [zvolené varianty]
- Google API se volá pouze po user interakci na frontendu (kliknutí na POI)
- Automatické batch processory respektují kvóty a přeskočí volání, pokud je limit vyčerpán
```

## Shrnutí priorit

### P1 - Kritické (blokující merge):
1. ✅ Opravit race condition (atomická operace)
2. ✅ Rezervovat kvótu před voláním API
3. ✅ Sjednotit výchozí limit (300 místo 500)
4. ✅ Implementovat synchronizaci kvót s WordPress

### P2 - Důležité:
5. ⚠️ Přidat error handling pro Google API chyby
6. ⚠️ Sjednotit názvy konfiguračních proměnných
7. ⚠️ Dokumentovat integraci s WordPress

### P3 - Vylepšení:
8. 💡 Přidat monitoring a alerting
9. 💡 Přidat testy pro quota management


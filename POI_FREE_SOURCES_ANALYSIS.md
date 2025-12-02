# Analýza implementace Free zdrojů pro POI microservice

## ✅ CO JE IMPLEMENTOVÁNO SPRÁVNĚ

### 1. **Aggregator logika (fallback)**
- ✅ Cache → DB → Manual → **OpenTripMap + Wikidata** → Google (fallback)
- ✅ Google se volá **pouze jako fallback** když `merged.length < googleThreshold`
- ✅ Správně filtruje rating 4.0+ pomocí `passesRatingFilter()`

### 2. **Wikidata Provider**
- ✅ Implementován jako free zdroj
- ✅ Používá SPARQL endpoint (zdarma)
- ✅ Nemá rating (což je OK)
- ✅ Přijímá se podle `ALLOW_POIS_WITHOUT_RATING` konfigurace
- ✅ Filtruje kategorie podle whitelistu

### 3. **OpenTripMap Provider**
- ✅ Implementován jako free zdroj
- ✅ Používá OpenTripMap API
- ✅ Mapuje kategorie podle whitelistu
- ✅ Normalizuje rating z OpenTripMap (1-3) na škálu 3.5-5

## ⚠️ PROBLÉMY, KTERÉ JE POTŘEBA OPRAVIT

### 1. **OpenTripMap - špatné filtrování ratingu**

**Problém:**
```typescript
// Současná implementace:
&rate=2  // Filtruje jen rating 2+ (což zahrnuje i rate=1 v některých případech)
```

**Mapování ratingu:**
```typescript
private convertRating(rate: number): number {
  if (rate >= 3) return 4.7;  // ✅ OK (4.7 >= 4.0)
  if (rate >= 2) return 4.2;  // ✅ OK (4.2 >= 4.0)
  return 3.8;                  // ❌ PROBLÉM (3.8 < 4.0)!
}
```

**Důsledek:**
- Pokud OpenTripMap API vrátí `rate=1`, mapuje se na 3.8, což je **pod 4.0**
- Tento POI by měl být **odfiltrován**, ale může projít, pokud se nefiltruje správně

**Řešení:**
1. **Buď** filtrovat už v API dotazu: `rate=3` (jen nejlepší místa)
2. **Nebo** filtrovat po normalizaci pomocí `passesRatingFilter()` (což už se dělá v `persistIncoming`)

### 2. **OpenTripMap - mapování kategorií**

**Problém:**
```typescript
private mapCategories(categories: string[]): string {
  return categories.join(',');  // ❌ OpenTripMap nepodporuje všechny naše kategorie
}
```

**Důsledek:**
- OpenTripMap má vlastní systém kategorií (`kinds`)
- Naše kategorie (`restaurant`, `cafe`, atd.) nemusí odpovídat OpenTripMap `kinds`
- Může to vést k prázdným výsledkům

**Řešení:**
- Mapovat naše kategorie na OpenTripMap `kinds`
- Např. `restaurant` → `restaurants`, `cafe` → `cafes`, atd.

### 3. **Wikidata - chybí filtrování podle typu místa**

**Problém:**
- Wikidata SPARQL dotaz nefiltruje podle typu místa (muzeum, památka, atd.)
- Vrací všechna místa v okolí, ne jen relevantní kategorie

**Řešení:**
- Přidat filtrování podle Wikidata property (P31 - instance of)
- Např. `wdt:P31/wdt:P279* wd:Q33506` (muzeum)

## 🔧 NAVRHOVANÉ OPRAVY

### Oprava 1: OpenTripMap - lepší filtrování ratingu

```typescript
// Změnit rate=2 na rate=3 (jen nejlepší místa)
const url = `${this.endpoint}?radius=${radiusMeters}&lon=${lon}&lat=${lat}&kinds=${encodeURIComponent(
  kinds
)}&rate=3&format=json&apikey=${CONFIG.opentripMapApiKey}`;  // rate=3 místo rate=2
```

**Nebo** ponechat `rate=2` ale filtrovat po normalizaci (což už se dělá).

### Oprava 2: OpenTripMap - mapování kategorií

```typescript
private mapCategories(categories: string[]): string {
  const OTM_KINDS_MAP: Record<string, string> = {
    'restaurant': 'restaurants',
    'cafe': 'cafes',
    'bar': 'bars',
    'pub': 'pubs',
    'fast_food': 'fast_food',
    'bakery': 'bakeries',
    'park': 'parks',
    'playground': 'playgrounds',
    'museum': 'museums',
    'gallery': 'galleries',
    'tourist_attraction': 'interesting_places',
    'viewpoint': 'viewpoints',
    // ... další mapování
  };
  
  const mapped = categories
    .map(cat => OTM_KINDS_MAP[cat] || cat)
    .filter(Boolean);
  
  return mapped.join(',');
}
```

### Oprava 3: Wikidata - filtrování podle typu

```typescript
private buildQuery(lat: number, lon: number, radiusMeters: number): string {
  return `
    SELECT ?item ?itemLabel ?lat ?lon ?cityLabel ?countryLabel WHERE {
      SERVICE wikibase:around {
        ?item wdt:P625 ?location .
        bd:serviceParam wikibase:center "Point(${lon} ${lat})"^^geo:wktLiteral .
        bd:serviceParam wikibase:radius ${radiusMeters / 1000} .
      }
      # Filtrovat jen relevantní typy míst
      {
        ?item wdt:P31/wdt:P279* ?type .
        VALUES ?type {
          wd:Q33506  # museum
          wd:Q190598  # art gallery
          wd:Q570116  # tourist attraction
          wd:Q1075788  # viewpoint
          wd:Q22698  # park
          # ... další relevantní typy
        }
      }
      OPTIONAL { ?item wdt:P131 ?city . }
      OPTIONAL { ?item wdt:P17 ?country . }
      BIND(STRBEFORE(STR(AFTER(STR(?location),"Point("))," ") AS ?lon)
      BIND(STRAFTER(STR(AFTER(STR(?location),"Point("))," ") AS ?lat)
      SERVICE wikibase:label { bd:serviceParam wikibase:language "en,cs". }
    }
    LIMIT 100
  `;
}
```

## 📊 SHRNUTÍ

### ✅ Co funguje:
1. Free zdroje (OpenTripMap, Wikidata) jsou **primární** zdroje
2. Google je **pouze fallback**
3. Správně se filtruje rating 4.0+ v `persistIncoming`
4. Správně se cachuje a používá DB

### ⚠️ Co je potřeba opravit:
1. **OpenTripMap** - buď změnit `rate=2` na `rate=3`, nebo zlepšit mapování kategorií
2. **Wikidata** - přidat filtrování podle typu místa pro lepší relevanci

### 🎯 Priorita oprav:
1. **Vysoká**: OpenTripMap mapování kategorií (může vést k prázdným výsledkům)
2. **Střední**: OpenTripMap rating filtrování (už se filtruje v `persistIncoming`, ale lepší filtrovat už v API)
3. **Nízká**: Wikidata filtrování (funguje, ale může vracet méně relevantní výsledky)


# Simplify POI fetching: Direct Wikidata integration in WordPress

## Přehled

Tento PR zjednodušuje stahování POIs z free zdrojů (Wikidata, OpenTripMap) přímo v WordPressu, bez potřeby samostatného Node.js microservice.

## Hlavní změny

### ✅ Přidáno
- **OpenTripMap_Provider.php** - stahuje POIs přímo z OpenTripMap API (volitelné, vyžaduje API key)
- **Wikidata_Provider.php** - stahuje POIs přímo z Wikidata (vždy dostupné, nevyžaduje API key)
- **fetch_pois_from_providers()** - nahrazuje sync_pois_from_microservice(), stahuje POIs přímo z free zdrojů
- **OpenTripMap API key setting** v admin rozhraní (volitelné)

### 🔄 Změněno
- **Nearby_Recompute_Job.php** - používá fetch_pois_from_providers() místo sync_pois_from_microservice()
- **POI_Service_Admin.php** - přidáno pole pro OpenTripMap API key (volitelné)
- **Wikidata_Provider.php** - vylepšený SPARQL query pro lepší geografické vyhledávání

### 📝 Dokumentace
- **POI_FETCHING_WORKFLOW.md** - dokumentace workflow stahování POIs z Wikidata

## Jak to funguje

1. **Wikidata (vždy dostupné)**
   - Nevyžaduje API key ani registraci
   - Stahuje muzea, galerie, památky, výhledy, parky
   - Funguje automaticky při hledání nearby POIs

2. **OpenTripMap (volitelné)**
   - Vyžaduje API key (zdarma na opentripmap.io)
   - Stahuje restaurace, kavárny, bary, atd.
   - Pokud není API key, přeskočí se

3. **Automatické stahování**
   - Při kontrole kandidátů (před zařazením do fronty)
   - Při zpracování nearby recompute jobu
   - Při on-demand requestu (při kliknutí na mapě)

## Výhody

- ✅ **Jednoduché** - vše v PHP, bez potřeby Node.js microservice
- ✅ **Automatické** - POIs se stahují automaticky při potřebě
- ✅ **Free zdroje** - Wikidata vždy dostupné, OpenTripMap volitelné
- ✅ **Cache** - 1 hodina cache pro stahování POIs
- ✅ **Deduplikace** - automatická deduplikace podle GPS + jméno

## Testování

1. Nastavit OpenTripMap API key (volitelné) v `Tools > POI Microservice`
2. Zařadit existující nabíječky do fronty: `Tools > Nearby Queue > Enqueue All Points`
3. Zpracovat frontu: `Tools > Nearby Queue > Process Batch`
4. Ověřit, že se POIs stáhly z Wikidata a vytvořily WordPress posty typu 'poi'

## Breaking Changes

- ❌ Žádné - POI microservice URL je stále volitelné, pokud ho používáte
- ✅ WordPress funguje i bez OpenTripMap API key (pouze Wikidata)

## Poznámky

- POI microservice je stále podporován, ale není nutný
- Wikidata funguje vždy, bez API key
- OpenTripMap je volitelný bonus pro více POIs

## Commits

- `be8237f` - Add: Documentation for POI fetching workflow from Wikidata
- `3740733` - Fix: Make OpenTripMap optional, use Wikidata as primary source
- `8660d11` - Fix: Replace sync_pois_from_microservice with fetch_pois_from_providers
- `e8d3f47` - Simplify: Add direct POI fetching from free sources in WordPress
- `4ed49b2` - Clarify: POI microservice is optional, WordPress works without it


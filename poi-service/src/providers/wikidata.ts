import { ALLOWED_CATEGORIES } from '../categories';
import { NormalizedPoi, PoiProvider } from './types';

interface WikidataBinding {
  item: { value: string };
  itemLabel: { value: string };
  lat: { value: string };
  lon: { value: string };
  city?: { value: string };
  country?: { value: string };
}

export class WikidataProvider implements PoiProvider {
  private endpoint = 'https://query.wikidata.org/sparql';

  async searchAround(
    lat: number,
    lon: number,
    radiusMeters: number,
    categories: string[]
  ): Promise<NormalizedPoi[]> {
    const query = this.buildQuery(lat, lon, radiusMeters);
    const response = await fetch(this.endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/sparql-query',
        Accept: 'application/sparql-results+json',
        'User-Agent': 'dobity-baterky-poi-service',
      },
      body: query,
    });

    if (!response.ok) return [];
    const json = await response.json();
    const results = json?.results?.bindings as WikidataBinding[];
    return results
      .map((binding) => this.normalize(binding, categories))
      .filter((poi): poi is NormalizedPoi => !!poi);
  }

  private buildQuery(lat: number, lon: number, radiusMeters: number): string {
    return `
      SELECT ?item ?itemLabel ?lat ?lon ?cityLabel ?countryLabel WHERE {
        SERVICE wikibase:around {
          ?item wdt:P625 ?location .
          bd:serviceParam wikibase:center "Point(${lon} ${lat})"^^geo:wktLiteral .
          bd:serviceParam wikibase:radius ${radiusMeters / 1000} .
        }
        # Filtrovat jen relevantní typy míst (muzea, galerie, památky, výhledy, parky)
        {
          ?item wdt:P31/wdt:P279* ?type .
          VALUES ?type {
            wd:Q33506    # museum
            wd:Q190598   # art gallery
            wd:Q570116   # tourist attraction
            wd:Q1075788  # viewpoint
            wd:Q22698    # park
            wd:Q12280    # monument
            wd:Q11424    # film
            wd:Q47513    # castle
            wd:Q16970    # church
            wd:Q483551   # cultural heritage
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

  private normalize(binding: WikidataBinding, categories: string[]): NormalizedPoi | null {
    const category = this.pickCategory(categories);
    if (!category) return null;
    return {
      name: binding.itemLabel?.value ?? 'Unknown place',
      lat: parseFloat(binding.lat.value),
      lon: parseFloat(binding.lon.value),
      city: binding.city?.value,
      country: binding.country?.value,
      category,
      source: 'wikidata',
      sourceId: binding.item.value,
      raw: binding,
    };
  }

  private pickCategory(categories: string[]): string | null {
    const preferred = ['tourist_attraction', 'viewpoint', 'museum', 'gallery'];
    const match = preferred.find((cat) => categories.includes(cat) && ALLOWED_CATEGORIES.includes(cat));
    return match ?? categories.find((c) => ALLOWED_CATEGORIES.includes(c)) ?? null;
  }
}

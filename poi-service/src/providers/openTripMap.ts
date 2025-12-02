import { ALLOWED_CATEGORIES } from '../categories';
import { CONFIG } from '../config';
import { NormalizedPoi, PoiProvider } from './types';

interface OpenTripMapFeature {
  xid: string;
  name: string;
  point: { lat: number; lon: number };
  kinds?: string;
  rate?: number;
}

export class OpenTripMapProvider implements PoiProvider {
  private endpoint = 'https://api.opentripmap.com/0.1/en/places/radius';

  async searchAround(
    lat: number,
    lon: number,
    radiusMeters: number,
    categories: string[]
  ): Promise<NormalizedPoi[]> {
    if (!CONFIG.opentripMapApiKey) return [];
    const kinds = this.mapCategories(categories);
    // rate=3 = jen nejlepší místa (mapuje na 4.7), rate=2 = dobrá místa (mapuje na 4.2)
    // Použijeme rate=2, protože rate=3 může být příliš restriktivní
    // Filtrování na 4.0+ se provede po normalizaci v persistIncoming pomocí passesRatingFilter
    const url = `${this.endpoint}?radius=${radiusMeters}&lon=${lon}&lat=${lat}&kinds=${encodeURIComponent(
      kinds
    )}&rate=2&format=json&apikey=${CONFIG.opentripMapApiKey}`;
    const response = await fetch(url);
    if (!response.ok) {
      return [];
    }
    const data = (await response.json()) as OpenTripMapFeature[];
    return data
      .map((item) => this.normalize(item))
      .filter((poi): poi is NormalizedPoi => !!poi);
  }

  private mapCategories(categories: string[]): string {
    // Map naše kategorie na OpenTripMap kinds
    // OpenTripMap má vlastní systém kategorií, které se liší od našich
    const OTM_KINDS_MAP: Record<string, string> = {
      'restaurant': 'restaurants',
      'cafe': 'cafes',
      'bar': 'bars',
      'pub': 'pubs',
      'fast_food': 'fast_food',
      'bakery': 'bakeries',
      'park': 'parks',
      'playground': 'playgrounds',
      'garden': 'gardens_and_parks',
      'sports_centre': 'sport',
      'swimming_pool': 'swimming_pool',
      'beach': 'beaches',
      'tourist_attraction': 'interesting_places',
      'viewpoint': 'viewpoints',
      'museum': 'museums',
      'gallery': 'galleries',
      'zoo': 'zoos',
      'aquarium': 'aquariums',
      'shopping_mall': 'shops',
      'supermarket': 'shops',
      'marketplace': 'markets',
    };
    
    const mapped = categories
      .map(cat => OTM_KINDS_MAP[cat] || cat)
      .filter(Boolean)
      .filter((kind, index, arr) => arr.indexOf(kind) === index); // deduplikace
    
    return mapped.join(',');
  }

  private normalize(item: OpenTripMapFeature): NormalizedPoi | null {
    const category = this.pickCategory(item.kinds);
    if (!category) return null;
    return {
      name: item.name || 'Unknown place',
      lat: item.point.lat,
      lon: item.point.lon,
      category,
      rating: item.rate ? this.convertRating(item.rate) : undefined,
      ratingSource: item.rate ? 'opentripmap' : undefined,
      source: 'opentripmap',
      sourceId: item.xid,
      raw: item,
    };
  }

  private pickCategory(kinds?: string): string | null {
    if (!kinds) return null;
    const candidates = kinds.split(',');
    const found = candidates.find((kind) => ALLOWED_CATEGORIES.includes(kind));
    return found ?? null;
  }

  private convertRating(rate: number): number {
    // OpenTripMap popularity rate is 1-3, map to 3.5-5 scale
    // rate=3 = top places (4.7), rate=2 = good places (4.2), rate=1 = acceptable (3.8)
    // POZNÁMKA: rate=1 mapuje na 3.8, což je < 4.0, ale to se filtruje v persistIncoming
    if (rate >= 3) return 4.7;  // Top places
    if (rate >= 2) return 4.2;  // Good places (>= 4.0)
    return 3.8;  // Acceptable (bude odfiltrováno v passesRatingFilter)
  }
}

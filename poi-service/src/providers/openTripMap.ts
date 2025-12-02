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
    // Opentripmap kinds list, map to provided categories or allow fallback
    return categories.join(',');
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
    if (rate >= 3) return 4.7;
    if (rate >= 2) return 4.2;
    return 3.8;
  }
}

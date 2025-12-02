import { ALLOWED_CATEGORIES } from '../categories';
import { CONFIG } from '../config';
import { NormalizedPoi, PoiProvider } from './types';

interface GooglePlaceResult {
  place_id: string;
  name: string;
  geometry: { location: { lat: number; lng: number } };
  vicinity?: string;
  rating?: number;
  user_ratings_total?: number;
  price_level?: number;
  opening_hours?: any;
  photos?: { photo_reference: string }[];
  types?: string[];
}

export class GooglePlacesProvider implements PoiProvider {
  private endpoint = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json';

  async searchAround(
    lat: number,
    lon: number,
    radiusMeters: number,
    categories: string[]
  ): Promise<NormalizedPoi[]> {
    if (!CONFIG.GOOGLE_PLACES_ENABLED || !CONFIG.googlePlacesApiKey) return [];
    const type = this.pickType(categories);
    const url = `${this.endpoint}?location=${lat},${lon}&radius=${radiusMeters}&type=${encodeURIComponent(
      type
    )}&key=${CONFIG.googlePlacesApiKey}`;
    const response = await fetch(url);
    if (!response.ok) return [];
    const data = await response.json();
    const results = (data.results ?? []) as GooglePlaceResult[];
    return results
      .map((result) => this.normalize(result))
      .filter((poi): poi is NormalizedPoi => !!poi && this.passesRating(poi));
  }

  private pickType(categories: string[]): string {
    const preferred = categories.find((c) => ALLOWED_CATEGORIES.includes(c));
    return preferred ?? 'restaurant';
  }

  private normalize(result: GooglePlaceResult): NormalizedPoi | null {
    const category = this.normalizeCategory(result.types ?? []);
    if (!category) return null;
    return {
      name: result.name,
      lat: result.geometry.location.lat,
      lon: result.geometry.location.lng,
      address: result.vicinity,
      category,
      rating: result.rating,
      ratingSource: 'google',
      priceLevel: result.price_level,
      openingHoursRaw: result.opening_hours,
      photoUrl: this.photoUrl(result.photos?.[0]?.photo_reference),
      source: 'google',
      sourceId: result.place_id,
      raw: result,
    };
  }

  private normalizeCategory(types: string[]): string | null {
    const normalized = types.map((t) => t.replace(/\s+/g, '_').toLowerCase());
    const match = normalized.find((type) => ALLOWED_CATEGORIES.includes(type));
    return match ?? null;
  }

  private photoUrl(photoRef?: string): string | undefined {
    if (!photoRef || !CONFIG.googlePlacesApiKey) return undefined;
    return `https://maps.googleapis.com/maps/api/place/photo?maxwidth=800&photo_reference=${photoRef}&key=${CONFIG.googlePlacesApiKey}`;
  }

  private passesRating(poi: NormalizedPoi): boolean {
    if (poi.rating === undefined || poi.rating === null) return false;
    return poi.rating >= CONFIG.MIN_RATING;
  }
}

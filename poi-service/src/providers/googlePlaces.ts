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
    if (!CONFIG.PLACES_ENRICHMENT_ENABLED || !CONFIG.googlePlacesApiKey) return [];
    const type = this.pickType(categories);
    const url = `${this.endpoint}?location=${lat},${lon}&radius=${radiusMeters}&type=${encodeURIComponent(
      type
    )}&key=${CONFIG.googlePlacesApiKey}`;
    
    let response: Response;
    try {
      response = await fetch(url);
    } catch (error) {
      console.error('[GooglePlaces] Network error:', error);
      return [];
    }
    
    if (!response.ok) {
      // Error handling pro různé HTTP status kódy
      if (response.status === 429) {
        console.warn('[GooglePlaces] Rate limit exceeded (HTTP 429)');
      } else if (response.status === 403) {
        console.error('[GooglePlaces] API key invalid or quota exceeded (HTTP 403)');
      } else {
        console.error(`[GooglePlaces] API error: ${response.status} ${response.statusText}`);
      }
      return [];
    }
    
    const data = await response.json();
    
    // Kontrola Google API error status
    if (data.status === 'OVER_QUERY_LIMIT') {
      console.warn('[GooglePlaces] Over query limit');
      return [];
    }
    if (data.status === 'REQUEST_DENIED') {
      console.error('[GooglePlaces] Request denied:', data.error_message);
      return [];
    }
    if (data.status === 'INVALID_REQUEST') {
      console.error('[GooglePlaces] Invalid request:', data.error_message);
      return [];
    }
    
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

export interface NormalizedPoi {
  name: string;
  lat: number;
  lon: number;
  address?: string;
  city?: string;
  country?: string;
  category: string;
  rating?: number;
  ratingSource?: string;
  priceLevel?: number;
  website?: string;
  phone?: string;
  openingHoursRaw?: any;
  photoUrl?: string;
  photoFilename?: string;
  photoLicense?: string;
  source: string;
  sourceId?: string;
  raw?: any;
}

export interface PoiProvider {
  searchAround(
    lat: number,
    lon: number,
    radiusMeters: number,
    categories: string[]
  ): Promise<NormalizedPoi[]>;

  getDetails?(externalId: string): Promise<NormalizedPoi | null>;
}

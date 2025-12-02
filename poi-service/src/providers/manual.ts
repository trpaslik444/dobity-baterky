import { prisma } from '../prisma';
import { ALLOWED_CATEGORIES } from '../categories';
import { CONFIG } from '../config';
import { NormalizedPoi, PoiProvider } from './types';
import { haversineDistanceMeters } from '../utils/geo';

export class ManualProvider implements PoiProvider {
  async searchAround(
    lat: number,
    lon: number,
    radiusMeters: number,
    categories: string[]
  ): Promise<NormalizedPoi[]> {
    const candidates = await prisma.poi.findMany({
      where: {
        category: { in: categories.length ? categories : ALLOWED_CATEGORIES },
        rating: CONFIG.ALLOW_POIS_WITHOUT_RATING
          ? undefined
          : {
              gte: CONFIG.MIN_RATING,
            },
      },
    });

    return candidates
      .filter((poi) => haversineDistanceMeters(lat, lon, poi.lat, poi.lon) <= radiusMeters)
      .map((poi) => ({
        name: poi.name,
        lat: poi.lat,
        lon: poi.lon,
        address: poi.address ?? undefined,
        city: poi.city ?? undefined,
        country: poi.country ?? undefined,
        category: poi.category,
        rating: poi.rating ?? undefined,
        ratingSource: poi.rating_source ?? undefined,
        priceLevel: poi.price_level ?? undefined,
        website: poi.website ?? undefined,
        phone: poi.phone ?? undefined,
        openingHoursRaw: poi.opening_hours ?? undefined,
        photoUrl: poi.photo_url ?? undefined,
        source: 'manual',
        sourceId: (poi.source_ids as any)?.manual ?? undefined,
        raw: poi.raw_payload ?? undefined,
      }));
  }
}

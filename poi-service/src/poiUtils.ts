import { Poi } from '@prisma/client';
import { CONFIG, RATING_PRIORITY_ORDER } from './config';
import { haversineDistanceMeters, namesAreSimilar } from './utils/geo';
import { NormalizedPoi } from './providers/types';

export function passesRatingFilter(poi: NormalizedPoi): boolean {
  if (poi.rating === undefined || poi.rating === null) {
    return CONFIG.ALLOW_POIS_WITHOUT_RATING;
  }
  return poi.rating >= CONFIG.MIN_RATING;
}

export function isDuplicatePoi(existing: Poi, incoming: NormalizedPoi): boolean {
  const distance = haversineDistanceMeters(existing.lat, existing.lon, incoming.lat, incoming.lon);
  return distance < 50 && namesAreSimilar(existing.name, incoming.name);
}

function infoScore(poi: Partial<Poi> | NormalizedPoi): number {
  let score = 0;
  const candidate: Record<string, any> = poi as any;
  [
    'address',
    'website',
    'phone',
    'photo_url',
    'photoUrl',
    'photoFilename',
    'rating',
    'opening_hours',
    'openingHoursRaw',
  ].forEach((key) => {
    if (candidate[key] !== undefined && candidate[key] !== null) {
      score += 1;
    }
  });
  return score;
}

function compareRatingPriority(existingSource?: string | null, incomingSource?: string): boolean {
  if (!incomingSource) return false;
  if (!existingSource) return true;
  const existingIndex = RATING_PRIORITY_ORDER.indexOf(existingSource);
  const incomingIndex = RATING_PRIORITY_ORDER.indexOf(incomingSource);
  if (existingIndex === -1 && incomingIndex >= 0) return true;
  if (incomingIndex === -1) return false;
  return incomingIndex < existingIndex;
}

export function mergePois(existing: Poi, incoming: NormalizedPoi): Poi {
  const updated: Poi = { ...existing };
  const sourceIds: Record<string, string> = { ...(existing.source_ids as any) };
  if (incoming.sourceId) {
    sourceIds[incoming.source] = incoming.sourceId;
  }
  updated.source_ids = Object.keys(sourceIds).length ? sourceIds : null;

  const incomingScore = infoScore(incoming);
  const existingScore = infoScore(existing);
  const preferIncoming = incomingScore > existingScore;

  const maybeSet = <K extends keyof Poi>(key: K, value: Poi[K] | undefined | null) => {
    if (value === undefined || value === null) return;
    if (!updated[key] || preferIncoming) {
      updated[key] = value as Poi[K];
    }
  };

  maybeSet('address', incoming.address as any);
  maybeSet('city', incoming.city as any);
  maybeSet('country', incoming.country as any);
  maybeSet('website', incoming.website as any);
  maybeSet('phone', incoming.phone as any);
  maybeSet('photo_url', incoming.photoUrl as any);
  maybeSet('photo_filename', incoming.photoFilename as any);
  maybeSet('photo_license', incoming.photoLicense as any);
  maybeSet('price_level', incoming.priceLevel as any);
  maybeSet('opening_hours', incoming.openingHoursRaw as any);
  maybeSet('raw_payload', incoming.raw as any);

  if (compareRatingPriority(existing.rating_source, incoming.ratingSource)) {
    if (incoming.rating !== undefined) {
      updated.rating = incoming.rating as any;
      updated.rating_source = incoming.ratingSource as any;
    }
  }

  return updated;
}

export function normalizedToPoiData(poi: NormalizedPoi) {
  return {
    name: poi.name,
    lat: poi.lat,
    lon: poi.lon,
    address: poi.address ?? null,
    city: poi.city ?? null,
    country: poi.country ?? null,
    category: poi.category,
    rating: poi.rating ?? null,
    rating_source: poi.ratingSource ?? null,
    price_level: poi.priceLevel ?? null,
    website: poi.website ?? null,
    phone: poi.phone ?? null,
    opening_hours: poi.openingHoursRaw ?? null,
    photo_url: poi.photoUrl ?? null,
    photo_filename: poi.photoFilename ?? null,
    photo_license: poi.photoLicense ?? null,
    source_ids: poi.sourceId ? { [poi.source]: poi.sourceId } : {},
    raw_payload: poi.raw ?? null,
  };
}

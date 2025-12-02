import { Poi } from '@prisma/client';
import { ALLOWED_CATEGORIES } from './categories';
import { CONFIG } from './config';
import { prisma } from './prisma';
import { GooglePlacesProvider } from './providers/googlePlaces';
import { ManualProvider } from './providers/manual';
import { OpenTripMapProvider } from './providers/openTripMap';
import { WikidataProvider } from './providers/wikidata';
import { NormalizedPoi } from './providers/types';
import {
  isDuplicatePoi,
  mergePois,
  normalizedToPoiData,
  passesRatingFilter,
} from './poiUtils';
import { haversineDistanceMeters } from './utils/geo';
import { reserveGoogleQuota } from './quota';

const CACHE_EPSILON = 0.0001;

export interface NearbyResult {
  pois: Poi[];
  providersUsed: string[];
}

export async function getNearbyPois(
  lat: number,
  lon: number,
  radiusMeters: number,
  minCount = 10,
  options?: { refresh?: boolean }
): Promise<NearbyResult> {
  const providersUsed: string[] = [];
  const normalizedCategories = ALLOWED_CATEGORIES;

  if (!options?.refresh) {
    const cached = await loadFromCache(lat, lon, radiusMeters, minCount);
    if (cached) {
      return { pois: cached, providersUsed: ['cache'] };
    }
  }

  const dbPois = await findNearbyFromDb(lat, lon, radiusMeters, minCount);
  providersUsed.push('db');
  if (dbPois.length >= minCount) {
    await saveCache(lat, lon, radiusMeters, dbPois, providersUsed);
    return { pois: dbPois, providersUsed };
  }

  const manualProvider = new ManualProvider();
  const manual = await manualProvider.searchAround(lat, lon, radiusMeters, normalizedCategories);
  let merged = await persistIncoming(manual, lat, lon, radiusMeters);
  providersUsed.push('manual');

  if (merged.length < minCount) {
    const otmProvider = new OpenTripMapProvider();
    const wikidataProvider = new WikidataProvider();
    const otm = await otmProvider.searchAround(lat, lon, radiusMeters, normalizedCategories);
    const wiki = await wikidataProvider.searchAround(lat, lon, radiusMeters, normalizedCategories);
    merged = await persistIncoming([...mergedToNormalized(merged), ...otm, ...wiki], lat, lon, radiusMeters);
    providersUsed.push('opentripmap', 'wikidata');
  }

  const googleThreshold = Math.max(minCount, CONFIG.MIN_POIS_BEFORE_GOOGLE);
  if (merged.length < googleThreshold && CONFIG.PLACES_ENRICHMENT_ENABLED) {
    // Atomicky rezervovat kvótu PŘED voláním API (prevence race condition)
    const quotaReserved = await reserveGoogleQuota();
    if (quotaReserved) {
      const googleProvider = new GooglePlacesProvider();
      const google = await googleProvider.searchAround(lat, lon, radiusMeters, normalizedCategories);
      if (google.length) {
        merged = await persistIncoming([...mergedToNormalized(merged), ...google], lat, lon, radiusMeters);
        providersUsed.push('google');
      }
      // Poznámka: Kvóta už byla rezervována v reserveGoogleQuota()
    }
  }

  await saveCache(lat, lon, radiusMeters, merged, providersUsed);
  return { pois: merged, providersUsed };
}

async function loadFromCache(
  lat: number,
  lon: number,
  radiusMeters: number,
  minCount: number
): Promise<Poi[] | null> {
  const cutoff = new Date(Date.now() - CONFIG.CACHE_TTL_DAYS * 24 * 60 * 60 * 1000);
  const caches = await prisma.poiCache.findMany({
    where: { radius_m: radiusMeters, created_at: { gt: cutoff } },
  });
  const match = caches.find(
    (cache) =>
      Math.abs(cache.lat - lat) < CACHE_EPSILON && Math.abs(cache.lon - lon) < CACHE_EPSILON
  );
  if (!match) return null;
  const poiIds = (match.poi_ids as string[]) ?? [];
  if (poiIds.length < minCount) return null;
  return prisma.poi.findMany({ where: { id: { in: poiIds } } });
}

async function findNearbyFromDb(
  lat: number,
  lon: number,
  radiusMeters: number,
  minCount: number
): Promise<Poi[]> {
  const candidates = await prisma.poi.findMany({
    where: {
      category: { in: ALLOWED_CATEGORIES },
      rating: CONFIG.ALLOW_POIS_WITHOUT_RATING
        ? undefined
        : {
            gte: CONFIG.MIN_RATING,
          },
    },
  });
  const withinRadius = candidates.filter(
    (poi) => haversineDistanceMeters(lat, lon, poi.lat, poi.lon) <= radiusMeters
  );
  return withinRadius.slice(0, Math.max(minCount, withinRadius.length));
}

async function persistIncoming(
  incoming: NormalizedPoi[],
  lat: number,
  lon: number,
  radiusMeters: number
): Promise<Poi[]> {
  const allowed = incoming.filter((poi) => ALLOWED_CATEGORIES.includes(poi.category));
  const filtered = allowed.filter(passesRatingFilter);

  const existing = await prisma.poi.findMany({});
  const result: Poi[] = [];

  for (const poi of filtered) {
    const duplicate = existing.find((item) => isDuplicatePoi(item, poi));
    if (duplicate) {
      const merged = mergePois(duplicate, poi);
      const updated = await prisma.poi.update({ where: { id: duplicate.id }, data: merged });
      updateArray(existing, updated);
      result.push(updated);
    } else {
      const created = await prisma.poi.create({ data: normalizedToPoiData(poi) });
      existing.push(created);
      result.push(created);
    }
  }

  const nearby = result.filter(
    (poi) => haversineDistanceMeters(lat, lon, poi.lat, poi.lon) <= radiusMeters
  );
  return nearby;
}

function updateArray(list: Poi[], updated: Poi) {
  const index = list.findIndex((item) => item.id === updated.id);
  if (index >= 0) list[index] = updated;
}

function mergedToNormalized(pois: Poi[]): NormalizedPoi[] {
  return pois.map((poi) => ({
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
    sourceId: (poi.source_ids as any)?.manual,
    raw: poi.raw_payload ?? undefined,
  }));
}

async function saveCache(
  lat: number,
  lon: number,
  radiusMeters: number,
  pois: Poi[],
  providersUsed: string[]
) {
  const cached = await prisma.poiCache.findFirst({ where: { radius_m: radiusMeters } });
  const payload = {
    lat,
    lon,
    radius_m: radiusMeters,
    poi_ids: pois.map((poi) => poi.id),
    providers_used: providersUsed,
  } as const;

  if (cached && Math.abs(cached.lat - lat) < CACHE_EPSILON && Math.abs(cached.lon - lon) < CACHE_EPSILON) {
    await prisma.poiCache.update({ where: { id: cached.id }, data: payload });
  } else {
    await prisma.poiCache.create({ data: payload });
  }
}


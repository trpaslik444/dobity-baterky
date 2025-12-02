/**
 * Periodická aktualizace POIs
 * 
 * Jednou za 30 dní zjišťuje nová místa, která nejsou v databázi
 */

import { prisma } from '../prisma';
import { getNearbyPois } from '../aggregator';
import { CONFIG } from '../config';
// WordPress sync je nyní přímý přístup k MySQL, není potřeba importovat

interface PeriodicUpdateConfig {
  // Seznam oblastí k prohledání (lat, lon, radius)
  areas: Array<{ lat: number; lon: number; radiusMeters: number }>;
  // Interval aktualizace v dnech
  updateIntervalDays: number;
}

/**
 * Zkontroluje, zda je potřeba aktualizace pro danou oblast
 */
async function needsUpdate(lat: number, lon: number, radiusMeters: number, intervalDays: number): Promise<boolean> {
  const cutoff = new Date(Date.now() - intervalDays * 24 * 60 * 60 * 1000);
  
  // Zkontrolovat cache
  const cache = await prisma.poiCache.findFirst({
    where: {
      lat: { gte: lat - 0.001, lte: lat + 0.001 },
      lon: { gte: lon - 0.001, lte: lon + 0.001 },
      radius_m: radiusMeters,
      updated_at: { gte: cutoff },
    },
  });

  return !cache; // Potřebujeme aktualizaci, pokud není fresh cache
}

/**
 * Provede periodickou aktualizaci pro jednu oblast
 */
async function updateArea(
  lat: number,
  lon: number,
  radiusMeters: number
): Promise<{ found: number; synced: number; errors: number }> {
  console.log(`[Periodic Update] Updating area: ${lat}, ${lon}, radius: ${radiusMeters}m`);

  try {
    // Získat POIs s refresh=true (ignorovat cache)
    const result = await getNearbyPois(lat, lon, radiusMeters, 10, { refresh: true });
    
    let synced = 0;
    let errors = 0;

    // WordPress sám volá POI microservice API a vytváří posty
    // POI microservice nemusí mít přístup k WordPress databázi
    // Synchronizace se provede automaticky při volání get_candidates() v WordPressu
    synced = result.pois.length; // POIs jsou v PostgreSQL, WordPress je stáhne přes API
    errors = 0;

    return {
      found: result.pois.length,
      synced,
      errors,
    };
  } catch (error) {
    console.error(`[Periodic Update] Error updating area ${lat}, ${lon}:`, error);
    return { found: 0, synced: 0, errors: 1 };
  }
}

/**
 * Provede periodickou aktualizaci pro všechny oblasti
 */
export async function runPeriodicUpdate(config: PeriodicUpdateConfig): Promise<{
  areasUpdated: number;
  totalFound: number;
  totalSynced: number;
  totalErrors: number;
}> {
  console.log(`[Periodic Update] Starting periodic update for ${config.areas.length} areas`);

  let areasUpdated = 0;
  let totalFound = 0;
  let totalSynced = 0;
  let totalErrors = 0;

  for (const area of config.areas) {
    const needs = await needsUpdate(
      area.lat,
      area.lon,
      area.radiusMeters,
      config.updateIntervalDays
    );

    if (!needs) {
      console.log(`[Periodic Update] Area ${area.lat}, ${area.lon} is up to date, skipping`);
      continue;
    }

    const result = await updateArea(area.lat, area.lon, area.radiusMeters);
    
    areasUpdated++;
    totalFound += result.found;
    totalSynced += result.synced;
    totalErrors += result.errors;

    // Rate limiting mezi oblastmi
    await new Promise(resolve => setTimeout(resolve, 2000)); // 2 sekundy mezi oblastmi
  }

  console.log(`[Periodic Update] Completed: ${areasUpdated} areas updated, ${totalFound} POIs found, ${totalSynced} synced, ${totalErrors} errors`);

  return {
    areasUpdated,
    totalFound,
    totalSynced,
    totalErrors,
  };
}

/**
 * Získá seznam oblastí z existujících POIs v databázi
 * Vytvoří grid oblastí pokrývající všechny POIs
 */
export async function getAreasFromExistingPois(gridSizeKm: number = 50): Promise<Array<{
  lat: number;
  lon: number;
  radiusMeters: number;
}>> {
  // Získat bounding box všech POIs
  const bounds = await prisma.$queryRaw<Array<{
    min_lat: number;
    max_lat: number;
    min_lon: number;
    max_lon: number;
  }>>`
    SELECT 
      MIN(lat) as min_lat,
      MAX(lat) as max_lat,
      MIN(lon) as min_lon,
      MAX(lon) as max_lon
    FROM "Poi"
  `;

  if (!bounds || bounds.length === 0 || !bounds[0].min_lat) {
    return [];
  }

  const { min_lat, max_lat, min_lon, max_lon } = bounds[0];
  const areas: Array<{ lat: number; lon: number; radiusMeters: number }> = [];

  // Vytvořit grid oblastí
  const latStep = gridSizeKm / 111.0; // Přibližně 1 stupeň = 111 km
  const lonStep = gridSizeKm / (111.0 * Math.cos((min_lat + max_lat) / 2 * Math.PI / 180));
  const radiusMeters = (gridSizeKm / 2) * 1000; // Polovina gridu jako radius

  for (let lat = min_lat; lat <= max_lat; lat += latStep) {
    for (let lon = min_lon; lon <= max_lon; lon += lonStep) {
      areas.push({
        lat: lat + latStep / 2, // Střed gridu
        lon: lon + lonStep / 2,
        radiusMeters,
      });
    }
  }

  return areas;
}


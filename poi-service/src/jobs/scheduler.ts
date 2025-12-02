/**
 * Scheduler pro periodické úlohy
 * 
 * Spouští periodickou aktualizaci POIs jednou za 30 dní
 */

import { runPeriodicUpdate, getAreasFromExistingPois } from './periodicUpdate';
import { CONFIG } from '../config';

const UPDATE_INTERVAL_DAYS = 30;

interface SchedulerConfig {
  // Vlastní seznam oblastí (pokud není zadán, použije se getAreasFromExistingPois)
  customAreas?: Array<{ lat: number; lon: number; radiusMeters: number }>;
  // Velikost gridu v km (použije se pokud není zadán customAreas)
  gridSizeKm?: number;
}

/**
 * Spustí periodickou aktualizaci
 */
export async function schedulePeriodicUpdate(config: SchedulerConfig = {}): Promise<void> {
  console.log(`[Scheduler] Starting periodic update (interval: ${UPDATE_INTERVAL_DAYS} days)`);

  let areas: Array<{ lat: number; lon: number; radiusMeters: number }>;

  if (config.customAreas && config.customAreas.length > 0) {
    areas = config.customAreas;
    console.log(`[Scheduler] Using ${areas.length} custom areas`);
  } else {
    const gridSizeKm = config.gridSizeKm || 50;
    areas = await getAreasFromExistingPois(gridSizeKm);
    console.log(`[Scheduler] Generated ${areas.length} areas from existing POIs (grid: ${gridSizeKm}km)`);
  }

  if (areas.length === 0) {
    console.log('[Scheduler] No areas to update');
    return;
  }

  const result = await runPeriodicUpdate({
    areas,
    updateIntervalDays: UPDATE_INTERVAL_DAYS,
  });

  console.log(`[Scheduler] Periodic update completed:`, result);
}

/**
 * CLI příkaz pro manuální spuštění
 */
// Spustit pokud je soubor volán přímo
if (process.argv[1]?.endsWith('scheduler.ts') || process.argv[1]?.endsWith('scheduler.js')) {
  schedulePeriodicUpdate({})
    .then(() => {
      console.log('[Scheduler] Done');
      process.exit(0);
    })
    .catch((error) => {
      console.error('[Scheduler] Error:', error);
      process.exit(1);
    });
}


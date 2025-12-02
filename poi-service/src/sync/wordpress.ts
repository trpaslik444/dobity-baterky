/**
 * WordPress synchronizace modul
 * 
 * Synchronizuje POIs z PostgreSQL do WordPress MySQL databáze
 * Vytváří WordPress post type 'poi' pro každý POI z microservice
 */

import { Poi } from '@prisma/client';
import { CONFIG } from '../config';

interface WordPressConfig {
  restUrl: string;
  restNonce?: string;
  username?: string;
  password?: string;
}

/**
 * Vytvoří WordPress post pro POI
 */
export async function syncPoiToWordPress(poi: Poi, config: WordPressConfig): Promise<number | null> {
  try {
    const url = `${config.restUrl}/wp-json/db/v1/poi-sync`;
    
    const body = {
      name: poi.name,
      lat: poi.lat,
      lon: poi.lon,
      address: poi.address,
      city: poi.city,
      country: poi.country,
      category: poi.category,
      rating: poi.rating,
      rating_source: poi.rating_source,
      price_level: poi.price_level,
      website: poi.website,
      phone: poi.phone,
      opening_hours: poi.opening_hours,
      photo_url: poi.photo_url,
      photo_filename: poi.photo_filename,
      photo_license: poi.photo_license,
      source_ids: poi.source_ids,
      external_id: poi.id, // ID z PostgreSQL pro deduplikaci
    };

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
    };

    if (config.restNonce) {
      headers['X-WP-Nonce'] = config.restNonce;
    } else if (config.username && config.password) {
      // Basic auth fallback
      const auth = Buffer.from(`${config.username}:${config.password}`).toString('base64');
      headers['Authorization'] = `Basic ${auth}`;
    } else {
      // Zkusit API key z environment
      const apiKey = process.env.WORDPRESS_API_KEY;
      if (apiKey) {
        headers['X-API-Key'] = apiKey;
      }
    }
    
    // Pokud není žádná autentizace, použít API key z CONFIG
    if (!headers['X-WP-Nonce'] && !headers['Authorization'] && !headers['X-API-Key']) {
      const apiKey = CONFIG.wordpressApiKey;
      if (apiKey) {
        headers['X-API-Key'] = apiKey;
      }
    }

    const response = await fetch(url, {
      method: 'POST',
      headers,
      body: JSON.stringify(body),
    });

    if (!response.ok) {
      const errorText = await response.text();
      console.error(`[WordPress Sync] Failed to sync POI ${poi.id}: ${response.status} ${errorText}`);
      return null;
    }

    const data = await response.json();
    return data.post_id || null;
  } catch (error) {
    console.error(`[WordPress Sync] Error syncing POI ${poi.id}:`, error);
    return null;
  }
}

/**
 * Synchronizuje více POIs do WordPressu
 */
export async function syncPoisToWordPress(
  pois: Poi[],
  config: WordPressConfig,
  batchSize: number = 10
): Promise<{ synced: number; failed: number }> {
  let synced = 0;
  let failed = 0;

  for (let i = 0; i < pois.length; i += batchSize) {
    const batch = pois.slice(i, i + batchSize);
    const results = await Promise.allSettled(
      batch.map(poi => syncPoiToWordPress(poi, config))
    );

    results.forEach((result, index) => {
      if (result.status === 'fulfilled' && result.value !== null) {
        synced++;
      } else {
        failed++;
        console.error(`[WordPress Sync] Failed to sync POI ${batch[index].id}`);
      }
    });

    // Rate limiting - počkat mezi batchi
    if (i + batchSize < pois.length) {
      await new Promise(resolve => setTimeout(resolve, 1000)); // 1 sekunda mezi batchi
    }
  }

  return { synced, failed };
}


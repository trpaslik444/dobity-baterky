/**
 * WordPress přímá synchronizace modul
 * 
 * Synchronizuje POIs z PostgreSQL přímo do WordPress MySQL databáze
 * Vytváří WordPress post type 'poi' přímo pomocí SQL dotazů
 */

import mysql from 'mysql2/promise';
import { Poi } from '@prisma/client';
import { CONFIG } from '../config';

/**
 * Získat připojení k WordPress MySQL databázi
 */
async function getWordPressConnection() {
  if (!CONFIG.wordpressDbHost || !CONFIG.wordpressDbName || !CONFIG.wordpressDbUser) {
    throw new Error('WordPress database configuration missing');
  }

  return mysql.createConnection({
    host: CONFIG.wordpressDbHost,
    database: CONFIG.wordpressDbName,
    user: CONFIG.wordpressDbUser,
    password: CONFIG.wordpressDbPassword || '',
  });
}

/**
 * Najít existující POI podle external_id nebo GPS + jméno
 */
async function findExistingPoi(
  connection: mysql.Connection,
  externalId: string,
  lat: number,
  lon: number,
  name: string
): Promise<number | null> {
  const prefix = CONFIG.wordpressDbPrefix || 'wp_';
  const postsTable = `${prefix}posts`;
  const postmetaTable = `${prefix}postmeta`;

  // Nejdříve zkusit podle external_id
  if (externalId) {
    const [rows] = await connection.execute<mysql.RowDataPacket[]>(
      `SELECT post_id FROM ${postmetaTable} 
       WHERE meta_key = '_poi_external_id' AND meta_value = ? 
       LIMIT 1`,
      [externalId]
    );
    if (rows.length > 0) {
      return rows[0].post_id;
    }
  }

  // Pokud ne, zkusit podle GPS + jméno (deduplikace)
  const [rows] = await connection.execute<mysql.RowDataPacket[]>(
    `SELECT p.ID, 
            pm_lat.meta_value+0 AS lat,
            pm_lng.meta_value+0 AS lon,
            p.post_title
     FROM ${postsTable} p
     INNER JOIN ${postmetaTable} pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = '_poi_lat'
     INNER JOIN ${postmetaTable} pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = '_poi_lng'
     WHERE p.post_type = 'poi' 
     AND p.post_status = 'publish'
     AND (
         6371 * ACOS(
             COS(RADIANS(?)) * COS(RADIANS(pm_lat.meta_value+0)) *
             COS(RADIANS(pm_lng.meta_value+0) - RADIANS(?)) +
             SIN(RADIANS(?)) * SIN(RADIANS(pm_lat.meta_value+0))
         )
     ) <= 0.05
     LIMIT 10`,
    [lat, lon, lat]
  );

  // Zkontrolovat podobnost jména
  const normalizedName = name.toLowerCase().trim();
  for (const row of rows) {
    const distance = haversineKm(lat, lon, row.lat, row.lon);
    if (distance <= 0.05) { // 50 metrů
      const rowName = (row.post_title || '').toLowerCase().trim();
      const similarity = nameSimilarity(normalizedName, rowName);
      if (similarity > 0.8) { // 80% podobnost
        return row.ID;
      }
    }
  }

  return null;
}

/**
 * Vytvořit WordPress post pro POI
 */
async function createWordPressPost(
  connection: mysql.Connection,
  poi: Poi
): Promise<number> {
  const prefix = CONFIG.wordpressDbPrefix || 'wp_';
  const postsTable = `${prefix}posts`;
  const postmetaTable = `${prefix}postmeta`;
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');

  // Vytvořit post
  const [result] = await connection.execute<mysql.ResultSetHeader>(
    `INSERT INTO ${postsTable} 
     (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, 
      post_status, comment_status, ping_status, post_password, post_name, to_ping, 
      pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, 
      guid, menu_order, post_type, post_mime_type, comment_count)
     VALUES (1, ?, ?, '', ?, '', 'publish', 'closed', 'closed', '', ?, '', '', ?, ?, '', 0, '', 0, 'poi', '', 0)`,
    [now, now, poi.name, sanitizeSlug(poi.name), now, now]
  );

  const postId = result.insertId;

  // Vytvořit meta data
  const metaData = [
    ['_poi_lat', poi.lat],
    ['_poi_lng', poi.lon],
    ['_poi_external_id', poi.id], // ID z PostgreSQL
    ['_poi_source_ids', poi.source_ids ? JSON.stringify(poi.source_ids) : null],
  ];

  if (poi.address) metaData.push(['_poi_address', poi.address]);
  if (poi.city) metaData.push(['_poi_city', poi.city]);
  if (poi.country) metaData.push(['_poi_country', poi.country]);
  if (poi.rating !== null) metaData.push(['_poi_rating', poi.rating]);
  if (poi.rating_source) metaData.push(['_poi_rating_source', poi.rating_source]);
  if (poi.price_level !== null) metaData.push(['_poi_price_level', poi.price_level]);
  if (poi.website) metaData.push(['_poi_website', poi.website]);
  if (poi.phone) metaData.push(['_poi_phone', poi.phone]);
  if (poi.opening_hours) metaData.push(['_poi_opening_hours', JSON.stringify(poi.opening_hours)]);
  if (poi.photo_url) metaData.push(['_poi_photo_url', poi.photo_url]);
  if (poi.photo_license) metaData.push(['_poi_photo_license', poi.photo_license]);
  if (poi.raw_payload) metaData.push(['_poi_raw_payload', JSON.stringify(poi.raw_payload)]);

  // Vložit meta data
  for (const [key, value] of metaData) {
    if (value !== null && value !== undefined) {
      await connection.execute(
        `INSERT INTO ${postmetaTable} (post_id, meta_key, meta_value) VALUES (?, ?, ?)`,
        [postId, key, String(value)]
      );
    }
  }

  // Nastavit POI type taxonomy (category)
  if (poi.category) {
    // Zkontrolovat, zda term existuje
    const termTable = `${prefix}terms`;
    const termTaxonomyTable = `${prefix}term_taxonomy`;
    const termRelationshipTable = `${prefix}term_relationships`;

    const [terms] = await connection.execute<mysql.RowDataPacket[]>(
      `SELECT term_id FROM ${termTable} WHERE slug = ? LIMIT 1`,
      [poi.category]
    );

    let termId: number;
    if (terms.length > 0) {
      termId = terms[0].term_id;
    } else {
      // Vytvořit nový term
      const [termResult] = await connection.execute<mysql.ResultSetHeader>(
        `INSERT INTO ${termTable} (name, slug) VALUES (?, ?)`,
        [poi.category, poi.category]
      );
      termId = termResult.insertId;

      // Vytvořit term taxonomy
      await connection.execute(
        `INSERT INTO ${termTaxonomyTable} (term_id, taxonomy, description, parent, count) 
         VALUES (?, 'poi_type', '', 0, 0)`,
        [termId]
      );
    }

    // Propojit post s termem
    await connection.execute(
      `INSERT INTO ${termRelationshipTable} (object_id, term_taxonomy_id, term_order) 
       VALUES (?, ?, 0)
       ON DUPLICATE KEY UPDATE term_order = 0`,
      [postId, termId]
    );
  }

  return postId;
}

/**
 * Aktualizovat existující WordPress post
 */
async function updateWordPressPost(
  connection: mysql.Connection,
  postId: number,
  poi: Poi
): Promise<void> {
  const prefix = CONFIG.wordpressDbPrefix || 'wp_';
  const postsTable = `${prefix}posts`;
  const postmetaTable = `${prefix}postmeta`;
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');

  // Aktualizovat post title
  await connection.execute(
    `UPDATE ${postsTable} SET post_title = ?, post_modified = ?, post_modified_gmt = ? WHERE ID = ?`,
    [poi.name, now, now, postId]
  );

  // Aktualizovat meta data
  const metaData: Array<[string, any]> = [
    ['_poi_lat', poi.lat],
    ['_poi_lng', poi.lon],
    ['_poi_external_id', poi.id],
    ['_poi_source_ids', poi.source_ids ? JSON.stringify(poi.source_ids) : null],
  ];

  if (poi.address) metaData.push(['_poi_address', poi.address]);
  if (poi.city) metaData.push(['_poi_city', poi.city]);
  if (poi.country) metaData.push(['_poi_country', poi.country]);
  if (poi.rating !== null) metaData.push(['_poi_rating', poi.rating]);
  if (poi.rating_source) metaData.push(['_poi_rating_source', poi.rating_source]);
  if (poi.price_level !== null) metaData.push(['_poi_price_level', poi.price_level]);
  if (poi.website) metaData.push(['_poi_website', poi.website]);
  if (poi.phone) metaData.push(['_poi_phone', poi.phone]);
  if (poi.opening_hours) metaData.push(['_poi_opening_hours', JSON.stringify(poi.opening_hours)]);
  if (poi.photo_url) metaData.push(['_poi_photo_url', poi.photo_url]);
  if (poi.photo_license) metaData.push(['_poi_photo_license', poi.photo_license]);
  if (poi.raw_payload) metaData.push(['_poi_raw_payload', JSON.stringify(poi.raw_payload)]);

  // Aktualizovat nebo vytvořit meta data
  for (const [key, value] of metaData) {
    if (value !== null && value !== undefined) {
      await connection.execute(
        `INSERT INTO ${postmetaTable} (post_id, meta_key, meta_value) 
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE meta_value = ?`,
        [postId, key, String(value), String(value)]
      );
    }
  }
}

/**
 * Synchronizovat POI do WordPressu
 */
export async function syncPoiToWordPress(poi: Poi): Promise<number | null> {
  if (!CONFIG.wordpressDbHost || !CONFIG.wordpressDbName) {
    console.warn('[WordPress Sync] WordPress database not configured, skipping sync');
    return null;
  }

  let connection: mysql.Connection | null = null;
  try {
    connection = await getWordPressConnection();

    // Najít existující POI
    const existingId = await findExistingPoi(connection, poi.id, poi.lat, poi.lon, poi.name);

    if (existingId) {
      // Aktualizovat existující
      await updateWordPressPost(connection, existingId, poi);
      return existingId;
    } else {
      // Vytvořit nový
      const postId = await createWordPressPost(connection, poi);
      return postId;
    }
  } catch (error) {
    console.error(`[WordPress Sync] Error syncing POI ${poi.id}:`, error);
    return null;
  } finally {
    if (connection) {
      await connection.end();
    }
  }
}

/**
 * Synchronizovat více POIs do WordPressu
 */
export async function syncPoisToWordPress(
  pois: Poi[],
  batchSize: number = 10
): Promise<{ synced: number; failed: number }> {
  if (!CONFIG.wordpressDbHost || !CONFIG.wordpressDbName) {
    console.warn('[WordPress Sync] WordPress database not configured, skipping sync');
    return { synced: 0, failed: pois.length };
  }

  let synced = 0;
  let failed = 0;

  // Použít jedno připojení pro všechny POIs
  let connection: mysql.Connection | null = null;
  try {
    connection = await getWordPressConnection();

    for (let i = 0; i < pois.length; i += batchSize) {
      const batch = pois.slice(i, i + batchSize);
      
      for (const poi of batch) {
        try {
          // Najít existující POI
          const existingId = await findExistingPoi(connection, poi.id, poi.lat, poi.lon, poi.name);

          if (existingId) {
            // Aktualizovat existující
            await updateWordPressPost(connection, existingId, poi);
            synced++;
          } else {
            // Vytvořit nový
            await createWordPressPost(connection, poi);
            synced++;
          }
        } catch (error) {
          console.error(`[WordPress Sync] Error syncing POI ${poi.id}:`, error);
          failed++;
        }
      }

      // Rate limiting - počkat mezi batchi
      if (i + batchSize < pois.length) {
        await new Promise(resolve => setTimeout(resolve, 500)); // 0.5 sekundy mezi batchi
      }
    }
  } catch (error) {
    console.error('[WordPress Sync] Connection error:', error);
    failed += pois.length - synced;
  } finally {
    if (connection) {
      await connection.end();
    }
  }

  return { synced, failed };
}

/**
 * Haversine vzdálenost v km
 */
function haversineKm(lat1: number, lon1: number, lat2: number, lon2: number): number {
  const earthKm = 6371.0;
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return earthKm * c;
}

/**
 * Podobnost jmen (jednoduchá Levenshtein podobnost)
 */
function nameSimilarity(name1: string, name2: string): number {
  // Odstranit diakritiku a normalizovat
  const normalize = (str: string) => str
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

  const n1 = normalize(name1);
  const n2 = normalize(name2);

  if (n1 === n2) return 1.0;
  if (n1.includes(n2) || n2.includes(n1)) return 0.9;

  // Levenshtein distance
  const len1 = n1.length;
  const len2 = n2.length;
  const maxLen = Math.max(len1, len2);

  if (maxLen === 0) return 1.0;

  // Jednoduchá Levenshtein implementace
  const matrix: number[][] = [];
  for (let i = 0; i <= len1; i++) {
    matrix[i] = [i];
  }
  for (let j = 0; j <= len2; j++) {
    matrix[0][j] = j;
  }

  for (let i = 1; i <= len1; i++) {
    for (let j = 1; j <= len2; j++) {
      const cost = n1[i - 1] === n2[j - 1] ? 0 : 1;
      matrix[i][j] = Math.min(
        matrix[i - 1][j] + 1,
        matrix[i][j - 1] + 1,
        matrix[i - 1][j - 1] + cost
      );
    }
  }

  const distance = matrix[len1][len2];
  return 1.0 - (distance / maxLen);
}

/**
 * Vytvořit slug z názvu
 */
function sanitizeSlug(name: string): string {
  return name
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .substring(0, 200);
}


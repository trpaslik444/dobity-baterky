import 'dotenv/config';
import { z } from 'zod';

const configSchema = z.object({
  DATABASE_URL: z.string().default('postgresql://user:pass@localhost:5432/pois'),
  OPENTRIPMAP_API_KEY: z.string().optional(),
  GOOGLE_PLACES_API_KEY: z.string().optional(),
  MIN_RATING: z.coerce.number().default(4.0),
  ALLOW_POIS_WITHOUT_RATING: z.coerce.boolean().default(false),
  CACHE_TTL_DAYS: z.coerce.number().default(30),
  MIN_POIS_BEFORE_GOOGLE: z.coerce.number().default(6),
  PLACES_ENRICHMENT_ENABLED: z.coerce.boolean().default(true),
  MAX_PLACES_REQUESTS_PER_DAY: z.coerce.number().default(300),
  // WordPress MySQL pro synchronizaci kvót
  WORDPRESS_DB_HOST: z.string().optional(),
  WORDPRESS_DB_NAME: z.string().optional(),
  WORDPRESS_DB_USER: z.string().optional(),
  WORDPRESS_DB_PASSWORD: z.string().optional(),
  WORDPRESS_DB_PREFIX: z.string().default('wp_'),
  // WordPress REST API pro synchronizaci POIs
  WORDPRESS_REST_URL: z.string().optional(),
  WORDPRESS_REST_NONCE: z.string().optional(),
  WORDPRESS_USERNAME: z.string().optional(),
  WORDPRESS_PASSWORD: z.string().optional(),
  WORDPRESS_API_KEY: z.string().optional(),
});

const parsed = configSchema.parse(process.env);

export const CONFIG = {
  databaseUrl: parsed.DATABASE_URL,
  opentripMapApiKey: parsed.OPENTRIPMAP_API_KEY,
  googlePlacesApiKey: parsed.GOOGLE_PLACES_API_KEY,
  MIN_RATING: parsed.MIN_RATING,
  ALLOW_POIS_WITHOUT_RATING: parsed.ALLOW_POIS_WITHOUT_RATING,
  CACHE_TTL_DAYS: parsed.CACHE_TTL_DAYS,
  MIN_POIS_BEFORE_GOOGLE: parsed.MIN_POIS_BEFORE_GOOGLE,
  GOOGLE_PLACES_ENABLED: parsed.PLACES_ENRICHMENT_ENABLED, // Alias pro zpětnou kompatibilitu
  PLACES_ENRICHMENT_ENABLED: parsed.PLACES_ENRICHMENT_ENABLED,
  MAX_PLACES_REQUESTS_PER_DAY: parsed.MAX_PLACES_REQUESTS_PER_DAY,
  MAX_GOOGLE_CALLS_PER_DAY: parsed.MAX_PLACES_REQUESTS_PER_DAY, // Alias pro zpětnou kompatibilitu
  // WordPress MySQL konfigurace
  wordpressDbHost: parsed.WORDPRESS_DB_HOST,
  wordpressDbName: parsed.WORDPRESS_DB_NAME,
  wordpressDbUser: parsed.WORDPRESS_DB_USER,
  wordpressDbPassword: parsed.WORDPRESS_DB_PASSWORD,
  wordpressDbPrefix: parsed.WORDPRESS_DB_PREFIX,
  // WordPress REST API pro synchronizaci
  wordpressRestUrl: parsed.WORDPRESS_REST_URL,
  wordpressRestNonce: parsed.WORDPRESS_REST_NONCE,
  wordpressUsername: parsed.WORDPRESS_USERNAME,
  wordpressPassword: parsed.WORDPRESS_PASSWORD,
  wordpressApiKey: parsed.WORDPRESS_API_KEY,
};

export const RATING_PRIORITY_ORDER = [
  'manual_import',
  'google',
  'tripadvisor',
  'opentripmap',
  'wikidata',
];

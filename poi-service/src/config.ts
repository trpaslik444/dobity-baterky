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
  GOOGLE_PLACES_ENABLED: z.coerce.boolean().default(true),
  MAX_GOOGLE_CALLS_PER_DAY: z.coerce.number().default(500),
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
  GOOGLE_PLACES_ENABLED: parsed.GOOGLE_PLACES_ENABLED,
  MAX_GOOGLE_CALLS_PER_DAY: parsed.MAX_GOOGLE_CALLS_PER_DAY,
};

export const RATING_PRIORITY_ORDER = [
  'manual_import',
  'google',
  'tripadvisor',
  'opentripmap',
  'wikidata',
];

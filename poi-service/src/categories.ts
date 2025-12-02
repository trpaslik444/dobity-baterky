export const ALLOWED_CATEGORIES = [
  'restaurant',
  'cafe',
  'bar',
  'pub',
  'fast_food',
  'bakery',
  'park',
  'playground',
  'garden',
  'sports_centre',
  'swimming_pool',
  'beach',
  'tourist_attraction',
  'viewpoint',
  'museum',
  'gallery',
  'zoo',
  'aquarium',
  'shopping_mall',
  'supermarket',
  'marketplace',
  'kids',
  'family'
];

const CSV_TYPE_MAPPING: Record<string, string> = {
  restaurant: 'restaurant',
  cafe: 'cafe',
  coffee: 'cafe',
  bar: 'bar',
  pub: 'pub',
  fast_food: 'fast_food',
  bakery: 'bakery',
  park: 'park',
  playground: 'playground',
  garden: 'garden',
  sports: 'sports_centre',
  pool: 'swimming_pool',
  beach: 'beach',
  attraction: 'tourist_attraction',
  viewpoint: 'viewpoint',
  museum: 'museum',
  gallery: 'gallery',
  zoo: 'zoo',
  aquarium: 'aquarium',
  shopping: 'shopping_mall',
  supermarket: 'supermarket',
  market: 'marketplace',
  kids: 'kids',
  family: 'family',
};

export function mapCsvTypeToCategory(type?: string): string | null {
  if (!type) return null;
  const normalized = type.trim().toLowerCase().replace(/\s+/g, '_');
  if (ALLOWED_CATEGORIES.includes(normalized)) {
    return normalized;
  }
  return CSV_TYPE_MAPPING[normalized] ?? null;
}

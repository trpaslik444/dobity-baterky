import Fastify from 'fastify';
import cors from '@fastify/cors';
import { z } from 'zod';
import { getNearbyPois } from './aggregator';
import { haversineDistanceMeters } from './utils/geo';

const querySchema = z.object({
  lat: z.coerce.number(),
  lon: z.coerce.number(),
  radius: z.coerce.number().default(2000),
  minCount: z.coerce.number().default(10),
  refresh: z.coerce.boolean().default(false),
});

export async function buildServer() {
  const server = Fastify({ logger: true });
  await server.register(cors, { origin: '*' });

  server.get('/api/pois/nearby', async (request, reply) => {
    const parseResult = querySchema.safeParse(request.query);
    if (!parseResult.success) {
      reply.code(400).send({ error: 'Invalid query', details: parseResult.error.flatten() });
      return;
    }
    const { lat, lon, radius, minCount, refresh } = parseResult.data;
    const result = await getNearbyPois(lat, lon, radius, minCount, { refresh });

    const payload = {
      lat,
      lon,
      radius,
      pois: result.pois.map((poi) => ({
        id: poi.id,
        name: poi.name,
        lat: poi.lat,
        lon: poi.lon,
        distance_m: Math.round(haversineDistanceMeters(lat, lon, poi.lat, poi.lon)),
        category: poi.category,
        rating: poi.rating,
        rating_source: poi.rating_source,
        source: poi.rating_source ?? Object.keys((poi.source_ids as any) ?? {})[0],
        source_ids: poi.source_ids,
        address: poi.address,
        city: poi.city,
        country: poi.country,
        website: poi.website,
        phone: poi.phone,
        photo_url: poi.photo_url,
        photo_license: poi.photo_license,
        opening_hours: poi.opening_hours,
      })),
      providers_used: result.providersUsed,
      generated_at: new Date().toISOString(),
    };

    return payload;
  });

  return server;
}

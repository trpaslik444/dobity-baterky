import { buildServer } from './server';
import { prisma } from './prisma';

const PORT = process.env.PORT ? Number(process.env.PORT) : 3333;

async function main() {
  const server = await buildServer();
  await server.listen({ port: PORT, host: '0.0.0.0' });
  server.log.info(`POI service listening on ${PORT}`);
}

main().catch((error) => {
  console.error('Failed to start server', error);
  prisma.$disconnect();
  process.exit(1);
});

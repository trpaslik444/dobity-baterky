import { prisma } from '../prisma';
import { CONFIG } from '../config';

/**
 * Prisma quota manager - fallback pokud není WordPress DB dostupná
 * Používá PostgreSQL ApiUsage tabulku
 */
class PrismaQuotaManager {
  /**
   * Atomicky rezervuje kvótu pro Google Places API
   * Používá Prisma transakci s SELECT FOR UPDATE
   */
  async reserveQuota(): Promise<boolean> {
    const today = startOfToday();
    const limit = CONFIG.MAX_PLACES_REQUESTS_PER_DAY;

    try {
      return await prisma.$transaction(async (tx) => {
        // SELECT FOR UPDATE pro lock
        const usage = await tx.$queryRaw<Array<{ count: number }>>`
          SELECT count FROM "ApiUsage" 
          WHERE provider = 'google' AND date = ${today}
          FOR UPDATE
        `;

        const current = usage[0]?.count ?? 0;

        // Kontrola limitu před incrementem
        if (current >= limit) {
          return false;
        }

        // Atomický upsert
        await tx.apiUsage.upsert({
          where: { provider_date: { provider: 'google', date: today } },
          create: { provider: 'google', date: today, count: 1 },
          update: { count: { increment: 1 } },
        });

        return true;
      });
    } catch (error) {
      console.error('[PrismaQuota] Error reserving quota:', error);
      return false;
    }
  }

  /**
   * Zkontroluje, zda lze použít Google API (bez rezervace)
   */
  async canUseGoogle(): Promise<boolean> {
    const today = startOfToday();
    const limit = CONFIG.MAX_PLACES_REQUESTS_PER_DAY;

    try {
      const usage = await prisma.apiUsage.findUnique({
        where: { provider_date: { provider: 'google', date: today } },
      });

      const current = usage?.count ?? 0;
      return current < limit;
    } catch (error) {
      console.error('[PrismaQuota] Error checking quota:', error);
      return false;
    }
  }
}

function startOfToday(): Date {
  const now = new Date();
  return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()));
}

// Singleton instance
let quotaManager: PrismaQuotaManager | null = null;

export function getPrismaQuotaManager(): PrismaQuotaManager {
  if (!quotaManager) {
    quotaManager = new PrismaQuotaManager();
  }
  return quotaManager;
}


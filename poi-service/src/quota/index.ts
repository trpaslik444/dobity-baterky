import { CONFIG } from '../config';
import { getWordPressQuotaManager } from './wordpressQuota';
import { getPrismaQuotaManager } from './prismaQuota';

/**
 * Unified quota manager - automaticky vybere WordPress nebo Prisma podle konfigurace
 */
export async function reserveGoogleQuota(): Promise<boolean> {
  if (!CONFIG.PLACES_ENRICHMENT_ENABLED) {
    return false;
  }

  // Prioritně použít WordPress DB pokud je nakonfigurována
  if (CONFIG.wordpressDbHost && CONFIG.wordpressDbName) {
    try {
      const wpQuota = getWordPressQuotaManager();
      return await wpQuota.reserveQuota();
    } catch (error) {
      console.warn('[Quota] WordPress quota failed, falling back to Prisma:', error);
      // Fallback na Prisma
    }
  }

  // Fallback na Prisma
  const prismaQuota = getPrismaQuotaManager();
  return await prismaQuota.reserveQuota();
}

export async function canUseGoogle(): Promise<boolean> {
  if (!CONFIG.PLACES_ENRICHMENT_ENABLED) {
    return false;
  }

  // Prioritně použít WordPress DB pokud je nakonfigurována
  if (CONFIG.wordpressDbHost && CONFIG.wordpressDbName) {
    try {
      const wpQuota = getWordPressQuotaManager();
      return await wpQuota.canUseGoogle();
    } catch (error) {
      console.warn('[Quota] WordPress quota check failed, falling back to Prisma:', error);
      // Fallback na Prisma
    }
  }

  // Fallback na Prisma
  const prismaQuota = getPrismaQuotaManager();
  return await prismaQuota.canUseGoogle();
}


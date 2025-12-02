import mysql from 'mysql2/promise';
import { CONFIG } from '../config';

/**
 * WordPress quota manager - synchronizuje kvóty s WordPress MySQL databází
 * Používá stejnou tabulku jako PR #75 (Places_Enrichment_Service)
 */
class WordPressQuotaManager {
  private connection: mysql.Connection | null = null;

  private async getConnection(): Promise<mysql.Connection> {
    if (!CONFIG.wordpressDbHost || !CONFIG.wordpressDbName) {
      throw new Error('WordPress database configuration is missing');
    }

    if (!this.connection) {
      this.connection = await mysql.createConnection({
        host: CONFIG.wordpressDbHost,
        database: CONFIG.wordpressDbName,
        user: CONFIG.wordpressDbUser,
        password: CONFIG.wordpressDbPassword,
        connectTimeout: 5000,
      });
    }

    return this.connection;
  }

  /**
   * Atomicky rezervuje kvótu pro Google Places API
   * Používá transakci s FOR UPDATE lock pro prevenci race conditions
   * @returns true pokud byla kvóta úspěšně rezervována, false pokud byl limit překročen
   */
  async reserveQuota(): Promise<boolean> {
    if (!CONFIG.wordpressDbHost) {
      // Fallback: pokud není WordPress DB nakonfigurována, použít Prisma
      return false;
    }

    const connection = await this.getConnection();
    const today = new Date().toISOString().split('T')[0]; // YYYY-MM-DD
    const tableName = `${CONFIG.wordpressDbPrefix}db_places_usage`;
    const apiName = 'places_details';
    const limit = CONFIG.MAX_PLACES_REQUESTS_PER_DAY;

    try {
      await connection.beginTransaction();

      // SELECT FOR UPDATE pro lock - prevence race conditions
      // Poznámka: tableName je z CONFIG, takže je bezpečné použít string interpolation
      const [rows] = await connection.execute<mysql.RowDataPacket[]>(
        `SELECT request_count FROM \`${tableName}\` 
         WHERE usage_date = ? AND api_name = ? 
         FOR UPDATE`,
        [today, apiName]
      );

      const currentCount = rows[0]?.request_count ?? 0;

      // Kontrola limitu před incrementem
      if (currentCount >= limit) {
        await connection.rollback();
        return false;
      }

      // Atomický INSERT ... ON DUPLICATE KEY UPDATE
      await connection.execute(
        `INSERT INTO \`${tableName}\` (usage_date, api_name, request_count) 
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE request_count = request_count + 1`,
        [today, apiName]
      );

      await connection.commit();
      return true;
    } catch (error) {
      await connection.rollback();
      console.error('[WordPressQuota] Error reserving quota:', error);
      return false;
    }
  }

  /**
   * Zkontroluje, zda lze použít Google API (bez rezervace)
   * @returns true pokud je kvóta dostupná
   */
  async canUseGoogle(): Promise<boolean> {
    if (!CONFIG.wordpressDbHost) {
      return false;
    }

    const connection = await this.getConnection();
    const today = new Date().toISOString().split('T')[0];
    const tableName = `${CONFIG.wordpressDbPrefix}db_places_usage`;
    const apiName = 'places_details';
    const limit = CONFIG.MAX_PLACES_REQUESTS_PER_DAY;

    try {
      const [rows] = await connection.execute<mysql.RowDataPacket[]>(
        `SELECT request_count FROM \`${tableName}\` 
         WHERE usage_date = ? AND api_name = ?`,
        [today, apiName]
      );

      const currentCount = rows[0]?.request_count ?? 0;
      return currentCount < limit;
    } catch (error) {
      console.error('[WordPressQuota] Error checking quota:', error);
      return false;
    }
  }

  /**
   * Zavře připojení k databázi
   */
  async close(): Promise<void> {
    if (this.connection) {
      await this.connection.end();
      this.connection = null;
    }
  }
}

// Singleton instance
let quotaManager: WordPressQuotaManager | null = null;

export function getWordPressQuotaManager(): WordPressQuotaManager {
  if (!quotaManager) {
    quotaManager = new WordPressQuotaManager();
  }
  return quotaManager;
}


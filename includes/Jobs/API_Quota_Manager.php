<?php
/**
 * API Quota Manager - Správa API kvót a automatické odložení
 * @package DobityBaterky
 */

namespace DB\Jobs;

class API_Quota_Manager {

    /**
     * Hodnoty podle dokumentace ORS pro free tier.
     * Viz https://openrouteservice.org/dev/#/signup - matrix i isochrones maximálně 1 požadavek za minutu
     * a jeden matrix request může obsahovat nejvýše 50 souřadnic (1 origin + 49 destinací).
     */
    public const MATRIX_PER_MINUTE = 1;
    public const ISOCHRONES_PER_MINUTE = 1;
    public const ORS_MATRIX_MAX_LOCATIONS = 50;
    
    private $config;
    
    public function __construct() {
        $this->config = get_option('db_nearby_config', array());
    }
    
    /**
     * Zkontrolovat dostupné API kvóty
     */
    public function check_available_quota() {
        $provider = $this->config['provider'] ?? 'ors';
        
        if ($provider === 'ors') {
            return $this->check_ors_quota();
        }
        
        // Pro OSRM není potřeba kontrola kvót
        return array(
            'available' => true,
            'remaining' => 999999,
            'reset_at' => null,
            'can_process' => true
        );
    }
    
    /**
     * Zkontrolovat ORS API kvóty
     */
    private function check_ors_quota() {
        $ors_key = trim($this->config['ors_api_key'] ?? '');
        if (empty($ors_key)) {
            return array(
                'available' => false,
                'remaining' => 0,
                'reset_at' => null,
                'can_process' => false,
                'error' => 'ORS API key není nastaven'
            );
        }
        
        // Získat kvóty z cache (z posledních ORS response hlaviček)
        $cached_quotas = $this->get_cached_ors_quotas();
        $matrix_quota = $cached_quotas['matrix_v2'];
        
        // Zkontrolovat retry_until (po 429)
        $retry_until = $matrix_quota['retry_until'];
        if ($retry_until && $retry_until > time()) {
            return array(
                'available' => false,
                'remaining' => $matrix_quota['remaining'],
                'reset_at' => date('c', $retry_until),
                'can_process' => false,
                'error' => 'Rate limited do ' . date('H:i:s', $retry_until)
            );
        }
        
        return array(
            'available' => $matrix_quota['remaining'] > 0,
            'remaining' => $matrix_quota['remaining'],
            'reset_at' => $matrix_quota['reset_at'],
            'can_process' => $matrix_quota['remaining'] > 0 && (!$retry_until || $retry_until <= time()),
            'max_daily' => $matrix_quota['total'],
            'per_minute' => $matrix_quota['per_minute'],
            'source' => $matrix_quota['source']
        );
    }
    
    /**
     * Zkontrolovat stav ORS API
     */
    private function check_ors_api_status($api_key) {
        try {
            $response = wp_remote_get('https://api.openrouteservice.org/v2/directions/foot-walking', array(
                'headers' => array(
                    'Authorization' => $api_key,
                    'Accept' => 'application/json'
                ),
                'timeout' => 10
            ));
            
            if (is_wp_error($response)) {
                return array('error' => $response->get_error_message());
            }
            
            $code = wp_remote_retrieve_response_code($response);
            $headers = wp_remote_retrieve_headers($response);
            
            if ($code === 429) {
                // Rate limited
                $retry_after = $headers->get('Retry-After');
                if ($retry_after) {
                    $reset_at = time() + (int)$retry_after;
                    set_transient('db_nearby_ors_rate_limit', $reset_at, $retry_after);
                    return array('reset_at' => date('c', $reset_at));
                }
            }
            
            if ($code === 401 || $code === 403) {
                return array('error' => 'ORS API key je neplatný');
            }
            
            return array('status' => 'ok');
            
        } catch (Exception $e) {
            return array('error' => $e->getMessage());
        }
    }
    
    /**
     * Získat kvóty z cache (uložené z posledních ORS response hlaviček)
     */
    public function get_cached_ors_quotas() {
        $remaining = get_transient('db_ors_matrix_remaining_day');
        $reset_epoch = get_transient('db_ors_matrix_reset_epoch');
        $retry_until = get_transient('db_ors_matrix_retry_until');
        
        // Pokud nemáme cached data, použít fallback
        if ($remaining === false) {
            return array(
                'matrix_v2' => array(
                    'total' => 500,
                    'per_minute' => 40,
                    'remaining' => 500,
                    'source' => 'fallback'
                )
            );
        }
        
        return array(
            'matrix_v2' => array(
                'total' => 500, // Známý limit
                'per_minute' => 40, // Známý limit
                'remaining' => (int)$remaining,
                'reset_at' => $reset_epoch ? date('c', $reset_epoch) : null,
                'retry_until' => $retry_until,
                'source' => 'headers'
            )
        );
    }
    
    /**
     * Uložit kvóty z ORS response hlaviček
     */
    public function save_ors_headers($headers) {
        $remaining = isset($headers['x-ratelimit-remaining']) ? (int)$headers['x-ratelimit-remaining'] : null;
        $reset_epoch = isset($headers['x-ratelimit-reset']) ? (int)$headers['x-ratelimit-reset'] : null;
        $retry_after = isset($headers['retry-after']) ? (int)$headers['retry-after'] : null;
        
        if ($remaining !== null) {
            set_transient('db_ors_matrix_remaining_day', $remaining, 15 * MINUTE_IN_SECONDS);
        }
        
        if ($reset_epoch !== null) {
            set_transient('db_ors_matrix_reset_epoch', $reset_epoch, 60 * MINUTE_IN_SECONDS);
        }
        
        if ($retry_after !== null) {
            $retry_until = time() + $retry_after;
            set_transient('db_ors_matrix_retry_until', $retry_until, $retry_after);
        }
        
        error_log("[DB ORS Headers] Saved: remaining={$remaining}, reset={$reset_epoch}, retry_after={$retry_after}");
    }
    
    /**
     * Token bucket pro minutový limit
     */
    public function check_minute_limit($type = 'matrix', $consume_token = true) {
        $type = ($type === 'isochrones') ? 'isochrones' : 'matrix';
        $limit = ($type === 'isochrones') ? self::ISOCHRONES_PER_MINUTE : self::MATRIX_PER_MINUTE;
        $bucket_key = 'db_ors_' . $type . '_token_bucket';
        $bucket = get_transient($bucket_key);

        if ($bucket === false) {
            // Nový bucket s plným počtem tokenů
            $bucket = array(
                'tokens' => $limit,
                'last_refill' => time()
            );
        }

        $now = time();
        $elapsed = $now - $bucket['last_refill'];
        $tokens_before_refill = $bucket['tokens'];

        if ($elapsed > 0) {
            $refill_rate = $limit / 60;
            $bucket['tokens'] = min($limit, $bucket['tokens'] + ($elapsed * $refill_rate));
            $bucket['last_refill'] = $now;
        }

        // Zkontrolovat dostupnost tokenů
        if ($bucket['tokens'] < 1) {
            $tokens_needed = 1 - $bucket['tokens'];
            $wait_time = ceil($tokens_needed / ($limit / 60));
            $wait_time = max(3, min(60, $wait_time));
            error_log(sprintf('[DB Quota] type=%s allowed=0 tokens_before=%.2f tokens_after=%.2f limit=%d wait=%d', $type, $tokens_before_refill, $bucket['tokens'], $limit, $wait_time));
            return array(
                'allowed' => false,
                'wait_seconds' => $wait_time,
                'tokens_before' => $tokens_before_refill,
                'tokens_after' => $bucket['tokens'],
                'limit' => $limit
            );
        }

        $tokens_before_consume = $bucket['tokens'];
        if ($consume_token) {
            $bucket['tokens']--;
            set_transient($bucket_key, $bucket, 60);
        } else {
            set_transient($bucket_key, $bucket, 60);
        }

        error_log(sprintf('[DB Quota] type=%s allowed=1 tokens_before=%.2f tokens_after=%.2f limit=%d', $type, $tokens_before_consume, $bucket['tokens'], $limit));

        return array(
            'allowed' => true,
            'tokens_remaining' => $bucket['tokens'],
            'tokens_before' => $tokens_before_consume,
            'tokens_after' => $bucket['tokens'],
            'limit' => $limit
        );
    }
    
    /**
     * Zaznamenat použití API
     */
    public function record_api_usage($count) {
        $provider = $this->config['provider'] ?? 'ors';
        
        if ($provider === 'ors') {
            $today = date('Y-m-d');
            $usage_key = 'db_nearby_ors_usage_' . $today;
            $current_usage = get_transient($usage_key) ?: 0;
            set_transient($usage_key, $current_usage + $count, DAY_IN_SECONDS);
        }
    }
    
    /**
     * Zkontrolovat, zda může fronta pokračovat
     */
    public function can_process_queue() {
        $quota = $this->check_available_quota();
        return $quota['can_process'];
    }
    
    /**
     * Získat doporučený batch size na základě dostupných kvót
     */
    public function get_recommended_batch_size() {
        $quota = $this->check_available_quota();
        
        if (!$quota['can_process']) {
            return 0;
        }
        
        $remaining = $quota['remaining'];
        
        // Doporučit menší batch, pokud zbývá málo kvóty
        if ($remaining <= 0) {
            return 0;
        }

        // ORS limity dovolují 1 matrix+isochron request za minutu, proto zpracujeme max. 1 položku.
        return min(1, (int)$remaining);
    }
    
    /**
     * Získat čas do resetu kvót
     */
    public function get_reset_time() {
        $quota = $this->check_available_quota();
        
        if ($quota['reset_at']) {
            return strtotime($quota['reset_at']);
        }
        
        // Denní reset v 00:00
        return strtotime('tomorrow 00:00:00');
    }
    
    /**
     * Naplánovat další běh fronty
     */
    public function schedule_next_run() {
        $quota = $this->check_available_quota();
        
        if ($quota['can_process']) {
            // Může pokračovat okamžitě
            return time();
        }
        
        // Naplánovat na reset kvót
        $reset_time = $this->get_reset_time();
        
        // Naplánovat WordPress cron
        if (!wp_next_scheduled('db_nearby_auto_process')) {
            wp_schedule_single_event($reset_time, 'db_nearby_auto_process');
        }
        
        return $reset_time;
    }
    
    /**
     * Získat statistiky API usage
     */
    public function get_usage_stats() {
        $quota = $this->check_available_quota();
        
        return array(
            'provider' => $this->config['provider'] ?? 'ors',
            'daily_usage' => $quota['daily_usage'] ?? 0,
            'max_daily' => $quota['max_daily'] ?? 500,
            'remaining' => $quota['remaining'] ?? 0,
            'reset_at' => $quota['reset_at'] ?? null,
            'can_process' => $quota['can_process'] ?? false,
            'per_minute' => $quota['per_minute'] ?? 40,
            'source' => $quota['source'] ?? 'fallback'
        );
    }
}

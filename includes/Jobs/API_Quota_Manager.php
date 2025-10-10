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
    public const ORS_MATRIX_MAX_LOCATIONS = 3500;
    
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
                'error' => 'ORS API key není nastaven',
                'reason' => 'missing_key'
            );
        }

        // Získat kvóty z cache (z posledních ORS response hlaviček)
        $cached_quotas = $this->get_cached_ors_quotas();
        $matrix_quota = $cached_quotas['matrix_v2'];
        $remaining = $matrix_quota['remaining'];
        $status = $matrix_quota['status'] ?? null;
        $retry_until = isset($matrix_quota['retry_until']) ? (int)$matrix_quota['retry_until'] : null;

        // Zkontrolovat retry_until (po 429)
        if ($retry_until && $retry_until > time()) {
            return array(
                'available' => false,
                'remaining' => $remaining,
                'reset_at' => date('c', $retry_until),
                'can_process' => false,
                'error' => 'Rate limited do ' . date('H:i:s', $retry_until),
                'reason' => 'retry_wait',
                'status' => $status
            );
        }

        $remaining_known = ($remaining !== null);
        $available = $remaining_known ? ($remaining > 0) : true;
        $can_process = $available && (!$retry_until || $retry_until <= time());
        $reason = null;

        if ($remaining_known && $remaining <= 0) {
            $reason = 'exhausted';
        }

        if ($status && isset($status['state']) && $status['state'] !== 'ok') {
            $reason = $status['state'];
        }

        return array(
            'available' => $available,
            'remaining' => $remaining,
            'reset_at' => $matrix_quota['reset_at'] ?? null,
            'can_process' => $can_process,
            'max_daily' => $matrix_quota['total'],
            'per_minute' => $matrix_quota['per_minute'],
            'source' => $matrix_quota['source'],
            'status' => $status,
            'reason' => $reason
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
            
        } catch (\Exception $e) {
            return array('error' => $e->getMessage());
        }
    }
    
    /**
     * Získat kvóty z cache (uložené z posledních ORS response hlaviček)
     */
    public function get_cached_ors_quotas() {
        // Matrix
        $mx_remaining = get_transient('db_ors_matrix_remaining_day');
        $mx_reset_epoch = get_transient('db_ors_matrix_reset_epoch');
        $mx_retry_until = get_transient('db_ors_matrix_retry_until');
        $mx_status = get_transient('db_ors_matrix_status');

        // Isochrones
        $iso_remaining = get_transient('db_ors_iso_remaining_day');
        $iso_reset_epoch = get_transient('db_ors_iso_reset_epoch');
        $iso_retry_until = get_transient('db_ors_iso_retry_until');
        $iso_status = get_transient('db_ors_iso_status');

        $matrix_remaining = ($mx_remaining === false || $mx_remaining === null) ? null : (int)$mx_remaining;
        $iso_remaining_value = ($iso_remaining === false || $iso_remaining === null) ? null : (int)$iso_remaining;

        $matrix = array(
            'total' => 500,
            'per_minute' => 40,
            'remaining' => $matrix_remaining,
            'reset_at' => $mx_reset_epoch ? date('c', $mx_reset_epoch) : null,
            'retry_until' => $mx_retry_until,
            'source' => $matrix_remaining === null ? 'unknown' : 'headers',
            'status' => $mx_status === false ? null : $mx_status
        );

        $isochrones = array(
            'total' => 500,
            'per_minute' => 40,
            'remaining' => $iso_remaining_value,
            'reset_at' => $iso_reset_epoch ? date('c', $iso_reset_epoch) : null,
            'retry_until' => $iso_retry_until,
            'source' => $iso_remaining_value === null ? 'unknown' : 'headers',
            'status' => $iso_status === false ? null : $iso_status
        );

        return array(
            'matrix_v2' => $matrix,
            'isochrones_v2' => $isochrones
        );
    }

    /**
     * Uložit kvóty z ORS response hlaviček
     */
    public function save_ors_headers($headers, $type = 'matrix') {
        $type_key = ($type === 'isochrones') ? 'iso' : 'matrix';
        $remaining = isset($headers['x-ratelimit-remaining']) ? (int)$headers['x-ratelimit-remaining'] : null;
        $reset_epoch = isset($headers['x-ratelimit-reset']) ? (int)$headers['x-ratelimit-reset'] : null;
        $retry_after = isset($headers['retry-after']) ? (int)$headers['retry-after'] : null;

        if ($remaining !== null) {
            set_transient('db_ors_' . $type_key . '_remaining_day', $remaining, 15 * MINUTE_IN_SECONDS);
        } else {
            delete_transient('db_ors_' . $type_key . '_remaining_day');
        }

        if ($reset_epoch !== null) {
            set_transient('db_ors_' . $type_key . '_reset_epoch', $reset_epoch, 60 * MINUTE_IN_SECONDS);
        }

        if ($retry_after !== null) {
            $retry_until = time() + $retry_after;
            set_transient('db_ors_' . $type_key . '_retry_until', $retry_until, $retry_after);
        }

        $this->record_status($type_key, 'ok', array(
            'remaining' => $remaining,
            'reset_epoch' => $reset_epoch,
            'source' => $remaining === null ? 'unknown' : 'headers'
        ));

        Nearby_Logger::log('QUOTA', 'Saved ORS headers', [
            'api' => $type_key,
            'remaining' => $remaining,
            'reset_epoch' => $reset_epoch,
            'retry_after' => $retry_after
        ]);
    }

    private function record_status($type_key, $state, array $extra = array()) {
        $payload = array_merge(array(
            'state' => $state,
            'recorded_at' => time()
        ), $extra);

        set_transient('db_ors_' . $type_key . '_status', $payload, 2 * DAY_IN_SECONDS);
    }

    public function mark_daily_quota_exhausted($type = 'matrix', $http_code = null) {
        $type_key = ($type === 'isochrones') ? 'iso' : 'matrix';
        $reset_epoch = strtotime('tomorrow 00:05:00');
        if (!$reset_epoch) {
            $reset_epoch = time() + DAY_IN_SECONDS;
        }
        $ttl = max(60, $reset_epoch - time());

        set_transient('db_ors_' . $type_key . '_remaining_day', 0, $ttl);
        set_transient('db_ors_' . $type_key . '_reset_epoch', $reset_epoch, $ttl);
        set_transient('db_ors_' . $type_key . '_retry_until', $reset_epoch, $ttl);

        $state = ((int)$http_code === 401) ? 'unauthorized' : 'daily_limit';
        $this->record_status($type_key, $state, array('http_code' => $http_code, 'reset_epoch' => $reset_epoch));

        Nearby_Logger::log('QUOTA', 'Marked ORS quota unavailable', [
            'api' => $type_key,
            'state' => $state,
            'http_code' => $http_code,
            'retry_until' => $reset_epoch
        ]);
    }
    
    /**
     * Token bucket pro minutový limit
     */
    public function check_minute_limit($type = 'matrix', $consume_token = true, $log = true) {
        $bucket_enabled = !empty($this->config['enable_token_bucket']);
        if (!$bucket_enabled) {
            $limit = ($type === 'isochrones') ? self::ISOCHRONES_PER_MINUTE : self::MATRIX_PER_MINUTE;
            return array(
                'allowed' => true,
                'tokens_remaining' => $limit,
                'tokens_before' => $limit,
                'tokens_after' => $limit,
                'limit' => $limit
            );
        }

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
            set_transient($bucket_key, $bucket, 60);
            if ($log) {
                Nearby_Logger::log('QUOTA', 'Token bucket blocked', [
                    'type' => $type,
                    'tokens_before' => round($tokens_before_refill, 2),
                    'tokens_after' => round($bucket['tokens'], 2),
                    'limit' => $limit,
                    'wait_seconds' => $wait_time
                ]);
            }
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

        if ($log) {
            Nearby_Logger::log('QUOTA', 'Token consumed', [
                'type' => $type,
                'tokens_before' => round($tokens_before_consume, 2),
                'tokens_after' => round($bucket['tokens'], 2),
                'limit' => $limit
            ]);
        }

        return array(
            'allowed' => true,
            'tokens_remaining' => $bucket['tokens'],
            'tokens_before' => $tokens_before_consume,
            'tokens_after' => $bucket['tokens'],
            'limit' => $limit
        );
    }

    private function minute_status_snapshot() {
        $provider = $this->config['provider'] ?? 'ors';
        if ($provider !== 'ors') {
            return array(
                'matrix' => array('allowed' => true, 'wait_seconds' => 0),
                'isochrones' => array('allowed' => true, 'wait_seconds' => 0),
            );
        }

        return array(
            'matrix' => $this->check_minute_limit('matrix', false, false),
            'isochrones' => $this->check_minute_limit('isochrones', false, false)
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
     * Resetovat lokální minutový bucket (pomocník pro testování)
     */
    public function reset_minute_bucket($type = 'matrix') {
        $type = ($type === 'isochrones') ? 'isochrones' : 'matrix';
        $bucket_key = 'db_ors_' . $type . '_token_bucket';
        delete_transient($bucket_key);
        Nearby_Logger::log('QUOTA', 'Manual minute bucket reset', ['type' => $type]);
    }

    /**
     * Zkontrolovat, zda může fronta pokračovat
     */
    public function can_process_queue() {
        $quota = $this->check_available_quota();
        if (!$quota['can_process']) {
            return false;
        }

        // Oba token buckety musí mít alespoň 1 token
        $minute_status = $this->minute_status_snapshot();
        return !empty($minute_status['matrix']['allowed']) && !empty($minute_status['isochrones']['allowed']);
    }
    
    /**
     * Získat doporučený batch size na základě dostupných kvót
     */
    public function get_recommended_batch_size() {
        $quota = $this->check_available_quota();
        
        if (!$quota['can_process']) {
            return 0;
        }

        $minute_status = $this->minute_status_snapshot();
        if (empty($minute_status['matrix']['allowed']) || empty($minute_status['isochrones']['allowed'])) {
            return 0;
        }

        $remaining = $quota['remaining'] ?? null;
        if ($remaining === null) {
            return 1; // neznámý stav - povolit minimálně jeden request pro obnovu hlaviček
        }

        $remaining = (int)$remaining;
        if ($remaining <= 0) {
            return 0;
        }

        return min(1, $remaining);
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
            $minute_status = $this->minute_status_snapshot();
            $matrix_allowed = !empty($minute_status['matrix']['allowed']);
            $iso_allowed = !empty($minute_status['isochrones']['allowed']);

            if (!$matrix_allowed || !$iso_allowed) {
                $wait_matrix = $matrix_allowed ? 0 : (int)($minute_status['matrix']['wait_seconds'] ?? 0);
                $wait_iso = $iso_allowed ? 0 : (int)($minute_status['isochrones']['wait_seconds'] ?? 0);
                $delay = max($wait_matrix, $wait_iso, 60);
                return time() + $delay;
            }
            // Když jsou oba buckety OK, plánovat další běh za 60s (1 origin/min)
            return time() + 60;
        }

        // Po 429: respektovat delší z obou retry_until a přidat 30 minut
        $retry_until_mx = $this->get_retry_until('matrix');
        $retry_until_iso = $this->get_retry_until('isochrones');
        $retry_until = max((int)$retry_until_mx, (int)$retry_until_iso);
        if ($retry_until && $retry_until > time()) {
            $delay = max(0, $retry_until - time() + 30 * MINUTE_IN_SECONDS);
            return time() + $delay;
        }

        $reset_time = $this->get_reset_time();

        if (!wp_next_scheduled('db_nearby_auto_process')) {
            wp_schedule_single_event($reset_time, 'db_nearby_auto_process');
        }

        return $reset_time;
    }

    public function get_retry_until($type = 'matrix') {
        $type = ($type === 'isochrones') ? 'iso' : 'matrix';
        $retry_until = get_transient('db_ors_' . $type . '_retry_until');
        if ($retry_until && $retry_until > time()) {
            return $retry_until;
        }
        return null;
    }
    
    /**
     * Získat statistiky API usage
     */
    public function get_usage_stats() {
        $quota = $this->check_available_quota();
        
        $remaining = array_key_exists('remaining', $quota) ? $quota['remaining'] : null;

        return array(
            'provider' => $this->config['provider'] ?? 'ors',
            'daily_usage' => $quota['daily_usage'] ?? 0,
            'max_daily' => $quota['max_daily'] ?? 500,
            'remaining' => $remaining,
            'reset_at' => $quota['reset_at'] ?? null,
            'can_process' => $quota['can_process'] ?? false,
            'per_minute' => $quota['per_minute'] ?? 40,
            'source' => $quota['source'] ?? 'fallback',
            'status' => $quota['status'] ?? null,
            'reason' => $quota['reason'] ?? null
        );
    }
}

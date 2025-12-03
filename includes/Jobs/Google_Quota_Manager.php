<?php
/**
 * Centralizovaný Google Quota Manager - jednotná správa kvót pro všechna Google Places API volání
 * Kombinuje měsíční a denní limity s bufferem
 */

namespace DB\Jobs;

if (!defined('ABSPATH')) { exit; }

class Google_Quota_Manager {
    private const OPT_CONFIG = 'db_google_quota_config';
    private const OPT_USAGE  = 'db_google_quota_usage';

    private $config;

    public function __construct() {
        $this->config = get_option(self::OPT_CONFIG, array(
            'google_monthly_total' => 10000,
            'google_daily_total' => 0, // 0 = bez denního limitu
            'buffer_abs' => 300,
        ));
        if (!is_array($this->config)) {
            $this->config = array(
                'google_monthly_total' => 10000,
                'google_daily_total' => 0,
                'buffer_abs' => 300,
            );
        }
    }

    private function month_key(): string {
        return date('Y-m');
    }

    private function day_key(): string {
        return date('Y-m-d');
    }

    private function get_usage(): array {
        $usage = get_option(self::OPT_USAGE, array());
        return is_array($usage) ? $usage : array();
    }

    private function save_usage(array $usage): void {
        update_option(self::OPT_USAGE, $usage, false);
    }

    /**
     * Získá aktuální stav kvót
     */
    public function get_status(): array {
        $usage = $this->get_usage();
        $mk = $this->month_key();
        $dk = $this->day_key();
        
        $g_monthly_used = (int) ($usage['google']['monthly'][$mk] ?? 0);
        $g_daily_used = (int) ($usage['google']['daily'][$dk] ?? 0);
        
        $monthly_total = (int) ($this->config['google_monthly_total'] ?? 0);
        $daily_total = (int) ($this->config['google_daily_total'] ?? 0);
        $buffer = (int) ($this->config['buffer_abs'] ?? 0);
        
        $monthly_remaining = max(0, $monthly_total - $g_monthly_used);
        $daily_remaining = $daily_total > 0 ? max(0, $daily_total - $g_daily_used) : null;
        
        return array(
            'google' => array(
                'monthly' => array(
                    'used' => $g_monthly_used,
                    'total' => $monthly_total,
                    'remaining' => $monthly_remaining,
                ),
                'daily' => array(
                    'used' => $g_daily_used,
                    'total' => $daily_total,
                    'remaining' => $daily_remaining,
                ),
            ),
            'buffer_abs' => $buffer,
        );
    }

    /**
     * Zkontroluje, zda lze použít Google API
     */
    public function can_use_google(): bool {
        $status = $this->get_status();
        $monthly_remaining = $status['google']['monthly']['remaining'] ?? 0;
        $daily_remaining = $status['google']['daily']['remaining'];
        $buffer = $status['buffer_abs'] ?? 0;
        
        // Měsíční limit musí být větší než buffer
        if ($monthly_remaining <= $buffer) {
            return false;
        }
        
        // Pokud je nastaven denní limit, musí být také větší než buffer
        if ($daily_remaining !== null && $daily_remaining <= $buffer) {
            return false;
        }
        
        return true;
    }

    /**
     * Zaznamená použití Google API
     */
    public function record_google(int $count = 1): void {
        $count = max(0, $count);
        if ($count === 0) {
            return;
        }
        
        $usage = $this->get_usage();
        $mk = $this->month_key();
        $dk = $this->day_key();
        
        if (!isset($usage['google'])) {
            $usage['google'] = array('monthly' => array(), 'daily' => array());
        }
        if (!isset($usage['google']['monthly'])) {
            $usage['google']['monthly'] = array();
        }
        if (!isset($usage['google']['daily'])) {
            $usage['google']['daily'] = array();
        }
        
        $usage['google']['monthly'][$mk] = (int) ($usage['google']['monthly'][$mk] ?? 0) + $count;
        $usage['google']['daily'][$dk] = (int) ($usage['google']['daily'][$dk] ?? 0) + $count;
        
        $this->save_usage($usage);
    }

    /**
     * Nastaví limity
     */
    public function set_totals(int $monthly, int $daily, int $bufferAbs): void {
        $monthly = max(0, $monthly);
        $daily = max(0, $daily);
        $bufferAbs = max(0, $bufferAbs);
        
        $cfg = get_option(self::OPT_CONFIG, array());
        if (!is_array($cfg)) {
            $cfg = array();
        }
        $cfg['google_monthly_total'] = $monthly;
        $cfg['google_daily_total'] = $daily;
        $cfg['buffer_abs'] = $bufferAbs;
        update_option(self::OPT_CONFIG, $cfg, false);
        $this->config = $cfg;
    }

    /**
     * Nastaví použité hodnoty (pro reset/manuální nastavení)
     */
    public function set_used(int $monthlyUsed, ?int $dailyUsed = null): void {
        $usage = $this->get_usage();
        $mk = $this->month_key();
        $dk = $this->day_key();
        
        if (!isset($usage['google'])) {
            $usage['google'] = array('monthly' => array(), 'daily' => array());
        }
        if (!isset($usage['google']['monthly'])) {
            $usage['google']['monthly'] = array();
        }
        if (!isset($usage['google']['daily'])) {
            $usage['google']['daily'] = array();
        }
        
        $usage['google']['monthly'][$mk] = max(0, $monthlyUsed);
        if ($dailyUsed !== null) {
            $usage['google']['daily'][$dk] = max(0, $dailyUsed);
        }
        
        $this->save_usage($usage);
    }

    /**
     * Zkontroluje a rezervuje kvótu (atomická operace s ochranou proti race condition)
     * Vrací true pokud je kvóta dostupná a byla rezervována, jinak WP_Error
     * 
     * Používá WordPress transients pro synchronizaci při souběžných requestech
     */
    public function reserve_quota(int $count = 1): \WP_Error|bool {
        $count = max(1, $count);
        $lock_key = 'db_google_quota_lock_' . get_current_blog_id();
        $max_attempts = 5;
        $attempt = 0;
        
        // Pokus o získání locku s retry logikou
        while ($attempt < $max_attempts) {
            $lock = get_transient($lock_key);
            if ($lock === false) {
                // Lock získán - nastavit ho na 1 sekundu
                set_transient($lock_key, time(), 1);
                break;
            }
            
            // Lock je aktivní, počkat chvíli a zkusit znovu
            $attempt++;
            if ($attempt >= $max_attempts) {
                // Po vyčerpání pokusů vrátit chybu
                return new \WP_Error(
                    'quota_locked',
                    'Kvóta je právě kontrolována, zkuste to prosím znovu',
                    array('status' => 429)
                );
            }
            usleep(100000); // 100ms
        }
        
        try {
            // Atomická kontrola a rezervace
            $status = $this->get_status();
            $monthly_remaining = $status['google']['monthly']['remaining'] ?? 0;
            $daily_remaining = $status['google']['daily']['remaining'];
            $buffer = $status['buffer_abs'] ?? 0;
            
            // Kontrola měsíčního limitu
            if ($monthly_remaining <= $buffer || ($monthly_remaining - $count) < $buffer) {
                $this->log_quota_rejection('google', $count, $status);
                return new \WP_Error(
                    'quota_exceeded',
                    'Google API kvóta byla vyčerpána',
                    array(
                        'status' => 429,
                        'quota_status' => $status,
                    )
                );
            }
            
            // Kontrola denního limitu (pokud je nastaven)
            if ($daily_remaining !== null) {
                if ($daily_remaining <= $buffer || ($daily_remaining - $count) < $buffer) {
                    $this->log_quota_rejection('google', $count, $status);
                    return new \WP_Error(
                        'quota_exceeded',
                        'Denní Google API kvóta byla vyčerpána',
                        array(
                            'status' => 429,
                            'quota_status' => $status,
                        )
                    );
                }
            }
            
            // Rezervovat kvótu (atomická operace)
            $this->record_google($count);
            return true;
        } finally {
            // Vždy uvolnit lock
            delete_transient($lock_key);
        }
    }

    /**
     * Loguje odmítnutí kvóty
     */
    private function log_quota_rejection(string $type, int $requested, array $status): void {
        $mk = $this->month_key();
        $dk = $this->day_key();
        $monthly_remaining = $status['google']['monthly']['remaining'] ?? 0;
        $daily_remaining = $status['google']['daily']['remaining'];
        
        error_log(sprintf(
            '[DB_QUOTA] Odmítnuto: typ=%s, požadováno=%d, měsíční_zbývá=%d, denní_zbývá=%s, měsíc=%s, datum=%s',
            $type,
            $requested,
            $monthly_remaining,
            $daily_remaining !== null ? (string)$daily_remaining : 'N/A',
            $mk,
            $dk
        ));
    }
}


<?php
/**
 * Charging discovery quota manager. Tracks Google Places consumption and exposes
 * helper methods for workers and admin UI. Open Charge Map does not enforce a
 * strict limit but we keep metrics for observability.
 */

namespace DB\Jobs;

if (!defined('ABSPATH')) { exit; }

class Charging_Quota_Manager {
    private const OPT_CONFIG = 'db_charging_quota_config';
    private const OPT_USAGE  = 'db_charging_quota_usage';

    private $config;

    public function __construct() {
        $this->config = get_option(self::OPT_CONFIG, array(
            'google_monthly_total' => 10000,
            'ocm_monthly_total' => 500000,
            'buffer_abs' => 300,
        ));
        if (!is_array($this->config)) {
            $this->config = array(
                'google_monthly_total' => 10000,
                'ocm_monthly_total' => 500000,
                'buffer_abs' => 300,
            );
        }
    }

    private function month_key(): string {
        return date('Y-m');
    }

    private function get_usage(): array {
        $usage = get_option(self::OPT_USAGE, array());
        return is_array($usage) ? $usage : array();
    }

    private function save_usage(array $usage): void {
        update_option(self::OPT_USAGE, $usage, false);
    }

    public function get_status(): array {
        $usage = $this->get_usage();
        $mk = $this->month_key();
        $g_used = (int) ($usage['google'][$mk] ?? 0);
        $ocm_used = (int) ($usage['ocm'][$mk] ?? 0);
        return array(
            'google' => array(
                'used' => $g_used,
                'total' => (int) ($this->config['google_monthly_total'] ?? 0),
                'remaining' => max(0, (int) ($this->config['google_monthly_total'] ?? 0) - $g_used),
            ),
            'open_charge_map' => array(
                'used' => $ocm_used,
                'total' => (int) ($this->config['ocm_monthly_total'] ?? 0),
                'remaining' => max(0, (int) ($this->config['ocm_monthly_total'] ?? 0) - $ocm_used),
            ),
            'buffer_abs' => (int) ($this->config['buffer_abs'] ?? 0),
        );
    }

    public function can_use_google(): bool {
        $status = $this->get_status();
        return (($status['google']['remaining'] ?? 0) > ($status['buffer_abs'] ?? 0));
    }

    public function can_use_ocm(): bool {
        return true; // Monitoring only, no enforced limit.
    }

    public function record_google(int $count = 1): void {
        $usage = $this->get_usage();
        $mk = $this->month_key();
        $usage['google'][$mk] = (int) ($usage['google'][$mk] ?? 0) + max(0, $count);
        $this->save_usage($usage);
    }

    public function record_ocm(int $count = 1): void {
        $usage = $this->get_usage();
        $mk = $this->month_key();
        $usage['ocm'][$mk] = (int) ($usage['ocm'][$mk] ?? 0) + max(0, $count);
        $this->save_usage($usage);
    }

    public function set_totals(int $google, int $ocm, int $bufferAbs): void {
        $google = max(0, $google);
        $ocm = max(0, $ocm);
        $bufferAbs = max(0, $bufferAbs);
        $cfg = get_option(self::OPT_CONFIG, array());
        if (!is_array($cfg)) {
            $cfg = array();
        }
        $cfg['google_monthly_total'] = $google;
        $cfg['ocm_monthly_total'] = $ocm;
        $cfg['buffer_abs'] = $bufferAbs;
        update_option(self::OPT_CONFIG, $cfg, false);
        $this->config = $cfg;
    }

    public function set_used(int $googleUsed, int $ocmUsed): void {
        $usage = $this->get_usage();
        $mk = $this->month_key();
        $usage['google'][$mk] = max(0, $googleUsed);
        $usage['ocm'][$mk] = max(0, $ocmUsed);
        $this->save_usage($usage);
    }
}

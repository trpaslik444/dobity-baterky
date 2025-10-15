<?php
/**
 * POI Quota Manager - měsíční kvóty pro Google Places a Tripadvisor
 */

namespace DB\Jobs;

class POI_Quota_Manager {

	private const OPT_CONFIG = 'db_poi_quota_config';
	private const OPT_USAGE  = 'db_poi_quota_usage';

	private $config;

	public function __construct() {
        $this->config = get_option(self::OPT_CONFIG, array(
            'google_monthly_total' => 10000,
            'tripadvisor_monthly_total' => 5000,
            // Bezpečnostní buffer: kolik dotazů ponechat „v záloze“ (absolutní hodnota)
            'buffer_abs' => 300,
        ));
	}

	private function month_key(): string {
		return date('Y-m');
	}

	private function get_usage(): array {
		$usage = get_option(self::OPT_USAGE, array());
		if (!is_array($usage)) $usage = array();
		return $usage;
	}

	private function save_usage(array $usage): void {
		update_option(self::OPT_USAGE, $usage, false);
	}

	public function get_status(): array {
		$usage = $this->get_usage();
		$mk = $this->month_key();
		$g_used = (int)($usage['google'][$mk] ?? 0);
		$ta_used = (int)($usage['tripadvisor'][$mk] ?? 0);
		return array(
			'google' => array(
				'used' => $g_used,
				'total' => (int)$this->config['google_monthly_total'],
                'remaining' => max(0, (int)$this->config['google_monthly_total'] - $g_used),
			),
			'tripadvisor' => array(
				'used' => $ta_used,
				'total' => (int)$this->config['tripadvisor_monthly_total'],
                'remaining' => max(0, (int)$this->config['tripadvisor_monthly_total'] - $ta_used),
			),
            'buffer_abs' => (int)$this->config['buffer_abs'],
		);
	}

	public function can_use_google(): bool {
        $status = $this->get_status();
        return (($status['google']['remaining'] ?? 0) > ($status['buffer_abs'] ?? 0));
	}

	public function can_use_tripadvisor(): bool {
        $status = $this->get_status();
        return (($status['tripadvisor']['remaining'] ?? 0) > ($status['buffer_abs'] ?? 0));
	}

	public function record_google(int $count = 1): void {
		$usage = $this->get_usage();
		$mk = $this->month_key();
		$usage['google'][$mk] = (int)($usage['google'][$mk] ?? 0) + max(0, $count);
		$this->save_usage($usage);
	}

	public function record_tripadvisor(int $count = 1): void {
		$usage = $this->get_usage();
		$mk = $this->month_key();
		$usage['tripadvisor'][$mk] = (int)($usage['tripadvisor'][$mk] ?? 0) + max(0, $count);
		$this->save_usage($usage);
	}

    // Admin helpers
    public function set_totals(int $google, int $tripadvisor, int $bufferAbs): void {
        $google = max(0, $google); $tripadvisor = max(0, $tripadvisor); $bufferAbs = max(0, $bufferAbs);
        $cfg = get_option(self::OPT_CONFIG, array());
        if (!is_array($cfg)) $cfg = array();
        $cfg['google_monthly_total'] = $google;
        $cfg['tripadvisor_monthly_total'] = $tripadvisor;
        $cfg['buffer_abs'] = $bufferAbs;
        update_option(self::OPT_CONFIG, $cfg, false);
        $this->config = $cfg;
    }

    public function set_used(int $googleUsed, int $tripadvisorUsed): void {
        $usage = $this->get_usage();
        $mk = $this->month_key();
        $usage['google'][$mk] = max(0, $googleUsed);
        $usage['tripadvisor'][$mk] = max(0, $tripadvisorUsed);
        $this->save_usage($usage);
    }
}



<?php
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array()) {
        return array_merge($defaults, $args);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct($code = '', $message = '', $data = array()) {}
    }
}

if (!function_exists('getenv')) {
    // PHP always defines getenv, stub only for static analysers
}

require_once __DIR__ . '/../includes/Util/Places_Enrichment_Service.php';

class PlacesEnrichmentServiceTest extends TestCase {
    protected function setUp(): void {
        $ref = new ReflectionProperty(DB\Util\Places_Enrichment_Service::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public function get_charset_collate() { return 'CHARSET'; }
            public function query($sql) { return true; }
            public function get_row($query, $output = ARRAY_A) { return array('request_count' => 0); }
            public function prepare($query, ...$args) { return $query; }
            public function insert($table, $data, $format) { return true; }
            public function update($table, $data, $where, $format, $where_format) { return true; }
        };
        if (!function_exists('dbDelta')) {
            function dbDelta($sql) { return true; }
        }
        if (!function_exists('get_option')) {
            function get_option($key) { return null; }
        }
    }

    public function test_feature_flag_can_disable_enrichment() {
        putenv('PLACES_ENRICHMENT_ENABLED=false');
        $svc = DB\Util\Places_Enrichment_Service::get_instance();
        $result = $svc->request_place_details('dummy', array());
        $this->assertIsArray($result);
        $this->assertFalse($result['enriched']);
        $this->assertSame('feature_flag_disabled', $result['reason']);
        putenv('PLACES_ENRICHMENT_ENABLED');
    }

    public function test_daily_cap_reads_env() {
        putenv('MAX_PLACES_REQUESTS_PER_DAY=123');
        $svc = DB\Util\Places_Enrichment_Service::get_instance();
        $this->assertSame(123, $svc->get_daily_cap());
        putenv('MAX_PLACES_REQUESTS_PER_DAY');
    }

    public function test_recent_days_default() {
        putenv('PLACES_ENRICHMENT_CACHE_DAYS');
        $svc = DB\Util\Places_Enrichment_Service::get_instance();
        $this->assertSame(7, $svc->get_recent_days());
    }
}

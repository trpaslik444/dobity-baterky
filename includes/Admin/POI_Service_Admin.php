<?php
/**
 * Admin rozhraní pro konfiguraci POI microservice
 */

namespace DB\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class POI_Service_Admin {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_db_poi_service_test', array($this, 'handle_test_connection'));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'POI Microservice',
            'POI Microservice',
            'manage_options',
            'db-poi-service',
            array($this, 'render_page')
        );
    }

    public function register_settings() {
        register_setting('db_poi_service_settings', 'db_poi_service_url', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_url'),
            'default' => '', // Prázdné - musí být explicitně nastaveno
        ));

        register_setting('db_poi_service_settings', 'db_poi_service_timeout', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_timeout'),
            'default' => 30,
        ));

        register_setting('db_poi_service_settings', 'db_poi_service_max_retries', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_max_retries'),
            'default' => 3,
        ));
    }

    public function sanitize_url($url) {
        $url = trim($url);
        
        $url = esc_url_raw($url);
        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
            add_settings_error(
                'db_poi_service_url',
                'invalid_url',
                'Neplatná URL adresa POI microservice'
            );
            return get_option('db_poi_service_url', '');
        }
        return rtrim($url, '/');
    }

    /**
     * P2: Sanitizace timeout hodnoty (5-300 sekund)
     */
    public function sanitize_timeout($timeout) {
        $timeout = (int) $timeout;
        if ($timeout < 5) {
            add_settings_error(
                'db_poi_service_timeout',
                'invalid_timeout',
                'Timeout musí být minimálně 5 sekund'
            );
            return 5;
        }
        if ($timeout > 300) {
            add_settings_error(
                'db_poi_service_timeout',
                'invalid_timeout',
                'Timeout musí být maximálně 300 sekund'
            );
            return 300;
        }
        return $timeout;
    }

    /**
     * P2: Sanitizace max_retries hodnoty (1-10)
     */
    public function sanitize_max_retries($max_retries) {
        $max_retries = (int) $max_retries;
        if ($max_retries < 1) {
            add_settings_error(
                'db_poi_service_max_retries',
                'invalid_max_retries',
                'Maximální počet pokusů musí být minimálně 1'
            );
            return 1;
        }
        if ($max_retries > 10) {
            add_settings_error(
                'db_poi_service_max_retries',
                'invalid_max_retries',
                'Maximální počet pokusů musí být maximálně 10'
            );
            return 10;
        }
        return $max_retries;
    }

    public function handle_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_die('Nemáte oprávnění k této akci');
        }

        check_admin_referer('db_poi_service_test');

        if (!class_exists('DB\\Services\\POI_Microservice_Client')) {
            require_once dirname(dirname(__FILE__)) . '/Services/POI_Microservice_Client.php';
        }

        $client = \DB\Services\POI_Microservice_Client::get_instance();
        
        // Test s Prahou (50.0755, 14.4378)
        $result = $client->get_nearby_pois(50.0755, 14.4378, 2000, 1, false);

        if (is_wp_error($result)) {
            wp_redirect(add_query_arg(array(
                'page' => 'db-poi-service',
                'test_result' => 'error',
                'test_message' => urlencode($result->get_error_message()),
            ), admin_url('tools.php')));
        } else {
            $count = isset($result['pois']) ? count($result['pois']) : 0;
            wp_redirect(add_query_arg(array(
                'page' => 'db-poi-service',
                'test_result' => 'success',
                'test_message' => urlencode(sprintf('Úspěšně připojeno! Nalezeno %d POIs.', $count)),
            ), admin_url('tools.php')));
        }
        exit;
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Nemáte oprávnění k této stránce');
        }

        // Zobrazit výsledek testu
        $test_result = isset($_GET['test_result']) ? $_GET['test_result'] : null;
        $test_message = isset($_GET['test_message']) ? urldecode($_GET['test_message']) : null;

        // Načíst statistiky
        $stats = get_option('db_poi_sync_stats', array(
            'total_synced' => 0,
            'total_failed' => 0,
            'last_sync' => null,
        ));

        ?>
        <div class="wrap">
            <h1>POI Microservice Konfigurace</h1>

            <?php if ($test_result): ?>
                <div class="notice notice-<?php echo $test_result === 'success' ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($test_message); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('db_poi_service_settings'); ?>
                <?php do_settings_sections('db_poi_service_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="opentripmap_api_key">OpenTripMap API Key (volitelné)</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="opentripmap_api_key" 
                                   name="opentripmap_api_key" 
                                   value="<?php echo esc_attr(get_option('opentripmap_api_key', '')); ?>" 
                                   class="regular-text"
                                   placeholder="Zadejte OpenTripMap API key (volitelné)" />
                            <p class="description">
                                <strong>Volitelné!</strong> WordPress funguje i bez OpenTripMap API key - použije pouze Wikidata (zdarma, nevyžaduje registraci).<br>
                                Pokud máte OpenTripMap API key, zadejte ho pro více POIs. Získejte na <a href="https://opentripmap.io/docs" target="_blank">opentripmap.io</a>.<br>
                                <strong>Bez API key:</strong> WordPress automaticky stáhne POIs z Wikidata při hledání nearby POIs.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="db_poi_service_url">POI Microservice URL (volitelné)</label>
                        </th>
                        <td>
                            <?php
                            $current_url = get_option('db_poi_service_url', '');
                            $is_constant = defined('DB_POI_SERVICE_URL');
                            if ($is_constant) {
                                $current_url = DB_POI_SERVICE_URL;
                            }
                            ?>
                            <input type="url" 
                                   id="db_poi_service_url" 
                                   name="db_poi_service_url" 
                                   value="<?php echo esc_attr($current_url); ?>" 
                                   class="regular-text"
                                   placeholder="https://poi-api.your-site.com (volitelné)"
                                   <?php echo $is_constant ? 'readonly' : ''; ?> />
                            <p class="description">
                                <strong>Pouze pokud používáte samostatný Node.js POI microservice.</strong><br>
                                Pokud máte nastavený OpenTripMap API key výše, <strong>není potřeba</strong> - WordPress stáhne POIs přímo z free zdrojů.<br>
                                <?php if ($is_constant): ?>
                                    <strong>Nastaveno pomocí konstanty <code>DB_POI_SERVICE_URL</code> v <code>wp-config.php</code>.</strong>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="db_poi_service_timeout">Timeout (sekundy)</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="db_poi_service_timeout" 
                                   name="db_poi_service_timeout" 
                                   value="<?php echo esc_attr(get_option('db_poi_service_timeout', 30)); ?>" 
                                   min="5" 
                                   max="300" 
                                   class="small-text" />
                            <p class="description">
                                Timeout pro HTTP requesty k POI microservice (5-300 sekund).
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="db_poi_service_max_retries">Maximální počet pokusů</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="db_poi_service_max_retries" 
                                   name="db_poi_service_max_retries" 
                                   value="<?php echo esc_attr(get_option('db_poi_service_max_retries', 3)); ?>" 
                                   min="1" 
                                   max="10" 
                                   class="small-text" />
                            <p class="description">
                                Počet pokusů při selhání requestu (1-10). Používá exponential backoff.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2>Test připojení</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('db_poi_service_test'); ?>
                <input type="hidden" name="action" value="db_poi_service_test" />
                <p>
                    <input type="submit" class="button button-secondary" value="Testovat připojení" />
                    <span class="description">Otestuje připojení k POI microservice pomocí testovacího dotazu (Praha, 2km radius).</span>
                </p>
            </form>

            <hr>

            <h2>Statistiky synchronizace</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Metrika</th>
                        <th>Hodnota</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Celkem synchronizováno POIs</strong></td>
                        <td><?php echo number_format($stats['total_synced'], 0, ',', ' '); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Celkem selhalo</strong></td>
                        <td><?php echo number_format($stats['total_failed'], 0, ',', ' '); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Poslední synchronizace</strong></td>
                        <td><?php echo $stats['last_sync'] ? esc_html($stats['last_sync']) : 'Nikdy'; ?></td>
                    </tr>
                </tbody>
            </table>

            <?php if (defined('DB_POI_SERVICE_URL')): ?>
                <div class="notice notice-info">
                    <p><strong>Poznámka:</strong> URL je nastaveno pomocí konstanty <code>DB_POI_SERVICE_URL</code> v <code>wp-config.php</code> a nelze ho změnit zde.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}


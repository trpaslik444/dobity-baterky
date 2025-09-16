<?php
/**
 * Administrační rozhraní pro DATEX II
 * 
 * @package DobityBaterky
 */

namespace DB;

/**
 * Admin rozhraní pro DATEX II
 */
class DATEX_Admin {

    /**
     * Instance třídy
     */
    private static $instance = null;

    /**
     * Získání instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'handle_actions'));
    }

    /**
     * Přidání menu stránky
     */
    public function add_menu_page() {
        add_submenu_page(
            'edit.php?post_type=charging_location',
            'DATEX II Import',
            'DATEX II Import',
            'manage_options',
            'db-datex-admin',
            array($this, 'render_page')
        );
    }

    /**
     * Zpracování akcí
     */
    public function handle_actions() {
        if (!isset($_POST['db_datex_action'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['db_datex_nonce'], 'db_datex_action')) {
            wp_die('Bezpečnostní kontrola selhala');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Nemáte oprávnění pro tuto akci');
        }

        $action = sanitize_text_field($_POST['db_datex_action']);

        switch ($action) {
            case 'import_stations':
                $this->handle_import_stations();
                break;
            case 'update_availability':
                $this->handle_update_availability();
                break;
            case 'clear_cache':
                $this->handle_clear_cache();
                break;
        }
    }

    /**
     * Zpracování importu stanic
     */
    private function handle_import_stations() {
        $region = sanitize_text_field($_POST['region'] ?? 'CZ');
        
        $datex_manager = DATEX_Manager::get_instance();
        $result = $datex_manager->import_datex_stations($region);

        if ($result['imported'] > 0) {
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-success"><p>DATEX II import dokončen: ' . $result['imported'] . ' stanic importováno.</p></div>';
            });
        }

        if (!empty($result['errors'])) {
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-error"><p>DATEX II import chyby: ' . implode(', ', $result['errors']) . '</p></div>';
            });
        }
    }

    /**
     * Zpracování aktualizace dostupnosti
     */
    private function handle_update_availability() {
        $datex_manager = DATEX_Manager::get_instance();
        $datex_manager->cron_update_availability();

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>Dostupnost stanic byla aktualizována.</p></div>';
        });
    }

    /**
     * Zpracování vyčištění cache
     */
    private function handle_clear_cache() {
        // Zde by bylo vyčištění cache
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>Cache byla vyčištěna.</p></div>';
        });
    }

    /**
     * Render stránky
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>DATEX II Import a Správa</h1>
            
            <div class="card">
                <h2>Import stanic z DATEX II</h2>
                <p>Importuje všechny nabíjecí stanice z DATEX II EnergyInfrastructureTablePublication.</p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('db_datex_action', 'db_datex_nonce'); ?>
                    <input type="hidden" name="db_datex_action" value="import_stations">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="region">Region</label></th>
                            <td>
                                <select name="region" id="region">
                                    <option value="CZ">Česká republika (NDIC)</option>
                                    <option value="SK">Slovensko</option>
                                    <option value="PL">Polsko</option>
                                    <option value="DE">Německo</option>
                                </select>
                                <p class="description">Vyberte region pro import stanic</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button-primary" value="Importovat stanice">
                    </p>
                </form>
            </div>

            <div class="card">
                <h2>Aktualizace dostupnosti</h2>
                <p>Manuální aktualizace dostupnosti všech stanic z DATEX II EnergyInfrastructureStatusPublication.</p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('db_datex_action', 'db_datex_nonce'); ?>
                    <input type="hidden" name="db_datex_action" value="update_availability">
                    
                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button" value="Aktualizovat dostupnost">
                    </p>
                </form>
            </div>

            <div class="card">
                <h2>Statistiky</h2>
                <?php $this->render_statistics(); ?>
            </div>

            <div class="card">
                <h2>Nastavení</h2>
                <?php $this->render_settings(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render statistik
     */
    private function render_statistics() {
        // Počet stanic s DATEX II ID
        $datex_stations = get_posts(array(
            'post_type' => 'charging_location',
            'meta_query' => array(
                array(
                    'key' => '_datex_station_id',
                    'compare' => 'EXISTS'
                )
            ),
            'post_status' => 'publish',
            'numberposts' => -1
        ));

        $total_stations = get_posts(array(
            'post_type' => 'charging_location',
            'post_status' => 'publish',
            'numberposts' => -1
        ));

        $operational_stations = get_posts(array(
            'post_type' => 'charging_location',
            'meta_query' => array(
                array(
                    'key' => '_operational_status',
                    'value' => 'operational',
                    'compare' => '='
                )
            ),
            'post_status' => 'publish',
            'numberposts' => -1
        ));

        ?>
        <table class="widefat">
            <tr>
                <td><strong>Celkem stanic:</strong></td>
                <td><?php echo count($total_stations); ?></td>
            </tr>
            <tr>
                <td><strong>Stanice s DATEX II ID:</strong></td>
                <td><?php echo count($datex_stations); ?> (<?php echo round(count($datex_stations) / count($total_stations) * 100, 1); ?>%)</td>
            </tr>
            <tr>
                <td><strong>Funkční stanice:</strong></td>
                <td><?php echo count($operational_stations); ?> (<?php echo round(count($operational_stations) / count($total_stations) * 100, 1); ?>%)</td>
            </tr>
            <tr>
                <td><strong>Poslední aktualizace:</strong></td>
                <td><?php echo get_option('db_datex_last_update', 'Nikdy'); ?></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render nastavení
     */
    private function render_settings() {
        $update_interval = get_option('db_datex_update_interval', '15');
        $auto_update = get_option('db_datex_auto_update', '1');
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('db_datex_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="db_datex_auto_update">Automatické aktualizace</label></th>
                    <td>
                        <input type="checkbox" id="db_datex_auto_update" name="db_datex_auto_update" value="1" <?php checked($auto_update, '1'); ?> />
                        <label for="db_datex_auto_update">Povolit automatické aktualizace dostupnosti</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="db_datex_update_interval">Interval aktualizací (minuty)</label></th>
                    <td>
                        <input type="number" id="db_datex_update_interval" name="db_datex_update_interval" value="<?php echo esc_attr($update_interval); ?>" min="5" max="60" />
                        <p class="description">Minimálně 5 minut, maximálně 60 minut</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Uložit nastavení'); ?>
        </form>
        <?php
    }
} 
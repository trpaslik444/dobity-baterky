<?php
/**
 * Nearby Places Settings - Admin stránka pro routing konfiguraci
 * @package DobityBaterky
 */

namespace DB\Admin;

class Nearby_Settings {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Nearby Settings',
            'Nearby Settings',
            'manage_options',
            'db-nearby-settings',
            array($this, 'render_settings_page')
        );
    }
    
    public function register_settings() {
        register_setting('db_nearby_settings', 'db_nearby_config', array($this, 'validate_settings'));
    }
    
    public function validate_settings($input) {
        $sanitized = array();
        
        // Provider
        $sanitized['provider'] = in_array($input['provider'] ?? '', ['ors', 'osrm']) ? $input['provider'] : 'ors';
        
        // ORS API key
        $sanitized['ors_api_key'] = sanitize_text_field($input['ors_api_key'] ?? '');
        // Ulož i do samostatného option pro snadné čtení mimo konfiguraci
        update_option('db_ors_api_key', $sanitized['ors_api_key']);
        
        // Radiusy
        $sanitized['radius_poi_for_charger'] = max(0.1, min(50, floatval($input['radius_poi_for_charger'] ?? 5)));
        $sanitized['radius_charger_for_poi'] = max(0.1, min(50, floatval($input['radius_charger_for_poi'] ?? 5)));
        
        // Matrix batch size
        $sanitized['matrix_batch_size'] = max(1, min(100, intval($input['matrix_batch_size'] ?? 24)));
        
        // Max kandidátů
        $sanitized['max_candidates'] = max(10, min(500, intval($input['max_candidates'] ?? 24)));
        
        // TTL cache (dny)
        $sanitized['cache_ttl_days'] = max(1, min(365, intval($input['cache_ttl_days'] ?? 21)));
        
        // Denní kvóty
        $sanitized['max_jobs_per_day'] = max(1, min(1000, intval($input['max_jobs_per_day'] ?? 100)));
        $sanitized['max_pairs_per_day'] = max(10, min(10000, intval($input['max_pairs_per_day'] ?? 1000)));
        
        // Lazy přepínače
        $sanitized['lazy_prompt_on_click'] = !empty($input['lazy_prompt_on_click']) ? 1 : 0;
        $sanitized['auto_enqueue_on_get'] = !empty($input['auto_enqueue_on_get']) ? 1 : 0;
        
        return $sanitized;
    }
    
    public function render_settings_page() {
        $config = get_option('db_nearby_config', array(
            'provider' => 'ors',
            'ors_api_key' => '',
            'radius_poi_for_charger' => 5,
            'radius_charger_for_poi' => 5,
            'matrix_batch_size' => 24,
            'max_candidates' => 24,
            'cache_ttl_days' => 21,
            'max_jobs_per_day' => 100,
            'max_pairs_per_day' => 1000,
            'lazy_prompt_on_click' => 1,
            'auto_enqueue_on_get' => 0
        ));
        ?>
        <div class="wrap">
            <h1>Nearby Places Settings</h1>
            <nav class="nav-tab-wrapper" style="margin-top: 10px;">
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-icon-admin') ); ?>" class="nav-tab">
                    Správa ikon
                </a>
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-nearby-queue') ); ?>" class="nav-tab">
                    Nearby Queue
                </a>
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-nearby-settings') ); ?>" class="nav-tab nav-tab-active">
                    Nearby Settings
                </a>
            </nav>
            <p>Konfigurace pro walking distance routing a cache.</p>
            
            <form method="post" action="options.php">
                <?php settings_fields('db_nearby_settings'); ?>
                <?php wp_nonce_field('db_nearby_settings_save', 'db_nearby_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Routing Provider</th>
                        <td>
                            <select name="db_nearby_config[provider]">
                                <option value="ors" <?php selected($config['provider'], 'ors'); ?>>OpenRouteService</option>
                                <option value="osrm" <?php selected($config['provider'], 'osrm'); ?>>OSRM (self-hosted)</option>
                            </select>
                            <p class="description">OpenRouteService doporučeno pro produkci.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">ORS API Key</th>
                        <td>
                            <input type="text" name="db_nearby_config[ors_api_key]" value="<?php echo esc_attr($config['ors_api_key']); ?>" class="regular-text" />
                            <p class="description">Získejte na <a href="https://openrouteservice.org/dev/#/signup" target="_blank">openrouteservice.org</a></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Radius POI pro nabíječky (km)</th>
                        <td>
                            <input type="number" name="db_nearby_config[radius_poi_for_charger]" value="<?php echo esc_attr($config['radius_poi_for_charger']); ?>" step="0.1" min="0.1" max="50" />
                            <p class="description">Hledání POI v okolí nabíječek.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Radius nabíječky pro POI (km)</th>
                        <td>
                            <input type="number" name="db_nearby_config[radius_charger_for_poi]" value="<?php echo esc_attr($config['radius_charger_for_poi']); ?>" step="0.1" min="0.1" max="50" />
                            <p class="description">Hledání nabíječek v okolí POI.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Matrix Batch Size</th>
                        <td>
                            <input type="number" name="db_nearby_config[matrix_batch_size]" value="<?php echo esc_attr($config['matrix_batch_size']); ?>" min="1" max="100" />
                            <p class="description">Počet cílů v jedné Matrix API dávce (max 24 doporučeno pro dynamic arguments).</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Max kandidátů na origin</th>
                        <td>
                            <input type="number" name="db_nearby_config[max_candidates]" value="<?php echo esc_attr($config['max_candidates']); ?>" min="10" max="500" />
                            <p class="description">Maximální počet kandidátů pro routing (prefiltr vzduchem).</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Cache TTL (dny)</th>
                        <td>
                            <input type="number" name="db_nearby_config[cache_ttl_days]" value="<?php echo esc_attr($config['cache_ttl_days']); ?>" min="1" max="365" />
                            <p class="description">Platnost cache; v lazy režimu invalidujeme změnou v okolí.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Lazy dialog při kliku</th>
                        <td>
                            <label><input type="checkbox" name="db_nearby_config[lazy_prompt_on_click]" value="1" <?php checked(!empty($config['lazy_prompt_on_click'])); ?> /> Zobrazit dialog a nabídnout výpočet Nearby</label>
                            <p class="description">Pokud bod nemá cache, po kliku se uživatele zeptat a spustit sync výpočet.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto enqueue v GET</th>
                        <td>
                            <label><input type="checkbox" name="db_nearby_config[auto_enqueue_on_get]" value="1" <?php checked(!empty($config['auto_enqueue_on_get'])); ?> /> Povolit tiché spouštění přepočtu v GET /nearby</label>
                            <p class="description">Doporučeno vypnuto v lazy režimu; FE vyvolá POST /nearby/recompute.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Uložit nastavení'); ?>
            </form>
            
            <h2>Cache Management</h2>
            <p>
                <button type="button" class="button" onclick="clearNearbyCache()">Vymazat všechny cache</button>
                <span id="cache-status"></span>
            </p>
            
            <script>
            function clearNearbyCache() {
                if (!confirm('Opravdu chcete vymazat všechny nearby cache?')) return;
                
                fetch('/wp-json/db/v1/nearby/clear-cache', {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>',
                        'Content-Type': 'application/json'
                    }
                })
                .then(r => r.json())
                .then(data => {
                    document.getElementById('cache-status').innerHTML = data.success ? 
                        '<span style="color: green;">✓ Cache vymazána</span>' : 
                        '<span style="color: red;">✗ Chyba: ' + data.message + '</span>';
                })
                .catch(e => {
                    document.getElementById('cache-status').innerHTML = '<span style="color: red;">✗ Chyba: ' + e.message + '</span>';
                });
            }
            </script>
        </div>
        <?php
    }
}

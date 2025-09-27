<?php
/**
 * Nearby Queue Admin - Admin rozhraní pro správu fronty nearby bodů
 * @package DobityBaterky
 */

namespace DB\Admin;

use DB\Jobs\Nearby_Queue_Manager;
use DB\Jobs\Nearby_Batch_Processor;
use DB\Jobs\Nearby_Auto_Processor;
use DB\Jobs\API_Quota_Manager;

class Nearby_Queue_Admin {
    
    private $queue_manager;
    private $processed_manager;
    private $batch_processor;
    private $auto_processor;
    private $quota_manager;
    
    public function __construct() {
        $this->queue_manager = new Nearby_Queue_Manager();
        $this->processed_manager = new \DB\Jobs\Nearby_Processed_Manager();
        $this->batch_processor = new Nearby_Batch_Processor();
        $this->auto_processor = new Nearby_Auto_Processor();
        $this->quota_manager = new API_Quota_Manager();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_db_process_nearby_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_db_enqueue_all_points', array($this, 'ajax_enqueue_all_points'));
        add_action('wp_ajax_db_reset_failed_items', array($this, 'ajax_reset_failed_items'));
        add_action('wp_ajax_db_cleanup_old_items', array($this, 'ajax_cleanup_old_items'));
        add_action('wp_ajax_db_get_queue_details', array($this, 'ajax_get_queue_details'));
        add_action('wp_ajax_db_set_priority', array($this, 'ajax_set_priority'));
        add_action('wp_ajax_db_move_to_front', array($this, 'ajax_move_to_front'));
        add_action('wp_ajax_db_toggle_auto_processing', array($this, 'ajax_toggle_auto_processing'));
        add_action('wp_ajax_db_trigger_auto_processing', array($this, 'ajax_trigger_auto_processing'));
        add_action('wp_ajax_db_test_token_bucket', array($this, 'ajax_test_token_bucket'));
        add_action('wp_ajax_db_test_ors_headers', array($this, 'ajax_test_ors_headers'));
        
        // Inicializovat hooky pro automatické zařazování do fronty
        $this->queue_manager->init_hooks();
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            "tools.php", // Parent slug - Tools menu
            __( "Nearby Queue", "dobity-baterky" ),
            __( "Nearby Queue", "dobity-baterky" ),
            "manage_options",
            "db-nearby-queue",
            array( $this, "render_queue_page" )
        );
        
        add_submenu_page(
            "tools.php", // Parent slug - Tools menu
            __( "Nearby Processed", "dobity-baterky" ),
            __( "Nearby Processed", "dobity-baterky" ),
            "manage_options",
            "db-nearby-processed",
            array( $this, "render_processed_page" )
        );
        
        add_submenu_page(
            "tools.php", // Parent slug - Tools menu
            __( "Isochrones Settings", "dobity-baterky" ),
            __( "Isochrones Settings", "dobity-baterky" ),
            "manage_options",
            "db-isochrones-settings",
            array( $this, "render_isochrones_settings_page" )
        );
    }
    
    public function render_isochrones_settings_page() {
        // Zpracovat POST požadavek
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['isochrones_nonce'], 'isochrones_settings')) {
            $isochrones_enabled = isset($_POST['isochrones_enabled']) ? 1 : 0;
            $walking_speed = floatval($_POST['walking_speed']);
            $display_times = array_map('intval', $_POST['display_times']);
            
            // Uložit nastavení
            update_option('db_isochrones_settings', [
                'enabled' => $isochrones_enabled,
                'walking_speed_kmh' => $walking_speed,
                'display_times_min' => $display_times
            ]);
            
            echo '<div class="notice notice-success"><p>Nastavení isochrones uloženo!</p></div>';
        }
        
        // Načíst aktuální nastavení
        $settings = get_option('db_isochrones_settings', [
            'enabled' => 1,
            'walking_speed_kmh' => 4.5,
            'display_times_min' => [10, 20, 30]
        ]);
        ?>
        <div class="wrap">
            <h1>Isochrones Settings</h1>
            <nav class="nav-tab-wrapper" style="margin-top: 10px;">
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-icon-admin') ); ?>" class="nav-tab">
                    Správa ikon
                </a>
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-nearby-queue') ); ?>" class="nav-tab">
                    Nearby Queue
                </a>
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-nearby-settings') ); ?>" class="nav-tab">
                    Nearby Settings
                </a>
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-isochrones-settings') ); ?>" class="nav-tab nav-tab-active">
                    Isochrones Settings
                </a>
            </nav>
            
            <div class="notice notice-info" style="margin: 20px 0; padding: 15px; background: #e7f3ff; border-left: 4px solid #0073aa;">
                <h3 style="margin: 0 0 10px 0; color: #0073aa;">ℹ️ Isochrones (Dochozí okruhy)</h3>
                <p style="margin: 0; color: #0073aa;">
                    Isochrones zobrazují oblasti dostupné za určitý čas pěší chůze. Můžete upravit rychlost chůze pro přesnější odhady.
                </p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('isochrones_settings', 'isochrones_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Zapnout isochrones</th>
                        <td>
                            <label>
                                <input type="checkbox" name="isochrones_enabled" value="1" <?php checked($settings['enabled'], 1); ?>>
                                Zobrazovat isochrones na mapě
                            </label>
                            <p class="description">Když je zapnuto, budou se zobrazovat dochozí okruhy při kliknutí na bod.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Rychlost chůze</th>
                        <td>
                            <input type="number" name="walking_speed" value="<?php echo esc_attr($settings['walking_speed_kmh']); ?>" 
                                   min="1" max="10" step="0.1" style="width: 80px;"> km/h
                            <p class="description">
                                Standardní rychlost chůze je 4.5 km/h. Můžete ji upravit pro přesnější odhady:
                                <br>• Pomalejší chůze: 3.0-4.0 km/h
                                <br>• Rychlejší chůze: 5.0-6.0 km/h
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Zobrazované časy</th>
                        <td>
                            <input type="number" name="display_times[]" value="<?php echo esc_attr($settings['display_times_min'][0]); ?>" 
                                   min="5" max="60" step="5" style="width: 60px;"> min
                            <input type="number" name="display_times[]" value="<?php echo esc_attr($settings['display_times_min'][1]); ?>" 
                                   min="5" max="60" step="5" style="width: 60px;"> min
                            <input type="number" name="display_times[]" value="<?php echo esc_attr($settings['display_times_min'][2]); ?>" 
                                   min="5" max="60" step="5" style="width: 60px;"> min
                            <p class="description">Časy, které se zobrazí v legendě isochrones (např. ~10 min, ~20 min, ~30 min).</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Uložit nastavení">
                </p>
            </form>
            
            <div class="notice notice-warning" style="margin: 20px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107;">
                <h3 style="margin: 0 0 10px 0; color: #856404;">⚠️ Poznámka k přesnosti</h3>
                <p style="margin: 0; color: #856404;">
                    Isochrones jsou založeny na standardní rychlosti chůze a neberou v úvahu individuální faktory jako:
                    <br>• Fyzická kondice uživatele
                    <br>• Terén (kopce, schody, chodníky)
                    <br>• Počasí a povrch
                    <br>• Zatížení (nákup, kufr)
                    <br><br>
                    <strong>Doporučujeme brát časy jako orientační!</strong>
                </p>
            </div>
        </div>
        <?php
    }
    
    public function render_queue_page() {
        $stats = $this->queue_manager->get_stats();
        $auto_status = $this->auto_processor->get_auto_status();
        $quota_stats = $this->quota_manager->get_usage_stats();
        ?>
        <div class="wrap">
            <h1>Nearby Queue Management</h1>
            <nav class="nav-tab-wrapper" style="margin-top: 10px;">
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-icon-admin') ); ?>" class="nav-tab">
                    Správa ikon
                </a>
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-nearby-queue') ); ?>" class="nav-tab nav-tab-active">
                    Nearby Queue
                </a>
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-nearby-settings') ); ?>" class="nav-tab">
                    Nearby Settings
                </a>
                <a href="<?php echo esc_url( admin_url('tools.php?page=db-isochrones-settings') ); ?>" class="nav-tab">
                    Isochrones Settings
                </a>
            </nav>
            <p>Správa fronty pro batch zpracování nearby bodů.</p>
            
            <div class="notice notice-info" style="margin: 20px 0; padding: 15px; background: #e7f3ff; border-left: 4px solid #0073aa;">
                <h3 style="margin: 0 0 10px 0; color: #0073aa;">ℹ️ Jak systém funguje</h3>
                <p style="margin: 0; color: #0073aa;">
                    <strong>Proces 1 (zdarma):</strong> Body se automaticky přidávají do fronty při uložení/změně.<br>
                    <strong>Proces 2 (stojí peníze):</strong> API zpracování fronty se spouští pouze na povolení admina.
                </p>
            </div>
            
            <?php if (!$auto_status['auto_enabled']): ?>
            <div class="notice notice-info" style="margin: 20px 0; padding: 15px; background: #d1ecf1; border-left: 4px solid #17a2b8;">
                <h3 style="margin: 0 0 10px 0; color: #0c5460;">ℹ️ Automatické zpracování vypnuto</h3>
                <p style="margin: 0; color: #0c5460;">
                    Automatické zpracování fronty je vypnuto. Můžete ho zapnout pomocí tlačítka níže 
                    nebo spouštět zpracování ručně.
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Statistiky -->
            <div class="db-queue-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                <div class="db-stat-card" style="background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center;">
                    <h3 style="margin: 0 0 10px 0; color: #495057;">Celkem</h3>
                    <div id="db-stat-total" style="font-size: 2em; font-weight: bold; color: #007cba;"><?php echo $stats->total; ?></div>
                </div>
                <div class="db-stat-card" style="background: #fff3cd; padding: 20px; border-radius: 8px; text-align: center;">
                    <h3 style="margin: 0 0 10px 0; color: #856404;">Čekající</h3>
                    <div id="db-stat-pending" style="font-size: 2em; font-weight: bold; color: #ffc107;"><?php echo $stats->pending; ?></div>
                </div>
                <div class="db-stat-card" style="background: #d1ecf1; padding: 20px; border-radius: 8px; text-align: center;">
                    <h3 style="margin: 0 0 10px 0; color: #0c5460;">Zpracovává se</h3>
                    <div id="db-stat-processing" style="font-size: 2em; font-weight: bold; color: #17a2b8;"><?php echo $stats->processing; ?></div>
                </div>
                <div class="db-stat-card" style="background: #d4edda; padding: 20px; border-radius: 8px; text-align: center;">
                    <h3 style="margin: 0 0 10px 0; color: #155724;">Dokončené</h3>
                    <div id="db-stat-completed" style="font-size: 2em; font-weight: bold; color: #28a745;"><?php echo $stats->completed; ?></div>
                </div>
                <div class="db-stat-card" style="background: #f8d7da; padding: 20px; border-radius: 8px; text-align: center;">
                    <h3 style="margin: 0 0 10px 0; color: #721c24;">Chyby</h3>
                    <div id="db-stat-failed" style="font-size: 2em; font-weight: bold; color: #dc3545;"><?php echo $stats->failed; ?></div>
                </div>
            </div>
            
            <!-- API Kvóty -->
            <div class="db-api-quotas" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                <h2>API Kvóty</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div style="background: white; padding: 15px; border-radius: 6px;">
                        <h4 style="margin: 0 0 8px 0;">Provider</h4>
                        <div style="font-size: 1.2em; font-weight: bold; color: #007cba;"><?php echo esc_html($quota_stats['provider']); ?></div>
                    </div>
                    <div style="background: white; padding: 15px; border-radius: 6px;">
                        <h4 style="margin: 0 0 8px 0;">Denní použití</h4>
                        <div style="font-size: 1.2em; font-weight: bold; color: #28a745;"><?php echo ($quota_stats['max_daily'] - $quota_stats['remaining']); ?> / <?php echo $quota_stats['max_daily']; ?></div>
                    </div>
                    <div style="background: white; padding: 15px; border-radius: 6px;">
                        <h4 style="margin: 0 0 8px 0;">Zbývá</h4>
                        <div style="font-size: 1.2em; font-weight: bold; color: <?php echo $quota_stats['remaining'] > 0 ? '#28a745' : '#dc3545'; ?>"><?php echo $quota_stats['remaining']; ?></div>
                    </div>
                    <div style="background: white; padding: 15px; border-radius: 6px;">
                        <h4 style="margin: 0 0 8px 0;">Stav</h4>
                        <div style="font-size: 1.2em; font-weight: bold; color: <?php echo $quota_stats['can_process'] ? '#28a745' : '#dc3545'; ?>">
                            <?php 
                            if ($quota_stats['can_process']) {
                                echo '✓ Může pokračovat';
                            } else {
                                if ($quota_stats['remaining'] <= 0) {
                                    echo '✗ Kvóty vyčerpány';
                                } else {
                                    echo '✗ Čeká na reset';
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div style="background: white; padding: 15px; border-radius: 6px;">
                        <h4 style="margin: 0 0 8px 0;">Zdroj kvót</h4>
                        <div style="font-size: 1.1em; font-weight: bold; color: #6c757d;">
                            <?php echo isset($quota_stats['source']) && $quota_stats['source'] === 'headers' ? '🌐 Headers' : '📋 Fallback'; ?>
                            <?php if (isset($quota_stats['per_minute'])): ?>
                                <br><small><?php echo $quota_stats['per_minute']; ?> / min</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (isset($quota_stats['reset_at']) && $quota_stats['reset_at']): ?>
                    <div style="background: white; padding: 15px; border-radius: 6px;">
                        <h4 style="margin: 0 0 8px 0;">Reset kvót</h4>
                        <div style="font-size: 1.1em; font-weight: bold; color: #6c757d;">
                            <?php 
                            $reset_time = strtotime($quota_stats['reset_at']);
                            $time_diff = $reset_time - time();
                            if ($time_diff > 0) {
                                $hours = floor($time_diff / 3600);
                                $minutes = floor(($time_diff % 3600) / 60);
                                echo "Za {$hours}h {$minutes}m";
                            } else {
                                echo "Nyní";
                            }
                            ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Automatické zpracování -->
            <div class="db-auto-processing" style="margin: 20px 0; padding: 15px; background: #e9ecef; border-radius: 8px;">
                <h2>Automatické zpracování</h2>
                <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <div style="background: white; padding: 15px; border-radius: 6px; flex: 1; min-width: 200px;">
                        <h4 style="margin: 0 0 8px 0;">Stav</h4>
                        <div id="db-auto-status-text" style="font-size: 1.1em; font-weight: bold; color: <?php echo $auto_status['auto_enabled'] ? '#28a745' : '#dc3545'; ?>">
                            <?php echo $auto_status['auto_enabled'] ? '✓ Zapnuto' : '✗ Vypnuto'; ?>
                        </div>
                        <div id="db-auto-next-run" style="font-size: 0.9em; color: #666; margin-top: 5px; <?php echo $auto_status['next_run'] ? '' : 'display:none;'; ?>">
                            Další běh: <span id="db-auto-next-run-value"><?php echo $auto_status['next_run'] ? esc_html($auto_status['next_run']) : ''; ?></span>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="button button-primary" id="db-toggle-auto" data-enabled="<?php echo $auto_status['auto_enabled'] ? '1' : '0'; ?>">
                            <?php echo $auto_status['auto_enabled'] ? 'Vypnout automatické zpracování' : 'Zapnout automatické zpracování'; ?>
                        </button>
                        <button type="button" class="button button-secondary" id="db-trigger-auto">
                            Zpracovat 1 položku
                        </button>
                    </div>
                </div>
            </div>

            <!-- Akce -->
            <div class="db-queue-actions" style="margin: 20px 0;">
                <h2>Ruční akce</h2>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" class="button button-secondary" id="db-enqueue-all">
                        Přidat všechny body do fronty
                    </button>
                    <button type="button" class="button button-secondary" id="db-reset-failed">
                        Resetovat chybné položky
                    </button>
                    <button type="button" class="button button-secondary" id="db-cleanup-old">
                        Vyčistit staré položky
                    </button>
                </div>
            </div>
            
            <!-- Podrobnosti fronty -->
            <div class="db-queue-details" style="margin: 20px 0;">
                <h2>Podrobnosti fronty</h2>
                <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; align-items: center;">
                    <button type="button" class="button button-secondary" id="db-refresh-details">
                        Obnovit
                    </button>
                    <button type="button" class="button button-primary" id="db-process-single">
                        Zpracovat 1 položku
                    </button>
                    <button type="button" class="button button-secondary" id="db-test-token-bucket">
                        Test Token Bucket
                    </button>
                    <button type="button" class="button button-secondary" id="db-test-ors-headers">
                        Test ORS Headers
                    </button>
                    <select id="db-details-limit" style="padding: 5px;">
                        <option value="20">20 položek</option>
                        <option value="50" selected>50 položek</option>
                        <option value="100">100 položek</option>
                        <option value="200">200 položek</option>
                        <option value="500">500 položek</option>
                    </select>
                    <div style="display: flex; gap: 5px; align-items: center;">
                        <button type="button" class="button button-small" id="db-prev-page" disabled>
                            ← Předchozí
                        </button>
                        <span id="db-page-info" style="padding: 0 10px;">Stránka 1</span>
                        <button type="button" class="button button-small" id="db-next-page">
                            Další →
                        </button>
                    </div>
                </div>
                <div id="db-queue-details-table" style="background: white; border: 1px solid #ddd; border-radius: 6px; overflow: hidden;">
                    <table class="wp-list-table widefat fixed striped" style="margin: 0;">
                        <thead>
                            <tr>
                                <th style="width: 50px;">ID</th>
                                <th style="width: 200px;">Bod</th>
                                <th style="width: 100px;">Typ</th>
                                <th style="width: 80px;">Priorita</th>
                                <th style="width: 100px;">Kandidáti</th>
                                <th style="width: 100px;">Stav</th>
                                <th style="width: 120px;">Vytvořeno</th>
                                <th style="width: 100px;">Akce</th>
                            </tr>
                        </thead>
                        <tbody id="db-queue-details-body">
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 20px;">
                                    <div style="font-size: 16px; margin-bottom: 8px;">⏳</div>
                                    <div>Načítání podrobností...</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Výsledky -->
            <div id="db-queue-results" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px; display: none;">
                <h3>Výsledky</h3>
                <div id="db-queue-output"></div>
            </div>
            
            <!-- Cron info -->
            <div class="db-cron-info" style="margin: 20px 0; padding: 15px; background: #e9ecef; border-radius: 8px;">
                <h3>Automatické zpracování</h3>
                <p>Doporučujeme nastavit cron job pro automatické zpracování:</p>
                <code style="background: #fff; padding: 10px; display: block; margin: 10px 0; border-radius: 4px;">
                    # Zpracovat 500 bodů denně v 2:00<br>
                    0 2 * * * curl -s "<?php echo admin_url('admin-ajax.php'); ?>?action=db_process_nearby_batch&batch_size=500&nonce=<?php echo wp_create_nonce('db_nearby_batch'); ?>"
                </code>
                <p><strong>Nebo použít WordPress cron:</strong></p>
                <code style="background: #fff; padding: 10px; display: block; margin: 10px 0; border-radius: 4px;">
                    wp_schedule_event(time(), 'daily', 'db_nearby_daily_batch');
                </code>
            </div>
        </div>
        
        <script>
        let currentPage = 1;
        
        function loadQueueDetails() {
            const limit = parseInt(jQuery('#db-details-limit').val());
            const offset = (currentPage - 1) * limit;
            showLoading('Načítají se podrobnosti fronty...');
            
            jQuery.post(ajaxurl, {
                action: 'db_get_queue_details',
                limit: limit,
                offset: offset,
                nonce: '<?php echo wp_create_nonce('db_nearby_batch'); ?>'
            }, function(response) {
                if (response.success) {
                    renderQueueDetails(response.data.data, response.data.pagination);
                    updatePagination(response.data.pagination);
                } else {
                    showResult('Chyba při načítání podrobností', 'error');
                }
            }).fail(function() {
                showResult('Chyba při načítání podrobností', 'error');
            });
        }
        
        function renderQueueDetails(items, pagination) {
            const tbody = jQuery('#db-queue-details-body');
            tbody.empty();
            
            // Zkontrolovat, zda je items pole
            if (!Array.isArray(items)) {
                console.error('Items není pole:', items);
                tbody.html('<tr><td colspan="8" style="text-align: center; padding: 20px; color: red;">Chyba při načítání dat</td></tr>');
                return;
            }
            
            if (items.length === 0) {
                tbody.html('<tr><td colspan="8" style="text-align: center; padding: 20px;">Žádné položky ve frontě</td></tr>');
                return;
            }
            
            items.forEach(function(item) {
                const row = jQuery(`
                    <tr>
                        <td>${item.id}</td>
                        <td>${item.post_title || 'N/A'}</td>
                        <td>${item.origin_type}</td>
                        <td>${item.priority}</td>
                        <td>${item.candidates_count || 0}</td>
                        <td><span class="status-${item.status}">${item.status}</span></td>
                        <td>${new Date(item.created_at).toLocaleString()}</td>
                        <td>
                            <button class="button button-small" onclick="moveToFront(${item.id})">Na začátek</button>
                        </td>
                    </tr>
                `);
                tbody.append(row);
            });
        }
        
        function updatePagination(pagination) {
            if (!pagination) {
                console.error('Pagination není definováno:', pagination);
                return;
            }
            
            // Pagination může být objekt nebo pole
            const totalPages = pagination.total_pages || pagination['total_pages'] || 1;
            
            jQuery('#db-page-info').text(`Stránka ${currentPage} z ${totalPages}`);
            jQuery('#db-prev-page').prop('disabled', currentPage <= 1);
            jQuery('#db-next-page').prop('disabled', currentPage >= totalPages);
        }
        
        function showLoading(message) {
            jQuery('#db-queue-results').show();
            jQuery('#db-queue-output').html('<div style="color: #007cba;">⏳ ' + message + '</div>');
        }
        
        function showResult(message, type = 'success') {
            jQuery('#db-queue-results').show();
            const color = type === 'error' ? '#dc3545' : '#28a745';
            const icon = type === 'error' ? '✗' : '✓';
            jQuery('#db-queue-output').html('<div style="color: ' + color + ';">' + icon + ' ' + message + '</div>');
        }

        function renderAutoStatus(status) {
            if (!status) {
                return;
            }

            const enabled = !!status.auto_enabled;
            const $statusText = jQuery('#db-auto-status-text');
            $statusText.text(enabled ? '✓ Zapnuto' : '✗ Vypnuto');
            $statusText.css('color', enabled ? '#28a745' : '#dc3545');

            const $nextRunWrap = jQuery('#db-auto-next-run');
            const $nextRunValue = jQuery('#db-auto-next-run-value');

            if (status.next_run) {
                $nextRunValue.text(status.next_run);
                $nextRunWrap.show();
            } else {
                $nextRunValue.text('');
                $nextRunWrap.hide();
            }

            const $toggle = jQuery('#db-toggle-auto');
            $toggle.data('enabled', enabled ? '1' : '0');
            $toggle.text(enabled ? 'Vypnout automatické zpracování' : 'Zapnout automatické zpracování');
        }

        function processSingleItem() {
            showLoading('Zpracovává se 1 položka...');

            jQuery.post(ajaxurl, {
                action: 'db_trigger_auto_processing',
                nonce: '<?php echo wp_create_nonce('db_nearby_batch'); ?>'
            }, function(response) {
                const payload = response.data || {};
                const message = payload.message || (response.success
                    ? 'Položka úspěšně zpracována'
                    : 'Chyba při zpracování položky');

                let messageType = response.success ? 'success' : 'error';

                if (!response.success && (payload.processed === 0 || payload.processed === '0') && (payload.errors === 0 || payload.errors === '0') && !payload.reset_at) {
                    messageType = 'success';
                }

                showResult(message, messageType);

                if (payload.queue_stats) {
                    updateStats(payload.queue_stats);
                }

                setTimeout(() => {
                    loadQueueDetails();
                }, 500);
            }).fail(function() {
                showResult('Chyba při ručním zpracování', 'error');
            });
        }

        function updateStats(stats) {
            if (!stats) {
                return;
            }

            const normalised = {
                total: parseInt(stats.total ?? stats['total'] ?? 0, 10) || 0,
                pending: parseInt(stats.pending ?? stats['pending'] ?? 0, 10) || 0,
                processing: parseInt(stats.processing ?? stats['processing'] ?? 0, 10) || 0,
                completed: parseInt(stats.completed ?? stats['completed'] ?? 0, 10) || 0,
                failed: parseInt(stats.failed ?? stats['failed'] ?? 0, 10) || 0
            };

            jQuery('#db-stat-total').text(normalised.total);
            jQuery('#db-stat-pending').text(normalised.pending);
            jQuery('#db-stat-processing').text(normalised.processing);
            jQuery('#db-stat-completed').text(normalised.completed);
            jQuery('#db-stat-failed').text(normalised.failed);
        }
        
        function testTokenBucket() {
            showLoading('Testuje se token bucket...');
            
            jQuery.post(ajaxurl, {
                action: 'db_test_token_bucket',
                nonce: '<?php echo wp_create_nonce('db_nearby_batch'); ?>'
            }, function(response) {
                if (response.success) {
                    const data = response.data;
                    if (data && typeof data === 'object') {
                        let message = `Token Bucket Status:<br>`;
                        message += `• Povoleno: ${data.allowed ? '✓ Ano' : '✗ Ne'}<br>`;
                        if (data.allowed) {
                            message += `• Zbývající tokeny: ${data.tokens_remaining || 'N/A'}<br>`;
                        } else {
                            message += `• Čekat: ${data.wait_seconds || 'N/A'}s<br>`;
                        }
                        message += `• Bucket data: ${JSON.stringify(data.bucket_data || {})}`;
                        
                        showResult(message, data.allowed ? 'success' : 'error');
                    } else {
                        showResult('Test Token Bucket: Neplatná data', 'error');
                    }
                } else {
                    const message = response.data?.message || response.data || 'Neznámá chyba';
                    showResult(`Test Token Bucket selhal: ${message}`, 'error');
                }
            }).fail(function() {
                showResult('Chyba při testování Token Bucket', 'error');
            });
        }
        
        function testOrsHeaders() {
            showLoading('Testuje se ORS hlavičky...');
            
            jQuery.post(ajaxurl, {
                action: 'db_test_ors_headers',
                nonce: '<?php echo wp_create_nonce('db_nearby_batch'); ?>'
            }, function(response) {
                if (response.success) {
                    const data = response.data;
                    if (data && typeof data === 'object') {
                        let message = `ORS Headers Test:<br>`;
                        message += `• Status: ${data.status || 'N/A'}<br>`;
                        message += `• Code: ${data.code || 'N/A'}<br>`;
                        message += `• Headers: ${JSON.stringify(data.headers || {})}<br>`;
                        message += `• Kvóty uloženy: ${data.quotas_saved ? '✓ Ano' : '✗ Ne'}<br>`;
                        if (data.cached_quotas) {
                            message += `• Cached kvóty: ${JSON.stringify(data.cached_quotas)}`;
                        }
                        
                        showResult(message, data.status === 'ok' ? 'success' : 'error');
                        
                        // Aktualizovat stránku po testu
                        setTimeout(() => {
                            location.reload();
                        }, 3000);
                    } else {
                        showResult('Test ORS Headers: Neplatná data', 'error');
                    }
                } else {
                    const message = response.data?.message || response.data || 'Neznámá chyba';
                    showResult(`Test ORS Headers selhal: ${message}`, 'error');
                }
            }).fail(function() {
                showResult('Chyba při testování ORS Headers', 'error');
            });
        }
        
        jQuery(document).ready(function($) {
            // Automaticky načíst frontu při načtení stránky
            loadQueueDetails();

            // Přidat všechny body
            $('#db-enqueue-all').on('click', function() {
                if (confirm('Opravdu chcete přidat všechny body do fronty?')) {
                    enqueueAll();
                }
            });

            // Resetovat chybné
            $('#db-reset-failed').on('click', function() {
                if (confirm('Opravdu chcete resetovat všechny chybné položky?')) {
                    resetFailed();
                }
            });

            // Vyčistit staré
            $('#db-cleanup-old').on('click', function() {
                if (confirm('Opravdu chcete vyčistit staré dokončené položky?')) {
                    cleanupOld();
                }
            });

            // Ruční zpracování jedné položky
            $('#db-process-single').on('click', function() {
                processSingleItem();
            });

            $('#db-trigger-auto').on('click', function() {
                processSingleItem();
            });

            // Toggle automatické zpracování
            $('#db-toggle-auto').on('click', function() {
                toggleAutoProcessing();
            });

            function enqueueAll() {
                showLoading('Přidávají se všechny body do fronty...');
                $.post(ajaxurl, {
                    action: 'db_enqueue_all_points',
                    nonce: '<?php echo wp_create_nonce('db_nearby_batch'); ?>'
                }, function(response) {
                    showResult(response.data.message);
                    if (response.data.stats) {
                        updateStats(response.data.stats);
                    }
                }).fail(function() {
                    showResult('Chyba při přidávání do fronty', 'error');
                });
            }
            
            function resetFailed() {
                showLoading('Resetují se chybné položky...');
                $.post(ajaxurl, {
                    action: 'db_reset_failed_items',
                    nonce: '<?php echo wp_create_nonce('db_nearby_batch'); ?>'
                }, function(response) {
                    showResult(response.data.message);
                    if (response.data.stats) {
                        updateStats(response.data.stats);
                    }
                }).fail(function() {
                    showResult('Chyba při resetování', 'error');
                });
            }
            
            function cleanupOld() {
                showLoading('Čistí se staré položky...');
                $.post(ajaxurl, {
                    action: 'db_cleanup_old_items',
                    nonce: '<?php echo wp_create_nonce('db_nearby_batch'); ?>'
                }, function(response) {
                    showResult(response.data.message);
                    if (response.data.stats) {
                        updateStats(response.data.stats);
                    }
                }).fail(function() {
                    showResult('Chyba při čištění', 'error');
                });
            }
            
            // Nové funkce pro podrobnosti fronty
            $('#db-refresh-details').on('click', function() {
                loadQueueDetails();
            });

            $('#db-details-limit').on('change', function() {
                currentPage = 1; // Reset na první stránku při změně limitu
                loadQueueDetails();
            });
            
            $('#db-prev-page').on('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    loadQueueDetails();
                }
            });
            
            $('#db-next-page').on('click', function() {
                currentPage++;
                loadQueueDetails();
            });
            
            $('#db-test-token-bucket').on('click', function() {
                testTokenBucket();
            });

            $('#db-test-ors-headers').on('click', function() {
                testOrsHeaders();
            });

            function toggleAutoProcessing() {
                const $toggle = jQuery('#db-toggle-auto');
                const dataEnabled = $toggle.data('enabled');
                const isEnabled = dataEnabled === 1 || dataEnabled === '1';
                const action = isEnabled ? 'vypnout' : 'zapnout';

                if (!confirm(`Opravdu chcete ${action} automatické zpracování fronty?`)) {
                    return;
                }

                showLoading(`${isEnabled ? 'Vypíná' : 'Zapíná'} se automatické zpracování...`);

                $.post(ajaxurl, {
                    action: 'db_toggle_auto_processing',
                    nonce: '<?php echo wp_create_nonce('db_nearby_batch'); ?>'
                }, function(response) {
                    if (response.success) {
                        const data = response.data || {};
                        const message = data.message || `Automatické zpracování ${isEnabled ? 'vypnuto' : 'zapnuto'}`;
                        showResult(message, 'success');

                        if (data.auto_status) {
                            renderAutoStatus(data.auto_status);
                        }

                        if (data.queue_stats) {
                            updateStats(data.queue_stats);
                        }

                        setTimeout(() => {
                            loadQueueDetails();
                        }, 500);
                    } else {
                        const message = response.data?.message || 'Chyba při přepínání automatického zpracování';
                        showResult(message, 'error');
                    }
                }).fail(function() {
                    showResult('Chyba při přepínání automatického zpracování', 'error');
                });
            }

            // Globální funkce pro akce v tabulce
            window.moveToFront = function(id) {
                if (confirm('Opravdu chcete přesunout tuto položku na začátek fronty?')) {
                    $.post(ajaxurl, {
                        action: 'db_move_to_front',
                        id: id,
                        nonce: '<?php echo wp_create_nonce('db_nearby_batch'); ?>'
                    }, function(response) {
                        if (response.success) {
                            showResult(response.data.message);
                            loadQueueDetails();
                        } else {
                            showResult('Chyba při přesouvání', 'error');
                        }
                    }).fail(function() {
                        showResult('Chyba při přesouvání', 'error');
                    });
                }
            };
            
        });
        </script>
        <?php
    }
    
    public function ajax_process_batch() {
        check_ajax_referer('db_nearby_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }
        
        $batch_size = $_POST['batch_size'] ?? 50;
        
        if ($batch_size === 'all') {
            $result = $this->batch_processor->process_all_pending();
        } else {
            $result = $this->batch_processor->process_batch((int)$batch_size);
        }
        
        $stats = $this->queue_manager->get_stats();
        
        wp_send_json_success(array(
            'message' => $result['message'],
            'stats' => $stats
        ));
    }
    
    public function ajax_enqueue_all_points() {
        check_ajax_referer('db_nearby_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }
        
        $added_count = $this->queue_manager->enqueue_all_points();
        $stats = $this->queue_manager->get_stats();
        
        wp_send_json_success(array(
            'message' => "Přidáno {$added_count} bodů do fronty",
            'stats' => $stats
        ));
    }
    
    public function ajax_reset_failed_items() {
        check_ajax_referer('db_nearby_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }
        
        $reset_count = $this->queue_manager->reset_failed_items();
        $stats = $this->queue_manager->get_stats();
        
        wp_send_json_success(array(
            'message' => "Resetováno {$reset_count} chybných položek",
            'stats' => $stats
        ));
    }
    
    public function ajax_cleanup_old_items() {
        check_ajax_referer('db_nearby_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }
        
        $deleted_count = $this->queue_manager->cleanup_old_items();
        $stats = $this->queue_manager->get_stats();
        
        wp_send_json_success(array(
            'message' => "Smazáno {$deleted_count} starých položek",
            'stats' => $stats
        ));
    }
    
    public function ajax_get_queue_details() {
        check_ajax_referer('db_nearby_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }
        
        $limit = (int)($_POST['limit'] ?? 50);
        $offset = (int)($_POST['offset'] ?? 0);
        
        $items = $this->queue_manager->get_queue_details($limit, $offset);
        $pagination = $this->queue_manager->get_queue_pagination($limit, $offset);
        
        wp_send_json_success(array(
            'data' => $items,
            'pagination' => $pagination
        ));
    }
    
    public function ajax_test_token_bucket() {
        check_ajax_referer('db_nearby_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }
        
        try {
            $quota_manager = new \DB\Jobs\API_Quota_Manager();
            
            // Test token bucket
            $result = $quota_manager->check_minute_limit('matrix');
            
            // Získat raw bucket data pro debug
            $bucket_key = 'db_ors_matrix_token_bucket';
            $bucket_data = get_transient($bucket_key);
            
            $debug_info = array(
                'allowed' => $result['allowed'],
                'tokens_remaining' => $result['tokens_remaining'] ?? 0,
                'tokens_before' => $result['tokens_before'] ?? null,
                'tokens_after' => $result['tokens_after'] ?? null,
                'wait_seconds' => $result['wait_seconds'] ?? 0,
                'bucket_data' => $bucket_data,
                'current_time' => time(),
                'transient_exists' => $bucket_data !== false
            );
            
            error_log("[DB Token Bucket Test] " . json_encode($debug_info));
            
            wp_send_json_success($debug_info);
            
        } catch (Exception $e) {
            $message = "Výjimka při testování token bucket: " . $e->getMessage();
            error_log("[DB Token Bucket Test] Výjimka: {$message}");
            wp_send_json_error(array('message' => $message));
        }
    }
    
    public function ajax_test_ors_headers() {
        check_ajax_referer('db_nearby_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }
        
        try {
            // Získat ORS API key
            $cfg = get_option('db_nearby_config', []);
            $ors_key = trim((string)($cfg['ors_api_key'] ?? ''));
            
            if (empty($ors_key)) {
                wp_send_json_error(array('message' => 'ORS API key není nastaven'));
            }
            
            // Udělat testovací ORS volání
            $test_data = array(
                'locations' => array(
                    array(14.4378, 50.0755), // Praha
                    array(14.4378, 50.0756)  // Praha + 0.0001
                ),
                'sources' => array(0),
                'destinations' => array(1),
                'metrics' => array('distance', 'duration')
            );
            
            $response = wp_remote_post("https://api.openrouteservice.org/v2/matrix/foot-walking", array(
                'headers' => array(
                    'Authorization' => $ors_key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'DobityBaterky/test (+https://dobitybaterky.cz)'
                ),
                'body' => json_encode($test_data),
                'timeout' => 10
            ));
            
            if (is_wp_error($response)) {
                wp_send_json_error(array('message' => 'ORS request failed: ' . $response->get_error_message()));
            }
            
            $code = wp_remote_retrieve_response_code($response);
            $headers = wp_remote_retrieve_headers($response);
            $body = wp_remote_retrieve_body($response);
            
            // Uložit kvóty z hlaviček
            $quota_manager = new \DB\Jobs\API_Quota_Manager();
            $quota_manager->save_ors_headers($headers);
            
            // Získat cached kvóty
            $cached_quotas = $quota_manager->get_cached_ors_quotas();
            
            $result = array(
                'status' => ($code >= 200 && $code < 300) ? 'ok' : 'error',
                'code' => $code,
                'headers' => $headers->getAll(),
                'quotas_saved' => true,
                'cached_quotas' => $cached_quotas['matrix_v2']
            );
            
            error_log("[DB ORS Headers Test] " . json_encode($result));
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            $message = "Výjimka při testování ORS headers: " . $e->getMessage();
            error_log("[DB ORS Headers Test] Výjimka: {$message}");
            wp_send_json_error(array('message' => $message));
        }
    }
    
    public function ajax_set_priority() {
        check_ajax_referer('db_nearby_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }
        
        $id = (int)($_POST['id'] ?? 0);
        $priority = (int)($_POST['priority'] ?? 0);
        
        $result = $this->queue_manager->set_priority($id, $priority);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Priorita nastavena'));
        } else {
            wp_send_json_error(array('message' => 'Chyba při nastavování priority'));
        }
    }
    
    public function ajax_move_to_front() {
        check_ajax_referer('db_nearby_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }
        
        $id = (int)($_POST['id'] ?? 0);
        
        $result = $this->queue_manager->move_to_front($id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Položka přesunuta na začátek fronty'));
        } else {
            wp_send_json_error(array('message' => 'Chyba při přesouvání'));
        }
    }
    
    public function ajax_toggle_auto_processing() {
        check_ajax_referer('db_nearby_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }
        
        $currently_enabled = (bool)get_option('db_nearby_auto_enabled', false);

        if ($currently_enabled) {
            update_option('db_nearby_auto_enabled', false);
            $this->auto_processor->stop_auto_processing();
            $message = 'Automatické zpracování vypnuto';
        } else {
            update_option('db_nearby_auto_enabled', true);
            $this->auto_processor->schedule_auto_processing(true);
            $message = 'Automatické zpracování zapnuto';
        }

        $auto_status = $this->auto_processor->get_auto_status();
        $queue_stats = $this->queue_manager->get_stats();

        wp_send_json_success(array(
            'message' => $message,
            'auto_status' => $auto_status,
            'queue_stats' => $queue_stats
        ));
    }

    public function ajax_trigger_auto_processing() {
        check_ajax_referer('db_nearby_batch', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $result = $this->auto_processor->trigger_auto_processing();

        if (empty($result['message'])) {
            $result['message'] = $result['success']
                ? sprintf('Zpracováno: %d položek, chyb: %d', (int)$result['processed'], (int)$result['errors'])
                : 'Žádné položky k zpracování nebo nedostatečná kvóta';
        }

        if (empty($result['success']) && !empty($result['last_error'])) {
            $result['message'] = $result['last_error'];
        }

        if (!empty($result['success'])) {
            wp_send_json_success($result);
        }

        wp_send_json_error($result);
    }
    
    /**
     * Renderovat stránku zpracovaných míst
     */
    public function render_processed_page() {
        $stats = $this->processed_manager->get_processed_stats();
        $current_page = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $limit = 50;
        $offset = ($current_page - 1) * $limit;
        
        // Filtry
        $filters = array();
        if (!empty($_GET['origin_type'])) {
            $filters['origin_type'] = sanitize_text_field($_GET['origin_type']);
        }
        if (!empty($_GET['api_provider'])) {
            $filters['api_provider'] = sanitize_text_field($_GET['api_provider']);
        }
        // Datové filtry odstraněny - způsobují jen nepořádek
        
        $items = $this->processed_manager->get_processed_locations($limit, $offset, $filters);
        $pagination = $this->processed_manager->get_processed_pagination($limit, $offset, $filters);
        ?>
        <div class="wrap">
            <h1>Zpracovaná místa (Nearby Data)</h1>
            <p>Správa míst, která už mají zpracovaná nearby data přes API.</p>
            
            <!-- Statistiky -->
            <div class="db-stats" style="margin: 20px 0;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div class="db-stat-card" style="background: #d4edda; padding: 20px; border-radius: 8px; text-align: center;">
                        <h3 style="margin: 0 0 10px 0; color: #155724;">Celkem zpracovaných</h3>
                        <div style="font-size: 2em; font-weight: bold; color: #28a745;"><?php echo $stats['total_processed']; ?></div>
                    </div>
                    <div class="db-stat-card" style="background: #d1ecf1; padding: 20px; border-radius: 8px; text-align: center;">
                        <h3 style="margin: 0 0 10px 0; color: #0c5460;">Celkem API volání</h3>
                        <div style="font-size: 2em; font-weight: bold; color: #17a2b8;"><?php echo number_format($stats['total_api_calls']); ?></div>
                    </div>
                    <div class="db-stat-card" style="background: #fff3cd; padding: 20px; border-radius: 8px; text-align: center;">
                        <h3 style="margin: 0 0 10px 0; color: #856404;">Velikost cache</h3>
                        <div style="font-size: 2em; font-weight: bold; color: #ffc107;"><?php echo round($stats['total_cache_size_kb'] / 1024, 2); ?> MB</div>
                    </div>
                    <div class="db-stat-card" style="background: #f8d7da; padding: 20px; border-radius: 8px; text-align: center;">
                        <h3 style="margin: 0 0 10px 0; color: #721c24;">Průměrný čas</h3>
                        <div style="font-size: 2em; font-weight: bold; color: #dc3545;"><?php echo round($stats['avg_processing_time'], 1); ?>s</div>
                    </div>
                </div>
            </div>
            
            <!-- Filtry -->
            <div class="db-filters" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                <h3>Filtry</h3>
                <form method="get" style="display: flex; gap: 15px; align-items: end; flex-wrap: wrap;">
                    <input type="hidden" name="page" value="db-nearby-processed">
                    <div>
                        <label for="origin_type">Typ původu:</label>
                        <select name="origin_type" id="origin_type">
                            <option value="">Všechny</option>
                            <option value="poi" <?php selected($filters['origin_type'] ?? '', 'poi'); ?>>POI</option>
                            <option value="charging_location" <?php selected($filters['origin_type'] ?? '', 'charging_location'); ?>>Charging Location</option>
                        </select>
                    </div>
                    <div>
                        <label for="api_provider">API Provider:</label>
                        <select name="api_provider" id="api_provider">
                            <option value="">Všechny</option>
                            <option value="ors" <?php selected($filters['api_provider'] ?? '', 'ors'); ?>>ORS</option>
                            <option value="osrm" <?php selected($filters['api_provider'] ?? '', 'osrm'); ?>>OSRM</option>
                        </select>
                    </div>
                    <!-- Datové filtry odstraněny -->
                    <div>
                        <input type="submit" class="button" value="Filtrovat">
                        <a href="?page=db-nearby-processed" class="button">Reset</a>
                    </div>
                </form>
            </div>
            
            <!-- Tabulka zpracovaných míst -->
            <div class="db-processed-table">
				<table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
							<th style="width:28px;"><input type="checkbox" id="db-select-all"></th>
							<th>Origin ID</th>
                            <th>Název</th>
                            <th>Typ</th>
                            <th>Kandidáti</th>
							<th>Čas zpracování</th>
                            <th>API Provider</th>
                            <th>Velikost cache</th>
                            <th>Datum zpracování</th>
                            <th>Status</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody id="db-processed-details-body">
						<?php if (empty($items)): ?>
                            <tr>
								<td colspan="11" style="text-align: center; padding: 20px;">Žádná zpracovaná místa</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
									<td><input type="checkbox" class="db-select-row" value="<?php echo (int)$item->origin_id; ?>"></td>
									<td><?php echo esc_html($item->origin_id); ?></td>
                                    <td><?php echo esc_html($item->origin_title); ?></td>
                                    <td><?php echo esc_html($item->origin_type); ?></td>
                                    <td><?php echo esc_html($item->candidates_count); ?></td>
									<td><?php echo esc_html(((int)$item->processing_time_seconds) * 1000); ?> ms</td>
                                    <td><?php echo esc_html(strtoupper($item->api_provider)); ?></td>
                                    <td><?php echo esc_html($item->cache_size_kb); ?> KB</td>
                                    <td><?php echo esc_html(date('d.m.Y H:i', strtotime($item->processing_date))); ?></td>
                                    <td>
                                        <span class="status-<?php echo esc_attr($item->status); ?>">
                                            <?php echo esc_html($item->status); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="button button-small" onclick="viewDetails(<?php echo $item->origin_id; ?>, '<?php echo esc_js($item->origin_type); ?>')">Detaily</button>
                                        <button class="button button-small button-secondary" onclick="requeueOrigin(<?php echo $item->origin_id; ?>, '<?php echo esc_js($item->origin_type); ?>')">Odeslat do fronty</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Hromadné akce -->
            <div style="margin:15px 0; display:flex; gap:10px; align-items:center;">
                <button class="button" onclick="requeueSelected()">Odeslat vybrané do fronty</button>
            </div>

            <!-- Paginace -->
            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="db-pagination" style="margin: 20px 0; text-align: center;">
                    <?php
                    $base_url = add_query_arg(array(
                        'page' => 'db-nearby-processed',
                        'origin_type' => $filters['origin_type'] ?? '',
                        'api_provider' => $filters['api_provider'] ?? ''
                    ), admin_url('tools.php'));
                    
                    echo paginate_links(array(
                        'base' => $base_url . '&paged=%#%',
                        'format' => '',
                        'current' => $current_page,
                        'total' => $pagination['total_pages'],
                        'prev_text' => '&laquo; Předchozí',
                        'next_text' => 'Další &raquo;'
                    ));
                    ?>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        function viewDetails(originId, originType) {
            // Otevřít detaily místa v novém okně nebo modalu
            window.open('<?php echo admin_url('post.php?action=edit&post='); ?>' + originId, '_blank');
        }
        async function requeueOrigin(originId, originType) {
            try {
                const res = await fetch('<?php echo esc_url_raw( rest_url('db/v1/admin/nearby/requeue') ); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>' },
                    body: JSON.stringify({ origin_ids: [originId] })
                });
                const json = await res.json();
                if (res.ok) {
                    const enq = json.enqueued || [];
                    const skp = json.skipped || [];
                    const msg = 'Zařazeno: ' + enq.length + ' / 1' + (enq.length ? ('\nID: ' + enq.map(r=>r[0]).join(', ')) : '')
                      + (skp.length ? ('\nPřeskočeno: ' + skp.length + ' (důvod: ' + skp.map(r=>r[1]).join(', ') + ')') : '');
                    alert(msg);
                } else {
                    alert('Chyba: ' + (json.message || 'Nepodařilo se odeslat do fronty'));
                }
            } catch (e) {
                alert('Chyba při volání API: ' + e);
            }
        }
        // Select all handling
        (function(){
            const selectAll = document.getElementById('db-select-all');
            if (selectAll) {
                selectAll.addEventListener('change', function(){
                    document.querySelectorAll('.db-select-row').forEach(cb => { cb.checked = selectAll.checked; });
                });
            }
        })();
        async function requeueSelected(){
            const ids = Array.from(document.querySelectorAll('.db-select-row:checked')).map(cb => parseInt(cb.value,10)).filter(Boolean);
            if (!ids.length) { alert('Vyberte prosím alespoň jeden řádek.'); return; }
            try {
                const res = await fetch('<?php echo esc_url_raw( rest_url('db/v1/admin/nearby/requeue') ); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce('wp_rest') ); ?>' },
                    body: JSON.stringify({ origin_ids: ids })
                });
                const json = await res.json();
                if (res.ok) {
                    const enq = json.enqueued || [];
                    const skp = json.skipped || [];
                    const msg = 'Zařazeno: ' + enq.length + ' / ' + ids.length
                      + (enq.length ? ('\nID: ' + enq.map(r=>r[0]).join(', ')) : '')
                      + (skp.length ? ('\nPřeskočeno: ' + skp.length + ' (důvod: ' + skp.map(r=>r[1]).join(', ') + ')\nID: ' + skp.map(r=>r[0]).join(', ')) : '');
                    alert(msg);
                } else {
                    alert('Chyba: ' + (json.message || 'Nepodařilo se odeslat do fronty'));
                }
            } catch (e) {
                alert('Chyba při volání API: ' + e);
            }
        }
        </script>
        
        <style>
        .status-completed { color: #28a745; font-weight: bold; }
        .status-failed { color: #dc3545; font-weight: bold; }
        .status-processing { color: #17a2b8; font-weight: bold; }
        </style>
        <?php
    }
}

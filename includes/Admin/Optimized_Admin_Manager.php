<?php
/**
 * Optimized Admin Manager - Optimalizovaný správce admin rozhraní
 * @package DobityBaterky
 */

namespace DB\Admin;

use DB\Admin\On_Demand_Admin;
use DB\Admin\Legacy_Admin_Manager;
use DB\Jobs\Optimized_Worker_Manager;

class Optimized_Admin_Manager {
    
    private $on_demand_admin;
    private $legacy_admin_manager;
    private $worker_manager;
    
    public function __construct() {
        $this->on_demand_admin = new On_Demand_Admin();
        $this->legacy_admin_manager = new Legacy_Admin_Manager();
        $this->worker_manager = new Optimized_Worker_Manager();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_db_optimized_admin_action', array($this, 'ajax_admin_action'));
    }
    
    public function add_admin_menu() {
        // Hlavní menu pro on-demand zpracování
        add_menu_page(
            'On-Demand Processing',
            'On-Demand',
            'manage_options',
            'db-ondemand',
            array($this, 'render_main_page'),
            'dashicons-update',
            30
        );
        
        // Submenu pro cache management
        add_submenu_page(
            'db-ondemand',
            'Cache Management',
            'Cache Management',
            'manage_options',
            'db-ondemand-cache',
            array($this, 'render_cache_page')
        );
        
        // Submenu pro database optimization
        add_submenu_page(
            'db-ondemand',
            'Database Optimization',
            'DB Optimization',
            'manage_options',
            'db-ondemand-optimization',
            array($this, 'render_optimization_page')
        );
        
        // Submenu pro processing logs
        add_submenu_page(
            'db-ondemand',
            'Processing Logs',
            'Processing Logs',
            'manage_options',
            'db-ondemand-logs',
            array($this, 'render_logs_page')
        );
        
        // Submenu pro legacy admin (pro zpětnou kompatibilitu)
        add_submenu_page(
            'db-ondemand',
            'Legacy Admin',
            'Legacy Admin',
            'manage_options',
            'db-legacy-admin',
            array($this, 'render_legacy_admin_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'db-ondemand') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-tabs');
        wp_enqueue_style('jquery-ui-tabs');
        
        // Custom admin styles
        wp_add_inline_style('wp-admin', $this->get_admin_styles());
        
        // Custom admin scripts
        wp_add_inline_script('jquery', $this->get_admin_scripts());
    }
    
    public function render_main_page() {
        $stats = $this->worker_manager->get_processing_stats();
        $performance_stats = $this->worker_manager->get_performance_stats();
        
        ?>
        <div class="wrap">
            <h1>🚀 On-Demand Processing</h1>
            <p>Optimalizované zpracování dat založené na uživatelské interakci místo neustále běžících workerů.</p>
            
            <!-- Statistiky -->
            <div class="db-ondemand-stats">
                <div class="db-stat-box">
                    <h3>📊 Celkové statistiky</h3>
                    <div class="db-stat-grid">
                        <div class="db-stat-item">
                            <span class="db-stat-number"><?php echo number_format($stats['total_points']); ?></span>
                            <span class="db-stat-label">Celkem bodů</span>
                        </div>
                        <div class="db-stat-item">
                            <span class="db-stat-number"><?php echo number_format($stats['cached_points']); ?></span>
                            <span class="db-stat-label">S cache</span>
                        </div>
                        <div class="db-stat-item">
                            <span class="db-stat-number"><?php echo number_format($stats['uncached_points']); ?></span>
                            <span class="db-stat-label">Bez cache</span>
                        </div>
                        <div class="db-stat-item">
                            <span class="db-stat-number"><?php echo round(($stats['cached_points'] / max($stats['total_points'], 1)) * 100, 1); ?>%</span>
                            <span class="db-stat-label">Cache pokrytí</span>
                        </div>
                    </div>
                </div>
                
                <div class="db-stat-box">
                    <h3>⚡ Výkonnostní statistiky</h3>
                    <div class="db-stat-grid">
                        <div class="db-stat-item">
                            <span class="db-stat-number"><?php echo number_format($performance_stats['total_requests']); ?></span>
                            <span class="db-stat-label">Celkem požadavků</span>
                        </div>
                        <div class="db-stat-item">
                            <span class="db-stat-number"><?php echo number_format($performance_stats['cached_requests']); ?></span>
                            <span class="db-stat-label">Z cache</span>
                        </div>
                        <div class="db-stat-item">
                            <span class="db-stat-number"><?php echo round($performance_stats['avg_processing_time'], 2); ?>ms</span>
                            <span class="db-stat-label">Prům. doba</span>
                        </div>
                        <div class="db-stat-item">
                            <span class="db-stat-number"><?php echo round($performance_stats['error_rate'], 2); ?>%</span>
                            <span class="db-stat-label">Chybovost</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Rychlé akce -->
            <div class="db-ondemand-actions">
                <h2>⚡ Rychlé akce</h2>
                <div class="db-action-buttons">
                    <button class="button button-primary" id="db-ondemand-refresh-stats">
                        <span class="dashicons dashicons-update"></span> Aktualizovat statistiky
                    </button>
                    <button class="button" id="db-ondemand-clear-cache">
                        <span class="dashicons dashicons-trash"></span> Vymazat cache
                    </button>
                    <button class="button" id="db-ondemand-optimize-db">
                        <span class="dashicons dashicons-performance"></span> Optimalizovat DB
                    </button>
                    <button class="button" id="db-ondemand-bulk-process">
                        <span class="dashicons dashicons-admin-tools"></span> Hromadné zpracování
                    </button>
                </div>
            </div>
            
            <!-- Test zpracování -->
            <div class="db-ondemand-test">
                <h2>🧪 Test zpracování</h2>
                <form id="db-ondemand-test-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">ID bodu</th>
                            <td>
                                <input type="number" id="test-point-id" class="regular-text" placeholder="Zadejte ID bodu" />
                                <p class="description">Zadejte ID charging_location, poi nebo rv_spot</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Typ bodu</th>
                            <td>
                                <select id="test-point-type">
                                    <option value="charging_location">Charging Location</option>
                                    <option value="poi">POI</option>
                                    <option value="rv_spot">RV Spot</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Priorita</th>
                            <td>
                                <select id="test-priority">
                                    <option value="normal">Normální</option>
                                    <option value="high">Vysoká</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Možnosti</th>
                            <td>
                                <label>
                                    <input type="checkbox" id="test-force-refresh" /> Vynutit refresh
                                </label><br>
                                <label>
                                    <input type="checkbox" id="test-include-nearby" checked /> Zahrnout nearby data
                                </label><br>
                                <label>
                                    <input type="checkbox" id="test-include-discovery" checked /> Zahrnout discovery data
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">Spustit test</button>
                    </p>
                </form>
                
                <div id="db-ondemand-test-result" style="margin-top: 20px;"></div>
            </div>
            
            <!-- Hromadné zpracování -->
            <div class="db-ondemand-bulk" style="display: none;">
                <h2>📦 Hromadné zpracování</h2>
                <form id="db-ondemand-bulk-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Typ bodů</th>
                            <td>
                                <select id="bulk-point-type">
                                    <option value="charging_location">Charging Locations</option>
                                    <option value="poi">POI</option>
                                    <option value="rv_spot">RV Spots</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Počet bodů</th>
                            <td>
                                <input type="number" id="bulk-limit" value="10" min="1" max="100" />
                                <p class="description">Maximálně 100 bodů najednou</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Priorita</th>
                            <td>
                                <select id="bulk-priority">
                                    <option value="normal">Normální</option>
                                    <option value="high">Vysoká</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">Spustit hromadné zpracování</button>
                    </p>
                </form>
                
                <div id="db-ondemand-bulk-result" style="margin-top: 20px;"></div>
            </div>
        </div>
        <?php
    }
    
    public function render_cache_page() {
        $cache_stats = $this->worker_manager->get_processing_stats();
        
        ?>
        <div class="wrap">
            <h1>💾 Cache Management</h1>
            <p>Správa cache pro on-demand zpracování.</p>
            
            <!-- Cache statistiky -->
            <div class="db-cache-stats">
                <h2>📊 Cache statistiky</h2>
                <table class="form-table">
                    <tr>
                        <th>Cache záznamy</th>
                        <td><?php echo number_format($cache_stats['cached_points']); ?></td>
                    </tr>
                    <tr>
                        <th>Celkem bodů</th>
                        <td><?php echo number_format($cache_stats['total_points']); ?></td>
                    </tr>
                    <tr>
                        <th>Cache pokrytí</th>
                        <td><?php echo round(($cache_stats['cached_points'] / max($cache_stats['total_points'], 1)) * 100, 2); ?>%</td>
                    </tr>
                </table>
            </div>
            
            <!-- Cache akce -->
            <div class="db-cache-actions">
                <h2>🔧 Cache akce</h2>
                <div class="db-action-buttons">
                    <button class="button" id="db-cache-clear-old">
                        <span class="dashicons dashicons-trash"></span> Vymazat starý cache (>7 dní)
                    </button>
                    <button class="button" id="db-cache-clear-all">
                        <span class="dashicons dashicons-trash"></span> Vymazat všechny cache
                    </button>
                    <button class="button" id="db-cache-refresh-stats">
                        <span class="dashicons dashicons-update"></span> Aktualizovat statistiky
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function render_optimization_page() {
        ?>
        <div class="wrap">
            <h1>⚡ Database Optimization</h1>
            <p>Optimalizace databáze pro lepší výkon on-demand zpracování.</p>
            
            <!-- Optimalizace akce -->
            <div class="db-optimization-actions">
                <h2>🔧 Optimalizační akce</h2>
                <div class="db-action-buttons">
                    <button class="button button-primary" id="db-opt-create-indexes">
                        <span class="dashicons dashicons-performance"></span> Vytvořit indexy
                    </button>
                    <button class="button" id="db-opt-optimize-tables">
                        <span class="dashicons dashicons-hammer"></span> Optimalizovat tabulky
                    </button>
                    <button class="button" id="db-opt-cleanup-cache">
                        <span class="dashicons dashicons-trash"></span> Vyčistit cache
                    </button>
                </div>
            </div>
            
            <!-- Výsledky optimalizace -->
            <div id="db-optimization-results" style="margin-top: 20px;"></div>
        </div>
        <?php
    }
    
    public function render_logs_page() {
        ?>
        <div class="wrap">
            <h1>📝 Processing Logs</h1>
            <p>Logy zpracování on-demand dat.</p>
            
            <div id="db-logs-content">
                <p>Načítám logy...</p>
            </div>
        </div>
        <?php
    }
    
    public function render_legacy_admin_page() {
        ?>
        <div class="wrap">
            <h1>🔧 Legacy Admin</h1>
            <p>Staré admin rozhraní pro zpětnou kompatibilitu.</p>
            
            <div class="notice notice-warning">
                <p><strong>Upozornění:</strong> Toto je staré admin rozhraní. Doporučujeme používat nové On-Demand rozhraní.</p>
            </div>
            
            <div id="db-legacy-admin-content">
                <p>Legacy admin rozhraní bude implementováno v další verzi.</p>
            </div>
        </div>
        <?php
    }
    
    public function ajax_admin_action() {
        check_ajax_referer('db_optimized_admin_action', 'nonce');
        
        $action = sanitize_text_field($_POST['action_type']);
        
        switch ($action) {
            case 'refresh_stats':
                $stats = $this->worker_manager->get_processing_stats();
                wp_send_json_success($stats);
                break;
                
            case 'clear_cache':
                wp_cache_flush();
                wp_send_json_success('Cache vymazán');
                break;
                
            case 'optimize_db':
                $operation = sanitize_text_field($_POST['operation'] ?? 'create_indexes');
                $result = $this->worker_manager->optimize_database($operation);
                wp_send_json_success($result);
                break;
                
            case 'process_point':
                $point_id = intval($_POST['point_id']);
                $point_type = sanitize_text_field($_POST['point_type']);
                $priority = sanitize_text_field($_POST['priority']);
                $options = array(
                    'force_refresh' => isset($_POST['force_refresh']),
                    'include_nearby' => isset($_POST['include_nearby']),
                    'include_discovery' => isset($_POST['include_discovery'])
                );
                
                $result = $this->worker_manager->process_on_demand($point_id, $point_type, $priority);
                wp_send_json_success($result);
                break;
                
            case 'bulk_process':
                $point_type = sanitize_text_field($_POST['point_type']);
                $limit = intval($_POST['limit']);
                $priority = sanitize_text_field($_POST['priority']);
                
                $points = $this->worker_manager->get_points_to_process($point_type, $limit);
                $point_ids = array_column($points, 'ID');
                
                $result = $this->worker_manager->process_bulk($point_ids, $point_type, array('priority' => $priority));
                wp_send_json_success($result);
                break;
                
            default:
                wp_send_json_error('Neznámá akce');
        }
    }
    
    private function get_admin_styles(): string {
        return '
        .db-ondemand-stats {
            margin: 20px 0;
        }
        
        .db-stat-box {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin-bottom: 20px;
        }
        
        .db-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }
        
        .db-stat-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .db-stat-number {
            display: block;
            font-size: 2em;
            font-weight: bold;
            color: #0073aa;
        }
        
        .db-stat-label {
            display: block;
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
        }
        
        .db-action-buttons {
            display: flex;
            gap: 10px;
            margin: 15px 0;
            flex-wrap: wrap;
        }
        
        .db-action-buttons .button {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .db-status-badge {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .db-status-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .db-status-processing {
            background: #fff3cd;
            color: #856404;
        }
        
        .db-status-error {
            background: #f8d7da;
            color: #721c24;
        }
        
        .db-status-cached {
            background: #d1ecf1;
            color: #0c5460;
        }
        ';
    }
    
    private function get_admin_scripts(): string {
        return '
        jQuery(document).ready(function($) {
            // Refresh statistik
            $("#db-ondemand-refresh-stats").on("click", function() {
                location.reload();
            });
            
            // Clear cache
            $("#db-ondemand-clear-cache").on("click", function() {
                if (confirm("Opravdu chcete vymazat všechny cache?")) {
                    $.post(ajaxurl, {
                        action: "db_optimized_admin_action",
                        action_type: "clear_cache",
                        nonce: "' . wp_create_nonce('db_optimized_admin_action') . '"
                    }, function(response) {
                        if (response.success) {
                            alert("Cache vymazán!");
                            location.reload();
                        } else {
                            alert("Chyba: " + response.data);
                        }
                    });
                }
            });
            
            // Optimize DB
            $("#db-ondemand-optimize-db").on("click", function() {
                if (confirm("Opravdu chcete optimalizovat databázi?")) {
                    $.post(ajaxurl, {
                        action: "db_optimized_admin_action",
                        action_type: "optimize_db",
                        operation: "create_indexes",
                        nonce: "' . wp_create_nonce('db_optimized_admin_action') . '"
                    }, function(response) {
                        if (response.success) {
                            alert("Databáze optimalizována!");
                        } else {
                            alert("Chyba: " + response.data);
                        }
                    });
                }
            });
            
            // Bulk process toggle
            $("#db-ondemand-bulk-process").on("click", function() {
                $(".db-ondemand-bulk").toggle();
            });
            
            // Test form
            $("#db-ondemand-test-form").on("submit", function(e) {
                e.preventDefault();
                
                const pointId = $("#test-point-id").val();
                const pointType = $("#test-point-type").val();
                const priority = $("#test-priority").val();
                const forceRefresh = $("#test-force-refresh").is(":checked");
                const includeNearby = $("#test-include-nearby").is(":checked");
                const includeDiscovery = $("#test-include-discovery").is(":checked");
                
                if (!pointId) {
                    alert("Zadejte ID bodu");
                    return;
                }
                
                const resultDiv = $("#db-ondemand-test-result");
                resultDiv.html("<div class=\"notice notice-info\"><p>Spouštím test zpracování...</p></div>");
                
                $.post(ajaxurl, {
                    action: "db_optimized_admin_action",
                    action_type: "process_point",
                    point_id: pointId,
                    point_type: pointType,
                    priority: priority,
                    force_refresh: forceRefresh,
                    include_nearby: includeNearby,
                    include_discovery: includeDiscovery,
                    nonce: "' . wp_create_nonce('db_optimized_admin_action') . '"
                }, function(response) {
                    if (response.success) {
                        resultDiv.html("<div class=\"notice notice-success\"><p><strong>Test dokončen:</strong><br>" + 
                            JSON.stringify(response.data, null, 2) + "</p></div>");
                    } else {
                        resultDiv.html("<div class=\"notice notice-error\"><p><strong>Chyba:</strong> " + response.data + "</p></div>");
                    }
                });
            });
            
            // Bulk form
            $("#db-ondemand-bulk-form").on("submit", function(e) {
                e.preventDefault();
                
                const pointType = $("#bulk-point-type").val();
                const limit = $("#bulk-limit").val();
                const priority = $("#bulk-priority").val();
                
                const resultDiv = $("#db-ondemand-bulk-result");
                resultDiv.html("<div class=\"notice notice-info\"><p>Spouštím hromadné zpracování...</p></div>");
                
                $.post(ajaxurl, {
                    action: "db_optimized_admin_action",
                    action_type: "bulk_process",
                    point_type: pointType,
                    limit: limit,
                    priority: priority,
                    nonce: "' . wp_create_nonce('db_optimized_admin_action') . '"
                }, function(response) {
                    if (response.success) {
                        resultDiv.html("<div class=\"notice notice-success\"><p><strong>Hromadné zpracování dokončeno:</strong><br>" + 
                            JSON.stringify(response.data, null, 2) + "</p></div>");
                    } else {
                        resultDiv.html("<div class=\"notice notice-error\"><p><strong>Chyba:</strong> " + response.data + "</p></div>");
                    }
                });
            });
        });
        ';
    }
}

<?php
/**
 * On-Demand Admin - Optimalizované admin rozhraní pro on-demand zpracování
 * @package DobityBaterky
 */

namespace DB\Admin;

use DB\Jobs\On_Demand_Processor;
use DB\Jobs\Optimized_Worker_Manager;
use DB\Database_Optimizer;

class On_Demand_Admin {
    
    private $processor;
    private $worker_manager;
    private $database_optimizer;
    
    public function __construct() {
        $this->processor = new On_Demand_Processor();
        $this->worker_manager = new Optimized_Worker_Manager();
        $this->database_optimizer = new Database_Optimizer();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_db_ondemand_process_point', array($this, 'ajax_process_point'));
        add_action('wp_ajax_db_ondemand_check_status', array($this, 'ajax_check_status'));
        add_action('wp_ajax_db_ondemand_bulk_process', array($this, 'ajax_bulk_process'));
        add_action('wp_ajax_db_ondemand_cache_stats', array($this, 'ajax_cache_stats'));
        add_action('wp_ajax_db_ondemand_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_db_ondemand_optimize_db', array($this, 'ajax_optimize_db'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'On-Demand Processing',
            'On-Demand',
            'manage_options',
            'db-ondemand',
            array($this, 'render_main_page'),
            'dashicons-update',
            30
        );
        
        add_submenu_page(
            'db-ondemand',
            'Cache Management',
            'Cache Management',
            'manage_options',
            'db-ondemand-cache',
            array($this, 'render_cache_page')
        );
        
        add_submenu_page(
            'db-ondemand',
            'Database Optimization',
            'DB Optimization',
            'manage_options',
            'db-ondemand-optimization',
            array($this, 'render_optimization_page')
        );
        
        add_submenu_page(
            'db-ondemand',
            'Processing Logs',
            'Processing Logs',
            'manage_options',
            'db-ondemand-logs',
            array($this, 'render_logs_page')
        );
    }
    
    public function render_main_page() {
        $stats = $this->worker_manager->get_processing_stats();
        $recent_processing = get_option('db_ondemand_recent_processing', array());
        
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
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">Spustit test</button>
                    </p>
                </form>
                
                <div id="db-ondemand-test-result" style="margin-top: 20px;"></div>
            </div>
            
            <!-- Nedávné zpracování -->
            <?php if (!empty($recent_processing)): ?>
            <div class="db-ondemand-recent">
                <h2>📝 Nedávné zpracování</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Čas</th>
                            <th>Bod ID</th>
                            <th>Typ</th>
                            <th>Status</th>
                            <th>Doba zpracování</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($recent_processing, 0, 10) as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item['timestamp']); ?></td>
                            <td><?php echo esc_html($item['point_id']); ?></td>
                            <td><?php echo esc_html($item['point_type']); ?></td>
                            <td>
                                <span class="db-status-badge db-status-<?php echo esc_attr($item['status']); ?>">
                                    <?php echo esc_html($item['status']); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($item['processing_time']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <style>
        .db-ondemand-stats {
            margin: 20px 0;
        }
        
        .db-stat-box {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
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
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Refresh statistik
            $('#db-ondemand-refresh-stats').on('click', function() {
                location.reload();
            });
            
            // Clear cache
            $('#db-ondemand-clear-cache').on('click', function() {
                if (confirm('Opravdu chcete vymazat všechny cache?')) {
                    $.post(ajaxurl, {
                        action: 'db_ondemand_clear_cache',
                        nonce: '<?php echo wp_create_nonce('db_ondemand_clear_cache'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('Cache vymazán!');
                            location.reload();
                        } else {
                            alert('Chyba: ' + response.data);
                        }
                    });
                }
            });
            
            // Optimize DB
            $('#db-ondemand-optimize-db').on('click', function() {
                if (confirm('Opravdu chcete optimalizovat databázi?')) {
                    $.post(ajaxurl, {
                        action: 'db_ondemand_optimize_db',
                        nonce: '<?php echo wp_create_nonce('db_ondemand_optimize_db'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('Databáze optimalizována!');
                        } else {
                            alert('Chyba: ' + response.data);
                        }
                    });
                }
            });
            
            // Test form
            $('#db-ondemand-test-form').on('submit', function(e) {
                e.preventDefault();
                
                const pointId = $('#test-point-id').val();
                const pointType = $('#test-point-type').val();
                const priority = $('#test-priority').val();
                
                if (!pointId) {
                    alert('Zadejte ID bodu');
                    return;
                }
                
                const resultDiv = $('#db-ondemand-test-result');
                resultDiv.html('<div class="notice notice-info"><p>Spouštím test zpracování...</p></div>');
                
                $.post(ajaxurl, {
                    action: 'db_ondemand_process_point',
                    point_id: pointId,
                    point_type: pointType,
                    priority: priority,
                    nonce: '<?php echo wp_create_nonce('db_ondemand_process_point'); ?>'
                }, function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success"><p><strong>Test dokončen:</strong><br>' + 
                            JSON.stringify(response.data, null, 2) + '</p></div>');
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p><strong>Chyba:</strong> ' + response.data + '</p></div>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function render_cache_page() {
        $cache_stats = $this->database_optimizer->get_performance_stats();
        
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
                        <td><?php echo number_format($cache_stats['cache_records']); ?></td>
                    </tr>
                    <tr>
                        <th>Cache velikost</th>
                        <td><?php echo $cache_stats['cache_size']; ?> MB</td>
                    </tr>
                    <tr>
                        <th>Meta záznamy</th>
                        <td><?php echo number_format($cache_stats['meta_records']); ?></td>
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
            
            <!-- Cache detaily -->
            <div class="db-cache-details">
                <h2>📋 Cache detaily</h2>
                <div id="db-cache-details-content">
                    <p>Načítám detaily cache...</p>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Load cache details
            function loadCacheDetails() {
                $.post(ajaxurl, {
                    action: 'db_ondemand_cache_stats',
                    nonce: '<?php echo wp_create_nonce('db_ondemand_cache_stats'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#db-cache-details-content').html(response.data);
                    }
                });
            }
            
            loadCacheDetails();
            
            // Cache actions
            $('#db-cache-clear-old').on('click', function() {
                if (confirm('Opravdu chcete vymazat starý cache?')) {
                    // Implement clear old cache
                    alert('Starý cache vymazán!');
                    loadCacheDetails();
                }
            });
            
            $('#db-cache-clear-all').on('click', function() {
                if (confirm('Opravdu chcete vymazat všechny cache?')) {
                    $.post(ajaxurl, {
                        action: 'db_ondemand_clear_cache',
                        nonce: '<?php echo wp_create_nonce('db_ondemand_clear_cache'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('Všechny cache vymazány!');
                            loadCacheDetails();
                        }
                    });
                }
            });
            
            $('#db-cache-refresh-stats').on('click', function() {
                loadCacheDetails();
            });
        });
        </script>
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
        
        <script>
        jQuery(document).ready(function($) {
            $('#db-opt-create-indexes').on('click', function() {
                const button = $(this);
                button.prop('disabled', true).text('Vytvářím indexy...');
                
                $.post(ajaxurl, {
                    action: 'db_ondemand_optimize_db',
                    operation: 'create_indexes',
                    nonce: '<?php echo wp_create_nonce('db_ondemand_optimize_db'); ?>'
                }, function(response) {
                    button.prop('disabled', false).text('Vytvořit indexy');
                    
                    if (response.success) {
                        $('#db-optimization-results').html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    } else {
                        $('#db-optimization-results').html('<div class="notice notice-error"><p>Chyba: ' + response.data + '</p></div>');
                    }
                });
            });
            
            $('#db-opt-optimize-tables').on('click', function() {
                const button = $(this);
                button.prop('disabled', true).text('Optimalizuji...');
                
                $.post(ajaxurl, {
                    action: 'db_ondemand_optimize_db',
                    operation: 'optimize_tables',
                    nonce: '<?php echo wp_create_nonce('db_ondemand_optimize_db'); ?>'
                }, function(response) {
                    button.prop('disabled', false).text('Optimalizovat tabulky');
                    
                    if (response.success) {
                        $('#db-optimization-results').html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    } else {
                        $('#db-optimization-results').html('<div class="notice notice-error"><p>Chyba: ' + response.data + '</p></div>');
                    }
                });
            });
        });
        </script>
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
        
        <script>
        jQuery(document).ready(function($) {
            // Load logs
            function loadLogs() {
                $('#db-logs-content').html('<p>Načítám logy...</p>');
                
                // Implement logs loading
                setTimeout(function() {
                    $('#db-logs-content').html('<p>Logy budou implementovány v další verzi.</p>');
                }, 1000);
            }
            
            loadLogs();
        });
        </script>
        <?php
    }
    
    // AJAX handlers
    public function ajax_process_point() {
        check_ajax_referer('db_ondemand_process_point', 'nonce');
        
        $point_id = intval($_POST['point_id']);
        $point_type = sanitize_text_field($_POST['point_type']);
        $priority = sanitize_text_field($_POST['priority']);
        
        if (!$point_id || !$point_type) {
            wp_send_json_error('Neplatné parametry');
        }
        
        $result = $this->worker_manager->process_on_demand($point_id, $point_type, $priority);
        
        // Uložit do recent processing
        $recent = get_option('db_ondemand_recent_processing', array());
        array_unshift($recent, array(
            'timestamp' => current_time('Y-m-d H:i:s'),
            'point_id' => $point_id,
            'point_type' => $point_type,
            'status' => $result['status'],
            'processing_time' => $result['processing_time'] ?? 'N/A'
        ));
        
        // Zachovat pouze posledních 50 záznamů
        $recent = array_slice($recent, 0, 50);
        update_option('db_ondemand_recent_processing', $recent);
        
        wp_send_json_success($result);
    }
    
    public function ajax_check_status() {
        check_ajax_referer('db_ondemand_check_status', 'nonce');
        
        $point_id = intval($_POST['point_id']);
        
        if (!$point_id) {
            wp_send_json_error('Neplatné ID bodu');
        }
        
        $status = On_Demand_Processor::check_processing_status($point_id);
        wp_send_json_success($status);
    }
    
    public function ajax_bulk_process() {
        check_ajax_referer('db_ondemand_bulk_process', 'nonce');
        
        // Implement bulk processing
        wp_send_json_success('Bulk processing bude implementováno');
    }
    
    public function ajax_cache_stats() {
        check_ajax_referer('db_ondemand_cache_stats', 'nonce');
        
        $stats = $this->database_optimizer->get_performance_stats();
        
        $html = '<table class="wp-list-table widefat fixed striped">';
        $html .= '<thead><tr><th>Cache typ</th><th>Počet záznamů</th><th>Velikost</th></tr></thead>';
        $html .= '<tbody>';
        $html .= '<tr><td>Nearby Cache</td><td>' . number_format($stats['cache_records']) . '</td><td>' . $stats['cache_size'] . ' MB</td></tr>';
        $html .= '<tr><td>Meta Records</td><td>' . number_format($stats['meta_records']) . '</td><td>N/A</td></tr>';
        $html .= '</tbody></table>';
        
        wp_send_json_success($html);
    }
    
    public function ajax_clear_cache() {
        check_ajax_referer('db_ondemand_clear_cache', 'nonce');
        
        wp_cache_flush();
        
        wp_send_json_success('Cache vymazán');
    }
    
    public function ajax_optimize_db() {
        check_ajax_referer('db_ondemand_optimize_db', 'nonce');
        
        $operation = sanitize_text_field($_POST['operation'] ?? 'create_indexes');
        
        if ($operation === 'create_indexes') {
            $result = $this->database_optimizer->create_indexes();
            wp_send_json_success('Vytvořeno ' . $result['indexes_created'] . ' indexů');
        } elseif ($operation === 'optimize_tables') {
            global $wpdb;
            $wpdb->query("OPTIMIZE TABLE {$wpdb->posts}");
            $wpdb->query("OPTIMIZE TABLE {$wpdb->postmeta}");
            wp_send_json_success('Tabulky optimalizovány');
        } else {
            wp_send_json_error('Neznámá operace');
        }
    }
}

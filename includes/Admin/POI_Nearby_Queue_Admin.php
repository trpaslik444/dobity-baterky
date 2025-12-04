<?php
/**
 * POI Nearby Queue Admin - Admin rozhraní pro správu fronty nearby výpočtů POI
 * @package DobityBaterky
 */

namespace DB\Admin;

use DB\Jobs\POI_Nearby_Queue_Manager;

if (!defined('ABSPATH')) {
    exit;
}

class POI_Nearby_Queue_Admin {
    
    private $queue_manager;
    
    public function __construct() {
        $this->queue_manager = new POI_Nearby_Queue_Manager();
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_actions'));
        add_action('wp_ajax_db_nearby_queue_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_db_nearby_queue_toggle_pause', array($this, 'ajax_toggle_pause'));
        add_action('wp_ajax_db_nearby_queue_update_limit', array($this, 'ajax_update_limit'));
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=poi', // Parent slug - POI menu
            __('Nearby Queue', 'dobity-baterky'),
            __('Nearby Queue', 'dobity-baterky'),
            'manage_options',
            'db-poi-nearby-queue',
            array($this, 'render_page')
        );
    }
    
    public function handle_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'db-poi-nearby-queue') {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Process now
        if (isset($_POST['process_now']) && wp_verify_nonce($_POST['_wpnonce'], 'db_nearby_queue_action')) {
            $this->process_now();
        }
        
        // Update limit per hour
        if (isset($_POST['update_limit']) && wp_verify_nonce($_POST['_wpnonce'], 'db_nearby_queue_action')) {
            $limit = isset($_POST['limit_per_hour']) ? max(1, (int)$_POST['limit_per_hour']) : 500;
            update_option('db_nearby_limit_per_hour', $limit);
            wp_redirect(admin_url('edit.php?post_type=poi&page=db-poi-nearby-queue&limit_updated=1'));
            exit;
        }
        
        // Reset failed
        if (isset($_POST['reset_failed']) && wp_verify_nonce($_POST['_wpnonce'], 'db_nearby_queue_action')) {
            $count = $this->queue_manager->reset_failed_to_pending();
            wp_redirect(admin_url('edit.php?post_type=poi&page=db-poi-nearby-queue&reset=' . $count));
            exit;
        }
        
        // Clear done older than 30d
        if (isset($_POST['clear_done']) && wp_verify_nonce($_POST['_wpnonce'], 'db_nearby_queue_action')) {
            $count = $this->queue_manager->clear_done_older_than(30);
            wp_redirect(admin_url('edit.php?post_type=poi&page=db-poi-nearby-queue&cleared=' . $count));
            exit;
        }
    }
    
    private function process_now() {
        $queue_manager = new POI_Nearby_Queue_Manager();
        $recompute_job = new \DB\Jobs\Nearby_Recompute_Job();
        
        $limit_per_hour = $this->get_limit_per_hour();
        $stats = $queue_manager->get_stats();
        $processed_last_hour = $stats['processed_last_hour'];
        
        if ($processed_last_hour >= $limit_per_hour) {
            wp_redirect(admin_url('edit.php?post_type=poi&page=db-poi-nearby-queue&limit_reached=1'));
            exit;
        }
        
        $items = $queue_manager->get_pending(50);
        $processed = 0;
        $failed = 0;
        $remaining_limit = $limit_per_hour - $processed_last_hour;
        $items_to_process = array_slice($items, 0, min(count($items), $remaining_limit));
        
        foreach ($items_to_process as $item) {
            $queue_id = (int) $item['id'];
            $post_id = (int) $item['post_id'];
            $origin_type = isset($item['origin_type']) ? $item['origin_type'] : 'poi';
            
            $queue_manager->mark_processing($queue_id);
            
            try {
                $target_type = ($origin_type === 'poi') ? 'charging_location' : 'poi';
                $recompute_job->recompute_nearby_for_origin($post_id, $target_type);
                $queue_manager->mark_done($queue_id);
                $processed++;
            } catch (\Throwable $e) {
                $queue_manager->mark_failed($queue_id, $e->getMessage());
                $failed++;
            }
        }
        
        wp_redirect(admin_url('edit.php?post_type=poi&page=db-poi-nearby-queue&processed=' . $processed . '&failed=' . $failed));
        exit;
    }
    
    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Nemáte oprávnění k přístupu na tuto stránku.'));
        }
        
        $stats = $this->queue_manager->get_stats();
        $progress = get_option('db_nearby_progress', array());
        $paused = (bool) get_option('db_nearby_paused', false);
        $limit_per_hour = $this->get_limit_per_hour();
        $failed_items = $this->queue_manager->get_failed_items(20);
        $processed_last_hour = $stats['processed_last_hour'];
        
        // Zobrazit zprávy
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Nastavení uloženo!</p></div>';
        }
        if (isset($_GET['limit_updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Limit per hour aktualizován!</p></div>';
        }
        if (isset($_GET['reset'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Resetováno ' . intval($_GET['reset']) . ' failed záznamů na pending.</p></div>';
        }
        if (isset($_GET['cleared'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Smazáno ' . intval($_GET['cleared']) . ' done záznamů starších než 30 dní.</p></div>';
        }
        if (isset($_GET['processed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Zpracováno: OK=' . intval($_GET['processed']) . ', FAILED=' . intval($_GET['failed'] ?? 0) . '</p></div>';
        }
        if (isset($_GET['limit_reached'])) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Limit per hour dosažen.</strong> Nelze zpracovat další záznamy.</p></div>';
        }
        
        // Stavové hlášky
        if ($paused) {
            echo '<div class="notice notice-warning" id="paused-notice"><p><strong>Fronta je pozastavena.</strong> Zpracování nebude probíhat, dokud ji neobnovíte.</p></div>';
        }
        if ($processed_last_hour >= $limit_per_hour) {
            echo '<div class="notice notice-warning" id="limit-notice"><p><strong>Limit per hour dosažen.</strong> Zpracováno za poslední hodinu: ' . esc_html($processed_last_hour) . ' / ' . esc_html($limit_per_hour) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="card" style="max-width: 800px;">
                <h2>Statistiky</h2>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td><strong>Pending:</strong></td>
                            <td id="stats-pending"><?php echo esc_html($stats['pending']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Processing:</strong></td>
                            <td id="stats-processing"><?php echo esc_html($stats['processing']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Done (dnes):</strong></td>
                            <td id="stats-done-today"><?php echo esc_html($stats['done_today']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Failed:</strong></td>
                            <td id="stats-failed"><?php echo esc_html($stats['failed']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Zpracováno (24h):</strong></td>
                            <td><?php echo esc_html($stats['processed_last_24h']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Zpracováno (1h):</strong></td>
                            <td id="processed-last-hour"><?php echo esc_html($processed_last_hour); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Limit per hour:</strong></td>
                            <td id="limit-per-hour"><?php echo esc_html($limit_per_hour); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td id="queue-status"><?php echo $paused ? '<span style="color: red;">POZASTAVENO</span>' : '<span style="color: green;">AKTIVNÍ</span>'; ?></td>
                        </tr>
                        <?php if (!empty($progress['last_run'])): ?>
                        <tr>
                            <td><strong>Poslední běh:</strong></td>
                            <td id="last-run"><?php echo esc_html($progress['last_run']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>Akce</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('db_nearby_queue_action'); ?>
                    <p>
                        <button type="submit" name="process_now" class="button button-primary">
                            Process now (default batch)
                        </button>
                        <span class="description">Spustí zpracování s default batch (50 záznamů)</span>
                    </p>
                </form>
                
                <form method="post" action="" style="margin-top: 10px;" id="toggle-pause-form">
                    <?php wp_nonce_field('db_nearby_queue_action'); ?>
                    <p>
                        <button type="button" id="toggle-pause-btn" class="button <?php echo $paused ? 'button-primary' : 'button-secondary'; ?>">
                            <span class="spinner" style="float: none; margin: 0 5px 0 0; visibility: hidden;"></span>
                            <?php echo $paused ? 'Resume' : 'Pause'; ?>
                        </button>
                        <span class="description"><?php echo $paused ? 'Obnoví zpracování fronty' : 'Pozastaví zpracování fronty'; ?></span>
                    </p>
                </form>
                
                <form method="post" action="" style="margin-top: 10px;">
                    <?php wp_nonce_field('db_nearby_queue_action'); ?>
                    <p>
                        <label>
                            Limit per hour:
                            <input type="number" name="limit_per_hour" value="<?php echo esc_attr($limit_per_hour); ?>" min="1" max="10000" style="width: 100px; margin-left: 5px;">
                        </label>
                        <button type="submit" name="update_limit" class="button button-secondary" style="margin-left: 10px;">
                            Aktualizovat
                        </button>
                        <span class="description">Maximální počet zpracovaných záznamů za hodinu (default: 2000 staging, 500 prod)</span>
                    </p>
                </form>
                
                <form method="post" action="" style="margin-top: 10px;">
                    <?php wp_nonce_field('db_nearby_queue_action'); ?>
                    <p>
                        <button type="submit" name="reset_failed" class="button button-secondary">
                            Reset failed to pending
                        </button>
                        <span class="description">Vrátí všechny failed záznamy zpět na pending</span>
                    </p>
                </form>
                
                <form method="post" action="" style="margin-top: 10px;">
                    <?php wp_nonce_field('db_nearby_queue_action'); ?>
                    <p>
                        <button type="submit" name="clear_done" class="button button-secondary">
                            Clear done older than 30d
                        </button>
                        <span class="description">Smaže done záznamy starší než 30 dní</span>
                    </p>
                </form>
            </div>
            
            <?php if (!empty($failed_items)): ?>
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>Posledních 20 failed položek</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Post ID</th>
                            <th>Error</th>
                            <th>Attempts</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($failed_items as $item): ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($item['post_id'])); ?>" target="_blank">
                                    <?php echo esc_html($item['post_id']); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html(substr($item['last_error'] ?? '', 0, 100)); ?></td>
                            <td><?php echo esc_html($item['attempts']); ?></td>
                            <td><?php echo esc_html($item['dts'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
        (function($) {
            var refreshInterval;
            var refreshDelay = 20000; // 20 sekund
            
            function updateStats() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'db_nearby_queue_get_stats',
                        _ajax_nonce: '<?php echo wp_create_nonce('db_nearby_queue_ajax'); ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            var data = response.data;
                            $('#stats-pending').text(data.pending || 0);
                            $('#stats-processing').text(data.processing || 0);
                            $('#stats-done-today').text(data.done_today || 0);
                            $('#stats-failed').text(data.failed || 0);
                            $('#processed-last-hour').text(data.processed_last_hour || 0);
                            $('#limit-per-hour').text(data.limit_per_hour || 500);
                            $('#last-run').text(data.last_run || 'Nikdy');
                            
                            // Aktualizovat status
                            var statusHtml = data.paused 
                                ? '<span style="color: red;">POZASTAVENO</span>' 
                                : '<span style="color: green;">AKTIVNÍ</span>';
                            $('#queue-status').html(statusHtml);
                            
                            // Aktualizovat tlačítko pause/resume
                            var btn = $('#toggle-pause-btn');
                            btn.removeClass('button-primary button-secondary');
                            btn.addClass(data.paused ? 'button-primary' : 'button-secondary');
                            btn.html((data.paused ? 'Resume' : 'Pause'));
                            
                            // Zobrazit/skrýt varování
                            var notices = $('.notice.notice-warning');
                            if (data.paused) {
                                if (notices.filter(':contains("pozastavena")').length === 0) {
                                    $('.wrap h1').after('<div class="notice notice-warning"><p><strong>Fronta je pozastavena.</strong> Zpracování nebude probíhat, dokud ji neobnovíte.</p></div>');
                                }
                            } else {
                                notices.filter(':contains("pozastavena")').remove();
                            }
                            
                            if (data.processed_last_hour >= data.limit_per_hour) {
                                if (notices.filter(':contains("Limit per hour")').length === 0) {
                                    $('.wrap h1').after('<div class="notice notice-warning"><p><strong>Limit per hour dosažen.</strong> Zpracováno za poslední hodinu: ' + data.processed_last_hour + ' / ' + data.limit_per_hour + '</p></div>');
                                }
                            } else {
                                notices.filter(':contains("Limit per hour")').remove();
                            }
                        }
                    },
                    error: function() {
                        console.log('Chyba při načítání statistik');
                    }
                });
            }
            
            // Auto-refresh každých 20 sekund
            refreshInterval = setInterval(updateStats, refreshDelay);
            
            // AJAX pause/resume
            $('#toggle-pause-btn').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                var spinner = btn.find('.spinner');
                spinner.css('visibility', 'visible');
                btn.prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'db_nearby_queue_toggle_pause',
                        _ajax_nonce: '<?php echo wp_create_nonce('db_nearby_queue_ajax'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            updateStats();
                        } else {
                            alert('Chyba: ' + (response.data || 'Neznámá chyba'));
                        }
                    },
                    error: function() {
                        alert('Chyba při komunikaci se serverem');
                    },
                    complete: function() {
                        spinner.css('visibility', 'hidden');
                        btn.prop('disabled', false);
                    }
                });
            });
            
            // Zastavit refresh při opuštění stránky
            $(window).on('beforeunload', function() {
                if (refreshInterval) {
                    clearInterval(refreshInterval);
                }
            });
        })(jQuery);
        </script>
        <?php
    }
    
    /**
     * AJAX handler pro získání statistik
     */
    public function ajax_get_stats() {
        check_ajax_referer('db_nearby_queue_ajax', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Nemáte oprávnění'));
            return;
        }
        
        $stats = $this->queue_manager->get_stats();
        $progress = get_option('db_nearby_progress', array());
        $paused = (bool) get_option('db_nearby_paused', false);
        $limit_per_hour = $this->get_limit_per_hour();
        
        wp_send_json_success(array(
            'pending' => $stats['pending'],
            'processing' => $stats['processing'],
            'done_today' => $stats['done_today'],
            'failed' => $stats['failed'],
            'processed_last_hour' => $stats['processed_last_hour'],
            'limit_per_hour' => $limit_per_hour,
            'paused' => $paused,
            'last_run' => isset($progress['last_run']) ? $progress['last_run'] : 'Nikdy',
        ));
    }
    
    /**
     * AJAX handler pro pause/resume
     */
    public function ajax_toggle_pause() {
        check_ajax_referer('db_nearby_queue_ajax', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Nemáte oprávnění'));
            return;
        }
        
        $paused = (bool) get_option('db_nearby_paused', false);
        update_option('db_nearby_paused', !$paused);
        
        wp_send_json_success(array(
            'paused' => !$paused,
            'message' => !$paused ? 'Fronta pozastavena' : 'Fronta obnovena'
        ));
    }
    
    /**
     * AJAX handler pro update limit
     */
    public function ajax_update_limit() {
        check_ajax_referer('db_nearby_queue_ajax', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Nemáte oprávnění'));
            return;
        }
        
        $limit = isset($_POST['limit']) ? max(1, (int)$_POST['limit']) : 500;
        update_option('db_nearby_limit_per_hour', $limit);
        
        wp_send_json_success(array(
            'limit' => $limit,
            'message' => 'Limit aktualizován'
        ));
    }
    
    /**
     * Získá limit per hour z option nebo default hodnotu
     */
    private function get_limit_per_hour(): int {
        $limit = get_option('db_nearby_limit_per_hour');
        if ($limit !== false) {
            return max(1, (int)$limit);
        }
        
        // Default hodnoty podle prostředí
        $is_staging = (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'staging') 
                   || (defined('WP_DEBUG') && WP_DEBUG);
        
        return $is_staging ? 2000 : 500;
    }
}


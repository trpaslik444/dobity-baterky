<?php
declare(strict_types=1);

namespace DB\Admin;

use DB\Jobs\Charging_Discovery_Queue_Manager;
use DB\Jobs\Charging_Discovery_Worker;
use DB\Util\Places_Enrichment_Service;
use DB\Charging_Discovery;

if (!defined('ABSPATH')) { exit; }

/**
 * Admin rozhraní pro Charging Discovery
 * 
 * Přepracováno podle nových principů:
 * - Používá Places_Enrichment_Service místo Charging_Quota_Manager
 * - Google API se volá pouze on-demand při kliknutí na bod
 * - Automatické zařazování nových nabíjecích bodů do fronty
 * - Zpracování všech existujících nabíjecích bodů
 */
class Charging_Discovery_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_db_charging_enqueue_all', [$this, 'handle_enqueue_all']);
        add_action('admin_post_db_charging_enqueue_missing', [$this, 'handle_enqueue_missing']);
        add_action('admin_post_db_charging_dispatch_worker', [$this, 'handle_dispatch_worker']);
        add_action('admin_post_db_charging_process_review', [$this, 'handle_process_review']);
        add_action('admin_post_db_charging_clear_cache', [$this, 'handle_clear_cache']);
        
        // Automatické zařazování nových nabíjecích bodů do fronty
        add_action('save_post', [$this, 'auto_enqueue_on_save'], 10, 2);
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'edit.php?post_type=charging_location',
            'Charging Discovery',
            'Discovery',
            'manage_options',
            'db-charging-discovery',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Automaticky zařadit nový/aktualizovaný nabíjecí bod do fronty
     */
    public function auto_enqueue_on_save(int $post_id, \WP_Post $post): void {
        // Přeskočit autosave a revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // Zkontrolovat, zda je to charging_location
        if (!$post || $post->post_type !== 'charging_location') {
            return;
        }
        
        // Zkontrolovat, zda má souřadnice
        $lat = (float) get_post_meta($post_id, '_db_lat', true);
        $lng = (float) get_post_meta($post_id, '_db_lng', true);
        
        if (!$lat || !$lng) {
            return; // Nemá souřadnice
        }
        
        // Zkontrolovat, zda už má Google Place ID
        $existingGoogleId = (string) get_post_meta($post_id, '_charging_google_place_id', true);
        
        // Pokud nemá Google ID, zařadit do fronty
        if ($existingGoogleId === '') {
            $queue = new Charging_Discovery_Queue_Manager();
            $queue->enqueue($post_id, 0);
        }
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'dobity-baterky'));
        }
        
        $enrichment_service = Places_Enrichment_Service::get_instance();
        $queue = new Charging_Discovery_Queue_Manager();
        
        $message = isset($_GET['db_msg']) ? sanitize_text_field((string) $_GET['db_msg']) : '';
        $notice = isset($_GET['db_notice']) ? sanitize_text_field((string) $_GET['db_notice']) : '';
        
        // Získat statistiky kvót
        $quota_stats = $this->get_quota_statistics($enrichment_service);
        
        echo '<div class="wrap">';
        echo '<h1>Charging Discovery</h1>';
        echo '<p>Správa discovery Google Places ID pro nabíjecí body. Google API se volá pouze on-demand při kliknutí na bod na mapě.</p>';
        
        if ($message !== '') {
            echo '<div class="updated notice"><p>' . esc_html($message) . '</p></div>';
        }
        if ($notice !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($notice) . '</p></div>';
        }
        
        // Statistiky
        echo '<div class="card" style="max-width: 800px; margin: 20px 0;">';
        echo '<h2>Statistiky</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>Google Places API kvóta (dnes)</th><td>';
        echo sprintf(
            '<strong>%d / %d</strong> požadavků (%.1f%%)',
            $quota_stats['used_today'],
            $quota_stats['limit'],
            $quota_stats['limit'] > 0 ? ($quota_stats['used_today'] / $quota_stats['limit'] * 100) : 0
        );
        if ($quota_stats['limit'] > 0 && $quota_stats['used_today'] >= $quota_stats['limit']) {
            echo ' <span style="color: red;">⚠️ Limit vyčerpán</span>';
        } elseif ($quota_stats['limit'] > 0 && $quota_stats['used_today'] >= ($quota_stats['limit'] * 0.8)) {
            echo ' <span style="color: orange;">⚠️ Blíží se limit</span>';
        }
        echo '</td></tr>';
        echo '<tr><th>Fronta (pending)</th><td>' . intval($queue->count_by_status('pending')) . ' položek</td></tr>';
        echo '<tr><th>Dokončené</th><td>' . intval($queue->count_by_status('completed')) . ' položek</td></tr>';
        echo '<tr><th>K potvrzení</th><td>' . intval($queue->count_by_status('review')) . ' položek</td></tr>';
        echo '<tr><th>Chybné</th><td>' . intval($queue->count_by_status('failed')) . ' položek</td></tr>';
        
        // Statistiky z databáze
        global $wpdb;
        $total_charging = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'charging_location' AND post_status = 'publish'");
        $with_google_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'charging_location' 
             AND p.post_status = 'publish'
             AND pm.meta_key = %s
             AND pm.meta_value != ''",
            '_charging_google_place_id'
        ));
        $without_google_id = $total_charging - $with_google_id;
        
        echo '<tr><th>Celkem nabíjecích bodů</th><td>' . $total_charging . '</td></tr>';
        echo '<tr><th>S Google Place ID</th><td>' . $with_google_id . ' (' . ($total_charging > 0 ? round($with_google_id / $total_charging * 100, 1) : 0) . '%)</td></tr>';
        echo '<tr><th>Bez Google Place ID</th><td>' . $without_google_id . ' (' . ($total_charging > 0 ? round($without_google_id / $total_charging * 100, 1) : 0) . '%)</td></tr>';
        echo '</table>';
        echo '</div>';
        
        // Akce
        echo '<div class="card" style="max-width: 800px; margin: 20px 0;">';
        echo '<h2>Akce</h2>';
        echo '<p>';
        echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=db_charging_enqueue_all'), 'db_charging_enqueue_all')) . '">Zařadit všechny nabíjecí body</a> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=db_charging_enqueue_missing'), 'db_charging_enqueue_missing')) . '">Zařadit pouze bez Google ID</a> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=db_charging_dispatch_worker'), 'db_charging_dispatch_worker')) . '">Spustit worker</a> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=db_charging_clear_cache'), 'db_charging_clear_cache')) . '">Vymazat cache</a>';
        echo '</p>';
        echo '<p class="description">';
        echo '<strong>Poznámka:</strong> Google API se nevolá automaticky v batch processoru. ';
        echo 'Volá se pouze on-demand při kliknutí na bod na mapě. ';
        echo 'Batch processor pouze kontroluje, zda už máme Google ID, a označuje body pro on-demand zpracování.';
        echo '</p>';
        echo '</div>';
        
        // Tabs
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'queue';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="?post_type=charging_location&page=db-charging-discovery&tab=queue" class="nav-tab ' . ($tab==='queue'?'nav-tab-active':'') . '">Fronta</a>';
        echo '<a href="?post_type=charging_location&page=db-charging-discovery&tab=completed" class="nav-tab ' . ($tab==='completed'?'nav-tab-active':'') . '">Dokončené</a>';
        echo '<a href="?post_type=charging_location&page=db-charging-discovery&tab=failed" class="nav-tab ' . ($tab==='failed'?'nav-tab-active':'') . '">Chybné</a>';
        echo '<a href="?post_type=charging_location&page=db-charging-discovery&tab=review" class="nav-tab ' . ($tab==='review'?'nav-tab-active':'') . '">K potvrzení</a>';
        echo '</h2>';

        // Obsah podle záložky
        switch ($tab) {
            case 'queue':
                echo '<h2>Fronta (pending)</h2>';
                $pending = $queue->get_by_status('pending', 100, 0);
                if (empty($pending)) {
                    echo '<p>Žádné čekající položky. Nové nabíjecí body se automaticky zařazují do fronty při uložení.</p>';
                } else {
                    $this->render_table($pending);
                }
                break;
                
            case 'completed':
                echo '<h2>Dokončené</h2>';
                $completed = $queue->get_by_status('completed', 100, 0);
                if (empty($completed)) {
                    echo '<p>Žádné dokončené položky.</p>';
                } else {
                    $this->render_table($completed);
                }
                break;
                
            case 'failed':
                echo '<h2>Chybné</h2>';
                $failed = $queue->get_by_status('failed', 50, 0);
                if (empty($failed)) {
                    echo '<p>Žádné chybné položky.</p>';
                } else {
                    $this->render_table($failed);
                }
                break;
                
            case 'review':
                echo '<h2>K potvrzení</h2>';
                echo '<p class="description">Body, které potřebují manuální kontrolu (např. Google ID příliš daleko od GPS souřadnic).</p>';
                $review = $queue->get_by_status('review', 50, 0);
                if (empty($review)) {
                    echo '<p>Žádné položky k potvrzení.</p>';
                } else {
                    $this->render_table($review, true);
                }
                break;
        }

        echo '</div>';
    }

    /**
     * Získat statistiky kvót z Places_Enrichment_Service
     */
    private function get_quota_statistics(Places_Enrichment_Service $service): array {
        global $wpdb;
        $today = gmdate('Y-m-d');
        $table = $wpdb->prefix . 'db_places_usage';
        
        // Zkontrolovat, zda tabulka existuje
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($table_exists !== $table) {
            return [
                'used_today' => 0,
                'limit' => $service->get_daily_cap(),
                'enabled' => $service->is_enabled(),
            ];
        }
        
        $usage = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(request_count) FROM {$table} WHERE usage_date = %s AND api_name IN ('places_details', 'places_textsearch', 'places_search_nearby')",
            $today
        ));
        
        return [
            'used_today' => (int) ($usage ?? 0),
            'limit' => $service->get_daily_cap(),
            'enabled' => $service->is_enabled(),
        ];
    }

    /**
     * Zařadit všechny nabíjecí body do fronty
     */
    public function handle_enqueue_all(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'dobity-baterky'));
        }
        check_admin_referer('db_charging_enqueue_all');
        
        $queue = new Charging_Discovery_Queue_Manager();
        $result = $queue->enqueue_missing_batch(10000); // Velké číslo pro všechny
        
        $message = sprintf(
            'Do fronty přidáno %d položek, přeskočeno %d. Všechny nabíjecí body jsou nyní ve frontě pro zpracování.',
            (int) ($result['enqueued'] ?? 0),
            (int) ($result['skipped'] ?? 0)
        );
        
        wp_safe_redirect(add_query_arg('db_msg', $message, admin_url('edit.php?post_type=charging_location&page=db-charging-discovery')));
        exit;
    }

    /**
     * Zařadit pouze nabíjecí body bez Google ID
     */
    public function handle_enqueue_missing(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'dobity-baterky'));
        }
        check_admin_referer('db_charging_enqueue_missing');
        
        $queue = new Charging_Discovery_Queue_Manager();
        $result = $queue->enqueue_missing_batch(500);
        
        $message = sprintf(
            'Do fronty přidáno %d položek, přeskočeno %d.',
            (int) ($result['enqueued'] ?? 0),
            (int) ($result['skipped'] ?? 0)
        );
        
        wp_safe_redirect(add_query_arg('db_msg', $message, admin_url('edit.php?post_type=charging_location&page=db-charging-discovery')));
        exit;
    }

    /**
     * Spustit worker
     */
    public function handle_dispatch_worker(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'dobity-baterky'));
        }
        check_admin_referer('db_charging_dispatch_worker');
        
        $ok = Charging_Discovery_Worker::dispatch(0) ? 'Worker spuštěn.' : 'Worker již běží.';
        wp_safe_redirect(add_query_arg('db_msg', $ok, admin_url('edit.php?post_type=charging_location&page=db-charging-discovery')));
        exit;
    }

    /**
     * Zpracovat položky k potvrzení (review)
     */
    public function handle_process_review(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'dobity-baterky'));
        }
        check_admin_referer('db_charging_process_review');
        
        $queue_id = isset($_POST['queue_id']) ? (int) $_POST['queue_id'] : 0;
        $action = isset($_POST['review_action']) ? sanitize_text_field($_POST['review_action']) : '';
        
        if (!$queue_id || !$action) {
            wp_safe_redirect(add_query_arg('db_notice', 'Chybí parametry.', admin_url('edit.php?post_type=charging_location&page=db-charging-discovery&tab=review')));
            exit;
        }
        
        $queue = new Charging_Discovery_Queue_Manager();
        $item = $queue->get_by_status('review', 1000, 0);
        $item = array_filter($item, fn($i) => (int) $i->id === $queue_id);
        $item = reset($item);
        
        if (!$item) {
            wp_safe_redirect(add_query_arg('db_notice', 'Položka nenalezena.', admin_url('edit.php?post_type=charging_location&page=db-charging-discovery&tab=review')));
            exit;
        }
        
        if ($action === 'approve') {
            // Schválit - označit jako completed
            $queue->mark_completed((int) $item->id);
            $message = 'Položka schválena.';
        } elseif ($action === 'reject') {
            // Zamítnout - označit jako failed
            $queue->mark_failed_or_retry((int) $item->id, 'Zamítnuto v review');
            $message = 'Položka zamítnuta.';
        } else {
            $message = 'Neplatná akce.';
        }
        
        wp_safe_redirect(add_query_arg('db_msg', $message, admin_url('edit.php?post_type=charging_location&page=db-charging-discovery&tab=review')));
        exit;
    }

    /**
     * Vymazat cache pro všechny nabíjecí body
     */
    public function handle_clear_cache(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'dobity-baterky'));
        }
        check_admin_referer('db_charging_clear_cache');
        
        global $wpdb;
        $deleted = $wpdb->query("
            DELETE FROM {$wpdb->postmeta}
            WHERE meta_key IN ('_charging_google_cache', '_charging_google_cache_expires', '_charging_live_status', '_charging_live_status_expires')
        ");
        
        $message = sprintf('Vymazáno %d cache záznamů.', $deleted);
        wp_safe_redirect(add_query_arg('db_msg', $message, admin_url('edit.php?post_type=charging_location&page=db-charging-discovery')));
        exit;
    }

    /**
     * Vykreslit tabulku s položkami
     */
    private function render_table(array $items, bool $show_actions = false): void {
        echo '<table class="wp-list-table widefat fixed striped">'
            . '<thead><tr>'
            . '<th>ID</th>'
            . '<th>Nabíječka</th>'
            . '<th>Status</th>'
            . '<th>Provider</th>'
            . '<th>Matched ID</th>'
            . '<th>Score/Reason</th>'
            . '<th>Aktualizováno</th>';
        if ($show_actions) {
            echo '<th>Akce</th>';
        }
        echo '</tr></thead><tbody>';
        
        foreach ($items as $item) {
            $stationId = (int) $item->station_id;
            $title = get_the_title($stationId);
            if (!is_string($title) || $title === '') {
                $title = 'Charging #' . $stationId;
            }
            $editUrl = esc_url(admin_url('post.php?post=' . $stationId . '&action=edit'));
            
            // Zkontrolovat, zda má Google ID
            $googleId = (string) get_post_meta($stationId, '_charging_google_place_id', true);
            $hasGoogleId = $googleId !== '';
            
            echo '<tr>';
            echo '<td>' . intval($item->id) . '</td>';
            echo '<td>';
            echo '<a href="' . $editUrl . '"><strong>' . esc_html($title) . '</strong></a><br>';
            echo '<span style="color:#666">ID: ' . $stationId . '</span>';
            if ($hasGoogleId) {
                echo ' <span style="color: green;">✓ Má Google ID</span>';
            } else {
                echo ' <span style="color: orange;">⚠ Bez Google ID</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html($item->status) . '</td>';
            echo '<td>' . esc_html($item->matched_provider ?? '') . '</td>';
            echo '<td>' . esc_html($item->matched_id ?? '') . '</td>';
            echo '<td>' . esc_html($item->matched_score ?? '') . '</td>';
            echo '<td>' . esc_html($item->updated_at ?? '') . '</td>';
            
            if ($show_actions) {
                echo '<td>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display: inline;">';
                wp_nonce_field('db_charging_process_review');
                echo '<input type="hidden" name="action" value="db_charging_process_review" />';
                echo '<input type="hidden" name="queue_id" value="' . intval($item->id) . '" />';
                echo '<input type="hidden" name="review_action" value="approve" />';
                echo '<button type="submit" class="button button-small">Schválit</button>';
                echo '</form> ';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display: inline;">';
                wp_nonce_field('db_charging_process_review');
                echo '<input type="hidden" name="action" value="db_charging_process_review" />';
                echo '<input type="hidden" name="queue_id" value="' . intval($item->id) . '" />';
                echo '<input type="hidden" name="review_action" value="reject" />';
                echo '<button type="submit" class="button button-small">Zamítnout</button>';
                echo '</form>';
                echo '</td>';
            }
            
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
}

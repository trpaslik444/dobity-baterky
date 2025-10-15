<?php
declare(strict_types=1);

namespace DB\Admin;

use DB\Jobs\Charging_Discovery_Queue_Manager;
use DB\Jobs\Charging_Discovery_Worker;
use DB\Jobs\Charging_Quota_Manager;

if (!defined('ABSPATH')) { exit; }

class Charging_Discovery_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_db_charging_enqueue_missing', [$this, 'handle_enqueue_missing']);
        add_action('admin_post_db_charging_dispatch_worker', [$this, 'handle_dispatch_worker']);
        add_action('admin_post_db_charging_update_quotas', [$this, 'handle_update_quotas']);
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'edit.php?post_type=charging_location',
            'Charging Discovery fronta',
            'Discovery fronta',
            'manage_options',
            'db-charging-discovery-queue',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'dobity-baterky'));
        }
        $quota = new Charging_Quota_Manager();
        $queue = new Charging_Discovery_Queue_Manager();
        $status = $quota->get_status();
        $message = isset($_GET['db_msg']) ? sanitize_text_field((string) $_GET['db_msg']) : '';
        $notice = isset($_GET['db_notice']) ? sanitize_text_field((string) $_GET['db_notice']) : '';
        echo '<div class="wrap">';
        echo '<h1>Charging Discovery – fronta</h1>';
        if ($message !== '') {
            echo '<div class="updated notice"><p>' . esc_html($message) . '</p></div>';
        }
        if ($notice !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<h2>Kvóty</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('db_charging_update_quotas', '_wpnonce_db_charging_quotas');
        echo '<input type="hidden" name="action" value="db_charging_update_quotas" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Google použito</th><td><input type="number" name="g_used" value="' . intval($status['google']['used']) . '" /></td></tr>';
        echo '<tr><th>Google měsíční limit</th><td><input type="number" name="g_total" value="' . intval($status['google']['total']) . '" /></td></tr>';
        echo '<tr><th>OCM použito</th><td><input type="number" name="ocm_used" value="' . intval($status['open_charge_map']['used']) . '" /></td></tr>';
        echo '<tr><th>OCM měsíční limit</th><td><input type="number" name="ocm_total" value="' . intval($status['open_charge_map']['total']) . '" /></td></tr>';
        echo '<tr><th>Bezpečnostní buffer (abs.)</th><td><input type="number" name="buffer_abs" value="' . intval($status['buffer_abs'] ?? 0) . '" /></td></tr>';
        echo '</tbody></table>';
        submit_button('Uložit kvóty');
        echo '</form>';

        $last = get_option('db_charging_last_batch');
        if (is_array($last)) {
            echo '<div class="notice notice-info" style="padding:10px 12px;margin:10px 0;">'
                . '<strong>Poslední běh workeru:</strong> ' . esc_html($last['ts'] ?? '')
                . ' — zpracováno: ' . intval($last['processed'] ?? 0)
                . ', chyby: ' . intval($last['errors'] ?? 0)
                . ', Google: ' . intval($last['usedGoogle'] ?? 0)
                . ', OCM: ' . intval($last['usedOpenChargeMap'] ?? 0)
                . '</div>';
        }

        echo '<h2>Akce</h2>';
        echo '<p>';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=db_charging_enqueue_missing'), 'db_charging_enqueue_missing')) . '">Zařadit chybějící</a> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=db_charging_dispatch_worker'), 'db_charging_dispatch_worker')) . '">Spustit worker</a>';
        echo '</p>';

        echo '<h2>Fronta</h2>';
        $pending = $queue->get_by_status('pending', 100, 0);
        if (empty($pending)) {
            echo '<p>Žádné čekající položky.</p>';
        } else {
            $this->render_table($pending);
        }

        echo '<h2>Chybné</h2>';
        $failed = $queue->get_by_status('failed', 50, 0);
        if (empty($failed)) {
            echo '<p>Žádné chybné položky.</p>';
        } else {
            $this->render_table($failed);
        }

        echo '</div>';
    }

    public function handle_enqueue_missing(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'dobity-baterky'));
        }
        check_admin_referer('db_charging_enqueue_missing');
        $queue = new Charging_Discovery_Queue_Manager();
        $result = $queue->enqueue_missing_batch(500);
        $message = sprintf('Do fronty přidáno %d položek, přeskočeno %d.', (int) ($result['enqueued'] ?? 0), (int) ($result['skipped'] ?? 0));
        wp_safe_redirect(add_query_arg('db_msg', $message, admin_url('edit.php?post_type=charging_location&page=db-charging-discovery-queue')));
        exit;
    }

    public function handle_dispatch_worker(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'dobity-baterky'));
        }
        check_admin_referer('db_charging_dispatch_worker');
        $ok = Charging_Discovery_Worker::dispatch(0) ? 'Worker spuštěn.' : 'Worker již běží.';
        wp_safe_redirect(add_query_arg('db_msg', $ok, admin_url('edit.php?post_type=charging_location&page=db-charging-discovery-queue')));
        exit;
    }

    public function handle_update_quotas(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'dobity-baterky'));
        }
        check_admin_referer('db_charging_update_quotas', '_wpnonce_db_charging_quotas');
        $gUsed = isset($_POST['g_used']) ? (int) $_POST['g_used'] : 0;
        $gTotal = isset($_POST['g_total']) ? (int) $_POST['g_total'] : 0;
        $ocmUsed = isset($_POST['ocm_used']) ? (int) $_POST['ocm_used'] : 0;
        $ocmTotal = isset($_POST['ocm_total']) ? (int) $_POST['ocm_total'] : 0;
        $buffer = isset($_POST['buffer_abs']) ? (int) $_POST['buffer_abs'] : 0;
        $quota = new Charging_Quota_Manager();
        $quota->set_totals($gTotal, $ocmTotal, $buffer);
        $quota->set_used($gUsed, $ocmUsed);
        wp_safe_redirect(add_query_arg('db_msg', 'Kvóty uloženy.', admin_url('edit.php?post_type=charging_location&page=db-charging-discovery-queue')));
        exit;
    }

    private function render_table(array $items): void {
        echo '<table class="wp-list-table widefat fixed striped">'
            . '<thead><tr>'
            . '<th>ID</th>'
            . '<th>Nabíječka</th>'
            . '<th>Status</th>'
            . '<th>Provider</th>'
            . '<th>Matched ID</th>'
            . '<th>Score</th>'
            . '<th>Aktualizováno</th>'
            . '</tr></thead><tbody>';
        foreach ($items as $item) {
            $stationId = (int) $item->station_id;
            $title = get_the_title($stationId);
            if (!is_string($title) || $title === '') {
                $title = 'Charging #' . $stationId;
            }
            $editUrl = esc_url(admin_url('post.php?post=' . $stationId . '&action=edit'));
            echo '<tr>'
                . '<td>' . intval($item->id) . '</td>'
                . '<td><a href="' . $editUrl . '"><strong>' . esc_html($title) . '</strong></a><br><span style="color:#666">ID: ' . $stationId . '</span></td>'
                . '<td>' . esc_html($item->status) . '</td>'
                . '<td>' . esc_html($item->matched_provider ?? '') . '</td>'
                . '<td>' . esc_html($item->matched_id ?? '') . '</td>'
                . '<td>' . esc_html($item->matched_score ?? '') . '</td>'
                . '<td>' . esc_html($item->updated_at ?? '') . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }
}

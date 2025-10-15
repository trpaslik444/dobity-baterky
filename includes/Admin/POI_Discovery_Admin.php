<?php
declare(strict_types=1);

namespace DB\Admin;

use DB\Jobs\POI_Discovery_Queue_Manager;
use DB\Jobs\POI_Discovery_Worker;
use DB\Jobs\POI_Quota_Manager;

if (!defined('ABSPATH')) { exit; }

class POI_Discovery_Admin {

	public function __construct() {
		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('wp_ajax_db_poi_enqueue_all', [$this, 'ajax_enqueue_all']);
		add_action('wp_ajax_db_poi_enqueue_ten', [$this, 'ajax_enqueue_ten']);
		add_action('wp_ajax_db_poi_dispatch_worker', [$this, 'ajax_dispatch_worker']);
		add_action('wp_ajax_db_poi_refresh_quotas', [$this, 'ajax_refresh_quotas']);
        add_action('wp_ajax_db_poi_review_confirm', [$this, 'ajax_review_confirm']);
        add_action('wp_ajax_db_poi_review_reject', [$this, 'ajax_review_reject']);
	}

	public function add_admin_menu(): void {
		add_submenu_page(
			'edit.php?post_type=poi',
			'POI Discovery fronta',
			'Discovery fronta',
			'manage_options',
			'db-poi-discovery-queue',
			[$this, 'render_admin_page']
		);
	}

	public function render_admin_page(): void {
		$quota = new POI_Quota_Manager();
		$status = $quota->get_status();
		echo '<div class="wrap">';
		echo '<h1>POI Discovery – fronta</h1>';
		echo '<p>Správa fronty pro automatické přiřazení Google Places / Tripadvisor ID.</p>';
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'queue';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="?post_type=poi&page=db-poi-discovery-queue&tab=queue" class="nav-tab ' . ($tab==='queue'?'nav-tab-active':'') . '">Fronta</a>';
        echo '<a href="?post_type=poi&page=db-poi-discovery-queue&tab=completed" class="nav-tab ' . ($tab==='completed'?'nav-tab-active':'') . '">Dokončené</a>';
        echo '<a href="?post_type=poi&page=db-poi-discovery-queue&tab=failed" class="nav-tab ' . ($tab==='failed'?'nav-tab-active':'') . '">Chybné</a>';
        echo '<a href="?post_type=poi&page=db-poi-discovery-queue&tab=review" class="nav-tab ' . ($tab==='review'?'nav-tab-active':'') . '">K potvrzení</a>';
        echo '</h2>';

        echo '<h2>Kvóty</h2>';
        echo '<form method="post" style="margin-bottom:10px">';
        echo '<input type="hidden" name="db_poi_quota_update" value="1" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Google použito</th><td><input type="number" name="g_used" value="' . intval($status['google']['used']) . '" /></td></tr>';
        echo '<tr><th>Google měsíční limit</th><td><input type="number" name="g_total" value="' . intval($status['google']['total']) . '" /></td></tr>';
        echo '<tr><th>Tripadvisor použito</th><td><input type="number" name="ta_used" value="' . intval($status['tripadvisor']['used']) . '" /></td></tr>';
        echo '<tr><th>Tripadvisor měsíční limit</th><td><input type="number" name="ta_total" value="' . intval($status['tripadvisor']['total']) . '" /></td></tr>';
        echo '<tr><th>Bezpečnostní buffer (abs.)</th><td><input type="number" name="buffer_abs" value="' . intval($status['buffer_abs'] ?? 300) . '" /></td></tr>';
        echo '</tbody></table>';
        submit_button('Uložit kvóty');
        echo '</form>';

        if (!empty($_POST['db_poi_quota_update']) && current_user_can('manage_options')) {
            check_admin_referer(); // best-effort, vyhneme se fatálnímu pádu, nonce tu není povinná
            $g_used = intval($_POST['g_used'] ?? 0);
            $g_total = intval($_POST['g_total'] ?? 0);
            $ta_used = intval($_POST['ta_used'] ?? 0);
            $ta_total = intval($_POST['ta_total'] ?? 0);
            $buffer = intval($_POST['buffer_abs'] ?? 300);
            $qm = new \DB\Jobs\POI_Quota_Manager();
            $qm->set_totals($g_total, $ta_total, $buffer);
            $qm->set_used($g_used, $ta_used);
            echo '<div class="updated notice"><p>Nastavení uloženo.</p></div>';
            $status = $qm->get_status();
        }

		// rychlý refresh kvót
		echo '<p><button class="button" id="db-poi-refresh-quotas">Aktualizovat stav kvót</button></p>';
		
        $last = get_option('db_poi_last_batch');
        if (is_array($last)) {
            echo '<div class="notice notice-info" style="padding:10px 12px;margin:10px 0;">'
                . '<strong>Poslední běh workeru:</strong> ' . esc_html($last['ts'] ?? '')
                . ' — zpracováno: ' . intval($last['processed'] ?? 0)
                . ', chyb: ' . intval($last['errors'] ?? 0)
                . ', Google: ' . intval($last['usedGoogle'] ?? 0)
                . ', Tripadvisor: ' . intval($last['usedTripadvisor'] ?? 0)
                . '</div>';
        }

        echo '<h2>Akce</h2>';
        // Odstraněny enqueue/worker akce v on-demand režimu. Ponecháme pouze refresh kvót.
        echo '<p><em>On-demand režim zapnut. Enqueue/Worker tlačítka jsou skryta.</em></p>';
		
		echo '<div id="db-poi-discovery-log" style="margin-top:10px"></div>';

        // tabulky podle tabu
        $qm = new POI_Discovery_Queue_Manager();
        if ($tab === 'queue') {
            $items = $qm->get_by_status('pending', 200, 0);
            echo '<h2>Fronta (čekající)</h2>';
            $this->render_table($items);
        } elseif ($tab === 'completed') {
            echo '<h2>Dokončené (POI s API ID)</h2>';
            $this->render_completed_pois(200);
        } elseif ($tab === 'failed') {
            $items = $qm->get_by_status('failed', 200, 0);
            echo '<h2>Chybné</h2>';
            $this->render_table($items);
        } elseif ($tab === 'review') {
            echo '<h2>K potvrzení</h2>';
            $this->render_review_posts(200);
        } else {
            $items = $qm->get_all(200, 0);
            echo '<h2>Vše</h2>';
            $this->render_table($items);
        }
        echo '<script> (function(){
            function log(t){ var el=document.getElementById("db-poi-discovery-log"); el.innerHTML = "<pre>"+t+"</pre>"; }
            function toForm(params){ return Object.entries(params).map(([k,v])=> encodeURIComponent(k)+"="+encodeURIComponent(v)).join("&"); }
            function post(action){
                return fetch(ajaxurl, { method: "POST", headers: {"Content-Type": "application/x-www-form-urlencoded"}, body: toForm({ action, _wpnonce: "' . wp_create_nonce('db_poi_discovery') . '" }) })
                    .then(async r => { const text = await r.text(); try { return JSON.parse(text); } catch(e){ return { error: "non_json", raw: text }; } });
            }
            document.getElementById("db-poi-enqueue-all").addEventListener("click", function(){ log("Pracuji..."); post("db_poi_enqueue_all").then(j=>{ log(JSON.stringify(j,null,2)); }); });
            document.getElementById("db-poi-dispatch").addEventListener("click", function(){ log("Spouštím worker..."); post("db_poi_dispatch_worker").then(j=>{ log(JSON.stringify(j,null,2)); }); });
            document.getElementById("db-poi-enqueue-ten").addEventListener("click", function(){ log("Pracuji (10)..."); post("db_poi_enqueue_ten").then(j=>{ log(JSON.stringify(j,null,2)); }); });
            document.getElementById("db-poi-refresh-quotas").addEventListener("click", function(){ log("Načítám kvóty..."); fetch(ajaxurl+"?action=db_poi_refresh_quotas", {credentials:"include"}).then(r=>r.json()).then(j=>{ location.reload(); }).catch(()=>location.reload()); });
        })(); </script>';
        echo '</div>';
    }
    private function render_completed_pois(int $limit = 200): void {
        $args = array(
            'post_type' => 'poi',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'modified',
            'order' => 'DESC',
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => '_poi_google_place_id', 'compare' => 'EXISTS'),
                array('key' => '_poi_tripadvisor_location_id', 'compare' => 'EXISTS'),
            ),
        );
        $posts = get_posts($args);
        if (empty($posts)) { echo '<p>Žádná data.</p>'; return; }
        echo '<table class="wp-list-table widefat fixed striped">'
            . '<thead><tr>'
            . '<th>POI</th>'
            . '<th>Google Place ID</th>'
            . '<th>Tripadvisor ID</th>'
            . '<th>Created</th>'
            . '<th>Updated</th>'
            . '</tr></thead><tbody>';
        foreach ($posts as $p) {
            $title = get_the_title($p->ID);
            $edit_url = esc_url(admin_url('post.php?post=' . (int)$p->ID . '&action=edit'));
            $g = get_post_meta($p->ID, '_poi_google_place_id', true);
            $ta = get_post_meta($p->ID, '_poi_tripadvisor_location_id', true);
            echo '<tr>'
                . '<td><a href="' . $edit_url . '"><strong>' . esc_html($title) . '</strong></a><br><span style="color:#666">ID: ' . (int)$p->ID . '</span></td>'
                . '<td>' . esc_html($g) . '</td>'
                . '<td>' . esc_html($ta) . '</td>'
                . '<td>' . esc_html(mysql2date('Y-m-d H:i', $p->post_date)) . '</td>'
                . '<td>' . esc_html(mysql2date('Y-m-d H:i', $p->post_modified)) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
	}

    private function render_table(array $items): void {
        if (empty($items)) { echo '<p>Žádné položky.</p>'; return; }
        echo '<table class="wp-list-table widefat fixed striped">'
            . '<thead><tr>'
            . '<th>ID</th>'
            . '<th>POI</th>'
            . '<th>Status</th>'
            . '<th>Provider</th>'
            . '<th>Matched ID</th>'
            . '<th>Score</th>'
            . '<th>Created</th>'
            . '<th>Updated</th>'
            . '</tr></thead><tbody>';
        foreach ($items as $it) {
            $poi_id = (int)$it->poi_id;
            $title = get_the_title($poi_id);
            if (!is_string($title) || $title === '') { $title = 'POI #' . $poi_id; }
            $edit_url = esc_url(admin_url('post.php?post=' . $poi_id . '&action=edit'));
            $poi_cell = '<a href="' . $edit_url . '"><strong>' . esc_html($title) . '</strong></a><br><span style="color:#666">ID: ' . $poi_id . '</span>';

            echo '<tr>'
                . '<td>' . intval($it->id) . '</td>'
                . '<td>' . $poi_cell . '</td>'
                . '<td>' . esc_html($it->status) . '</td>'
                . '<td>' . esc_html($it->matched_provider ?? '') . '</td>'
                . '<td>' . esc_html($it->matched_id ?? '') . '</td>'
                . '<td>' . esc_html($it->matched_score !== null ? number_format((float)$it->matched_score, 2) : '') . '</td>'
                . '<td>' . esc_html($it->created_at) . '</td>'
                . '<td>' . esc_html($it->updated_at) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }

    private function render_review_posts(int $limit = 200): void {
        $posts = get_posts(array(
            'post_type' => 'poi',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_query' => array(
                array('key' => '_poi_review_candidates', 'compare' => 'EXISTS')
            ),
        ));
        if (empty($posts)) { echo '<p>Žádné položky k potvrzení.</p>'; return; }
        echo '<table class="wp-list-table widefat fixed striped">'
            . '<thead><tr>'
            . '<th>POI</th>'
            . '<th>Kandidáti</th>'
            . '<th>Akce</th>'
            . '</tr></thead><tbody>';
        foreach ($posts as $p) {
            $c = get_post_meta($p->ID, '_poi_review_candidates', true);
            $cands = is_string($c) ? json_decode($c, true) : (is_array($c) ? $c : array());
            if (!is_array($cands)) $cands = array();
            $edit_url = esc_url(admin_url('post.php?post=' . (int)$p->ID . '&action=edit'));
            echo '<tr>'
                . '<td><a href="' . $edit_url . '"><strong>' . esc_html(get_the_title($p->ID)) . '</strong></a><br><span style="color:#666">ID: ' . (int)$p->ID . '</span></td>'
                . '<td>';
            if (empty($cands)) {
                echo '<em>Žádní kandidáti</em>';
            } else {
                echo '<ul style="margin:0;">';
                foreach ($cands as $cand) {
                    $prov = esc_html($cand['provider'] ?? '');
                    $cid  = esc_html($cand['id'] ?? '');
                    $name = esc_html($cand['name'] ?? '');
                    $addr = esc_html($cand['address'] ?? '');
                    $sc   = isset($cand['score']) ? number_format((float)$cand['score'], 2) : '';
                    echo '<li>' . $prov . ' • <code>' . $cid . '</code> • <strong>' . $name . '</strong><br><span style="color:#666">' . $addr . '</span> <span style="color:#666">(score ' . $sc . ')</span></li>';
                }
                echo '</ul>';
            }
            echo '</td>'
                . '<td>'
                . '<button class="button button-primary db-poi-confirm" data-poi="' . (int)$p->ID . '">Potvrdit (první)</button> '
                . '<button class="button db-poi-reject" data-poi="' . (int)$p->ID . '">Zamítnout</button>'
                . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';

        // Inline JS pro akce confirm/reject (potvrzuje prvního kandidáta)
        echo '<script>(function(){
            function toForm(o){return Object.entries(o).map(function(kv){return encodeURIComponent(kv[0])+"="+encodeURIComponent(kv[1]);}).join("&");}
            function post(action, params){return fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:toForm(Object.assign({action:action,_wpnonce:"' . wp_create_nonce('db_poi_discovery') . '"},params))}).then(function(r){return r.json();});}
            document.querySelectorAll(".db-poi-confirm").forEach(function(btn){btn.addEventListener("click",function(){var tr=btn.closest("tr");var poi=btn.getAttribute("data-poi");post("db_poi_review_confirm",{poi_id:poi}).then(function(){location.reload();});});});
            document.querySelectorAll(".db-poi-reject").forEach(function(btn){btn.addEventListener("click",function(){var poi=btn.getAttribute("data-poi");post("db_poi_review_reject",{poi_id:poi}).then(function(){location.reload();});});});
        })();</script>';
    }

    public function ajax_review_confirm() {
        check_ajax_referer('db_poi_discovery', '_wpnonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
        $poi_id = intval($_POST['poi_id'] ?? 0);
        if ($poi_id <= 0) wp_send_json_error('bad_request', 400);
        $c = get_post_meta($poi_id, '_poi_review_candidates', true);
        $cands = is_string($c) ? json_decode($c, true) : (is_array($c) ? $c : array());
        if (!is_array($cands) || empty($cands)) wp_send_json_error('no_candidates', 400);
        $cand = $cands[0];
        $provider = (string)($cand['provider'] ?? '');
        $id = (string)($cand['id'] ?? '');
        if ($provider === 'google_places' && $id !== '') {
            update_post_meta($poi_id, '_poi_google_place_id', $id);
            // pokus o korekci GPS z Details
            $api_key = get_option('db_google_api_key');
            if (!empty($api_key)) {
                $url = add_query_arg(array('place_id'=>$id,'fields'=>'geometry','key'=>$api_key),'https://maps.googleapis.com/maps/api/place/details/json');
                $resp = wp_remote_get($url, array('timeout'=>10));
                if (!is_wp_error($resp)) {
                    $data = json_decode((string)wp_remote_retrieve_body($resp), true);
                    $loc = $data['result']['geometry']['location'] ?? null;
                    if (is_array($loc) && isset($loc['lat'],$loc['lng'])) {
                        update_post_meta($poi_id, '_poi_lat', (float)$loc['lat']);
                        update_post_meta($poi_id, '_poi_lng', (float)$loc['lng']);
                    }
                }
            }
        } elseif ($provider === 'tripadvisor' && $id !== '') {
            update_post_meta($poi_id, '_poi_tripadvisor_location_id', $id);
        }
        delete_post_meta($poi_id, '_poi_review_candidates');
        wp_send_json_success(array('ok'=>true));
    }

    public function ajax_review_reject() {
        check_ajax_referer('db_poi_discovery', '_wpnonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
        $poi_id = intval($_POST['poi_id'] ?? 0);
        if ($poi_id <= 0) wp_send_json_error('bad_request', 400);
        delete_post_meta($poi_id, '_poi_review_candidates');
        wp_send_json_success(array('ok'=>true));
    }
    public function ajax_enqueue_all() {
        check_ajax_referer('db_poi_discovery', '_wpnonce');
		if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
		$qm = new POI_Discovery_Queue_Manager();
		$added = 0; $skipped = 0;
        $stats = $qm->enqueue_missing_batch(1000);
        $added = $stats['enqueued'];
        $skipped = $stats['skipped'];
        \DB\Jobs\POI_Discovery_Worker::dispatch(1);
        wp_send_json_success(array('enqueued' => $added, 'skipped' => $skipped, 'dispatched' => true));
	}

    public function ajax_enqueue_ten() {
        check_ajax_referer('db_poi_discovery', '_wpnonce');
		if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
		$qm = new POI_Discovery_Queue_Manager();
		$added = 0; $skipped = 0;
        $ids = get_posts(array(
			'post_type' => 'poi',
			'post_status' => 'publish',
			'fields' => 'ids',
			'posts_per_page' => 10,
			'orderby' => 'date',
			'order' => 'DESC',
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => '_poi_google_place_id', 'compare' => 'NOT EXISTS'),
                array('key' => '_poi_google_place_id', 'value' => '', 'compare' => '='),
                array('key' => '_poi_tripadvisor_location_id', 'compare' => 'NOT EXISTS'),
                array('key' => '_poi_tripadvisor_location_id', 'value' => '', 'compare' => '=')
            )
		));
		if (!is_array($ids)) $ids = array();
        foreach ($ids as $pid) {
            if ($qm->enqueue((int)$pid, 0)) $added++; else $skipped++;
        }
        \DB\Jobs\POI_Discovery_Worker::dispatch(1);
        wp_send_json_success(array('enqueued' => $added, 'skipped' => $skipped, 'dispatched' => true));
	}

    public function ajax_dispatch_worker() {
        check_ajax_referer('db_poi_discovery', '_wpnonce');
		if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
		POI_Discovery_Worker::dispatch(1);
		wp_send_json_success(array('status' => 'dispatched'));
	}

	public function ajax_refresh_quotas() {
		if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
		$q = new POI_Quota_Manager();
		wp_send_json_success($q->get_status());
	}
}



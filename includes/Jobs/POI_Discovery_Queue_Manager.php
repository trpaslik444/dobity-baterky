<?php
/**
 * POI Discovery Queue Manager - DB fronta pro objevování externích ID
 */

namespace DB\Jobs;

class POI_Discovery_Queue_Manager {

	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'db_poi_discovery_queue';
        $this->ensure_table_exists();
	}

	public function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE {$this->table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			poi_id BIGINT(20) UNSIGNED NOT NULL,
			priority INT(11) DEFAULT 0,
			status VARCHAR(20) DEFAULT 'pending',
			attempts INT(11) DEFAULT 0,
			max_attempts INT(11) DEFAULT 3,
			error_message TEXT NULL,
			matched_provider VARCHAR(20) NULL,
			matched_id VARCHAR(128) NULL,
			matched_score FLOAT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			processed_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY poi_idx (poi_id),
			KEY status_idx (status),
			KEY prio_idx (priority)
		) {$charset};";
		dbDelta($sql);
	}

    private function ensure_table_exists(): void {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table));
        if ($exists !== $this->table) {
            $this->create_table();
        }
    }

	public function enqueue(int $poi_id, int $priority = 0): bool {
        global $wpdb;
        $this->ensure_table_exists();
		$row = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$this->table} WHERE poi_id=%d LIMIT 1", $poi_id));
		if ($row) {
			$status = (string)$row->status;
			if (in_array($status, array('pending','processing','review'), true)) {
				return false; // už je ve frontě
			}
			// requeue z completed/failed
			return (bool)$wpdb->update($this->table, array(
				'status' => 'pending',
				'priority' => $priority,
				'attempts' => 0,
				'error_message' => null,
				'matched_provider' => null,
				'matched_id' => null,
				'matched_score' => null,
				'processed_at' => null,
			), array('id' => (int)$row->id), array('%s','%d','%d','%s','%s','%s','%f','%s'), array('%d'));
		}
		return (bool)$wpdb->insert($this->table, array(
			'poi_id' => $poi_id,
			'priority' => $priority,
			'status' => 'pending',
		), array('%d','%d','%s'));
	}

	public function get_pending(int $limit = 10): array {
        global $wpdb;
        $this->ensure_table_exists();
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE status='pending' ORDER BY priority DESC, created_at ASC LIMIT %d", $limit));
		return is_array($rows) ? $rows : array();
	}

	public function get_by_status(string $status, int $limit = 50, int $offset = 0): array {
		global $wpdb;
		$this->ensure_table_exists();
		$status = sanitize_text_field($status);
		$limit = max(1, $limit);
		$offset = max(0, $offset);
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE status=%s ORDER BY updated_at DESC, created_at DESC LIMIT %d OFFSET %d", $status, $limit, $offset));
		return is_array($rows) ? $rows : array();
	}

	public function get_all(int $limit = 100, int $offset = 0): array {
		global $wpdb;
		$this->ensure_table_exists();
		$limit = max(1, $limit);
		$offset = max(0, $offset);
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} ORDER BY updated_at DESC, created_at DESC LIMIT %d OFFSET %d", $limit, $offset));
		return is_array($rows) ? $rows : array();
	}

	public function count_by_status(string $status): int {
		global $wpdb;
		$this->ensure_table_exists();
		$status = sanitize_text_field($status);
		$cnt = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE status=%s", $status));
		return (int) $cnt;
	}

	/**
	 * Najde POI bez Google/TA ID a zařadí do fronty (batchově)
	 */
    public function enqueue_missing_batch(int $limit = 500): array {
        $limit = max(1, $limit);
        $paged = 1; $enq = 0; $skip = 0;
        do {
            $args = array(
                'post_type' => 'poi',
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => min(200, $limit),
                'paged' => $paged,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => array(
                    'relation' => 'OR',
                    array('key' => '_poi_google_place_id', 'compare' => 'NOT EXISTS'),
                    array('key' => '_poi_google_place_id', 'value' => '', 'compare' => '='),
                    array('key' => '_poi_tripadvisor_location_id', 'compare' => 'NOT EXISTS'),
                    array('key' => '_poi_tripadvisor_location_id', 'value' => '', 'compare' => '=')
                )
            );
            $ids = get_posts($args);
            if (!is_array($ids) || empty($ids)) { break; }
            foreach ($ids as $pid) { if ($this->enqueue((int)$pid, 0)) $enq++; else $skip++; }
            $paged++;
            $limit -= 200;
        } while ($limit > 0);
        return array('enqueued' => $enq, 'skipped' => $skip);
    }

	public function mark_processing(int $id): void {
        global $wpdb;
        $this->ensure_table_exists();
		$wpdb->update($this->table, array('status' => 'processing'), array('id' => $id), array('%s'), array('%d'));
	}

	public function mark_completed(int $id): void {
        global $wpdb;
        $this->ensure_table_exists();
		$wpdb->update($this->table, array('status' => 'completed', 'processed_at' => current_time('mysql')), array('id' => $id), array('%s','%s'), array('%d'));
	}

	public function mark_failed_or_retry(int $id, string $error): void {
        global $wpdb;
        $this->ensure_table_exists();
		$item = $wpdb->get_row($wpdb->prepare("SELECT attempts, max_attempts FROM {$this->table} WHERE id=%d", $id));
		if (!$item) return;
		$attempts = ((int)$item->attempts) + 1;
		$status = ($attempts >= (int)$item->max_attempts) ? 'failed' : 'pending';
		$wpdb->update($this->table, array('status' => $status, 'attempts' => $attempts, 'error_message' => $error), array('id' => $id), array('%s','%d','%s'), array('%d'));
	}

	public function move_to_review(int $id, string $provider, string $matched_id, float $score): void {
        global $wpdb;
        $this->ensure_table_exists();
		$wpdb->update($this->table, array(
			'status' => 'review',
			'matched_provider' => $provider,
			'matched_id' => $matched_id,
			'matched_score' => $score,
			'processed_at' => current_time('mysql')
		), array('id' => $id), array('%s','%s','%s','%f','%s'), array('%d'));
	}
}



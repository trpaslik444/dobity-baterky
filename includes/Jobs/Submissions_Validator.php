<?php
/**
 * Submissions Validator – předkontrola podání přes ORS
 * @package DobityBaterky
 */

namespace DB\Jobs;

use DB\Sources\Adapters\ORS_Geocode_Adapter;

if (!defined('ABSPATH')) exit;

class Submissions_Validator {
	public function validate(int $submission_id): array {
		$post = get_post($submission_id);
		if (!$post || $post->post_type !== 'user_submission') return array('error' => 'not_found');
		$address = get_post_meta($submission_id, '_address', true);
		$lat = get_post_meta($submission_id, '_lat', true);
		$lng = get_post_meta($submission_id, '_lng', true);

*		// Preferuj ORS – když je adresa, zkus dohledat a navrhnout přesnější souřadnice
		$adapter = new ORS_Geocode_Adapter();
		$suggestions = array();
		if ($address && $adapter->is_configured()) {
			$res = $adapter->search_address($address, 5);
			if (isset($res['items'])) {
				$suggestions = $res['items'];
			}
		}

		// Základní heuristika relevance
		$score = 0;
		if ($lat && $lng) $score += 30;
		if ($address) $score += 20;
		if (!empty($suggestions)) $score += 30;
		if ($post->post_title) $score += 10;

		$payload = array(
			'score' => $score,
			'suggestions' => $suggestions,
		);
		update_post_meta($submission_id, '_validation_result', $payload);
		update_post_meta($submission_id, '_submission_status', 'validated');
		return $payload;
	}
}


<?php
/**
 * ORS Geocode Adapter – Pelias Search API
 * @package DobityBaterky
 */

namespace DB\Sources\Adapters;

if (!defined('ABSPATH')) exit;

class ORS_Geocode_Adapter {
	private $api_key;

	public function __construct(?string $api_key = null) {
		$this->api_key = $api_key ?: get_option('db_ors_api_key', '');
	}

	public function is_configured(): bool {
		return !empty($this->api_key);
	}

	public function search_address(string $text, int $size = 5): array {
		$endpoint = 'https://api.openrouteservice.org/geocode/search';
		$args = array(
			'timeout' => 15,
			'headers' => array('Authorization' => $this->api_key),
		);
		$url = add_query_arg(array('text' => $text, 'size' => $size), $endpoint);
		$res = wp_remote_get($url, $args);
		if (is_wp_error($res)) return array('error' => $res->get_error_message());
		$code = wp_remote_retrieve_response_code($res);
		$body = wp_remote_retrieve_body($res);
		$data = json_decode($body, true);
		if ($code !== 200 || !is_array($data)) return array('error' => 'ORS error');
		$items = array();
		foreach (($data['features'] ?? array()) as $f) {
			$props = $f['properties'] ?? array();
			$geom = $f['geometry']['coordinates'] ?? null; // [lng, lat]
			if (!$geom || !is_array($geom) || count($geom) < 2) continue;
			$items[] = array(
				'lat' => floatval($geom[1]),
				'lng' => floatval($geom[0]),
				'label' => $props['label'] ?? '',
				'confidence' => $props['confidence'] ?? null,
			);
		}
		return array('items' => $items);
	}
}


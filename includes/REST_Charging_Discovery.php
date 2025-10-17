<?php
declare(strict_types=1);

namespace DB;

use DB\Charging_Discovery;
use DB\Jobs\Charging_Discovery_Worker;
use DB\Jobs\Charging_Quota_Manager;
use WP_Error;

if (!defined('ABSPATH')) { exit; }

class REST_Charging_Discovery {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(): void {
        add_action('rest_api_init', function () {
            register_rest_route('db/v1', '/charging-discovery/(?P<id>\d+)', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_discover_one'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]);

            register_rest_route('db/v1', '/charging-discovery/worker', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_worker_run'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('db/v1', '/charging-external/(?P<id>\d+)', [
                'methods' => 'GET',
                'callback' => [$this, 'handle_external_details'],
                'permission_callback' => '__return_true', // Dočasně povolíme pro testování
            ]);
        });
    }

    public function handle_discover_one($request) {
        $postId = (int) ($request['id'] ?? 0);
        $save = (bool) ($request->get_param('save') ?? false);
        $force = (bool) ($request->get_param('force') ?? false);
        if (!$postId) {
            return new WP_Error('bad_request', 'Missing id', ['status' => 400]);
        }
        $svc = new Charging_Discovery();
        $quota = new Charging_Quota_Manager();
        $useGoogle = $quota->can_use_google();
        $useOcm = $quota->can_use_ocm();
        $result = $svc->discoverForCharging($postId, $save, $force, $useGoogle, $useOcm);
        if ($save) {
            if ($useGoogle) {
                $quota->record_google(1);
            }
            if ($useOcm) {
                $quota->record_ocm(1);
            }
        }
        return rest_ensure_response($result);
    }

    public function handle_worker_run($request) {
        $token = (string) ($request->get_param('token') ?? '');
        $delay = (int) ($request->get_param('delay') ?? 0);
        if (!Charging_Discovery_Worker::verify_token($token)) {
            return new WP_Error('forbidden', 'Bad token', ['status' => 403]);
        }
        $result = Charging_Discovery_Worker::run($delay);
        return rest_ensure_response($result);
    }

    public function handle_external_details($request) {
        $postId = (int) ($request['id'] ?? 0);
        if (!$postId) {
            return new WP_Error('missing_id', 'Missing charging location id', ['status' => 400]);
        }
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'charging_location') {
            return new WP_Error('not_found', 'Charging location not found', ['status' => 404]);
        }
        $svc = new Charging_Discovery();
        $googleId = (string) get_post_meta($postId, '_charging_google_place_id', true);
        
        // Pokud nemáme externí ID, spustit discovery (s cache kontrolou)
        // Ale ne pokud už máme fallback metadata
        $hasFallback = get_post_meta($postId, '_charging_fallback_metadata', true);
        if ($googleId === '' && !$hasFallback) {
            // Zkontrolovat, zda už není discovery v procesu (cache mechanismus)
            $discoveryInProgress = get_post_meta($postId, '_charging_discovery_in_progress', true);
            if ($discoveryInProgress !== '1') {
                // Označit discovery jako v procesu
                update_post_meta($postId, '_charging_discovery_in_progress', '1');
                
                try {
                    $discoveryResult = $svc->discoverForCharging($postId, true, false, true, false); // OCM disabled
                    $googleId = $discoveryResult['google'] ?? '';
                    
                    // Aktualizovat metadata po discovery
                    if ($googleId !== '') {
                        update_post_meta($postId, '_charging_google_place_id', $googleId);
                    }
                } finally {
                    // Odstranit flag "v procesu" i při chybě
                    delete_post_meta($postId, '_charging_discovery_in_progress');
                }
            } else {
                // Discovery už běží, načíst stávající data
                $googleId = get_post_meta($postId, '_charging_google_place_id', true);
            }
        } else {
            // Pokud máme externí ID, zkusit rychlou aktualizaci dostupnosti
            $liveDataAvailable = get_post_meta($postId, '_charging_live_data_available', true);
            if ($liveDataAvailable === '1') {
                // Only refresh live availability if we have live data available
                $svc->refreshLiveAvailabilityOnly($postId);
            } else {
                // If no live data available, try to refresh Google metadata only if cache is expired
                $cacheExpires = get_post_meta($postId, '_charging_google_cache_expires', true);
                if (!$cacheExpires || $cacheExpires < time()) {
                    if ($googleId !== '') {
                        $svc->refreshGoogleMetadata($postId, $googleId, false);
                    }
                }
            }
        }
        
        $meta = $svc->getCachedMetadata($postId);
        if ($googleId !== '' && $meta['google'] === null) {
            $meta['google'] = $svc->refreshGoogleMetadata($postId, $googleId, false);
        }
        $live = $svc->refreshLiveStatus($postId, false);
        
        // Vytvořit odpověď ve stejném formátu jako poi-external endpoint
        $response = [
            'post_id' => $postId,
            'google_place_id' => $googleId ?: null,
            'open_charge_map_id' => null, // OCM removed
            'metadata' => $meta,
            'live_status' => $live,
        ];
        
        // Přidat data pro frontend kompatibilitu
        $data = [];
        
        // Zpracovat Google fotky
        if (!empty($meta['google']['photos']) && is_array($meta['google']['photos'])) {
            $photos = $meta['google']['photos'];
            $data['photos'] = $photos;
            
            // Vytvořit přímou URL pro první fotku
            $api_key = get_option('db_google_api_key');
            if (!empty($api_key) && !empty($photos)) {
                $firstPhoto = $photos[0];
                $ref = $firstPhoto['photo_reference'] ?? '';
                
                if ($ref === 'streetview' && isset($firstPhoto['street_view_url'])) {
                    // Street View obrázek
                    $data['photoUrl'] = $firstPhoto['street_view_url'];
                } elseif ($ref !== '' && $ref !== 'streetview') {
                    // Nové Google Places API v1 foto
                    if (strpos($ref, 'places/') === 0) {
                        // Nové API v1 formát
                        $data['photoUrl'] = add_query_arg([
                            'maxWidthPx' => 1200,
                            'key' => $api_key,
                        ], "https://places.googleapis.com/v1/$ref/media");
                    } else {
                        // Staré API formát (fallback)
                        $data['photoUrl'] = add_query_arg([
                            'maxwidth' => 1200,
                            'photo_reference' => $ref,
                            'key' => $api_key,
                        ], 'https://maps.googleapis.com/maps/api/place/photo');
                    }
                }
            }
        }
        
        // Přidat informace o stavu a dostupnosti
        if (!empty($meta['google']['business_status'])) {
            $data['business_status'] = $meta['google']['business_status'];
        }
        
        // Přidat informace o dostupnosti konektorů - používat aktuální meta data místo starého live status
        $liveAvailable = get_post_meta($postId, '_charging_live_available', true);
        $liveTotal = get_post_meta($postId, '_charging_live_total', true);
        $liveSource = get_post_meta($postId, '_charging_live_source', true);
        $liveUpdated = get_post_meta($postId, '_charging_live_updated', true);
        
        if ($liveAvailable !== '' && $liveTotal !== '') {
            $data['charging_live_available'] = (int) $liveAvailable;
            $data['charging_live_total'] = (int) $liveTotal;
            $data['charging_live_source'] = $liveSource ?: 'unknown';
            $data['charging_live_updated'] = $liveUpdated ?: null;
        }
        
        // Přidat flag o dostupnosti dat o dostupnosti
        $liveDataAvailable = get_post_meta($postId, '_charging_live_data_available', true);
        $data['charging_live_data_available'] = $liveDataAvailable === '1';
        
        // Přidat data o konektorech z Google API
        if (!empty($meta['google']['connectors']) && is_array($meta['google']['connectors'])) {
            $data['google_connectors'] = $meta['google']['connectors'];
        }
        
        // Vždy přidat konektory z databáze (hlavní zdroj)
        $data['db_connectors'] = $this->getDbConnectors($postId);
        
        // Přidat fallback metadata (Street View pro nabíječky ve frontě)
        $fallbackMeta = get_post_meta($postId, '_charging_fallback_metadata', true);
        if ($fallbackMeta && is_array($fallbackMeta)) {
            $data['fallback_metadata'] = $fallbackMeta;
        }
        
        $response['data'] = $data;
        
        return rest_ensure_response($response);
    }
    
    /**
     * Získá konektory z databáze pro nabíječku
     */
    private function getDbConnectors(int $postId): array {
        $connectors = [];
        
        // Získat typy konektorů z taxonomie
        $connector_types = get_the_terms($postId, 'charger_type');
        if ($connector_types && !is_wp_error($connector_types)) {
            foreach ($connector_types as $type) {
                $count = get_post_meta($postId, '_db_charger_counts_' . $type->term_id, true);
                // Pokud není count v jednotlivém meta, zkusit z celkových počtů
                if (!$count || $count <= 0) {
                    $total_counts = get_post_meta($postId, '_db_charger_counts', true);
                    if (is_array($total_counts) && isset($total_counts[$type->term_id])) {
                        $count = $total_counts[$type->term_id];
                    }
                }
                if ($count && $count > 0) {
                    $power = get_post_meta($postId, '_db_charger_power_' . $type->term_id, true);
                    if (!$power) {
                        // Zkusit získat power z celkových power dat
                        $total_powers = get_post_meta($postId, '_db_charger_power', true);
                        if (is_array($total_powers) && isset($total_powers[$type->term_id])) {
                            $power = $total_powers[$type->term_id];
                        }
                    }
                    
                    $connectors[] = [
                        'type' => $type->name,
                        'type_key' => $type->slug,
                        'count' => (int) $count,
                        'power' => $power,
                        'icon' => get_term_meta($type->term_id, 'charger_icon', true),
                        'source' => 'database'
                    ];
                }
            }
        }
        
        // Pokud nemáme typy z taxonomie, zkusit celkové počty
        if (empty($connectors)) {
            $total_counts = get_post_meta($postId, '_db_charger_counts', true);
            if ($total_counts && is_array($total_counts)) {
                foreach ($total_counts as $typeId => $count) {
                    if ($count > 0) {
                        $type = get_term($typeId, 'charger_type');
                        if ($type && !is_wp_error($type)) {
                            $power = get_post_meta($postId, '_db_charger_power_' . $typeId, true);
                            if (!$power) {
                                // Zkusit získat power z celkových power dat
                                $total_powers = get_post_meta($postId, '_db_charger_power', true);
                                if (is_array($total_powers) && isset($total_powers[$typeId])) {
                                    $power = $total_powers[$typeId];
                                }
                            }
                            
                            $connectors[] = [
                                'type' => $type->name,
                                'type_key' => $type->slug,
                                'count' => (int) $count,
                                'power' => $power,
                                'icon' => get_term_meta($typeId, 'charger_icon', true),
                                'source' => 'database'
                            ];
                        }
                    }
                }
            }
        }
        
        return $connectors;
    }
}

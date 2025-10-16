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
        $ocmId = (string) get_post_meta($postId, '_openchargemap_id', true);
        
        // Pokud nemáme externí ID, spustit discovery (s cache kontrolou)
        if ($googleId === '' && $ocmId === '') {
            // Zkontrolovat, zda už není discovery v procesu (cache mechanismus)
            $discoveryInProgress = get_post_meta($postId, '_charging_discovery_in_progress', true);
            if ($discoveryInProgress !== '1') {
                // Označit discovery jako v procesu
                update_post_meta($postId, '_charging_discovery_in_progress', '1');
                
                try {
                    $discoveryResult = $svc->discoverForCharging($postId, true, false, true, true);
                    $googleId = $discoveryResult['google'] ?? '';
                    $ocmId = $discoveryResult['open_charge_map'] ?? '';
                    
                    // Aktualizovat metadata po discovery
                    if ($googleId !== '') {
                        update_post_meta($postId, '_charging_google_place_id', $googleId);
                    }
                    if ($ocmId !== '') {
                        update_post_meta($postId, '_openchargemap_id', $ocmId);
                    }
                } finally {
                    // Odstranit flag "v procesu" i při chybě
                    delete_post_meta($postId, '_charging_discovery_in_progress');
                }
            } else {
                // Discovery už běží, načíst stávající data
                $googleId = get_post_meta($postId, '_charging_google_place_id', true);
                $ocmId = get_post_meta($postId, '_openchargemap_id', true);
            }
        } else {
            // Pokud máme externí ID, zkusit rychlou aktualizaci dostupnosti
            $liveDataAvailable = get_post_meta($postId, '_charging_live_data_available', true);
            if ($liveDataAvailable === '1') {
                $svc->refreshLiveAvailabilityOnly($postId);
            }
        }
        
        $meta = $svc->getCachedMetadata($postId);
        if ($googleId !== '' && $meta['google'] === null) {
            $meta['google'] = $svc->refreshGoogleMetadata($postId, $googleId, false);
        }
        if ($ocmId !== '' && $meta['open_charge_map'] === null) {
            $meta['open_charge_map'] = $svc->refreshOcmMetadata($postId, $ocmId, false);
        }
        $live = $svc->refreshLiveStatus($postId, false);
        
        // Vytvořit odpověď ve stejném formátu jako poi-external endpoint
        $response = [
            'post_id' => $postId,
            'google_place_id' => $googleId ?: null,
            'open_charge_map_id' => $ocmId ?: null,
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
        
        // Přidat informace o dostupnosti konektorů
        if ($live && isset($live['available']) && isset($live['total'])) {
            $data['charging_live_available'] = (int) $live['available'];
            $data['charging_live_total'] = (int) $live['total'];
            $data['charging_live_source'] = $live['source'] ?? 'unknown';
            $data['charging_live_updated'] = $live['updated_at'] ?? null;
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
                if ($count && $count > 0) {
                    $connectors[] = [
                        'type' => $type->name,
                        'type_key' => $type->slug,
                        'count' => (int) $count,
                        'power' => get_post_meta($postId, '_db_charger_power_' . $type->term_id, true) ?: null,
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
                            $connectors[] = [
                                'type' => $type->name,
                                'type_key' => $type->slug,
                                'count' => (int) $count,
                                'power' => get_post_meta($postId, '_db_charger_power_' . $typeId, true) ?: null,
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

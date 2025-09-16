<?php
namespace DB;

if (!defined('ABSPATH')) exit;

class MPO_JSON_Admin {
    private static $instance = null;

    public static function get_instance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public function add_menu_page(): void {
        add_submenu_page(
            'edit.php?post_type=charging_location',
            'MPO JSON import',
            'MPO JSON import',
            'manage_options',
            'mpo-json-import',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) return;
        $defaultPath = DB_PLUGIN_DIR . 'assets/mpo_stations_agg_2025-06-30_expanded_evseSUM.json';
        $path = isset($_GET['json']) ? sanitize_text_field(wp_unslash($_GET['json'])) : $defaultPath;
        ?>
        <div class="wrap">
            <h1>MPO – Import z bundlovaného JSON</h1>
            <form method="get" action="">
                <input type="hidden" name="page" value="mpo-json-import" />
                <label>Cesta k JSON souboru:
                    <input type="text" name="json" class="regular-text" value="<?php echo esc_attr($path); ?>" />
                </label>
                <button class="button">Načíst</button>
            </form>

            <h2>Akce</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:10px;">
                <?php wp_nonce_field('mpo_json_actions'); ?>
                <input type="hidden" name="action" value="mpo_json_preview" />
                <input type="hidden" name="json" value="<?php echo esc_attr($path); ?>" />
                <label>Ukázat prvních
                    <input type="number" name="limit" value="5" min="1" max="100" class="small-text" />
                    záznamů
                </label>
                <button class="button">Náhled</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin.php')); ?>">
                <?php wp_nonce_field('mpo_json_actions'); ?>
                <input type="hidden" name="action" value="mpo_json_import" />
                <input type="hidden" name="json" value="<?php echo esc_attr($path); ?>" />
                <label>Zpracovat maximálně
                    <input type="number" name="max" value="0" min="0" class="small-text" />
                    (0 = vše)
                </label>
                <button class="button button-primary" onclick="return confirm('Spustit import z JSON?');">Spustit import</button>
            </form>

            <?php if (isset($_GET['mpo_msg'])): ?>
                <div class="notice notice-info"><p><?php echo esc_html($_GET['mpo_msg']); ?></p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_actions(): void {
        if (!current_user_can('manage_options')) return;
        if (!isset($_REQUEST['action'])) return;
        $action = sanitize_text_field(wp_unslash($_REQUEST['action']));
        if (!in_array($action, ['mpo_json_preview','mpo_json_import'], true)) return;
        check_admin_referer('mpo_json_actions');

        $path = isset($_REQUEST['json']) ? sanitize_text_field(wp_unslash($_REQUEST['json'])) : '';
        if ($path === '') $path = DB_PLUGIN_DIR . 'assets/mpo_stations_agg_2025-06-30_expanded_evseSUM.json';
        if (!file_exists($path)) {
            $this->redirect_with_msg('Soubor JSON neexistuje: ' . $path);
        }

        if ($action === 'mpo_json_preview') {
            $limit = max(1, min(100, intval($_POST['limit'] ?? 5)));
            $sample = $this->load_json_sample($path, $limit);
            $msg = 'Náhled prvních ' . $limit . ' záznamů:\n' . $sample;
            $this->redirect_with_msg($msg);
        }

        if ($action === 'mpo_json_import') {
            @set_time_limit(0);
            @ini_set('memory_limit', '1024M');
            $max = max(0, intval($_POST['max'] ?? 0));
            $result = $this->import_from_json($path, $max);
            $msg = 'Import hotov: vytvořeno ' . $result['created'] . ', aktualizováno ' . $result['updated'] . ', přeskočeno ' . $result['skipped'] . ', chyb: ' . count($result['errors']);
            $this->redirect_with_msg($msg);
        }
    }

    private function load_json_sample(string $path, int $limit): string {
        $raw = file_get_contents($path);
        if ($raw === false) return 'Nelze číst JSON.';
        $data = json_decode($raw, true);
        if (!is_array($data)) return 'JSON není pole.';
        $slice = array_slice($data, 0, $limit);
        $out = [];
        foreach ($slice as $i => $row) {
            $out[] = ($i+1) . ') ' . ($row['street'] ?? '(bez adresy)') . ', ' . ($row['city'] ?? '') . ' – ' . ($row['op_key'] ?? '') . ' @ ' . ($row['lat_5dp'] ?? '') . ',' . ($row['lon_5dp'] ?? '');
        }
        return implode("\n", $out);
    }

    private function redirect_with_msg(string $msg): void {
        $url = add_query_arg(['page' => 'mpo-json-import', 'mpo_msg' => rawurlencode($msg)], admin_url('edit.php?post_type=charging_location'));
        wp_safe_redirect($url);
        exit;
    }

    private function import_from_json(string $path, int $max): array {
        $raw = file_get_contents($path);
        if ($raw === false) return ['created'=>0,'updated'=>0,'skipped'=>0,'errors'=>['Nelze číst JSON']];
        $data = json_decode($raw, true);
        if (!is_array($data)) return ['created'=>0,'updated'=>0,'skipped'=>0,'errors'=>['Neplatný JSON']];

        $created = 0; $updated = 0; $skipped = 0; $errors = [];
        $count = 0;
        foreach ($data as $station) {
            if ($max > 0 && $count >= $max) break;
            $count++;
            try {
                $res = $this->upsert_station_from_mpo($station);
                if ($res === 'created') $created++; elseif ($res === 'updated') $updated++; else $skipped++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        return compact('created','updated','skipped','errors');
    }

    private function upsert_station_from_mpo(array $s): string {
        $uniq = (string)($s['uniq_key'] ?? '');
        if ($uniq === '') throw new \RuntimeException('Chybí uniq_key');

        $addressStreet = trim((string)($s['street'] ?? ''));
        $addressCity   = trim((string)($s['city'] ?? ''));
        $addressPsc    = trim((string)($s['psc'] ?? ''));
        $addressLine   = trim($addressStreet . ( $addressCity !== '' ? (', ' . $addressCity) : '' ) . ( $addressPsc !== '' ? (' ' . $addressPsc) : '' ));

        $lat = isset($s['lat']) ? (float)$s['lat'] : null;
        $lng = isset($s['lon']) ? (float)$s['lon'] : null;
        $maxPower = isset($s['station_max_power_kw']) ? (float)$s['station_max_power_kw'] : null;
        $evseCount = isset($s['evse_count']) ? (int)$s['evse_count'] : null;
        $operatorOriginal = (string)($s['operator_original'] ?? '');

        $existing = get_posts([
            'post_type' => 'charging_location',
            'post_status' => 'any',
            'meta_query' => [ [ 'key' => '_mpo_uniq_key', 'value' => $uniq, 'compare' => '=' ] ],
            'numberposts' => 1,
            'fields' => 'ids',
        ]);

        $post_id = $existing ? (int)$existing[0] : 0;

        // Druhé kolo idempotence: adresa + operátor, pokud MPO UID není nalezeno
        if ($post_id <= 0 && $addressLine !== '') {
            $candidates = get_posts([
                'post_type' => 'charging_location',
                'post_status' => 'any',
                'meta_query' => [ [ 'key' => '_db_address', 'value' => $addressLine, 'compare' => '=' ] ],
                'numberposts' => 5,
                'fields' => 'ids',
            ]);
            if (!empty($candidates)) {
                foreach ($candidates as $cid) {
                    $op = get_post_meta($cid, '_operator', true);
                    if ($op === (string)($s['operator_original'] ?? '') || $op === '' || !$op) {
                        $post_id = (int)$cid;
                        break;
                    }
                }
            }
        }
        $post_title = '';
        if (!empty($operatorOriginal) && !empty($addressStreet)) {
            $post_title = trim($operatorOriginal . ' - ' . $addressStreet);
        } elseif ($addressLine !== '') {
            $post_title = $addressLine;
        } else {
            $post_title = (string)($s['op_key'] ?? 'MPO lokalita');
        }

        if ($post_id <= 0) {
            $post_id = wp_insert_post([
                'post_type' => 'charging_location',
                'post_status' => 'publish',
                'post_title' => $post_title,
                'post_content' => '',
            ], true);
            if (is_wp_error($post_id)) throw new \RuntimeException($post_id->get_error_message());
            $created = true;
        } else {
            $created = false;
            // aktualizace názvu i když není prázdný – držíme formát "Poskytovatel - ulice"
            $p = get_post($post_id);
            if ($p && is_string($p->post_title)) {
                $current = trim($p->post_title);
                if ($current !== $post_title) {
                    wp_update_post(['ID' => $post_id, 'post_title' => $post_title]);
                }
            } else {
                wp_update_post(['ID' => $post_id, 'post_title' => $post_title]);
            }
        }

        // meta
        update_post_meta($post_id, '_mpo_uniq_key', $uniq);
        update_post_meta($post_id, '_db_address', $addressLine);
        if ($lat !== null) update_post_meta($post_id, '_db_lat', $lat);
        if ($lng !== null) update_post_meta($post_id, '_db_lng', $lng);
        if ($maxPower !== null) update_post_meta($post_id, '_max_power_kw', $maxPower);
        if ($evseCount !== null) update_post_meta($post_id, '_db_total_stations', $evseCount);
        update_post_meta($post_id, '_operator', $operatorOriginal);

        // Provider taxonomie (vytvořit pokud neexistuje)
        if ($operatorOriginal !== '') {
            $provider_term_id = $this->upsert_provider_term($operatorOriginal);
            if ($provider_term_id) {
                wp_set_post_terms($post_id, [ (int)$provider_term_id ], 'provider', false);
            }
        }

        // zdroj
        update_post_meta($post_id, '_data_source', 'mpo');
        update_post_meta($post_id, '_mpo_source', (string)($s['source'] ?? 'MPO'));
        update_post_meta($post_id, '_mpo_source_as_of_date', (string)($s['source_as_of_date'] ?? ''));
        update_post_meta($post_id, '_mpo_op_key', (string)($s['op_key'] ?? ''));
        update_post_meta($post_id, '_mpo_op_norm', (string)($s['op_norm'] ?? ''));
        update_post_meta($post_id, '_mpo_operator_original', (string)($s['operator_original'] ?? ''));

        // konektory – zjednodušené + raw
        $connectors = is_array($s['connectors'] ?? null) ? $s['connectors'] : [];
        $simple = [];
        foreach ($connectors as $c) {
            $type = (string)($c['connector_standard'] ?? '');
            if ($type === '') $type = (string)($c['charge_type'] ?? '');
            $simple[] = [
                'type' => $type,
                'power_kw' => isset($c['connector_power_kw']) ? (float)$c['connector_power_kw'] : null,
                'status' => 'unknown',
                'uid' => (string)($c['connector_uid'] ?? ''),
                'index' => isset($c['connector_index']) ? (int)$c['connector_index'] : null,
                'connection_method' => (string)($c['connection_method'] ?? ''),
                'source' => (string)($c['source'] ?? 'MPO'),
            ];
        }
        update_post_meta($post_id, '_connectors', $simple);
        update_post_meta($post_id, '_mpo_connectors', $connectors);

        // Mapování MPO konektorů na taxonomii charger_type a technická pole
        $term_ids = [];
        $countsByTerm = [];
        $powerByTerm = [];
        $methodByTerm = [];
        $ocmNames = [];
        $countsByName = [];
        $powerByName = [];
        $methodByName = [];
        foreach ($connectors as $c) {
            $standard = strtolower(trim((string)($c['connector_standard'] ?? '')));
            if ($standard === '') $standard = strtolower(trim((string)($c['charge_type'] ?? '')));
            if ($standard === '') continue;
            $canonical = $this->canonicalize_standard($standard);
            $termId = $this->find_charger_type_term_id($canonical, (string)($c['charge_type'] ?? ''));
            if ($termId) {
                $term_ids[$termId] = true;
                $countsByTerm[$termId] = ($countsByTerm[$termId] ?? 0) + 1;
                $p = isset($c['connector_power_kw']) ? (float)$c['connector_power_kw'] : null;
                if ($p !== null) {
                    $powerByTerm[$termId] = isset($powerByTerm[$termId]) ? max($powerByTerm[$termId], $p) : $p;
                }
                $m = strtolower(trim((string)($c['connection_method'] ?? '')));
                if ($m !== '') {
                    // preferuj "kabel" před "zásuvka" pokud existuje
                    $current = $methodByTerm[$termId] ?? '';
                    if ($current === '' || ($current !== 'kabel' && $m === 'kabel')) {
                        $methodByTerm[$termId] = $m;
                    }
                }
            }

            // OCM-style fallback (pro UI render, pokud nejsou termy)
            $ocmNames[] = $canonical;
            $countsByName[$canonical] = ($countsByName[$canonical] ?? 0) + 1;
            $p2 = isset($c['connector_power_kw']) ? (float)$c['connector_power_kw'] : null;
            if ($p2 !== null) {
                $powerByName[$canonical] = isset($powerByName[$canonical]) ? max($powerByName[$canonical], $p2) : $p2;
            }
            $m2 = strtolower(trim((string)($c['connection_method'] ?? '')));
            if ($m2 !== '') {
                $current = $methodByName[$canonical] ?? '';
                if ($current === '' || ($current !== 'kabel' && $m2 === 'kabel')) {
                    $methodByName[$canonical] = $m2;
                }
            }
        }
        // Pokud se nenašel žádný existující term, pokusit se je vytvořit z názvů
        if (empty($term_ids) && !empty($ocmNames)) {
            $createdIds = [];
            foreach (array_unique($ocmNames) as $name) {
                $created = $this->create_charger_type_term($name, '');
                if ($created) $createdIds[$created] = true;
            }
            if (!empty($createdIds)) {
                $term_ids = $createdIds + $term_ids;
            }
        }

        // Nastavit termy (pokud existují)
        if (!empty($term_ids)) {
            wp_set_post_terms($post_id, array_map('intval', array_keys($term_ids)), 'charger_type', false);
        }
        // Uložit počty/výkony pro obě mapování: podle term_id i podle názvu (fallback pro UI)
        $countsFinal = $countsByTerm + $countsByName; // zachová klíče zleva, doplní zprava
        $powerFinal  = $powerByTerm + $powerByName;
        $methodFinal = $methodByTerm + $methodByName;
        if (!empty($countsFinal)) update_post_meta($post_id, '_db_charger_counts', $countsFinal);
        if (!empty($powerFinal)) update_post_meta($post_id, '_db_charger_power', $powerFinal);
        if (!empty($methodFinal)) update_post_meta($post_id, '_db_charger_connection_method', $methodFinal);

        // OCM-like seznam jmen konektorů pro render v metaboxu
        if (!empty($ocmNames)) {
            $uniqueNames = array_values(array_unique($ocmNames));
            update_post_meta($post_id, '_ocm_connector_names', $uniqueNames);
        }

        // Další MPO pole z JSONu
        if (!empty($s['rows_in_mpo'])) update_post_meta($post_id, '_mpo_rows_in_mpo', (int)$s['rows_in_mpo']);
        if (!empty($s['cpo_id'])) update_post_meta($post_id, '_mpo_cpo_id', (string)$s['cpo_id']);
        if (!empty($s['opening_hours'])) update_post_meta($post_id, '_mpo_opening_hours', (string)$s['opening_hours']);

        // Zdrojový badge/barva (pro konzistenci s OCM ukládáním)
        update_post_meta($post_id, '_data_source_badge', 'MPO');
        update_post_meta($post_id, '_data_source_color', '#005a87');

        return $created ? 'created' : 'updated';
    }

    private function find_charger_type_term_id(string $standard, string $chargeType = ''): ?int {
        // Kanonizace
        $s = strtolower(trim($standard));
        $s = str_replace(['\t','\n','\r'], ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = str_replace([',',';'], ' ', $s);
        $s = trim($s);

        // Základní aliasy MPO → interní názvy
        $aliases = [
            'typ 2' => ['type 2','type2','mennekes','type 2 (socket only)','type 2 socket','typ2','t2'],
            'ccs' => ['ccs','ccs2','combined charging system','ccs (type 2)','combo 2','ccs combo 2'],
            'chademo' => ['chademo','cha de mo','cha-de-mo'],
            'schuko' => ['schuko','euro','cee 7/4','type f'],
        ];

        $terms = get_terms(['taxonomy' => 'charger_type','hide_empty' => false]);
        if (is_wp_error($terms) || empty($terms)) return null;

        // Přesná/podobná shoda
        foreach ($terms as $term) {
            $name = strtolower(trim($term->name));
            if ($name === $s || strpos($name, $s) !== false || strpos($s, $name) !== false) return (int)$term->term_id;
        }

        // Aliasová shoda
        foreach ($aliases as $canonical => $list) {
            if ($canonical === $s || in_array($s, $list, true)) {
                foreach ($terms as $term) {
                    $name = strtolower(trim($term->name));
                    if ($name === $canonical || strpos($name, $canonical) !== false) return (int)$term->term_id;
                }
            }
        }

        // Vytvoření termu, pokud nebyl nalezen – zkus kanonický název
        $created_id = $this->create_charger_type_term($s, $chargeType);
        return $created_id ?: null;
    }

    private function canonicalize_standard(string $standard): string {
        $s = strtolower(trim($standard));
        $s = str_replace(['\t','\n','\r'], ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = str_replace([',',';'], ' ', $s);
        $s = trim($s);
        $aliases = [
            'typ 2' => ['type 2','type2','mennekes','type 2 (socket only)','type 2 socket','typ2','t2'],
            'ccs' => ['ccs','ccs2','combined charging system','ccs (type 2)','combo 2','ccs combo 2'],
            'chademo' => ['chademo','cha de mo','cha-de-mo'],
            'schuko' => ['schuko','euro','cee 7/4','type f']
        ];
        foreach ($aliases as $canon => $list) {
            if ($s === $canon || in_array($s, $list, true)) return $canon;
        }
        return $s;
    }

    private function create_charger_type_term(string $name, string $chargeType): ?int {
        if ($name === '') return null;
        $exists = term_exists($name, 'charger_type');
        if ($exists && is_array($exists) && !empty($exists['term_id'])) return (int)$exists['term_id'];
        $term = wp_insert_term($name, 'charger_type');
        if (is_wp_error($term)) return null;
        $term_id = (int)$term['term_id'];
        // Nastavit typ proudu podle chargeType
        $ct = strtoupper(trim($chargeType));
        if ($ct !== 'AC' && $ct !== 'DC') {
            // heuristika
            $nameL = strtolower($name);
            $ct = (strpos($nameL, 'ccs') !== false || strpos($nameL, 'chademo') !== false) ? 'DC' : 'AC';
        }
        update_term_meta($term_id, 'charger_current_type', $ct);
        return $term_id;
    }

    private function upsert_provider_term(string $name): ?int {
        if ($name === '') return null;
        $exists = term_exists($name, 'provider');
        if ($exists && is_array($exists) && !empty($exists['term_id'])) return (int)$exists['term_id'];
        $term = wp_insert_term($name, 'provider');
        if (is_wp_error($term)) return null;
        return (int)$term['term_id'];
    }
}



<?php
/**
 * Třída pro správu nabíjecích stanic s OpenChargeMap API integrací
 * 
 * @package DobityBaterky
 */

namespace DB;

/**
 * Manager pro nabíjecí stanice
 */
class Charging_Manager {

    /**
     * Instance třídy
     */
    private static $instance = null;

    /**
     * Získání instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
        add_action('init', array($this, 'init'));
    }

    /**
     * Inicializace
     */
    public function init() {
        // Meta boxy jsou zakázány - používá se nový Charging_Location_Form
        // add_action('add_meta_boxes', array($this, 'add_charging_meta_boxes'));
        // add_action('save_post', array($this, 'save_charging_meta_boxes'));
        
        // AJAX handlery pro OpenChargeMap vyhledávání
        add_action('wp_ajax_search_charging_stations_by_name', array($this, 'ajax_search_charging_stations_by_name'));
        add_action('wp_ajax_search_charging_stations_by_coordinates', array($this, 'ajax_search_charging_stations_by_coordinates'));
        add_action('wp_ajax_save_selected_station_data', array($this, 'ajax_save_selected_station_data'));
        add_action('wp_ajax_db_ocm_enrich_suggestions', array($this, 'ajax_ocm_enrich_suggestions'));
        
        // AJAX handlery pro kombinované hledání (OCM + DATEX II)
        add_action('wp_ajax_search_both_databases', array($this, 'ajax_search_both_databases'));
    }

    // Nedestruktivní návrhy doplnění z OCM – nikdy nepřepisuje existující hodnoty
    public function ajax_ocm_enrich_suggestions() {
        check_ajax_referer('db_ocm_enrich', 'nonce');
        $lat = isset($_POST['lat']) ? floatval($_POST['lat']) : 0;
        $lng = isset($_POST['lng']) ? floatval($_POST['lng']) : 0;
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$lat || !$lng) {
            wp_send_json_success([]);
        }
        $results = $this->search_openchargemap_by_coordinates($lat, $lng);
        // Fallback: když nic v těsném okolí, zkus vyhledávání podle názvu/adresy
        if (empty($results) && $post_id > 0) {
            $address = get_post_meta($post_id, '_db_address', true);
            $operator = get_post_meta($post_id, '_operator', true);
            $title = get_post($post_id) ? get_post($post_id)->post_title : '';
            $candidates = array_filter([ $address, $operator, $title ]);
            foreach ($candidates as $q) {
                $byName = $this->search_openchargemap_by_name($q);
                if (!empty($byName)) { $results = $byName; break; }
            }
        }
        if (empty($results)) wp_send_json_success([]);
        $first = $results[0];

        $suggestion = [];
        if (!empty($first['connectors'])) {
            // filtrování nesmyslů: Unknown typy ven, výkon > 0
            $filtered = array();
            $max = 0;
            foreach ($first['connectors'] as $c) {
                $type = trim((string)($c['type'] ?? ''));
                $p = floatval($c['power_kw'] ?? 0);
                if ($type === '' || stripos($type, 'unknown') !== false) continue;
                if ($p <= 0) continue;
                $filtered[] = array(
                    'type' => $type,
                    'power_kw' => $p,
                    'quantity' => intval($c['quantity'] ?? 1),
                    'current_type' => (string)($c['current_type'] ?? ''),
                );
                if ($p > $max) $max = $p;
            }
            if (!empty($filtered)) {
                $suggestion['connectors'] = $filtered;
                if ($max > 0) $suggestion['max_power_kw'] = $max;
            }
        }
        if (!empty($first['media'][0]['url'])) {
            $suggestion['image_url'] = $first['media'][0]['url'];
        }
        wp_send_json_success($suggestion);
    }

    /**
     * Přidání meta boxů pro nabíjecí stanice
     */
    public function add_charging_meta_boxes() {
        add_meta_box(
            'db_charging_search',
            'Vyhledávání nabíjecích stanic',
            array($this, 'render_search_meta_box'),
            'charging_location',
            'normal',
            'high'
        );
        
        add_meta_box(
            'db_charging_tomtom',
            'TomTom EV Data',
            array($this, 'render_charging_meta_box'),
            'charging_location',
            'normal',
            'default'
        );
    }

    /**
     * Render meta boxu pro vyhledávání stanic
     */
    public function render_search_meta_box($post) {
        wp_nonce_field('db_save_charging_search', 'db_charging_search_nonce');
        
        $selected_station = get_post_meta($post->ID, '_selected_station_data', true);
        ?>
        <div class="charging-search-container">
            <h4>Vyhledávání podle názvu nebo souřadnic</h4>
            
            <div class="search-section">
                <h5>1. Vyhledávání podle názvu</h5>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <input type="text" id="station_name_search" placeholder="Zadejte název stanice..." style="flex: 1;" />
                    <button type="button" class="button" onclick="searchStationsByName()">Vyhledat</button>
                </div>
            </div>
            
            <div class="search-section">
                <h5>2. Vyhledávání podle souřadnic</h5>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <input type="text" id="coordinates_search" placeholder="50.0755, 14.4378 nebo 50.0755N, 14.4378E" style="flex: 1;" />
                    <button type="button" class="button" onclick="searchStationsByCoordinates()">Vyhledat</button>
                </div>
            </div>
            
            <div class="search-section">
                <h5>3. Výsledky vyhledávání</h5>
                <div id="search_results" style="margin-bottom: 15px;">
                    <p style="color: #666;">Zadejte název nebo souřadnice pro vyhledávání...</p>
                </div>
            </div>
            
            <div class="search-section" id="selected_station_section" style="display: none;">
                <h5>4. Vybraná stanice</h5>
                <div id="selected_station_info" style="padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <!-- Zde se zobrazí informace o vybrané stanici -->
                </div>
                <div style="margin-top: 10px;">
                    <button type="button" class="button button-primary" onclick="applySelectedStation()">Použít vybranou stanici</button>
                    <button type="button" class="button" onclick="clearSelectedStation()">Zrušit výběr</button>
                </div>
            </div>
        </div>

        <style>
        .charging-search-container {
            padding: 15px;
        }
        .search-section {
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #e5e5e5;
            border-radius: 4px;
            background: #fff;
        }
        .search-section h5 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #23282d;
        }
        .station-result {
            padding: 10px;
            margin: 5px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            background: #f9f9f9;
        }
        .station-result:hover {
            background: #e9e9e9;
        }
        .station-result.selected {
            background: #0073aa;
            color: white;
        }
        .station-details {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        </style>

        <script>
        var selectedStationData = null;
        
        function searchStationsByName() {
            var name = document.getElementById('station_name_search').value.trim();
            if (!name) {
                alert('Zadejte název stanice');
                return;
            }
            
            document.getElementById('search_results').innerHTML = '<p>Vyhledávám...</p>';
            
            jQuery.post(ajaxurl, {
                action: 'search_charging_stations_by_name',
                nonce: '<?php echo wp_create_nonce('db_charging_search'); ?>',
                name: name
            }, function(response) {
                if (response.success) {
                    displaySearchResults(response.data);
                } else {
                    document.getElementById('search_results').innerHTML = '<p style="color: red;">Chyba: ' + response.data + '</p>';
                }
            }).fail(function() {
                document.getElementById('search_results').innerHTML = '<p style="color: red;">Chyba při vyhledávání</p>';
            });
        }
        
        function searchStationsByCoordinates() {
            var coords = document.getElementById('coordinates_search').value.trim();
            console.log('[DEBUG] Zadané souřadnice:', coords);
            
            if (!coords) {
                alert('Zadejte souřadnice');
                return;
            }
            
            // Parsování souřadnic
            var coordsData = parseCoordinates(coords);
            console.log('[DEBUG] Parsované souřadnice:', coordsData);
            
            if (!coordsData) {
                alert('Neplatný formát souřadnic. Použijte: 50.0755, 14.4378 nebo 50.0755N, 14.4378E');
                return;
            }
            
            document.getElementById('search_results').innerHTML = '<p>Vyhledávám...</p>';
            console.log('[DEBUG] Odesílám AJAX požadavek s lat:', coordsData.lat, 'lng:', coordsData.lng);
            
            jQuery.post(ajaxurl, {
                action: 'search_charging_stations_by_coordinates',
                nonce: '<?php echo wp_create_nonce('db_charging_search'); ?>',
                lat: coordsData.lat,
                lng: coordsData.lng
            }, function(response) {
                console.log('[DEBUG] AJAX odpověď:', response);
                if (response.success) {
                    // Zobrazit výsledky
                    if (response.data && response.data.length > 0) {
                        displaySearchResults(response.data);
                    } else {
                        document.getElementById('search_results').innerHTML = '<p>Nebyly nalezeny žádné stanice</p>';
                    }
                } else {
                    document.getElementById('search_results').innerHTML = '<p style="color: red;">Chyba: ' + response.data + '</p>';
                }
            }).fail(function(xhr, status, error) {
                console.log('[DEBUG] AJAX chyba:', {xhr: xhr, status: status, error: error});
                document.getElementById('search_results').innerHTML = '<p style="color: red;">Chyba při vyhledávání</p>';
            });
        }
        
        function parseCoordinates(coords) {
            console.log('[DEBUG] Parsování souřadnic:', coords);
            
            // Formát: 50.0755, 14.4378 nebo 50.0755N, 14.4378E
            var match = coords.match(/([0-9.-]+)[°\s]*([NS]?)[,\s]+([0-9.-]+)[°\s]*([EW]?)/i);
            if (match) {
                console.log('[DEBUG] Regex match:', match);
                var lat = parseFloat(match[1]);
                var lng = parseFloat(match[3]);
                
                // Úprava podle směru
                if (match[2].toUpperCase() === 'S') lat = -lat;
                if (match[4].toUpperCase() === 'W') lng = -lng;
                
                console.log('[DEBUG] Parsované s regex:', { lat: lat, lng: lng });
                return { lat: lat, lng: lng };
            }
            
            // Jednoduchý formát: 50.0755, 14.4378
            var parts = coords.split(/[,\s]+/);
            console.log('[DEBUG] Split parts:', parts);
            if (parts.length === 2) {
                var lat = parseFloat(parts[0]);
                var lng = parseFloat(parts[1]);
                if (!isNaN(lat) && !isNaN(lng)) {
                    console.log('[DEBUG] Parsované s split:', { lat: lat, lng: lng });
                    return { lat: lat, lng: lng };
                }
            }
            
            console.log('[DEBUG] Nepodařilo se parsovat souřadnice');
            return null;
        }
        
        function displaySearchResults(stations) {
            var resultsDiv = document.getElementById('search_results');
            
            if (!stations || stations.length === 0) {
                resultsDiv.innerHTML = '<p>Nebyly nalezeny žádné stanice</p>';
                return;
            }
            
            console.log('[DEBUG] Zobrazuji výsledky:', stations);
            
            var html = '<div class="station-results">';
            stations.forEach(function(station, index) {
                html += '<div class="station-result" onclick="selectStation(' + index + ')">';
                html += '<strong>' + station.name + '</strong>';
                html += '<div class="station-details">';
                html += 'Adresa: ' + (station.address || 'N/A') + '<br>';
                html += 'Souřadnice: ' + station.lat + ', ' + station.lng + '<br>';
                html += 'ID: ' + (station.openchargemap_id || 'N/A') + '<br>';
                if (station.connectors && station.connectors.length > 0) {
                    html += 'Konektory: ' + station.connectors.map(c => c.type + ' ' + c.power_kw + 'kW').join(', ');
                } else {
                    html += 'Konektory: N/A';
                }
                if (station.operator) {
                    html += '<br>Operátor: ' + (station.operator.Title || 'N/A');
                }
                if (station.media && station.media.length > 0) {
                    html += '<br>Obrázky: ' + station.media.length + ' ks';
                }
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
            
            resultsDiv.innerHTML = html;
            
            // Uložit data pro výběr
            window.searchResults = stations;
            console.log('[DEBUG] Uložena data pro výběr:', window.searchResults);
        }
        
        function selectStation(index) {
            console.log('[DEBUG] Kliknuto na stanici index:', index);
            console.log('[DEBUG] Dostupné výsledky:', window.searchResults);
            
            if (!window.searchResults || !window.searchResults[index]) {
                console.error('[DEBUG] Stanice s indexem', index, 'neexistuje');
                return;
            }
            
            // Odstranit předchozí výběr
            var results = document.querySelectorAll('.station-result');
            results.forEach(function(result) {
                result.classList.remove('selected');
            });
            
            // Označit vybranou stanici
            if (results[index]) {
                results[index].classList.add('selected');
            }
            
            // Uložit vybraná data
            selectedStationData = window.searchResults[index];
            console.log('[DEBUG] Vybraná stanice:', selectedStationData);
            
            // Zobrazit informace o vybrané stanici
            displaySelectedStation();
        }
        
        function displaySelectedStation() {
            console.log('[DEBUG] Zobrazuji vybranou stanici:', selectedStationData);
            
            if (!selectedStationData) {
                console.error('[DEBUG] Žádná vybraná stanice');
                return;
            }
            
            var infoDiv = document.getElementById('selected_station_info');
            var html = '<h4>' + selectedStationData.name + '</h4>';
            html += '<p><strong>Adresa:</strong> ' + (selectedStationData.address || 'N/A') + '</p>';
            html += '<p><strong>Souřadnice:</strong> ' + selectedStationData.lat + ', ' + selectedStationData.lng + '</p>';
            html += '<p><strong>OpenChargeMap ID:</strong> ' + (selectedStationData.openchargemap_id || 'N/A') + '</p>';
            
            if (selectedStationData.operator) {
                html += '<p><strong>Operátor:</strong> ' + (selectedStationData.operator.Title || 'N/A') + '</p>';
            }
            
            if (selectedStationData.connectors && selectedStationData.connectors.length > 0) {
                html += '<p><strong>Konektory:</strong></p><ul>';
                selectedStationData.connectors.forEach(function(connector) {
                    html += '<li>' + connector.type + ' - ' + connector.power_kw + ' kW (množství: ' + connector.quantity + ')</li>';
                });
                html += '</ul>';
            } else {
                html += '<p><strong>Konektory:</strong> N/A</p>';
            }
            
            if (selectedStationData.media && selectedStationData.media.length > 0) {
                html += '<p><strong>Obrázky (' + selectedStationData.media.length + '):</strong></p>';
                html += '<div style="display: flex; flex-wrap: wrap; gap: 10px;">';
                selectedStationData.media.forEach(function(media) {
                    if (media.thumbnail_url) {
                        html += '<img src="' + media.thumbnail_url + '" style="width: 100px; height: 100px; object-fit: cover; border: 1px solid #ddd;" title="' + media.comment + '" />';
                    }
                });
                html += '</div>';
            }
            
            if (selectedStationData.comments && selectedStationData.comments.length > 0) {
                html += '<p><strong>Komentáře (' + selectedStationData.comments.length + '):</strong></p>';
                html += '<div style="max-height: 200px; overflow-y: auto;">';
                selectedStationData.comments.forEach(function(comment) {
                    html += '<div style="border: 1px solid #ddd; padding: 10px; margin: 5px 0;">';
                    html += '<strong>' + comment.user + '</strong> (' + comment.rating + '/5)<br>';
                    html += comment.comment;
                    html += '</div>';
                });
                html += '</div>';
            }
            
            // Debug informace o všech dostupných datech
            html += '<hr><h5>Debug - Všechna dostupná data:</h5>';
            html += '<pre style="background: #f5f5f5; padding: 10px; font-size: 11px; overflow: auto;">';
            html += JSON.stringify(selectedStationData, null, 2);
            html += '</pre>';
            
            infoDiv.innerHTML = html;
            document.getElementById('selected_station_section').style.display = 'block';
        }
        
        function applySelectedStation() {
            if (!selectedStationData) {
                alert('Není vybraná žádná stanice');
                return;
            }
            
            // 1. Vyplnit název v hlavním formuláři
            if (document.getElementById('title')) {
                document.getElementById('title').value = selectedStationData.name;
            }
            
            // 2. Vyplnit skrytá pole pro OCM data
            if (document.getElementById('_ocm_title')) {
                document.getElementById('_ocm_title').value = selectedStationData.name;
            }
            
            // 3. Vyplnit adresu
            if (document.getElementById('_db_address')) {
                document.getElementById('_db_address').value = selectedStationData.address || '';
            }
            
            // 4. Vyplnit souřadnice
            if (document.getElementById('_db_lat')) {
                document.getElementById('_db_lat').value = selectedStationData.lat || '';
            }
            if (document.getElementById('_db_lng')) {
                document.getElementById('_db_lng').value = selectedStationData.lng || '';
            }
            
            // 5. Vyplnit obrázek z OCM
            if (document.getElementById('_ocm_image_url')) {
                document.getElementById('_ocm_image_url').value = '';
                if (selectedStationData.media && selectedStationData.media.length > 0) {
                    document.getElementById('_ocm_image_url').value = selectedStationData.media[0].url || '';
                }
            }
            
            if (document.getElementById('_ocm_image_comment')) {
                document.getElementById('_ocm_image_comment').value = '';
                if (selectedStationData.media && selectedStationData.media.length > 0) {
                    document.getElementById('_ocm_image_comment').value = selectedStationData.media[0].comment || '';
                }
            }
            
            // 6. Propisování do meta box polí
            if (document.getElementById('_openchargemap_id')) {
                document.getElementById('_openchargemap_id').value = selectedStationData.openchargemap_id || '';
            }
            
            if (document.getElementById('_operator')) {
                document.getElementById('_operator').value = selectedStationData.operator?.Title || '';
            }
            
            if (document.getElementById('_featured_image_url')) {
                document.getElementById('_featured_image_url').value = '';
                if (selectedStationData.media && selectedStationData.media.length > 0) {
                    document.getElementById('_featured_image_url').value = selectedStationData.media[0].url || '';
                }
            }
            
            if (document.getElementById('_featured_image_comment')) {
                document.getElementById('_featured_image_comment').value = '';
                if (selectedStationData.media && selectedStationData.media.length > 0) {
                    document.getElementById('_featured_image_comment').value = selectedStationData.media[0].comment || '';
                }
            }
            
            // 7. Propisování DATEX II polí (zatím prázdné, pro budoucí integraci)
            if (document.getElementById('_datex_station_id')) {
                document.getElementById('_datex_station_id').value = '';
            }
            
            if (document.getElementById('_datex_refill_point_ids')) {
                document.getElementById('_datex_refill_point_ids').value = '';
            }
            
            // 8. Propisování konektorů do formuláře
            if (selectedStationData.connectors && selectedStationData.connectors.length > 0) {
                var connectorsContainer = document.getElementById('connectors-container');
                if (connectorsContainer) {
                    connectorsContainer.innerHTML = '';
                    
                    selectedStationData.connectors.forEach(function(connector, index) {
                        var connectorHtml = createConnectorRow(index, connector);
                        connectorsContainer.innerHTML += connectorHtml;
                    });
                }
            }
            
            // 9. Uložit data pro pozdější použití
            jQuery.post(ajaxurl, {
                action: 'save_selected_station_data',
                nonce: '<?php echo wp_create_nonce('db_save_station_data'); ?>',
                post_id: <?php echo $post->ID; ?>,
                station_data: selectedStationData
            }, function(response) {
                if (response.success) {
                    console.log('Data úspěšně uložena přes AJAX');
                    alert('Stanice byla úspěšně aplikována! Název a obrázek budou uloženy při uložení příspěvku.');
                } else {
                    console.error('Chyba při AJAX ukládání: ' + response.data);
                    alert('Chyba při ukládání: ' + response.data);
                }
            });
        }
        
        function createConnectorRow(index, connector) {
            var html = '<div class="connector-row">';
            html += '<table class="form-table">';
            html += '<tr><th scope="row">Typ konektoru</th>';
            html += '<td><input type="text" name="_connectors[' + index + '][type]" value="' + (connector.type || '') + '" class="regular-text" /></td></tr>';
            html += '<tr><th scope="row">Výkon (kW)</th>';
            html += '<td><input type="number" name="_connectors[' + index + '][power_kw]" value="' + (connector.power_kw || '') + '" class="small-text" step="0.1" /></td></tr>';
            html += '<tr><th scope="row">Množství</th>';
            html += '<td><input type="number" name="_connectors[' + index + '][quantity]" value="' + (connector.quantity || '1') + '" class="small-text" min="1" /></td></tr>';
            html += '<tr><th scope="row">Stav</th>';
            html += '<td><select name="_connectors[' + index + '][status]">';
            html += '<option value="Operational"' + (connector.status === 'Operational' ? ' selected' : '') + '>Funkční</option>';
            html += '<option value="NonOperational"' + (connector.status === 'NonOperational' ? ' selected' : '') + '>Nefunkční</option>';
            html += '<option value="Planned"' + (connector.status === 'Planned' ? ' selected' : '') + '>Plánovaný</option>';
            html += '<option value="UnderConstruction"' + (connector.status === 'UnderConstruction' ? ' selected' : '') + '>Ve výstavbě</option>';
            html += '</select></td></tr>';
            html += '<tr><th scope="row">Úroveň</th>';
            html += '<td><input type="text" name="_connectors[' + index + '][level]" value="' + (connector.level || '') + '" class="regular-text" readonly /></td></tr>';
            html += '<tr><th scope="row">Typ proudu</th>';
            html += '<td><input type="text" name="_connectors[' + index + '][current_type]" value="' + (connector.current_type || '') + '" class="regular-text" readonly /></td></tr>';
            html += '</table>';
            html += '<button type="button" class="button remove-connector">Odebrat konektor</button>';
            html += '<hr>';
            html += '</div>';
            return html;
        }
        
        function clearSelectedStation() {
            selectedStationData = null;
            document.getElementById('selected_station_section').style.display = 'none';
            document.getElementById('selected_station_info').innerHTML = '';
            
            // Odstranit výběr z výsledků
            var results = document.querySelectorAll('.station-result');
            results.forEach(function(result) {
                result.classList.remove('selected');
            });
        }
        </script>
        <?php
    }

    /**
     * Render meta boxu pro OpenChargeMap data
     */
    public function render_charging_meta_box($post) {
        wp_nonce_field('db_save_charging_data', 'db_charging_data_nonce');

        // OpenChargeMap data
        $openchargemap_id = get_post_meta($post->ID, '_openchargemap_id', true);
        $operator = get_post_meta($post->ID, '_operator', true);
        $connectors = get_post_meta($post->ID, '_connectors', true);
        $featured_image_url = get_post_meta($post->ID, '_featured_image_url', true);
        $featured_image_comment = get_post_meta($post->ID, '_featured_image_comment', true);
        $data_provider = get_post_meta($post->ID, '_data_provider', true);
        $data_quality = get_post_meta($post->ID, '_data_quality', true);
        $date_created = get_post_meta($post->ID, '_date_created', true);
        $last_status_update = get_post_meta($post->ID, '_last_status_update', true);
        
        // DATEX II data pro budoucí kontrolu dostupnosti
        $datex_station_id = get_post_meta($post->ID, '_datex_station_id', true);
        $datex_refill_point_ids = get_post_meta($post->ID, '_datex_refill_point_ids', true);
        
        // Admin poznámky
        $admin_notes = get_post_meta($post->ID, '_admin_notes', true);

        if (!is_array($connectors)) {
            $connectors = array();
        }

        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label>OpenChargeMap ID</label></th>
                <td>
                    <input type="text" name="_openchargemap_id" value="<?php echo esc_attr($openchargemap_id); ?>" class="regular-text" readonly />
                    <p class="description">ID stanice z OpenChargeMap API</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Operátor</label></th>
                <td>
                    <input type="text" name="_operator" value="<?php echo esc_attr($operator); ?>" class="regular-text" />
                    <p class="description">Operátor nabíjecí stanice</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Obrázek stanice</label></th>
                <td>
                    <?php if ($featured_image_url): ?>
                        <img src="<?php echo esc_url($featured_image_url); ?>" style="max-width: 200px; height: auto; margin-bottom: 10px;" />
                        <br>
                    <?php endif; ?>
                    <input type="text" name="_featured_image_url" value="<?php echo esc_attr($featured_image_url); ?>" class="regular-text" placeholder="URL obrázku" />
                    <p class="description">URL obrázku stanice z OpenChargeMap</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Popis obrázku</label></th>
                <td>
                    <textarea name="_featured_image_comment" rows="3" class="large-text"><?php echo esc_textarea($featured_image_comment); ?></textarea>
                    <p class="description">Popis nebo komentář k obrázku</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Admin poznámky</label></th>
                <td>
                    <textarea name="_admin_notes" rows="4" class="large-text"><?php echo esc_textarea($admin_notes); ?></textarea>
                    <p class="description">Poznámky pro administrátora (zobrazí se na frontendu)</p>
                </td>
            </tr>
        </table>

        <h3>DATEX II data (pro budoucí kontrolu dostupnosti)</h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label>DATEX II Station ID</label></th>
                <td>
                    <input type="text" name="_datex_station_id" value="<?php echo esc_attr($datex_station_id); ?>" class="regular-text" />
                    <p class="description">ID stanice v DATEX II systému pro kontrolu dostupnosti</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>DATEX II Refill Point IDs</label></th>
                <td>
                    <textarea name="_datex_refill_point_ids" rows="3" class="large-text"><?php echo esc_textarea($datex_refill_point_ids); ?></textarea>
                    <p class="description">ID nabíjecích bodů v DATEX II (oddělené čárkami)</p>
                </td>
            </tr>
        </table>

        <h3>Metadata</h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label>Zdroj dat</label></th>
                <td>
                    <input type="text" value="<?php echo esc_attr($data_provider['Title'] ?? ''); ?>" class="regular-text" readonly />
                    <p class="description">Zdroj dat z OpenChargeMap</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Kvalita dat</label></th>
                <td>
                    <input type="text" value="<?php echo esc_attr($data_quality); ?>" class="small-text" readonly />
                    <p class="description">Kvalita dat (1-5)</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Datum vytvoření</label></th>
                <td>
                    <input type="text" value="<?php echo esc_attr($date_created); ?>" class="regular-text" readonly />
                    <p class="description">Datum vytvoření záznamu</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Poslední aktualizace</label></th>
                <td>
                    <input type="text" value="<?php echo esc_attr($last_status_update); ?>" class="regular-text" readonly />
                    <p class="description">Poslední aktualizace stavu</p>
                </td>
            </tr>
        </table>

        <h3>DATEX II Data (Dynamická dostupnost)</h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label>Země</label></th>
                <td>
                    <select name="_country" id="country_select">
                        <option value="CZ" <?php selected(get_post_meta($post->ID, '_country', true), 'CZ'); ?>>Česká republika</option>
                        <option value="SK" <?php selected(get_post_meta($post->ID, '_country', true), 'SK'); ?>>Slovensko</option>
                        <option value="PL" <?php selected(get_post_meta($post->ID, '_country', true), 'PL'); ?>>Polsko</option>
                        <option value="DE" <?php selected(get_post_meta($post->ID, '_country', true), 'DE'); ?>>Německo</option>
                        <option value="AT" <?php selected(get_post_meta($post->ID, '_country', true), 'AT'); ?>>Rakousko</option>
                        <option value="HU" <?php selected(get_post_meta($post->ID, '_country', true), 'HU'); ?>>Maďarsko</option>
                        <option value="FR" <?php selected(get_post_meta($post->ID, '_country', true), 'FR'); ?>>Francie</option>
                        <option value="IT" <?php selected(get_post_meta($post->ID, '_country', true), 'IT'); ?>>Itálie</option>
                        <option value="ES" <?php selected(get_post_meta($post->ID, '_country', true), 'ES'); ?>>Španělsko</option>
                        <option value="NL" <?php selected(get_post_meta($post->ID, '_country', true), 'NL'); ?>>Nizozemsko</option>
                        <option value="BE" <?php selected(get_post_meta($post->ID, '_country', true), 'BE'); ?>>Belgie</option>
                        <option value="SE" <?php selected(get_post_meta($post->ID, '_country', true), 'SE'); ?>>Švédsko</option>
                        <option value="NO" <?php selected(get_post_meta($post->ID, '_country', true), 'NO'); ?>>Norsko</option>
                        <option value="DK" <?php selected(get_post_meta($post->ID, '_country', true), 'DK'); ?>>Dánsko</option>
                        <option value="FI" <?php selected(get_post_meta($post->ID, '_country', true), 'FI'); ?>>Finsko</option>
                    </select>
                    <p class="description">Vyberte zemi pro DATEX II API</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>DATEX II Station ID</label></th>
                <td>
                    <input type="text" name="_datex_station_id" id="datex_station_id" value="<?php echo esc_attr($datex_station_id); ?>" class="regular-text" />
                    <button type="button" class="button" onclick="findDatexStation()">Najít DATEX II stanici</button>
                    <p class="description">ID stanice z DATEX II EnergyInfrastructureTablePublication</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>DATEX II Refill Point IDs</label></th>
                <td>
                    <input type="text" name="_datex_refill_point_ids" id="datex_refill_point_ids" value="<?php echo esc_attr($datex_refill_point_ids); ?>" class="regular-text" />
                    <p class="description">ID konektorů oddělené čárkami (např. id1,id2,id3)</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Poslední DATEX II aktualizace</label></th>
                <td>
                    <input type="text" value="<?php echo esc_attr($datex_last_status_update); ?>" class="regular-text" readonly />
                    <p class="description">Čas poslední aktualizace z DATEX II</p>
                </td>
            </tr>
        </table>

        <div style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa;">
            <h4>Automatické načítání dat</h4>
            <p>Klikněte na tlačítka níže pro automatické načítání dat z TomTom a DATEX II API:</p>
            
            <div style="margin: 10px 0;">
                <button type="button" class="button button-primary" onclick="loadTomTomData()">
                    <span class="dashicons dashicons-search"></span> Načíst TomTom data
                </button>
                <span id="tomtom_status" style="margin-left: 10px;"></span>
            </div>
            
            <div style="margin: 10px 0;">
                <button type="button" class="button button-primary" onclick="loadDatexData()">
                    <span class="dashicons dashicons-search"></span> Načíst DATEX II data
                </button>
                <span id="datex_status" style="margin-left: 10px;"></span>
            </div>
            
            <div style="margin: 10px 0;">
                <button type="button" class="button button-secondary" onclick="saveAllData()">
                    <span class="dashicons dashicons-saved"></span> Uložit všechna data
                </button>
                <span id="save_status" style="margin-left: 10px;"></span>
            </div>
        </div>

        <h3>Konektory</h3>
        <div id="connectors-container">
            <?php if (!empty($connectors)): ?>
                <?php foreach ($connectors as $index => $connector): ?>
                    <div class="connector-row">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Typ konektoru</th>
                                <td>
                                    <input type="text" name="_connectors[<?php echo $index; ?>][type]" value="<?php echo esc_attr($connector['type']); ?>" class="regular-text" />
                                    <p class="description">Název typu konektoru (CHAdeMO, CCS, Type 2, atd.)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Výkon (kW)</th>
                                <td>
                                    <input type="number" name="_connectors[<?php echo $index; ?>][power_kw]" value="<?php echo esc_attr($connector['power_kw']); ?>" class="small-text" step="0.1" />
                                    <p class="description">Výkon konektoru v kW</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Množství</th>
                                <td>
                                    <input type="number" name="_connectors[<?php echo $index; ?>][quantity]" value="<?php echo esc_attr($connector['quantity']); ?>" class="small-text" min="1" />
                                    <p class="description">Počet konektorů tohoto typu</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Stav</th>
                                <td>
                                    <select name="_connectors[<?php echo $index; ?>][status]">
                                        <option value="Operational" <?php selected($connector['status'], 'Operational'); ?>>Funkční</option>
                                        <option value="NonOperational" <?php selected($connector['status'], 'NonOperational'); ?>>Nefunkční</option>
                                        <option value="Planned" <?php selected($connector['status'], 'Planned'); ?>>Plánovaný</option>
                                        <option value="UnderConstruction" <?php selected($connector['status'], 'UnderConstruction'); ?>>Ve výstavbě</option>
                                    </select>
                                    <p class="description">Stav konektoru</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Úroveň</th>
                                <td>
                                    <input type="text" name="_connectors[<?php echo $index; ?>][level]" value="<?php echo esc_attr($connector['level']); ?>" class="regular-text" readonly />
                                    <p class="description">Úroveň nabíjení (Level 1/2/3)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Typ proudu</th>
                                <td>
                                    <input type="text" name="_connectors[<?php echo $index; ?>][current_type]" value="<?php echo esc_attr($connector['current_type']); ?>" class="regular-text" readonly />
                                    <p class="description">AC/DC</p>
                                </td>
                            </tr>
                        </table>
                        <button type="button" class="button remove-connector">Odebrat konektor</button>
                        <hr>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <button type="button" class="button" id="add-connector">Přidat konektor</button>

        <script>
        jQuery(document).ready(function($) {
            var connectorIndex = <?php echo count($connectors); ?>;
            var tomtomData = null;
            var datexData = null;
            
            $('#add-connector').click(function() {
                var html = '<div class="connector-row">' +
                    '<select name="_connectors[' + connectorIndex + '][type]">' +
                    '<option value="Type 2">Type 2</option>' +
                    '<option value="CCS">CCS</option>' +
                    '<option value="CHAdeMO">CHAdeMO</option>' +
                    '<option value="Tesla">Tesla</option>' +
                    '</select>' +
                    '<input type="number" name="_connectors[' + connectorIndex + '][power_kw]" placeholder="Výkon (kW)" class="small-text" step="0.1" />' +
                    '<select name="_connectors[' + connectorIndex + '][status]">' +
                    '<option value="available">Dostupný</option>' +
                    '<option value="occupied">Obsazeno</option>' +
                    '<option value="fault">Porucha</option>' +
                    '<option value="offline">Offline</option>' +
                    '</select>' +
                    '<button type="button" class="button remove-connector">Odebrat</button>' +
                    '</div>';
                $('#connectors-container').append(html);
                connectorIndex++;
            });
            
            $(document).on('click', '.remove-connector', function() {
                $(this).closest('.connector-row').remove();
            });
        });

        // Globální funkce pro načítání dat
        function loadTomTomData() {
            var lat = document.getElementById('_db_lat').value;
            var lng = document.getElementById('_db_lng').value;
            
            if (!lat || !lng) {
                alert('Nejdříve zadejte souřadnice stanice');
                return;
            }
            
            document.getElementById('tomtom_status').innerHTML = '<span style="color: orange;">Načítám...</span>';
            
            jQuery.post(ajaxurl, {
                action: 'search_tomtom_charging',
                nonce: '<?php echo wp_create_nonce('db_tomtom_search'); ?>',
                lat: lat,
                lng: lng,
                radius: 1000
            }, function(response) {
                if (response.success && response.data.results && response.data.results.length > 0) {
                    tomtomData = response.data.results[0]; // První nejbližší stanice
                    document.getElementById('tomtom_status').innerHTML = '<span style="color: green;">✓ Data načtena</span>';
                    
                    // Automaticky vyplnit pole
                    if (tomtomData.id) {
                        document.getElementById('_tomtom_poi_id').value = tomtomData.id;
                    }
                    if (tomtomData.chargingAvailability && tomtomData.chargingAvailability.id) {
                        document.getElementById('_tomtom_availability_id').value = tomtomData.chargingAvailability.id;
                    }
                } else {
                    document.getElementById('tomtom_status').innerHTML = '<span style="color: red;">✗ Nebyly nalezeny žádné stanice</span>';
                }
            }).fail(function() {
                document.getElementById('tomtom_status').innerHTML = '<span style="color: red;">✗ Chyba při načítání</span>';
            });
        }

        function loadDatexData() {
            var lat = document.getElementById('_db_lat').value;
            var lng = document.getElementById('_db_lng').value;
            var country = document.getElementById('country_select').value;
            
            if (!lat || !lng) {
                alert('Nejdříve zadejte souřadnice stanice');
                return;
            }
            
            document.getElementById('datex_status').innerHTML = '<span style="color: orange;">Načítám...</span>';
            
            jQuery.post(ajaxurl, {
                action: 'get_datex_station_data',
                nonce: '<?php echo wp_create_nonce('db_datex_station'); ?>',
                lat: lat,
                lng: lng,
                country: country
            }, function(response) {
                if (response.success) {
                    datexData = response.data;
                    document.getElementById('datex_status').innerHTML = '<span style="color: green;">✓ Data načtena</span>';
                    
                    // Automaticky vyplnit pole
                    if (datexData.station_id) {
                        document.getElementById('datex_station_id').value = datexData.station_id;
                    }
                    if (datexData.refill_point_ids && datexData.refill_point_ids.length > 0) {
                        document.getElementById('datex_refill_point_ids').value = datexData.refill_point_ids.join(',');
                    }
                } else {
                    document.getElementById('datex_status').innerHTML = '<span style="color: red;">✗ ' + response.data + '</span>';
                }
            }).fail(function() {
                document.getElementById('datex_status').innerHTML = '<span style="color: red;">✗ Chyba při načítání</span>';
            });
        }

        function saveAllData() {
            var postId = <?php echo $post->ID; ?>;
            
            if (!tomtomData && !datexData) {
                alert('Nejdříve načtěte data z TomTom nebo DATEX II');
                return;
            }
            
            document.getElementById('save_status').innerHTML = '<span style="color: orange;">Ukládám...</span>';
            
            jQuery.post(ajaxurl, {
                action: 'save_station_data',
                nonce: '<?php echo wp_create_nonce('db_save_station'); ?>',
                post_id: postId,
                tomtom_data: tomtomData,
                datex_data: datexData
            }, function(response) {
                if (response.success) {
                    document.getElementById('save_status').innerHTML = '<span style="color: green;">✓ ' + response.data.message + '</span>';
                    // Reload stránky pro zobrazení aktualizovaných dat
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    document.getElementById('save_status').innerHTML = '<span style="color: red;">✗ ' + response.data + '</span>';
                }
            }).fail(function() {
                document.getElementById('save_status').innerHTML = '<span style="color: red;">✗ Chyba při ukládání</span>';
            });
        }

        function findDatexStation() {
            loadDatexData();
        }
        </script>

        <style>
        .connector-row {
            margin-bottom: 10px;
            padding: 10px;
            border: 1px solid #ddd;
            background: #f9f9f9;
        }
        .connector-row select,
        .connector-row input {
            margin-right: 10px;
        }
        </style>
        <?php
    }

    /**
     * Uložení meta boxů pro nabíjecí stanice
     */
    public function save_charging_meta_boxes($post_id) {
        if (!isset($_POST['db_charging_data_nonce']) || !wp_verify_nonce($_POST['db_charging_data_nonce'], 'db_save_charging_data')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if ($_POST['post_type'] !== 'charging_location') {
            return;
        }

        // OpenChargeMap ID
        if (isset($_POST['_openchargemap_id'])) {
            update_post_meta($post_id, '_openchargemap_id', sanitize_text_field($_POST['_openchargemap_id']));
        }

        // Operátor
        if (isset($_POST['_operator'])) {
            update_post_meta($post_id, '_operator', sanitize_text_field($_POST['_operator']));
        }

        // Obrázek stanice
        if (isset($_POST['_featured_image_url'])) {
            update_post_meta($post_id, '_featured_image_url', esc_url_raw($_POST['_featured_image_url']));
        }

        // Popis obrázku
        if (isset($_POST['_featured_image_comment'])) {
            update_post_meta($post_id, '_featured_image_comment', sanitize_textarea_field($_POST['_featured_image_comment']));
        }

        // Admin poznámky
        if (isset($_POST['_admin_notes'])) {
            update_post_meta($post_id, '_admin_notes', sanitize_textarea_field($_POST['_admin_notes']));
        }

        // DATEX II data
        if (isset($_POST['_datex_station_id'])) {
            update_post_meta($post_id, '_datex_station_id', sanitize_text_field($_POST['_datex_station_id']));
        }
        
        if (isset($_POST['_datex_refill_point_ids'])) {
            update_post_meta($post_id, '_datex_refill_point_ids', sanitize_textarea_field($_POST['_datex_refill_point_ids']));
        }

        // Konektory
        if (isset($_POST['_connectors']) && is_array($_POST['_connectors'])) {
            $connectors = array();
            foreach ($_POST['_connectors'] as $connector) {
                if (!empty($connector['type'])) {
                    $connectors[] = array(
                        'type' => sanitize_text_field($connector['type']),
                        'power_kw' => floatval($connector['power_kw']),
                        'quantity' => intval($connector['quantity']),
                        'status' => sanitize_text_field($connector['status']),
                        'level' => sanitize_text_field($connector['level']),
                        'current_type' => sanitize_text_field($connector['current_type']),
                        'icon' => sanitize_text_field($connector['icon'] ?? '')
                    );
                }
            }
            update_post_meta($post_id, '_connectors', $connectors);
        }
    }

    /**
     * AJAX handler pro vyhledávání stanic podle názvu
     */
    public function ajax_search_charging_stations_by_name() {
        check_ajax_referer('db_charging_search', 'nonce');

        $name = sanitize_text_field($_POST['name'] ?? '');
        if (empty($name)) {
            wp_send_json_error('Chybí název stanice');
        }

        // OpenChargeMap vyhledávání
        $results = $this->search_openchargemap_by_name($name);
        
        if (is_wp_error($results)) {
            wp_send_json_error($results->get_error_message());
        }

        // Seřadit výsledky podle relevance
        usort($results, function($a, $b) use ($name) {
            $a_score = $this->calculate_relevance_score($a, $name);
            $b_score = $this->calculate_relevance_score($b, $name);
            return $b_score - $a_score;
        });

        // Omezit na 20 nejlepších výsledků
        $results = array_slice($results, 0, 20);

        wp_send_json_success($results);
    }

    /**
     * AJAX handler pro vyhledávání stanic podle souřadnic
     */
    public function ajax_search_charging_stations_by_coordinates() {
        check_ajax_referer('db_charging_search', 'nonce');

        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);

        error_log('[DEBUG] Vyhledávání podle souřadnic: lat=' . $lat . ', lng=' . $lng);

        if (!$lat || !$lng) {
            error_log('[DEBUG] Neplatné souřadnice');
            wp_send_json_error('Neplatné souřadnice');
        }

        // OpenChargeMap vyhledávání
        $results = $this->search_openchargemap_by_coordinates($lat, $lng);
        
        if (is_wp_error($results)) {
            error_log('[DEBUG] OpenChargeMap chyba: ' . $results->get_error_message());
            wp_send_json_error($results->get_error_message());
        }

        error_log('[DEBUG] OpenChargeMap výsledky: ' . count($results) . ' stanic');

        // Seřadit výsledky podle vzdálenosti
        usort($results, function($a, $b) use ($lat, $lng) {
            $a_distance = $this->calculate_distance($lat, $lng, $a['lat'], $a['lng']);
            $b_distance = $this->calculate_distance($lat, $lng, $b['lat'], $b['lng']);
            return $a_distance - $b_distance;
        });

        // Omezit na 20 nejbližších výsledků
        $results = array_slice($results, 0, 20);

        error_log('[DEBUG] Finální výsledky: ' . count($results));
        
        wp_send_json_success($results);
    }

    /**
     * AJAX handler pro uložení vybrané stanice
     */
    public function ajax_save_selected_station_data() {
        check_ajax_referer('db_save_station_data', 'nonce');

        $post_id = intval($_POST['post_id'] ?? 0);
        $station_data = $_POST['station_data'] ?? null;

        if (!$post_id || !$station_data) {
            wp_send_json_error('Chybí data');
        }

        // Uložit základní data
        update_post_meta($post_id, '_selected_station_data', $station_data);
        
        // Uložit název pro pozdější zpracování
        if (isset($station_data['name'])) {
            update_post_meta($post_id, '_ocm_title', $station_data['name']);
        }
        
        // Uložit OpenChargeMap ID
        if (isset($station_data['openchargemap_id'])) {
            update_post_meta($post_id, '_openchargemap_id', $station_data['openchargemap_id']);
        }
        
        // Uložit souřadnice
        if (isset($station_data['lat']) && isset($station_data['lng'])) {
            update_post_meta($post_id, '_db_lat', floatval($station_data['lat']));
            update_post_meta($post_id, '_db_lng', floatval($station_data['lng']));
        }
        
        // Uložit adresu
        if (isset($station_data['address'])) {
            update_post_meta($post_id, '_db_address', $station_data['address']);
        }
        
        // Uložit operátora
        if (isset($station_data['operator']['Title'])) {
            update_post_meta($post_id, '_operator', $station_data['operator']['Title']);
        }
        
        // Uložit konektory
        if (isset($station_data['connectors']) && is_array($station_data['connectors'])) {
            update_post_meta($post_id, '_connectors', $station_data['connectors']);
            
            // Vytvořit nebo najít typy nabíječek
            foreach ($station_data['connectors'] as $connector) {
                if (isset($connector['type']) && $connector['type'] !== 'Unknown') {
                    $this->create_or_find_charger_type($connector['type'], $connector['type_id']);
                }
            }
        }
        
        // Uložit první obrázek
        if (isset($station_data['media']) && is_array($station_data['media']) && count($station_data['media']) > 0) {
            $first_image = $station_data['media'][0];
            if (isset($first_image['url'])) {
                update_post_meta($post_id, '_ocm_image_url', $first_image['url']);
                update_post_meta($post_id, '_ocm_image_comment', $first_image['comment'] ?? '');
                update_post_meta($post_id, '_featured_image_url', $first_image['url']);
                update_post_meta($post_id, '_featured_image_comment', $first_image['comment'] ?? '');
            }
        }
        
        // Uložit zdroj dat (OCM nebo DATEX II)
        if (isset($station_data['data_source'])) {
            update_post_meta($post_id, '_data_source', $station_data['data_source']);
            update_post_meta($post_id, '_data_source_badge', $station_data['data_source_badge'] ?? '');
            update_post_meta($post_id, '_data_source_color', $station_data['data_source_color'] ?? '');
            error_log('[DEBUG] Uložen zdroj dat: ' . $station_data['data_source'] . ' (' . ($station_data['data_source_badge'] ?? 'N/A') . ')');
        }
        
        // Uložit metadata
        if (isset($station_data['data_provider'])) {
            update_post_meta($post_id, '_data_provider', $station_data['data_provider']);
        }
        if (isset($station_data['data_quality'])) {
            update_post_meta($post_id, '_data_quality', $station_data['data_quality']);
        }
        if (isset($station_data['date_created'])) {
            update_post_meta($post_id, '_date_created', $station_data['date_created']);
        }
        if (isset($station_data['last_status_update'])) {
            update_post_meta($post_id, '_last_status_update', $station_data['last_status_update']);
        }

        wp_send_json_success('Data uložena');
    }

    /**
     * Vytvoření nebo nalezení typu nabíječky
     */
    private function create_or_find_charger_type($type_name, $type_id = null) {
        // Pokus o nalezení existujícího termu
        $existing_term = get_term_by('name', $type_name, 'charger_type');
        
        if ($existing_term) {
            return $existing_term->term_id;
        }
        
        // Vytvoření nového termu
        $term_data = wp_insert_term($type_name, 'charger_type');
        
        if (is_wp_error($term_data)) {
            error_log('[DEBUG] Chyba při vytváření typu nabíječky: ' . $term_data->get_error_message());
            return null;
        }
        
        $term_id = is_array($term_data) ? $term_data['term_id'] : $term_data;
        
        // Uložit OpenChargeMap ID typu
        if ($type_id) {
            update_term_meta($term_id, '_openchargemap_type_id', $type_id);
        }
        
        error_log('[DEBUG] Vytvořen nový typ nabíječky: ' . $type_name . ' (ID: ' . $term_id . ')');
        return $term_id;
    }

    /**
     * Vyhledávání v OpenChargeMap podle názvu
     */
    private function search_openchargemap_by_name($name) {
        error_log('[DEBUG] OpenChargeMap API - vyhledávání podle názvu');
        
        // Získat OpenChargeMap API klíč
        $api_key = get_option('db_openchargemap_api_key');
        if (empty($api_key)) {
            error_log('[DEBUG] OpenChargeMap API klíč není nastaven');
            return new \WP_Error('no_api_key', 'OpenChargeMap API klíč není nastaven');
        }
        
        // OpenChargeMap API v3 - POI Search by title s kompletními daty
        $url = "https://api.openchargemap.io/v3/poi";
        $url .= "?key=" . urlencode($api_key);
        $url .= "&title=" . urlencode($name);
        $url .= "&maxresults=20";
        $url .= "&compact=false"; // Kompletní data
        $url .= "&verbose=true"; // Detailní informace
        $url .= "&includemediadata=true"; // Obrázky a média
        $url .= "&includecomments=true"; // Komentáře
        $url .= "&connectiontypeid="; // Všechny typy konektorů

        error_log('[DEBUG] OpenChargeMap URL (název): ' . $url);

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            )
        ));

        if (is_wp_error($response)) {
            error_log('[DEBUG] OpenChargeMap API chyba (název): ' . $response->get_error_message());
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        
        error_log('[DEBUG] OpenChargeMap HTTP kód (název): ' . $http_code);
        error_log('[DEBUG] OpenChargeMap odpověď (název): ' . substr($body, 0, 500) . '...');

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[DEBUG] OpenChargeMap JSON chyba (název): ' . json_last_error_msg());
            error_log('[DEBUG] OpenChargeMap odpověď (název, prvních 2000 znaků): ' . substr($body, 0, 2000));
            return new \WP_Error('json_error', 'Chyba při parsování JSON odpovědi: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            error_log('[DEBUG] OpenChargeMap - neplatná odpověď (není pole)');
            return array();
        }

        error_log('[DEBUG] OpenChargeMap nalezeno výsledků (název): ' . count($data));
        
        $results = array();
        foreach ($data as $poi) {
            error_log('[DEBUG] Zpracovávám POI (název): ' . json_encode($poi));
            
            // Kontrola povinných polí
            if (!isset($poi['AddressInfo']) || !isset($poi['AddressInfo']['Title'])) {
                error_log('[DEBUG] POI přeskočen (název) - chybí AddressInfo nebo Title');
                continue;
            }
            
            $station_data = array(
                'name' => $poi['AddressInfo']['Title'],
                'address' => $poi['AddressInfo']['AddressLine1'] ?? '',
                'lat' => floatval($poi['AddressInfo']['Latitude'] ?? 0),
                'lng' => floatval($poi['AddressInfo']['Longitude'] ?? 0),
                'openchargemap_id' => 'ocm_' . ($poi['ID'] ?? 'unknown'),
                'source' => 'openchargemap',
                'connectors' => $this->parse_openchargemap_connectors($poi['Connections'] ?? [])
            );
            
            // Přidat další dostupná data pro debugging
            if (isset($poi['OperatorInfo'])) {
                $station_data['operator'] = $poi['OperatorInfo'];
            }
            if (isset($poi['StatusType'])) {
                $station_data['status'] = $poi['StatusType'];
            }
            if (isset($poi['UsageType'])) {
                $station_data['usage_type'] = $poi['UsageType'];
            }
            if (isset($poi['SubmissionStatus'])) {
                $station_data['submission_status'] = $poi['SubmissionStatus'];
            }
            
            // Parsování médií (obrázky)
            if (isset($poi['MediaItems']) && is_array($poi['MediaItems'])) {
                $station_data['media'] = $this->parse_media_items($poi['MediaItems']);
            }
            
            // Parsování komentářů
            if (isset($poi['UserComments']) && is_array($poi['UserComments'])) {
                $station_data['comments'] = $this->parse_user_comments($poi['UserComments']);
            }
            
            // Další metadata
            if (isset($poi['DataProvider'])) {
                $station_data['data_provider'] = $poi['DataProvider'];
            }
            if (isset($poi['DataQualityLevel'])) {
                $station_data['data_quality'] = $poi['DataQualityLevel'];
            }
            if (isset($poi['DateCreated'])) {
                $station_data['date_created'] = $poi['DateCreated'];
            }
            if (isset($poi['DateLastStatusUpdate'])) {
                $station_data['last_status_update'] = $poi['DateLastStatusUpdate'];
            }
            
            $results[] = $station_data;
            error_log('[DEBUG] Přidána stanice (název): ' . $station_data['name'] . ' (ID: ' . $station_data['openchargemap_id'] . ')');
        }

        error_log('[DEBUG] OpenChargeMap zpracováno výsledků (název): ' . count($results));
        return $results;
    }

    /**
     * Vyhledávání v OpenChargeMap podle souřadnic
     */
    private function search_openchargemap_by_coordinates($lat, $lng) {
        error_log('[DEBUG] OpenChargeMap API - vyhledávání podle souřadnic');
        
        // Získat OpenChargeMap API klíč
        $api_key = get_option('db_openchargemap_api_key');
        if (empty($api_key)) {
            error_log('[DEBUG] OpenChargeMap API klíč není nastaven');
            return new \WP_Error('no_api_key', 'OpenChargeMap API klíč není nastaven');
        }
        
        // OpenChargeMap API v3 - POI Search s kompletními daty
        $url = "https://api.openchargemap.io/v3/poi";
        $url .= "?key=" . urlencode($api_key);
        $url .= "&latitude=" . urlencode($lat);
        $url .= "&longitude=" . urlencode($lng);
        $url .= "&distance=0.5"; // 500m radius
        $url .= "&distanceunit=km";
        $url .= "&maxresults=20";
        $url .= "&compact=false"; // Kompletní data
        $url .= "&verbose=true"; // Detailní informace
        $url .= "&includemediadata=true"; // Obrázky a média
        $url .= "&includecomments=true"; // Komentáře
        $url .= "&connectiontypeid="; // Všechny typy konektorů

        error_log('[DEBUG] OpenChargeMap URL: ' . $url);

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            )
        ));

        if (is_wp_error($response)) {
            error_log('[DEBUG] OpenChargeMap API chyba: ' . $response->get_error_message());
            return new \WP_Error('api_error', 'Chyba při volání OpenChargeMap API: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        
        error_log('[DEBUG] OpenChargeMap HTTP kód: ' . $http_code);
        error_log('[DEBUG] OpenChargeMap odpověď: ' . substr($body, 0, 1000) . '...');

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[DEBUG] OpenChargeMap JSON chyba: ' . json_last_error_msg());
            error_log('[DEBUG] OpenChargeMap odpověď (prvních 2000 znaků): ' . substr($body, 0, 2000));
            return new \WP_Error('json_error', 'Chyba při parsování JSON odpovědi: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            error_log('[DEBUG] OpenChargeMap - neplatná odpověď (není pole)');
            return array();
        }

        error_log('[DEBUG] OpenChargeMap nalezeno výsledků: ' . count($data));
        
        $results = array();
        foreach ($data as $poi) {
            error_log('[DEBUG] Zpracovávám POI (souřadnice): ' . json_encode($poi));
            
            // Kontrola povinných polí
            if (!isset($poi['AddressInfo']) || !isset($poi['AddressInfo']['Title'])) {
                error_log('[DEBUG] POI přeskočen (souřadnice) - chybí AddressInfo nebo Title');
                continue;
            }
            
            $station_data = array(
                'name' => $poi['AddressInfo']['Title'],
                'address' => $poi['AddressInfo']['AddressLine1'] ?? '',
                'lat' => floatval($poi['AddressInfo']['Latitude'] ?? 0),
                'lng' => floatval($poi['AddressInfo']['Longitude'] ?? 0),
                'openchargemap_id' => 'ocm_' . ($poi['ID'] ?? 'unknown'),
                'source' => 'openchargemap',
                'connectors' => $this->parse_openchargemap_connectors($poi['Connections'] ?? [])
            );
            
            // Přidat další dostupná data pro debugging
            if (isset($poi['OperatorInfo'])) {
                $station_data['operator'] = $poi['OperatorInfo'];
            }
            if (isset($poi['StatusType'])) {
                $station_data['status'] = $poi['StatusType'];
            }
            if (isset($poi['UsageType'])) {
                $station_data['usage_type'] = $poi['UsageType'];
            }
            if (isset($poi['SubmissionStatus'])) {
                $station_data['submission_status'] = $poi['SubmissionStatus'];
            }
            
            // Parsování médií (obrázky)
            if (isset($poi['MediaItems']) && is_array($poi['MediaItems'])) {
                $station_data['media'] = $this->parse_media_items($poi['MediaItems']);
            }
            
            // Parsování komentářů
            if (isset($poi['UserComments']) && is_array($poi['UserComments'])) {
                $station_data['comments'] = $this->parse_user_comments($poi['UserComments']);
            }
            
            // Další metadata
            if (isset($poi['DataProvider'])) {
                $station_data['data_provider'] = $poi['DataProvider'];
            }
            if (isset($poi['DataQualityLevel'])) {
                $station_data['data_quality'] = $poi['DataQualityLevel'];
            }
            if (isset($poi['DateCreated'])) {
                $station_data['date_created'] = $poi['DateCreated'];
            }
            if (isset($poi['DateLastStatusUpdate'])) {
                $station_data['last_status_update'] = $poi['DateLastStatusUpdate'];
            }
            
            $results[] = $station_data;
            error_log('[DEBUG] Přidána stanice (souřadnice): ' . $station_data['name'] . ' (ID: ' . $station_data['openchargemap_id'] . ')');
        }

        error_log('[DEBUG] OpenChargeMap zpracováno výsledků: ' . count($results));
        return $results;
    }

    /**
     * Parsování konektorů z OpenChargeMap dat
     */
    private function parse_openchargemap_connectors($connections) {
        $connectors = array();

        error_log('[DEBUG] Parsování konektorů - vstupní data: ' . json_encode($connections));

        if (is_array($connections)) {
            foreach ($connections as $connection) {
                error_log('[DEBUG] Zpracovávám konektor: ' . json_encode($connection));
                
                $connector_data = array(
                    'id' => $connection['ID'] ?? null,
                    'type' => 'Unknown',
                    'type_id' => null,
                    'power_kw' => 0,
                    'quantity' => intval($connection['Quantity'] ?? 1),
                    'status' => $connection['StatusType']['Title'] ?? 'Unknown',
                    'is_operational' => $connection['StatusType']['IsOperational'] ?? false,
                    'level' => $connection['Level']['Title'] ?? 'Unknown',
                    'level_id' => $connection['LevelID'] ?? null,
                    'current_type' => $connection['CurrentType']['Title'] ?? 'Unknown',
                    'current_type_id' => $connection['CurrentTypeID'] ?? null,
                    'amps' => floatval($connection['Amps'] ?? 0),
                    'voltage' => floatval($connection['Voltage'] ?? 0),
                    'raw_data' => $connection
                );
                
                // Pokus o získání typu konektoru - preferujeme friendly name
                if (isset($connection['ConnectionType']['Title'])) {
                    $connector_data['type'] = $connection['ConnectionType']['Title'];
                    $connector_data['type_id'] = $connection['ConnectionTypeID'] ?? null;
                } elseif (isset($connection['ConnectionType']['FormalName'])) {
                    $connector_data['type'] = $connection['ConnectionType']['FormalName'];
                    $connector_data['type_id'] = $connection['ConnectionTypeID'] ?? null;
                } else {
                    $connection_type_id = $connection['ConnectionTypeID'] ?? null;
                    if ($connection_type_id) {
                        $connector_data['type'] = $this->get_connection_type_name($connection_type_id);
                        $connector_data['type_id'] = $connection_type_id;
                    }
                }
                
                // Pokus o získání výkonu
                if (isset($connection['PowerKW'])) {
                    $connector_data['power_kw'] = floatval($connection['PowerKW']);
                } elseif (isset($connection['PowerKW']['Value'])) {
                    $connector_data['power_kw'] = floatval($connection['PowerKW']['Value']);
                }
                
                // Výpočet výkonu z napětí a proudu pokud není přímo uveden
                if ($connector_data['power_kw'] == 0 && $connector_data['amps'] > 0 && $connector_data['voltage'] > 0) {
                    $connector_data['power_kw'] = ($connector_data['amps'] * $connector_data['voltage']) / 1000;
                }
                
                $connectors[] = $connector_data;
                
                error_log('[DEBUG] Přidán konektor: ' . $connector_data['type'] . ' - ' . $connector_data['power_kw'] . ' kW (ID: ' . $connection_type_id . ')');
            }
        }

        error_log('[DEBUG] Celkem zpracováno konektorů: ' . count($connectors));
        return $connectors;
    }

    /**
     * Získání názvu typu konektoru podle ID
     */
    private function get_connection_type_name($type_id) {
        $connection_types = array(
            1 => 'Type 1 (J1772)',
            2 => 'Type 2 (Mennekes)',
            3 => 'CHAdeMO',
            4 => 'CCS (Type 1)',
            5 => 'CCS (Type 2)',
            6 => 'Tesla (Supercharger)',
            7 => 'Tesla (Destination)',
            8 => 'Type 3 (Scame)',
            9 => 'Type 3A',
            10 => 'Type 3C',
            11 => 'Type E/F (Schuko)',
            12 => 'Type G (BS1363)',
            13 => 'Type H (SI 32)',
            14 => 'Type I (AS3112)',
            15 => 'Type J (SEV1011)',
            16 => 'Type K (DS60884-2-D1)',
            17 => 'Type L (CEI 23-16-VII)',
            18 => 'Type M (BS546)',
            19 => 'Type N (NBR14136)',
            20 => 'Type O (NBR14136)',
            21 => 'Type P (SABS164)',
            22 => 'Type Q (NBR14136)',
            23 => 'Type R (NBR14136)',
            24 => 'Type S (NBR14136)',
            25 => 'Type T (NBR14136)',
            26 => 'Type U (NBR14136)',
            27 => 'Type V (NBR14136)',
            28 => 'Type W (NBR14136)',
            29 => 'Type X (NBR14136)',
            30 => 'Type Y (NBR14136)',
            31 => 'Type Z (NBR14136)',
            32 => 'Type AA (NBR14136)',
            33 => 'Type BB (NBR14136)',
            34 => 'Type CC (NBR14136)',
            35 => 'Type DD (NBR14136)',
            36 => 'Type EE (NBR14136)',
            37 => 'Type FF (NBR14136)',
            38 => 'Type GG (NBR14136)',
            39 => 'Type HH (NBR14136)',
            40 => 'Type II (NBR14136)',
            41 => 'Type JJ (NBR14136)',
            42 => 'Type KK (NBR14136)',
            43 => 'Type LL (NBR14136)',
            44 => 'Type MM (NBR14136)',
            45 => 'Type NN (NBR14136)',
            46 => 'Type OO (NBR14136)',
            47 => 'Type PP (NBR14136)',
            48 => 'Type QQ (NBR14136)',
            49 => 'Type RR (NBR14136)',
            50 => 'Type SS (NBR14136)',
            51 => 'Type TT (NBR14136)',
            52 => 'Type UU (NBR14136)',
            53 => 'Type VV (NBR14136)',
            54 => 'Type WW (NBR14136)',
            55 => 'Type XX (NBR14136)',
            56 => 'Type YY (NBR14136)',
            57 => 'Type ZZ (NBR14136)',
            58 => 'Type AAA (NBR14136)',
            59 => 'Type BBB (NBR14136)',
            60 => 'Type CCC (NBR14136)',
            61 => 'Type DDD (NBR14136)',
            62 => 'Type EEE (NBR14136)',
            63 => 'Type FFF (NBR14136)',
            64 => 'Type GGG (NBR14136)',
            65 => 'Type HHH (NBR14136)',
            66 => 'Type III (NBR14136)',
            67 => 'Type JJJ (NBR14136)',
            68 => 'Type KKK (NBR14136)',
            69 => 'Type LLL (NBR14136)',
            70 => 'Type MMM (NBR14136)',
            71 => 'Type NNN (NBR14136)',
            72 => 'Type OOO (NBR14136)',
            73 => 'Type PPP (NBR14136)',
            74 => 'Type QQQ (NBR14136)',
            75 => 'Type RRR (NBR14136)',
            76 => 'Type SSS (NBR14136)',
            77 => 'Type TTT (NBR14136)',
            78 => 'Type UUU (NBR14136)',
            79 => 'Type VVV (NBR14136)',
            80 => 'Type WWW (NBR14136)',
            81 => 'Type XXX (NBR14136)',
            82 => 'Type YYY (NBR14136)',
            83 => 'Type ZZZ (NBR14136)',
            84 => 'Type AAAA (NBR14136)',
            85 => 'Type BBBB (NBR14136)',
            86 => 'Type CCCC (NBR14136)',
            87 => 'Type DDDD (NBR14136)',
            88 => 'Type EEEE (NBR14136)',
            89 => 'Type FFFF (NBR14136)',
            90 => 'Type GGGG (NBR14136)',
            91 => 'Type HHHH (NBR14136)',
            92 => 'Type IIII (NBR14136)',
            93 => 'Type JJJJ (NBR14136)',
            94 => 'Type KKKK (NBR14136)',
            95 => 'Type LLLL (NBR14136)',
            96 => 'Type MMMM (NBR14136)',
            97 => 'Type NNNN (NBR14136)',
            98 => 'Type OOOO (NBR14136)',
            99 => 'Type PPPP (NBR14136)',
            100 => 'Type QQQQ (NBR14136)'
        );
        
        return $connection_types[$type_id] ?? 'Type ' . $type_id;
    }

    /**
     * Parsování médií z OpenChargeMap dat
     */
    private function parse_media_items($media_items) {
        $media = array();
        
        error_log('[DEBUG] Parsování médií - vstupní data: ' . json_encode($media_items));
        
        if (is_array($media_items)) {
            foreach ($media_items as $item) {
                $media_data = array(
                    'id' => $item['ID'] ?? null,
                    'url' => $item['ItemURL'] ?? null,
                    'thumbnail_url' => $item['ItemThumbnailURL'] ?? null,
                    'type' => $item['ItemType']['Title'] ?? 'Unknown',
                    'comment' => $item['Comment'] ?? '',
                    'date_created' => $item['DateCreated'] ?? null,
                    'user' => $item['User']['Username'] ?? 'Unknown'
                );
                
                $media[] = $media_data;
                error_log('[DEBUG] Přidáno médium: ' . $media_data['type'] . ' - ' . $media_data['url']);
            }
        }
        
        error_log('[DEBUG] Celkem zpracováno médií: ' . count($media));
        return $media;
    }

    /**
     * Parsování komentářů z OpenChargeMap dat
     */
    private function parse_user_comments($comments) {
        $parsed_comments = array();
        
        error_log('[DEBUG] Parsování komentářů - vstupní data: ' . json_encode($comments));
        
        if (is_array($comments)) {
            foreach ($comments as $comment) {
                $comment_data = array(
                    'id' => $comment['ID'] ?? null,
                    'comment' => $comment['Comment'] ?? '',
                    'rating' => $comment['Rating'] ?? null,
                    'date_created' => $comment['DateCreated'] ?? null,
                    'user' => $comment['User']['Username'] ?? 'Unknown',
                    'checkin_status' => $comment['CheckinStatusType']['Title'] ?? 'Unknown'
                );
                
                $parsed_comments[] = $comment_data;
                error_log('[DEBUG] Přidán komentář: ' . $comment_data['user'] . ' - ' . substr($comment_data['comment'], 0, 50) . '...');
            }
        }
        
        error_log('[DEBUG] Celkem zpracováno komentářů: ' . count($parsed_comments));
        return $parsed_comments;
    }

    /**
     * Výpočet relevance score pro řazení výsledků
     */
    private function calculate_relevance_score($station, $search_term) {
        $score = 0;
        
        // Základní score podle vzdálenosti (čím blíž, tím lepší)
        if (isset($station['distance'])) {
            $score += (1000 - min($station['distance'], 1000)) / 10;
        }
        
        // Bonus za přesnou shodu názvu
        if (stripos($station['name'], $search_term) !== false) {
            $score += 50;
        }
        
        // Bonus za OpenChargeMap data (obvykle kvalitnější)
        if ($station['source'] === 'openchargemap') {
            $score += 10;
        }
        
        return $score;
    }

    /**
     * Výpočet vzdálenosti mezi dvěma body
     */
    private function calculate_distance($lat1, $lng1, $lat2, $lng2) {
        $earth_radius = 6371000; // metry
        
        $lat1_rad = deg2rad($lat1);
        $lng1_rad = deg2rad($lng1);
        $lat2_rad = deg2rad($lat2);
        $lng2_rad = deg2rad($lng2);
        
        $delta_lat = $lat2_rad - $lat1_rad;
        $delta_lng = $lng2_rad - $lng1_rad;
        
        $a = sin($delta_lat/2) * sin($delta_lat/2) +
             cos($lat1_rad) * cos($lat2_rad) *
             sin($delta_lng/2) * sin($delta_lng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earth_radius * $c;
    }

    /**
     * AJAX handler pro kombinované hledání v OCM a DATEX II databázích
     */
    public function ajax_search_both_databases() {
        check_ajax_referer('db_charging_search', 'nonce');

        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        $search_type = $_POST['search_type'] ?? 'coordinates'; // 'coordinates' nebo 'name'

        error_log('[DEBUG] Kombinované hledání - typ: ' . $search_type . ', lat: ' . $lat . ', lng: ' . $lng);

        if ($search_type === 'coordinates' && (!$lat || !$lng)) {
            wp_send_json_error('Neplatné souřadnice');
        }

        $results = array();
        $errors = array();

        // 1. OpenChargeMap vyhledávání
        if ($search_type === 'coordinates') {
            $ocm_results = $this->search_openchargemap_by_coordinates($lat, $lng);
        } else {
            $name = sanitize_text_field($_POST['name'] ?? '');
            if (empty($name)) {
                wp_send_json_error('Chybí název pro vyhledávání');
            }
            $ocm_results = $this->search_openchargemap_by_name($name);
        }

        if (is_wp_error($ocm_results)) {
            $errors[] = 'OpenChargeMap: ' . $ocm_results->get_error_message();
            error_log('[DEBUG] OpenChargeMap chyba: ' . $ocm_results->get_error_message());
        } else {
            // Označit OCM výsledky
            foreach ($ocm_results as &$station) {
                $station['data_source'] = 'openchargemap';
                $station['data_source_badge'] = 'OCM';
                $station['data_source_color'] = '#0073aa';
            }
            $results = array_merge($results, $ocm_results);
            error_log('[DEBUG] OpenChargeMap nalezeno: ' . count($ocm_results) . ' stanic');
        }

        // DATEX II vyhledávání bylo odstraněno - vracíme se k čisté OCM implementaci
        error_log('[CHARGING DEBUG] - DATEX II vyhledávání bylo odstraněno');
        error_log('[CHARGING DEBUG] - vracíme se k čisté OCM implementaci');

        // 3. Kombinace a řazení výsledků
        if (empty($results)) {
            if (!empty($errors)) {
                wp_send_json_error('Chyby při vyhledávání: ' . implode(', ', $errors));
            } else {
                wp_send_json_success(array());
            }
        }

        // Seřadit výsledky podle vzdálenosti (pro GPS hledání) nebo relevance (pro název)
        if ($search_type === 'coordinates') {
            usort($results, function($a, $b) use ($lat, $lng) {
                $a_distance = $this->calculate_distance($lat, $lng, $a['lat'], $a['lng']);
                $b_distance = $this->calculate_distance($lat, $lng, $b['lat'], $b['lng']);
                return $a_distance - $b_distance;
            });
        } else {
            usort($results, function($a, $b) use ($name) {
                $a_score = $this->calculate_relevance_score($a, $name);
                $b_score = $this->calculate_relevance_score($b, $name);
                return $b_score - $a_score; // Větší score = lepší
            });
        }

        // Omezit na 30 nejlepších výsledků
        $results = array_slice($results, 0, 30);

        error_log('[DEBUG] Finální výsledky: ' . count($results) . ' stanic (OCM: ' . count(array_filter($results, function($r) { return $r['data_source'] === 'openchargemap'; })) . ', DATEX II: ' . count(array_filter($results, function($r) { return $r['data_source'] === 'datex'; })) . ')');
        
        wp_send_json_success($results);
    }

    // DATEX II funkce byly odstraněny

    // DATEX II funkce byly odstraněny

    // DATEX II parsovací funkce byly odstraněny
} 
<?php

declare(strict_types=1);

namespace DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple provider management interface (export/import)
 * Safe version with AJAX import functionality
 */
class Provider_Manager {

    public function __construct() {
        // Only add admin menu - no admin_post handlers
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // AJAX handlers for import
        add_action('wp_ajax_db_import_providers_csv', [$this, 'handle_csv_import']);
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'edit.php?post_type=charging_location',
            'Správa poskytovatelů',
            'Správa poskytovatelů',
            'manage_options',
            'db-provider-manager',
            [$this, 'admin_page']
        );
    }

    public function enqueue_admin_scripts(string $hook): void {
        if ($hook !== 'charging_location_page_db-provider-manager') {
            return;
        }

        wp_enqueue_style(
            'db-provider-manager',
            DB_PLUGIN_URL . 'assets/admin.css',
            [],
            DB_PLUGIN_VERSION
        );
        
        wp_enqueue_script(
            'db-provider-manager',
            DB_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            DB_PLUGIN_VERSION,
            true
        );
        
        // Localize script with AJAX URL
        wp_localize_script('db-provider-manager', 'dbProviderManager', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('db_provider_import_nonce'),
            'strings' => [
                'importing' => 'Importuji...',
                'success' => 'Import dokončen úspěšně!',
                'error' => 'Chyba při importu: ',
                'selectFile' => 'Vyberte CSV soubor'
            ]
        ]);
    }

    public function admin_page(): void {
        $providers = get_terms([
            'taxonomy' => 'provider',
            'hide_empty' => false,
        ]);

        if (is_wp_error($providers)) {
            $providers = [];
        }

        $total_providers = count($providers);
        $providers_with_ios = 0;
        $providers_with_android = 0;
        $providers_with_website = 0;

        foreach ($providers as $provider) {
            $ios = get_term_meta($provider->term_id, 'provider_ios_app_url', true);
            $android = get_term_meta($provider->term_id, 'provider_android_app_url', true);
            $website = get_term_meta($provider->term_id, 'provider_website', true);

            if (!empty($ios)) $providers_with_ios++;
            if (!empty($android)) $providers_with_android++;
            if (!empty($website)) $providers_with_website++;
        }

        ?>
        <div class="wrap">
            <h1>Správa poskytovatelů</h1>
            
            <div class="db-provider-stats">
                <h2>Přehled</h2>
                <div class="db-stats-grid">
                    <div class="db-stat-box">
                        <h3><?php echo $total_providers; ?></h3>
                        <p>Celkem poskytovatelů</p>
                    </div>
                    <div class="db-stat-box">
                        <h3><?php echo $providers_with_ios; ?></h3>
                        <p>S iOS aplikací</p>
                    </div>
                    <div class="db-stat-box">
                        <h3><?php echo $providers_with_android; ?></h3>
                        <p>S Android aplikací</p>
                    </div>
                    <div class="db-stat-box">
                        <h3><?php echo $providers_with_website; ?></h3>
                        <p>S webem</p>
                    </div>
                </div>
            </div>

            <div class="db-provider-actions">
                <h2>Export a import</h2>
                
                <div class="db-action-section">
                    <h3>Export poskytovatelů</h3>
                    <p>Exportujte aktuální stav poskytovatelů do CSV souboru pro úpravy v Excelu nebo ChatGPT.</p>
                    
                    <p><strong>Použijte WP-CLI příkaz:</strong></p>
                    <code>wp db-providers export-csv --file=providers.csv</code>
                    
                    <p class="description">Tento příkaz exportuje všechny poskytovatele do CSV souboru.</p>
                </div>

                <div class="db-action-section">
                    <h3>Import poskytovatelů</h3>
                    <p>Importujte doplněná data o poskytovatelích z CSV souboru přímo z admin rozhraní.</p>
                    
                    <div class="db-import-form">
                        <form id="db-csv-import-form" enctype="multipart/form-data">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="csv_file">CSV soubor</label>
                                    </th>
                                    <td>
                                        <input type="file" id="csv_file" name="csv_file" accept=".csv" required />
                                        <p class="description">Vyberte CSV soubor s daty o poskytovatelích</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="import_mode">Režim importu</label>
                                    </th>
                                    <td>
                                        <select id="import_mode" name="import_mode">
                                            <option value="update">Aktualizovat existující (použít term_id)</option>
                                            <option value="merge">Sloučit s existujícími</option>
                                            <option value="replace">Nahradit všechna data</option>
                                        </select>
                                        <p class="description">Jak zacházet s existujícími daty</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button type="submit" class="button button-primary" id="db-import-submit">
                                    Importovat CSV
                                </button>
                                <span class="spinner" style="float: none; margin-left: 10px;"></span>
                            </p>
                        </form>
                        
                        <div id="db-import-results" style="display: none;">
                            <h3>Výsledek importu</h3>
                            <div id="db-import-stats"></div>
                            <div id="db-import-errors" style="display: none;"></div>
                        </div>
                    </div>
                    
                    <p><strong>Alternativně použijte WP-CLI příkaz:</strong></p>
                    <code>wp db-providers import-csv --file=providers_enriched.csv</code>
                </div>
            </div>

            <div class="db-provider-help">
                <h2>Nápověda</h2>
                
                <h3>Formát CSV souboru</h3>
                <p>CSV soubor by měl obsahovat následující sloupce:</p>
                <ul>
                    <li><strong>term_id</strong> - ID termu v taxonomii (povinné pro aktualizaci)</li>
                    <li><strong>name</strong> - Název poskytovatele</li>
                    <li><strong>friendly_name</strong> - Uživatelsky přívětivý název</li>
                    <li><strong>logo</strong> - URL loga</li>
                    <li><strong>ios_app_url</strong> - Odkaz na iOS aplikaci</li>
                    <li><strong>android_app_url</strong> - Odkaz na Android aplikaci</li>
                    <li><strong>website</strong> - Oficiální web</li>
                    <li><strong>notes</strong> - Poznámky</li>
                    <li><strong>source_url</strong> - Zdroj dat</li>
                </ul>

                <h3>Příklad CSV</h3>
                <pre>term_id,name,friendly_name,ios_app_url,android_app_url,website
655,"Shell Deutschland GmbH","Shell","https://apps.apple.com/cz/app/shell-recharge/...","https://play.google.com/store/apps/details?id=com.shell.recharge","https://www.shell.de/"
648,"EnBW mobility+ AG und Co.KG","EnBW","https://apps.apple.com/cz/app/enbw-mobility/...","https://play.google.com/store/apps/details?id=com.enbw.mobility","https://www.enbw.com/"</pre>

                <h3>Workflow</h3>
                <ol>
                    <li>Exportujte aktuální stav: <code>wp db-providers export-csv</code></li>
                    <li>Otevřete CSV v Excelu nebo ChatGPT</li>
                    <li>Doplňte chybějící odkazy na aplikace a weby</li>
                    <li>Importujte zpět pomocí formuláře výše nebo WP-CLI: <code>wp db-providers import-csv --file=providers_enriched.csv</code></li>
                </ol>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for CSV import
     */
    public function handle_csv_import(): void {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'db_provider_import_nonce')) {
            wp_die('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        try {
            // Check if file was uploaded
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception('No file uploaded or upload error');
            }
            
            $file = $_FILES['csv_file'];
            $mode = sanitize_text_field($_POST['import_mode'] ?? 'update');
            
            // Validate file type
            $file_info = pathinfo($file['name']);
            if (strtolower($file_info['extension']) !== 'csv') {
                throw new \Exception('Only CSV files are allowed');
            }
            
            // Parse CSV
            $data = $this->parse_csv($file['tmp_name']);
            if (empty($data)) {
                throw new \Exception('CSV file is empty or invalid');
            }
            
            // Process import
            $stats = $this->process_import($data, $mode);
            
            // Return success response
            wp_send_json_success([
                'message' => 'Import completed successfully',
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Import failed: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Parse CSV file
     */
    private function parse_csv(string $filepath): array {
        $data = [];
        
        if (($handle = fopen($filepath, 'r')) === false) {
            return [];
        }
        
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return [];
        }
        
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($headers)) {
                continue; // Skip malformed rows
            }
            
            $row_data = array_combine($headers, $row);
            if ($row_data) {
                $data[] = $row_data;
            }
        }
        
        fclose($handle);
        return $data;
    }
    
    /**
     * Process import data
     */
    private function process_import(array $data, string $mode): array {
        $stats = [
            'total' => count($data),
            'updated' => 0,
            'created' => 0,
            'errors' => 0,
            'error_messages' => []
        ];
        
        foreach ($data as $row) {
            try {
                if ($mode === 'update' && !empty($row['term_id'])) {
                    $this->update_provider($row);
                    $stats['updated']++;
                } else {
                    $this->create_or_update_provider($row);
                    $stats['updated']++;
                }
                
                // Log successful update for debugging
                error_log('[PROVIDER IMPORT] Updated provider: ' . ($row['name'] ?? 'unknown') . ' (ID: ' . ($row['term_id'] ?? 'new') . ')');
                
                // Log what was imported for better feedback
                $imported_fields = [];
                foreach (['ios_app_url', 'android_app_url', 'website', 'notes', 'source_url'] as $field) {
                    if (!empty($row[$field])) {
                        $imported_fields[] = $field . ': ' . $row[$field];
                    }
                }
                if (!empty($imported_fields)) {
                    error_log('[PROVIDER IMPORT] Fields imported: ' . implode(', ', $imported_fields));
                }
                
            } catch (\Exception $e) {
                $stats['errors']++;
                $error_msg = "Row " . ($stats['updated'] + $stats['created'] + $stats['errors']) . ": " . $e->getMessage();
                $stats['error_messages'][] = $error_msg;
                error_log('[PROVIDER IMPORT ERROR] ' . $error_msg);
            }
        }
        
        return $stats;
    }
    
    /**
     * Update existing provider
     */
    private function update_provider(array $row): void {
        $term_id = (int) $row['term_id'];
        
        if (!$term_id || !term_exists($term_id, 'provider')) {
            throw new \Exception("Term ID $term_id does not exist");
        }
        
        $this->update_provider_meta($term_id, $row);
    }
    
    /**
     * Create or update provider
     */
    private function create_or_update_provider(array $row): void {
        $name = sanitize_text_field($row['name'] ?? '');
        if (empty($name)) {
            throw new \Exception("Provider name is required");
        }
        
        $term = term_exists($name, 'provider');
        if ($term) {
            $term_id = is_array($term) ? $term['term_id'] : $term;
        } else {
            $term = wp_insert_term($name, 'provider');
            if (is_wp_error($term)) {
                throw new \Exception("Error creating term: " . $term->get_error_message());
            }
            $term_id = is_array($term) ? $term['term_id'] : $term;
        }
        
        $this->update_provider_meta($term_id, $row);
    }
    
    /**
     * Update provider meta fields
     */
    private function update_provider_meta(int $term_id, array $row): void {
        $meta_fields = [
            'provider_friendly_name' => 'friendly_name',
            'provider_logo' => 'logo',
            'provider_ios_app_url' => 'ios_app_url',
            'provider_android_app_url' => 'android_app_url',
            'provider_website' => 'website',
            'provider_notes' => 'notes',
            'provider_source_url' => 'source_url',
        ];
        
        foreach ($meta_fields as $meta_key => $row_key) {
            $value = '';
            
            // Get value from CSV row if exists
            if (isset($row[$row_key]) && $row[$row_key] !== '') {
                $value = $meta_key === 'provider_website' ? esc_url_raw($row[$row_key]) : sanitize_text_field($row[$row_key]);
            }
            
            // Set default values for empty fields
            if (empty($value)) {
                if ($meta_key === 'provider_website') {
                    $value = 'Neznámé';
                } elseif ($meta_key === 'provider_ios_app_url') {
                    $value = 'Není k dispozici';
                } elseif ($meta_key === 'provider_android_app_url') {
                    $value = 'Není k dispozici';
                } elseif ($meta_key === 'provider_notes') {
                    $value = 'Bez poznámek';
                }
            }
            
            // Always update the meta field (even with default values)
            update_term_meta($term_id, $meta_key, $value);
        }
        
        // Update last enriched timestamp
        update_term_meta($term_id, 'provider_last_enriched_at', current_time('mysql'));
    }
}

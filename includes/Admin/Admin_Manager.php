<?php

declare(strict_types=1);

namespace EVDataBridge\Admin;

class Admin_Manager {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }
    
    public function add_admin_menu(): void {
        add_menu_page(
            'EV Data Bridge',
            'EV Data Bridge',
            'manage_options',
            'ev-data-bridge',
            [$this, 'admin_page'],
            'dashicons-database-import',
            30
        );
        
        add_submenu_page(
            'ev-data-bridge',
            'Sources',
            'Sources',
            'manage_options',
            'ev-data-bridge-sources',
            [$this, 'sources_page']
        );
    }
    
    public function enqueue_admin_scripts(string $hook): void {
        if (strpos($hook, 'ev-data-bridge') === false) {
            return;
        }
        
        wp_enqueue_style(
            'ev-data-bridge-admin',
            EV_DATA_BRIDGE_PLUGIN_URL . 'assets/admin.css',
            [],
            EV_DATA_BRIDGE_VERSION
        );
        
        wp_enqueue_script(
            'ev-data-bridge-admin',
            EV_DATA_BRIDGE_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            EV_DATA_BRIDGE_VERSION,
            true
        );
    }
    
    public function admin_page(): void {
        echo '<div class="wrap">';
        echo '<h1>EV Data Bridge</h1>';
        echo '<p>WordPress plugin for importing and normalizing EV charging station data from national sources across EU27+ countries.</p>';
        echo '<p><a href="' . admin_url('admin.php?page=ev-data-bridge-sources') . '" class="button button-primary">View Sources</a></p>';
        echo '</div>';
    }
    
    public function sources_page(): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ev_sources';
        $sources = $wpdb->get_results("SELECT * FROM $table ORDER BY country_code, adapter_key");
        
        echo '<div class="wrap">';
        echo '<h1>EV Data Bridge - Sources</h1>';
        echo '<p>Registry of data sources and their status.</p>';
        
        if (empty($sources)) {
            echo '<div class="notice notice-warning"><p>No sources found. Please activate the plugin to seed the registry.</p></div>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Country</th>';
        echo '<th>Adapter</th>';
        echo '<th>Landing URL</th>';
        echo '<th>Type</th>';
        echo '<th>Frequency</th>';
        echo '<th>Status</th>';
        echo '<th>Last Version</th>';
        echo '<th>Last Success</th>';
        echo '<th>Last Error</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($sources as $source) {
            $status_class = $source->enabled ? 'enabled' : 'disabled';
            $status_text = $source->enabled ? 'Enabled' : 'Disabled';
            
            echo '<tr>';
            echo '<td><strong>' . esc_html($source->country_code) . '</strong></td>';
            echo '<td><code>' . esc_html($source->adapter_key) . '</code></td>';
            echo '<td>' . esc_html($source->landing_url) . '</td>';
            echo '<td>' . esc_html(strtoupper($source->fetch_type)) . '</td>';
            echo '<td>' . esc_html($source->update_frequency) . '</td>';
            echo '<td><span class="status-' . $status_class . '">' . esc_html($status_text) . '</span></td>';
            echo '<td>' . esc_html($source->last_version_label ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($source->last_success_at ?? 'Never') . '</td>';
            echo '<td>';
            if ($source->last_error_at) {
                echo esc_html($source->last_error_at);
                if ($source->last_error_message) {
                    echo '<br><small>' . esc_html($source->last_error_message) . '</small>';
                }
            } else {
                echo 'N/A';
            }
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        
        echo '<div class="tablenav bottom">';
        echo '<div class="alignleft actions">';
        echo '<p><strong>Note:</strong> Use WP-CLI commands to probe and fetch data:</p>';
        echo '<code>wp ev-bridge probe --source=DE</code><br>';
        echo '<code>wp ev-bridge fetch --source=DE</code>';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
    }
}

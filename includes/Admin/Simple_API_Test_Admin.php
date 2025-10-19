<?php
/**
 * Simple API Test Admin - Jednoduché testování API služeb
 * @package DobityBaterky
 */

namespace DB\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Simple_API_Test_Admin {
    
    private $apiSelector;
    
    public function __construct() {
        $this->apiSelector = new \DB\Simple_API_Selector();
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_test_api_service', [$this, 'ajax_test_service']);
        add_action('wp_ajax_clear_api_cache', [$this, 'ajax_clear_cache']);
    }
    
    /**
     * Přidat admin menu
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'tools.php',
            'API Test',
            'API Test',
            'manage_options',
            'db-api-test',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Render admin stránky
     */
    public function render_admin_page(): void {
        $stats = $this->apiSelector->getServiceStats();
        
        ?>
        <div class="wrap">
            <h1>API Service Test</h1>
            
            <div class="notice notice-info">
                <p><strong>API Workflow:</strong> Mapy.com → Google → Tripadvisor (pouze pro Českou republiku)</p>
                <p><strong>Cache TTL:</strong> Mapy.com (30 dní) | Google (30 dní) | Tripadvisor (24 hodin)</p>
            </div>
            
            <div class="card" style="max-width: 800px;">
                <h2>Service Status</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Service</th>
                            <th>API Key</th>
                            <th>Cached Items</th>
                            <th>Cache TTL</th>
                            <th>Test</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats as $service => $data): ?>
                            <tr>
                                <td><strong><?php echo esc_html(ucfirst($service)); ?></strong></td>
                                <td>
                                    <?php if ($data['api_key_configured']): ?>
                                        <span style="color: green;">✓ Configured</span>
                                    <?php else: ?>
                                        <span style="color: red;">✗ Not configured</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($data['cached_items']); ?></td>
                                <td><?php echo esc_html($data['cache_ttl']); ?></td>
                                <td>
                                    <button type="button" class="button test-service-btn" data-service="<?php echo esc_attr($service); ?>">
                                        Test Service
                                    </button>
                                </td>
                                <td>
                                    <button type="button" class="button clear-cache-btn" data-service="<?php echo esc_attr($service); ?>">
                                        Clear Cache
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="card" style="max-width: 800px;">
                <h2>Test Results</h2>
                <div id="test-results">
                    <p>Click "Test Service" to check API availability and response times.</p>
                </div>
            </div>
            
            <div class="card" style="max-width: 800px;">
                <h2>Quick Test</h2>
                <p>Test API services with a sample POI:</p>
                <button type="button" class="button button-primary" id="quick-test-btn">
                    Run Quick Test
                </button>
                <div id="quick-test-results" style="margin-top: 20px;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Test individual service
            $('.test-service-btn').on('click', function() {
                var service = $(this).data('service');
                var button = $(this);
                
                button.prop('disabled', true).text('Testing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_api_service',
                        service: service,
                        nonce: '<?php echo wp_create_nonce("test_api_service"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var result = response.data;
                            var status = result.available ? 
                                '<span style="color: green;">✓ Available</span>' : 
                                '<span style="color: red;">✗ Unavailable</span>';
                            
                            var details = '<p><strong>' + service + ':</strong> ' + status;
                            if (result.response_time) {
                                details += ' (' + result.response_time + 'ms)';
                            }
                            if (result.error) {
                                details += '<br>Error: ' + result.error;
                            }
                            details += '</p>';
                            
                            $('#test-results').html(details);
                        } else {
                            $('#test-results').html('<p style="color: red;">Test failed: ' + response.data + '</p>');
                        }
                    },
                    error: function() {
                        $('#test-results').html('<p style="color: red;">AJAX error occurred</p>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Test Service');
                    }
                });
            });
            
            // Clear cache
            $('.clear-cache-btn').on('click', function() {
                var service = $(this).data('service');
                var button = $(this);
                
                if (!confirm('Are you sure you want to clear the cache for ' + service + '?')) {
                    return;
                }
                
                button.prop('disabled', true).text('Clearing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'clear_api_cache',
                        service: service,
                        nonce: '<?php echo wp_create_nonce("clear_api_cache"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Cache cleared for ' + service + '. Cleared ' + response.data + ' items.');
                            location.reload();
                        } else {
                            alert('Failed to clear cache: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('AJAX error occurred');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Clear Cache');
                    }
                });
            });
            
            // Quick test
            $('#quick-test-btn').on('click', function() {
                var button = $(this);
                
                button.prop('disabled', true).text('Running Quick Test...');
                $('#quick-test-results').html('<p>Testing all services...</p>');
                
                var services = ['mapy', 'google', 'tripadvisor'];
                var results = {};
                var completed = 0;
                
                services.forEach(function(service) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'test_api_service',
                            service: service,
                            nonce: '<?php echo wp_create_nonce("test_api_service"); ?>'
                        },
                        success: function(response) {
                            results[service] = response.success ? response.data : { available: false, error: response.data };
                        },
                        complete: function() {
                            completed++;
                            if (completed === services.length) {
                                displayQuickTestResults(results);
                                button.prop('disabled', false).text('Run Quick Test');
                            }
                        }
                    });
                });
            });
            
            function displayQuickTestResults(results) {
                var html = '<h3>Quick Test Results:</h3><ul>';
                
                Object.keys(results).forEach(function(service) {
                    var result = results[service];
                    var status = result.available ? 
                        '<span style="color: green;">✓ Available</span>' : 
                        '<span style="color: red;">✗ Unavailable</span>';
                    
                    html += '<li><strong>' + service + ':</strong> ' + status;
                    if (result.response_time) {
                        html += ' (' + result.response_time + 'ms)';
                    }
                    if (result.error) {
                        html += ' - ' + result.error;
                    }
                    html += '</li>';
                });
                
                html += '</ul>';
                $('#quick-test-results').html(html);
            }
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler pro testování služby
     */
    public function ajax_test_service(): void {
        check_ajax_referer('test_api_service', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $service = sanitize_text_field($_POST['service'] ?? '');
        
        if (!in_array($service, ['mapy', 'google', 'tripadvisor'])) {
            wp_send_json_error('Invalid service');
        }
        
        try {
            $result = $this->apiSelector->testServiceAvailability($service);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    /**
     * AJAX handler pro vyčištění cache
     */
    public function ajax_clear_cache(): void {
        check_ajax_referer('clear_api_cache', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $service = sanitize_text_field($_POST['service'] ?? '');
        
        if (!in_array($service, ['mapy', 'google', 'tripadvisor'])) {
            wp_send_json_error('Invalid service');
        }
        
        try {
            $cleared = $this->apiSelector->clearServiceCache($service);
            wp_send_json_success($cleared);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}

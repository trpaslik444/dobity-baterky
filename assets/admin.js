/**
 * EV Data Bridge Admin JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Add admin wrapper class
    $('.wrap').addClass('ev-bridge-admin');
    
    // Initialize admin functionality
    initEVBridgeAdmin();
    
    function initEVBridgeAdmin() {
        // Add source status indicators
        addSourceStatusIndicators();
        
        // Add error message tooltips
        addErrorTooltips();
        
        // Add version label styling
        addVersionLabelStyling();
    }
    
    /**
     * Add visual status indicators to the sources table
     */
    function addSourceStatusIndicators() {
        $('.wp-list-table tbody tr').each(function() {
            var $row = $(this);
            var $statusCell = $row.find('td:eq(5)'); // Status column
            
            if ($statusCell.length) {
                var statusText = $statusCell.text().trim();
                var statusClass = statusText === 'Enabled' ? 'enabled' : 'disabled';
                
                $statusCell.prepend('<span class="source-status ' + statusClass + '"></span>');
            }
        });
    }
    
    /**
     * Add tooltips for error messages
     */
    function addErrorTooltips() {
        $('.wp-list-table tbody tr').each(function() {
            var $row = $(this);
            var $errorCell = $row.find('td:eq(8)'); // Last Error column
            
            if ($errorCell.length) {
                var errorMessage = $errorCell.find('small').text();
                
                if (errorMessage && errorMessage.trim()) {
                    $errorCell.attr('title', errorMessage.trim());
                    $errorCell.css('cursor', 'help');
                }
            }
        });
    }
    
    /**
     * Add styling to version labels
     */
    function addVersionLabelStyling() {
        $('.wp-list-table tbody tr').each(function() {
            var $row = $(this);
            var $versionCell = $row.find('td:eq(6)'); // Last Version column
            
            if ($versionCell.length) {
                var versionText = $versionCell.text().trim();
                
                if (versionText && versionText !== 'N/A') {
                    $versionCell.html('<span class="version-label">' + versionText + '</span>');
                }
            }
        });
    }
    
    /**
     * Refresh sources table (for future AJAX functionality)
     */
    function refreshSourcesTable() {
        // This would be implemented with AJAX in future versions
        location.reload();
    }
    
    /**
     * Show notification message
     */
    function showNotification(message, type) {
        type = type || 'info';
        
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        
        $('.wrap h1').after($notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut();
        }, 5000);
    }
    
    /**
     * Handle source actions (for future implementation)
     */
    $(document).on('click', '.source-action', function(e) {
        e.preventDefault();
        
        var action = $(this).data('action');
        var sourceId = $(this).data('source-id');
        
        // This would be implemented with AJAX in future versions
        console.log('Source action:', action, 'for source:', sourceId);
    });
    
    /**
     * Handle table row hover effects
     */
    $('.wp-list-table tbody tr').hover(
        function() {
            $(this).addClass('hover');
        },
        function() {
            $(this).removeClass('hover');
        }
    );
    
    /**
     * Add keyboard navigation support
     */
    $('.wp-list-table tbody tr').on('keydown', function(e) {
        var $currentRow = $(this);
        var $nextRow, $prevRow;
        
        switch(e.keyCode) {
            case 38: // Up arrow
                $prevRow = $currentRow.prev('tr');
                if ($prevRow.length) {
                    $prevRow.focus();
                }
                break;
                
            case 40: // Down arrow
                $nextRow = $currentRow.next('tr');
                if ($nextRow.length) {
                    $nextRow.focus();
                }
                break;
                
            case 13: // Enter key
                // Could be used to expand row details in future
                break;
        }
    });
    
    /**
     * Make table rows focusable for accessibility
     */
    $('.wp-list-table tbody tr').attr('tabindex', '0');
    
    /**
     * Add loading states for future AJAX operations
     */
    function showLoadingState() {
        $('.wrap').append('<div class="loading-overlay"><div class="spinner"></div></div>');
    }
    
    function hideLoadingState() {
        $('.loading-overlay').remove();
    }
    
    // Expose functions globally for potential future use
    window.EVBridgeAdmin = {
        refreshSourcesTable: refreshSourcesTable,
        showNotification: showNotification,
        showLoadingState: showLoadingState,
        hideLoadingState: hideLoadingState
    };
});

/**
 * Provider Manager CSV Import functionality
 */
jQuery(document).ready(function($) {
    const importForm = $('#db-csv-import-form');
    const submitBtn = $('#db-import-submit');
    const spinner = importForm.find('.spinner');
    const results = $('#db-import-results');
    const stats = $('#db-import-stats');
    const errors = $('#db-import-errors');
    
    if (importForm.length) {
        importForm.on('submit', function(e) {
            e.preventDefault();
            
            const fileInput = $('#csv_file')[0];
            const modeSelect = $('#import_mode');
            
            if (!fileInput.files.length) {
                alert(dbProviderManager.strings.selectFile);
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'db_import_providers_csv');
            formData.append('nonce', dbProviderManager.nonce);
            formData.append('csv_file', fileInput.files[0]);
            formData.append('import_mode', modeSelect.val());
            
            // Show loading state
            submitBtn.prop('disabled', true);
            spinner.addClass('is-active');
            results.hide();
            
            $.ajax({
                url: dbProviderManager.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        showImportResults(response.data.stats, null);
                    } else {
                        showImportResults(null, response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    showImportResults(null, 'AJAX error: ' + error);
                },
                complete: function() {
                    // Reset loading state
                    submitBtn.prop('disabled', false);
                    spinner.removeClass('is-active');
                }
            });
        });
    }
    
    function showImportResults(importStats, error) {
        if (error) {
            // Show error
            stats.html('');
            errors.html('<div class="notice notice-error"><p>' + error + '</p></div>').show();
            results.show();
        } else if (importStats) {
            // Show success with stats
            const statsHtml = `
                <div class="notice notice-success">
                    <p><strong>${dbProviderManager.strings.success}</strong></p>
                    <ul>
                        <li>Celkem: ${importStats.total}</li>
                        <li>Aktualizováno: ${importStats.updated}</li>
                        <li>Vytvořeno: ${importStats.created}</li>
                        <li>Chyby: ${importStats.errors}</li>
                    </ul>
                </div>
            `;
            
            stats.html(statsHtml);
            
            if (importStats.errors > 0 && importStats.error_messages.length > 0) {
                const errorsHtml = `
                    <div class="notice notice-warning">
                        <p><strong>Nalezené chyby:</strong></p>
                        <ul>
                            ${importStats.error_messages.slice(0, 10).map(msg => '<li>' + msg + '</li>').join('')}
                            ${importStats.error_messages.length > 10 ? '<li>... a dalších ' + (importStats.error_messages.length - 10) + ' chyb</li>' : ''}
                        </ul>
                    </div>
                `;
                errors.html(errorsHtml).show();
            } else {
                errors.hide();
            }
            
            results.show();
        }
    }
});

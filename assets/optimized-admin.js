/**
 * Optimized Admin JavaScript - JavaScript pro optimalizované admin rozhraní
 * @package DobityBaterky
 */

(function($) {
    'use strict';
    
    // Hlavní objekt pro admin funkcionalitu
    const DBAdmin = {
        
        // Inicializace
        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initTooltips();
            this.initProgressBars();
        },
        
        // Bindování eventů
        bindEvents: function() {
            // Refresh statistik
            $(document).on('click', '#db-ondemand-refresh-stats', this.refreshStats);
            
            // Clear cache
            $(document).on('click', '#db-ondemand-clear-cache', this.clearCache);
            
            // Optimize DB
            $(document).on('click', '#db-ondemand-optimize-db', this.optimizeDatabase);
            
            // Bulk process toggle
            $(document).on('click', '#db-ondemand-bulk-process', this.toggleBulkProcessing);
            
            // Test form
            $(document).on('submit', '#db-ondemand-test-form', this.handleTestForm);
            
            // Bulk form
            $(document).on('submit', '#db-ondemand-bulk-form', this.handleBulkForm);
            
            // Cache actions
            $(document).on('click', '#db-cache-clear-old', this.clearOldCache);
            $(document).on('click', '#db-cache-clear-all', this.clearAllCache);
            $(document).on('click', '#db-cache-refresh-stats', this.refreshCacheStats);
            
            // Optimization actions
            $(document).on('click', '#db-opt-create-indexes', this.createIndexes);
            $(document).on('click', '#db-opt-optimize-tables', this.optimizeTables);
            $(document).on('click', '#db-opt-cleanup-cache', this.cleanupCache);
        },
        
        // Inicializace tabů
        initTabs: function() {
            if ($.fn.tabs) {
                $('#db-ondemand-tabs').tabs({
                    active: 0,
                    activate: function(event, ui) {
                        // Zavolej callback pro aktivní tab
                        const activeTab = ui.newPanel.attr('id');
                        DBAdmin.handleTabActivation(activeTab);
                    }
                });
            }
        },
        
        // Inicializace tooltipů
        initTooltips: function() {
            $('[data-tooltip]').each(function() {
                const $this = $(this);
                const tooltip = $this.data('tooltip');
                
                $this.attr('title', tooltip);
            });
        },
        
        // Inicializace progress barů
        initProgressBars: function() {
            $('.db-progress-bar').each(function() {
                const $this = $(this);
                const percentage = $this.data('percentage') || 0;
                
                $this.find('.db-progress-fill').css('width', percentage + '%');
            });
        },
        
        // Refresh statistik
        refreshStats: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const originalText = $button.html();
            
            $button.prop('disabled', true)
                   .html('<span class="dashicons dashicons-update"></span> Aktualizuji...')
                   .addClass('db-loading');
            
            $.post(ajaxurl, {
                action: 'db_optimized_admin_action',
                action_type: 'refresh_stats',
                nonce: DBAdmin.getNonce('db_optimized_admin_action')
            })
            .done(function(response) {
                if (response.success) {
                    DBAdmin.updateStatsDisplay(response.data);
                    DBAdmin.showNotice('Statistiky aktualizovány', 'success');
                } else {
                    DBAdmin.showNotice('Chyba při aktualizaci statistik: ' + response.data, 'error');
                }
            })
            .fail(function() {
                DBAdmin.showNotice('Chyba při komunikaci se serverem', 'error');
            })
            .always(function() {
                $button.prop('disabled', false)
                       .html(originalText)
                       .removeClass('db-loading');
            });
        },
        
        // Clear cache
        clearCache: function(e) {
            e.preventDefault();
            
            if (!confirm('Opravdu chcete vymazat všechny cache?')) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.html();
            
            $button.prop('disabled', true)
                   .html('<span class="dashicons dashicons-trash"></span> Mažu...')
                   .addClass('db-loading');
            
            $.post(ajaxurl, {
                action: 'db_optimized_admin_action',
                action_type: 'clear_cache',
                nonce: DBAdmin.getNonce('db_optimized_admin_action')
            })
            .done(function(response) {
                if (response.success) {
                    DBAdmin.showNotice('Cache vymazán', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    DBAdmin.showNotice('Chyba při mazání cache: ' + response.data, 'error');
                }
            })
            .fail(function() {
                DBAdmin.showNotice('Chyba při komunikaci se serverem', 'error');
            })
            .always(function() {
                $button.prop('disabled', false)
                       .html(originalText)
                       .removeClass('db-loading');
            });
        },
        
        // Optimize database
        optimizeDatabase: function(e) {
            e.preventDefault();
            
            if (!confirm('Opravdu chcete optimalizovat databázi?')) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.html();
            
            $button.prop('disabled', true)
                   .html('<span class="dashicons dashicons-performance"></span> Optimalizuji...')
                   .addClass('db-loading');
            
            $.post(ajaxurl, {
                action: 'db_optimized_admin_action',
                action_type: 'optimize_db',
                operation: 'create_indexes',
                nonce: DBAdmin.getNonce('db_optimized_admin_action')
            })
            .done(function(response) {
                if (response.success) {
                    DBAdmin.showNotice('Databáze optimalizována: ' + response.data, 'success');
                } else {
                    DBAdmin.showNotice('Chyba při optimalizaci: ' + response.data, 'error');
                }
            })
            .fail(function() {
                DBAdmin.showNotice('Chyba při komunikaci se serverem', 'error');
            })
            .always(function() {
                $button.prop('disabled', false)
                       .html(originalText)
                       .removeClass('db-loading');
            });
        },
        
        // Toggle bulk processing
        toggleBulkProcessing: function(e) {
            e.preventDefault();
            
            $('.db-ondemand-bulk').slideToggle(300);
        },
        
        // Handle test form
        handleTestForm: function(e) {
            e.preventDefault();
            
            const formData = {
                point_id: $('#test-point-id').val(),
                point_type: $('#test-point-type').val(),
                priority: $('#test-priority').val(),
                force_refresh: $('#test-force-refresh').is(':checked'),
                include_nearby: $('#test-include-nearby').is(':checked'),
                include_discovery: $('#test-include-discovery').is(':checked')
            };
            
            if (!formData.point_id) {
                DBAdmin.showNotice('Zadejte ID bodu', 'error');
                return;
            }
            
            const $resultDiv = $('#db-ondemand-test-result');
            $resultDiv.html('<div class="notice notice-info"><p>Spouštím test zpracování...</p></div>');
            
            $.post(ajaxurl, {
                action: 'db_optimized_admin_action',
                action_type: 'process_point',
                ...formData,
                nonce: DBAdmin.getNonce('db_optimized_admin_action')
            })
            .done(function(response) {
                if (response.success) {
                    const resultHtml = DBAdmin.formatTestResult(response.data);
                    $resultDiv.html(resultHtml);
                } else {
                    $resultDiv.html('<div class="notice notice-error"><p><strong>Chyba:</strong> ' + response.data + '</p></div>');
                }
            })
            .fail(function() {
                $resultDiv.html('<div class="notice notice-error"><p><strong>Chyba:</strong> Chyba při komunikaci se serverem</p></div>');
            });
        },
        
        // Handle bulk form
        handleBulkForm: function(e) {
            e.preventDefault();
            
            const formData = {
                point_type: $('#bulk-point-type').val(),
                limit: parseInt($('#bulk-limit').val()),
                priority: $('#bulk-priority').val()
            };
            
            if (formData.limit < 1 || formData.limit > 100) {
                DBAdmin.showNotice('Počet bodů musí být mezi 1 a 100', 'error');
                return;
            }
            
            const $resultDiv = $('#db-ondemand-bulk-result');
            $resultDiv.html('<div class="notice notice-info"><p>Spouštím hromadné zpracování...</p></div>');
            
            $.post(ajaxurl, {
                action: 'db_optimized_admin_action',
                action_type: 'bulk_process',
                ...formData,
                nonce: DBAdmin.getNonce('db_optimized_admin_action')
            })
            .done(function(response) {
                if (response.success) {
                    const resultHtml = DBAdmin.formatBulkResult(response.data);
                    $resultDiv.html(resultHtml);
                } else {
                    $resultDiv.html('<div class="notice notice-error"><p><strong>Chyba:</strong> ' + response.data + '</p></div>');
                }
            })
            .fail(function() {
                $resultDiv.html('<div class="notice notice-error"><p><strong>Chyba:</strong> Chyba při komunikaci se serverem</p></div>');
            });
        },
        
        // Clear old cache
        clearOldCache: function(e) {
            e.preventDefault();
            
            if (!confirm('Opravdu chcete vymazat starý cache?')) {
                return;
            }
            
            DBAdmin.showNotice('Funkce bude implementována v další verzi', 'info');
        },
        
        // Clear all cache
        clearAllCache: function(e) {
            e.preventDefault();
            
            if (!confirm('Opravdu chcete vymazat všechny cache?')) {
                return;
            }
            
            DBAdmin.clearCache(e);
        },
        
        // Refresh cache stats
        refreshCacheStats: function(e) {
            e.preventDefault();
            
            DBAdmin.showNotice('Funkce bude implementována v další verzi', 'info');
        },
        
        // Create indexes
        createIndexes: function(e) {
            e.preventDefault();
            
            DBAdmin.optimizeDatabase(e);
        },
        
        // Optimize tables
        optimizeTables: function(e) {
            e.preventDefault();
            
            if (!confirm('Opravdu chcete optimalizovat tabulky?')) {
                return;
            }
            
            const $button = $(this);
            const originalText = $button.html();
            
            $button.prop('disabled', true)
                   .html('<span class="dashicons dashicons-hammer"></span> Optimalizuji...')
                   .addClass('db-loading');
            
            $.post(ajaxurl, {
                action: 'db_optimized_admin_action',
                action_type: 'optimize_db',
                operation: 'optimize_tables',
                nonce: DBAdmin.getNonce('db_optimized_admin_action')
            })
            .done(function(response) {
                if (response.success) {
                    DBAdmin.showNotice('Tabulky optimalizovány: ' + response.data, 'success');
                } else {
                    DBAdmin.showNotice('Chyba při optimalizaci tabulek: ' + response.data, 'error');
                }
            })
            .fail(function() {
                DBAdmin.showNotice('Chyba při komunikaci se serverem', 'error');
            })
            .always(function() {
                $button.prop('disabled', false)
                       .html(originalText)
                       .removeClass('db-loading');
            });
        },
        
        // Cleanup cache
        cleanupCache: function(e) {
            e.preventDefault();
            
            DBAdmin.clearCache(e);
        },
        
        // Handle tab activation
        handleTabActivation: function(tabId) {
            // Zavolej specifické funkce pro aktivní tab
            switch (tabId) {
                case 'db-ondemand-cache':
                    DBAdmin.loadCacheStats();
                    break;
                case 'db-ondemand-optimization':
                    DBAdmin.loadOptimizationStats();
                    break;
                case 'db-ondemand-logs':
                    DBAdmin.loadLogs();
                    break;
            }
        },
        
        // Load cache stats
        loadCacheStats: function() {
            // Implementace načítání cache statistik
            console.log('Loading cache stats...');
        },
        
        // Load optimization stats
        loadOptimizationStats: function() {
            // Implementace načítání optimalizačních statistik
            console.log('Loading optimization stats...');
        },
        
        // Load logs
        loadLogs: function() {
            // Implementace načítání logů
            console.log('Loading logs...');
        },
        
        // Update stats display
        updateStatsDisplay: function(stats) {
            // Aktualizuj zobrazení statistik
            if (stats.total_points !== undefined) {
                $('.db-stat-item').each(function() {
                    const $this = $(this);
                    const label = $this.find('.db-stat-label').text();
                    
                    if (label.includes('Celkem bodů')) {
                        $this.find('.db-stat-number').text(stats.total_points.toLocaleString());
                    } else if (label.includes('S cache')) {
                        $this.find('.db-stat-number').text(stats.cached_points.toLocaleString());
                    } else if (label.includes('Bez cache')) {
                        $this.find('.db-stat-number').text(stats.uncached_points.toLocaleString());
                    } else if (label.includes('Cache pokrytí')) {
                        const coverage = ((stats.cached_points / Math.max(stats.total_points, 1)) * 100).toFixed(1);
                        $this.find('.db-stat-number').text(coverage + '%');
                    }
                });
            }
        },
        
        // Format test result
        formatTestResult: function(data) {
            let html = '<div class="notice notice-success">';
            html += '<p><strong>Test dokončen:</strong></p>';
            html += '<ul>';
            html += '<li><strong>Status:</strong> ' + data.status + '</li>';
            html += '<li><strong>Doba zpracování:</strong> ' + data.processing_time + '</li>';
            html += '<li><strong>Z cache:</strong> ' + (data.cached ? 'Ano' : 'Ne') + '</li>';
            
            if (data.nearby) {
                html += '<li><strong>Nearby zpracováno:</strong> ' + (data.nearby.processed || 0) + '</li>';
                html += '<li><strong>Nearby chyby:</strong> ' + (data.nearby.errors || 0) + '</li>';
            }
            
            if (data.discovery) {
                html += '<li><strong>Discovery zpracováno:</strong> ' + (data.discovery.processed || 0) + '</li>';
                html += '<li><strong>Discovery chyby:</strong> ' + (data.discovery.errors || 0) + '</li>';
            }
            
            if (data.error) {
                html += '<li><strong>Chyba:</strong> ' + data.error + '</li>';
            }
            
            html += '</ul>';
            html += '</div>';
            
            return html;
        },
        
        // Format bulk result
        formatBulkResult: function(data) {
            let html = '<div class="notice notice-success">';
            html += '<p><strong>Hromadné zpracování dokončeno:</strong></p>';
            html += '<ul>';
            html += '<li><strong>Celkem bodů:</strong> ' + data.total_points + '</li>';
            html += '<li><strong>Zpracováno:</strong> ' + data.processed + '</li>';
            html += '<li><strong>Chyby:</strong> ' + data.errors + '</li>';
            html += '</ul>';
            html += '</div>';
            
            return html;
        },
        
        // Show notice
        showNotice: function(message, type) {
            const noticeClass = 'notice-' + type;
            const notice = $('<div class="notice ' + noticeClass + '"><p>' + message + '</p></div>');
            
            $('.wrap h1').after(notice);
            
            // Auto-hide po 5 sekundách
            setTimeout(function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        },
        
        // Get nonce
        getNonce: function(action) {
            // Toto by mělo být dynamicky generováno
            return $('input[name="' + action + '_nonce"]').val() || '';
        }
    };
    
    // Inicializace při načtení DOM
    $(document).ready(function() {
        DBAdmin.init();
    });
    
    // Export pro globální použití
    window.DBAdmin = DBAdmin;
    
})(jQuery);

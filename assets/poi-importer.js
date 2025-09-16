/**
 * Cafe Importer Admin JavaScript
 * Handles AJAX operations for the cafe import process
 */

jQuery(document).ready(function($) {
    'use strict';

    // Global variables
    let importRunning = false;
    let importOffset = 0;
    let importTotal = 0;
    let importProcessed = 0;

    // Initialize
    initCafeImporter();

    function initCafeImporter() {
        // Load preview if available
        if ($('#file-preview').length) {
            loadFilePreview();
        }

        // Load stats if available
        if ($('#import-stats').length) {
            loadImportStats();
        }

        // Bind events
        bindEvents();
    }

    function bindEvents() {
        // Run import button
        $('#poi-run-import').on('click', function(e) {
            e.preventDefault();
            if (!importRunning) {
                startImport();
            }
        });

        // File upload form validation
        $('form[action*="cafe_upload"]').on('submit', function(e) {
            const fileInput = $(this).find('input[type="file"]');
            if (!fileInput[0].files.length) {
                e.preventDefault();
                alert('Prosím vyberte CSV soubor.');
                return false;
            }
            
            const file = fileInput[0].files[0];
            if (file.type !== 'text/csv' && !file.name.endsWith('.csv')) {
                e.preventDefault();
                alert('Prosím vyberte platný CSV soubor.');
                return false;
            }
        });
    }

    /**
     * Load file preview
     */
    async function loadFilePreview() {
        const previewElement = $('#file-preview');
        
        try {
            previewElement.html('<em>Načítám náhled...</em>');
            
            const response = await $.post(ajaxurl, {
                action: 'poi_preview',
                _ajax_nonce: poiImporterAjax.nonce
            });
            
            if (response.success) {
                previewElement.html('<strong>Náhled souboru:</strong><br>' + response.data.preview);
            } else {
                previewElement.html('<strong>Chyba:</strong> ' + response.data);
            }
        } catch (error) {
            previewElement.html('<strong>Chyba načítání:</strong> ' + error.message);
        }
    }

    /**
     * Start import process
     */
    async function startImport() {
        if (importRunning) {
            return;
        }

        importRunning = true;
        importOffset = 0;
        importProcessed = 0;
        
        const btn = $('#poi-run-import');
        const log = $('#poi-import-log');
        
        btn.prop('disabled', true).text('Import běží...');
        log.html('Spouštím import...\n');
        
        try {
            await runImportBatch();
        } catch (error) {
            log.append(`❌ Kritická chyba: ${error.message}\n`);
            btn.prop('disabled', false).text('Chyba - zkus znovu');
        } finally {
            importRunning = false;
        }
    }

    /**
     * Run single import batch
     */
    async function runImportBatch() {
        const btn = $('#poi-run-import');
        const log = $('#poi-import-log');
        
        console.log('POI Import: Starting batch import...', {importOffset, importProcessed, importTotal});
        
        try {
            const response = await $.post(ajaxurl, {
                action: 'poi_run_import',
                offset: importOffset,
                _ajax_nonce: poiImporterAjax.nonce
            });

            if (response.success) {
                const data = response.data;
                console.log('POI Import: AJAX response received:', response);
                console.log('POI Import: Batch data:', data);
                
                importTotal = data.total;
                importProcessed += data.processed;
                importOffset += data.processed;
                
                log.append(`Dávka: ${data.processed} záznamů zpracováno (celkem: ${importProcessed}/${importTotal})\n`);
                
                if (data.done || data.processed === 0) {
                    // Import completed
                    log.append('✅ Import dokončen!\n');
                    btn.prop('disabled', false).text('Import dokončen');
                    
                    // Refresh stats
                    await loadImportStats();
                    return;
                }
                
                // Continue with next batch
                await runImportBatch();
                
            } else {
                throw new Error(response.data || 'Neznámá chyba');
            }
            
        } catch (error) {
            log.append(`❌ Chyba: ${error.message}\n`);
            btn.prop('disabled', false).text('Chyba - zkus znovu');
            throw error;
        }
    }

    /**
     * Load import statistics
     */
    async function loadImportStats() {
        const statsElement = $('#import-stats');
        
        try {
            const response = await $.post(ajaxurl, {
                action: 'poi_stats',
                _ajax_nonce: poiImporterAjax.nonce
            });
            
            if (response.success) {
                statsElement.html(response.data.html);
            } else {
                statsElement.html('<strong>Chyba načítání statistik:</strong> ' + response.data);
            }
        } catch (error) {
            statsElement.html('<strong>Chyba načítání statistik:</strong> ' + error.message);
        }
    }

    /**
     * Utility function to format numbers
     */
    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, " ");
    }

    /**
     * Show success message
     */
    function showSuccess(message) {
        const notice = $(`<div class="notice notice-success is-dismissible"><p>${message}</p></div>`);
        $('.wrap h1').after(notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            notice.fadeOut();
        }, 5000);
    }

    /**
     * Show error message
     */
    function showError(message) {
        const notice = $(`<div class="notice notice-error is-dismissible"><p>${message}</p></div>`);
        $('.wrap h1').after(notice);
        
        // Auto-dismiss after 10 seconds
        setTimeout(() => {
            notice.fadeOut();
        }, 10000);
    }

    /**
     * Show info message
     */
    function showInfo(message) {
        const notice = $(`<div class="notice notice-info is-dismissible"><p>${message}</p></div>`);
        $('.wrap h1').after(notice);
        
        // Auto-dismiss after 7 seconds
        setTimeout(() => {
            notice.fadeOut();
        }, 7000);
    }

    // Check for URL parameters to show messages
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('success')) {
        const success = urlParams.get('success');
        switch (success) {
            case 'upload':
                showSuccess('CSV soubor byl úspěšně nahrán. Nyní můžete spustit import.');
                break;
        }
    }
    
    if (urlParams.has('error')) {
        const error = urlParams.get('error');
        switch (error) {
            case 'upload':
                showError('Chyba při nahrávání souboru. Zkuste to znovu.');
                break;
            case 'type':
                showError('Nesprávný typ souboru. Použijte pouze CSV soubory.');
                break;
            case 'move':
                showError('Chyba při ukládání souboru. Zkontrolujte oprávnění složky.');
                break;
        }
    }
});

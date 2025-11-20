/**
 * JavaScript pro pokročilé admin rozhraní POI
 * @package DobityBaterky
 */

jQuery(document).ready(function($) {
    'use strict';

    // Globální proměnné
    let selectedPoiIds = [];
    let currentFilters = {};

    // Inicializace
    init();

    function init() {
        bindEvents();
        setupAjaxDefaults();
    }

    function bindEvents() {
        // Filtry
        $('#load-poi-btn').on('click', loadPoiByFilters);
        
        // Batch operace
        $('#select-all-poi').on('change', toggleSelectAll);
        $('#db-batch-edit-form').on('submit', handleBatchUpdate);
        $('#bulk-delete-btn').on('click', handleBulkDelete);
        
        // Import/Export
        $('#export-csv-btn').on('click', handleExportCsv);
        $('#db-import-form').on('submit', handleImportCsv);
        
        // Logování - kopírování a mazání
        $('#db-copy-log-btn').on('click', function() {
            const logTextarea = $('#db-import-log')[0];
            logTextarea.select();
            document.execCommand('copy');
            alert('Logy zkopírovány do schránky');
        });
        
        $('#db-clear-log-btn').on('click', function() {
            if (confirm('Opravdu chcete vymazat logy?')) {
                $('#db-import-log').val('');
            }
        });
        
        // Aktualizace ikon
        $('.update-type-icon').on('click', updateTypeIcon);
        $('#update-all-icons-btn').on('click', updateAllIcons);
    }
    
    // Helper funkce pro logování
    function addLog(message, type = 'info') {
        const logSection = $('#db-import-log-section');
        const logTextarea = $('#db-import-log');
        const timestamp = new Date().toLocaleTimeString('cs-CZ');
        const prefix = type === 'error' ? '❌' : type === 'success' ? '✅' : type === 'warning' ? '⚠️' : 'ℹ️';
        const logLine = `[${timestamp}] ${prefix} ${message}\n`;
        logTextarea.val(logTextarea.val() + logLine);
        logSection.show();
        // Auto-scroll na konec
        logTextarea[0].scrollTop = logTextarea[0].scrollHeight;
    }

    function setupAjaxDefaults() {
        $.ajaxSetup({
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', dbPoiAdmin.nonce);
            }
        });
    }

    // Načtení POI podle filtrů
    function loadPoiByFilters() {
        const filters = {
            poi_type: $('#poi_type_filter').val(),
            coords_status: $('#coords_status_filter').val(),
            limit: $('#limit_filter').val()
        };

        currentFilters = filters;

        // Zobrazit loading
        $('#load-poi-btn').prop('disabled', true).text('Načítám...');

        $.ajax({
            url: dbPoiAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_load_poi_by_filters',
                filters: filters,
                nonce: dbPoiAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayPoiList(response.data.poi);
                    $('#db-poi-list').show();
                    $('#db-batch-edit').show();
                } else {
                    alert('Chyba: ' + response.data);
                }
            },
            error: function() {
                alert('Chyba při načítání POI');
            },
            complete: function() {
                $('#load-poi-btn').prop('disabled', false).text('Načíst POI podle filtrů');
            }
        });
    }

    // Zobrazení seznamu POI
    function displayPoiList(poiList) {
        const tbody = $('#db-poi-table-body');
        tbody.empty();
        selectedPoiIds = [];

        poiList.forEach(function(poi) {
            const row = createPoiRow(poi);
            tbody.append(row);
        });

        updateSelectAllState();
    }

    // Vytvoření řádku POI
    function createPoiRow(poi) {
        const coords = poi.lat && poi.lng ? `${poi.lat}, ${poi.lng}` : 'Chybí';
        const icon = poi.icon || 'Nastavena';
        const type = poi.type || 'Neznámý';
        const recommended = poi.db_recommended ? '✓' : '—';

        return `
            <tr data-poi-id="${poi.id}">
                <td>
                    <input type="checkbox" class="poi-checkbox" value="${poi.id}">
                </td>
                <td>
                    <strong>${poi.title}</strong>
                    <div class="row-actions">
                        <span class="edit">
                            <a href="post.php?post=${poi.id}&action=edit">Upravit</a> |
                        </span>
                        <span class="view">
                            <a href="${poi.url}" target="_blank">Zobrazit</a>
                        </span>
                    </div>
                </td>
                <td>${type}</td>
                <td data-col="recommended">${recommended}</td>
                <td>${coords} ${recommended === '✓' ? '<span class="db-recommended">✓</span>' : ''}</td>
                <td>${icon}</td>
                <td>
                    <button type="button" class="button button-small edit-poi" data-poi-id="${poi.id}">
                        Rychlá úprava
                    </button>
                </td>
            </tr>
        `;
    }

    // Výběr všech POI
    function toggleSelectAll() {
        const isChecked = $('#select-all-poi').is(':checked');
        $('.poi-checkbox').prop('checked', isChecked);
        
        if (isChecked) {
            selectedPoiIds = $('.poi-checkbox').map(function() {
                return $(this).val();
            }).get();
        } else {
            selectedPoiIds = [];
        }
        
        updateBatchEditState();
    }

    // Aktualizace stavu select all
    function updateSelectAllState() {
        const totalCheckboxes = $('.poi-checkbox').length;
        const checkedCheckboxes = $('.poi-checkbox:checked').length;
        
        if (checkedCheckboxes === 0) {
            $('#select-all-poi').prop('indeterminate', false).prop('checked', false);
        } else if (checkedCheckboxes === totalCheckboxes) {
            $('#select-all-poi').prop('indeterminate', false).prop('checked', true);
        } else {
            $('#select-all-poi').prop('indeterminate', true);
        }
    }

    // Aktualizace stavu batch edit
    function updateBatchEditState() {
        const hasSelection = selectedPoiIds.length > 0;
        $('#db-batch-edit-form button[type="submit"]').prop('disabled', !hasSelection);
        $('#bulk-delete-btn').prop('disabled', !hasSelection);
    }

    // Batch update
    function handleBatchUpdate(e) {
        e.preventDefault();

        if (selectedPoiIds.length === 0) {
            alert(dbPoiAdmin.strings.selectItems);
            return;
        }

        if (!confirm(dbPoiAdmin.strings.confirmUpdate)) {
            return;
        }

        const updates = {
            poi_type: $('select[name="batch_poi_type"]').val(),
            icon: $('input[name="batch_icon"]').val(),
            color: $('input[name="batch_color"]').val(),
            recommended: $('select[name="batch_recommended"]').val()
        };

        // Skrýt prázdné hodnoty, ale zachovat '0'
        Object.keys(updates).forEach(key => {
            const v = updates[key];
            if (v === '' || v === null || typeof v === 'undefined') {
                delete updates[key];
            }
        });

        if (Object.keys(updates).length === 0) {
            alert('Vyberte alespoň jedno pole k aktualizaci');
            return;
        }

        // Zobrazit loading
        const submitBtn = $(e.target).find('button[type="submit"]');
        const originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text(dbPoiAdmin.strings.updating);

        $.ajax({
            url: dbPoiAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_batch_update_poi',
                poi_ids: selectedPoiIds.join(','),
                updates: updates,
                nonce: dbPoiAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    if (response.data.errors && response.data.errors.length > 0) {
                        console.warn('Chyby při aktualizaci:', response.data.errors);
                    }
                    // Znovu načíst POI
                    loadPoiByFilters();
                } else {
                    alert('Chyba: ' + response.data);
                }
            },
            error: function() {
                alert('Chyba při aktualizaci POI');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(originalText);
            }
        });
    }

    // Bulk delete
    function handleBulkDelete() {
        if (selectedPoiIds.length === 0) {
            alert(dbPoiAdmin.strings.selectItems);
            return;
        }

        if (!confirm(dbPoiAdmin.strings.confirmDelete)) {
            return;
        }

        // Zobrazit loading
        const deleteBtn = $('#bulk-delete-btn');
        const originalText = deleteBtn.text();
        deleteBtn.prop('disabled', true).text(dbPoiAdmin.strings.deleting);

        $.ajax({
            url: dbPoiAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_bulk_delete_poi',
                poi_ids: selectedPoiIds.join(','),
                nonce: dbPoiAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    // Znovu načíst POI
                    loadPoiByFilters();
                } else {
                    alert('Chyba: ' + response.data);
                }
            },
            error: function() {
                alert('Chyba při mazání POI');
            },
            complete: function() {
                deleteBtn.prop('disabled', false).text(originalText);
            }
        });
    }

    // Export CSV
    function handleExportCsv() {
        const filters = currentFilters;
        
        // Vytvořit dočasný formulář pro download
        const form = $('<form>', {
            method: 'POST',
            action: dbPoiAdmin.ajaxUrl,
            target: '_blank'
        });

        form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'db_export_poi_csv'
        }));

        form.append($('<input>', {
            type: 'hidden',
            name: 'filters',
            value: JSON.stringify(filters)
        }));

        form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: dbPoiAdmin.nonce
        }));

        $('body').append(form);
        form.submit();
        form.remove();
    }

    // Import CSV
    function handleImportCsv(e) {
        e.preventDefault();

        // Vymazat předchozí logy
        $('#db-import-log').val('');
        $('#db-import-log-section').show();
        addLog('Začíná import CSV souboru...', 'info');

        const formData = new FormData(e.target);
        try {
            const fileInput = $(e.target).find('input[type="file"][name="poi_csv"]')[0];
            if (fileInput && fileInput.files && fileInput.files[0]) {
                const f = fileInput.files[0];
                const fileSizeMB = (f.size / 1024 / 1024).toFixed(2);
                addLog(`Soubor: ${f.name}, velikost: ${fileSizeMB} MB (${(f.size / 1024).toFixed(2)} KB), typ: ${f.type}`, 'info');
                
                // Varování pro velké soubory
                if (f.size > 1024 * 1024) { // Více než 1 MB
                    addLog('⚠️ POZOR: Soubor je větší než 1 MB. Import může trvat dlouho a může dojít k timeoutu.', 'warning');
                    addLog('💡 Pro velké soubory doporučujeme použít CLI import: wp db-poi import-csv <cesta_k_souboru>', 'info');
                    if (!confirm('Soubor je větší než 1 MB. Import může trvat dlouho a může dojít k timeoutu.\n\nPro velké soubory doporučujeme použít CLI import.\n\nChcete pokračovat s AJAX importem?')) {
                        submitBtn.prop('disabled', false).text(originalText);
                        return;
                    }
                }
            } else {
                addLog('Chyba: Nenašel jsem soubor ve vstupu', 'error');
                return;
            }
        } catch (ex) {
            addLog(`Chyba při čtení souboru: ${ex.message}`, 'error');
            return;
        }
        formData.append('action', 'db_import_poi_csv');
        formData.append('nonce', dbPoiAdmin.nonce);

        // Zobrazit loading
        const submitBtn = $(e.target).find('button[type="submit"]');
        const originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text('Importuji...');
        addLog('Odesílám požadavek na server...', 'info');

        $.ajax({
            url: dbPoiAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 600000, // 10 minut timeout
            success: function(response) {
                if (response.success) {
                    addLog('✅ Import úspěšně dokončen!', 'success');
                    addLog(`Importováno: ${response.data.imported || 0} nových POI`, 'success');
                    addLog(`Aktualizováno: ${response.data.updated || 0} existujících POI`, 'success');
                    addLog(`Celkem řádků: ${response.data.total_rows || 0}`, 'info');
                    addLog(`Přeskočeno prázdných: ${response.data.skipped_rows || 0}`, 'info');
                    
                    if (response.data.processed_poi_ids && response.data.processed_poi_ids.length > 0) {
                        addLog(`Zařazeno ${response.data.processed_poi_ids.length} POI do fronty pro nearby recompute`, 'info');
                    }
                    
                    if (response.data.errors && response.data.errors.length > 0) {
                        addLog(`\n⚠️ Nalezeno ${response.data.errors.length} chyb:`, 'warning');
                        response.data.errors.forEach(function(error, index) {
                            addLog(`  ${index + 1}. ${error}`, 'error');
                        });
                    }
                    
                    addLog('\n' + response.data.message, 'success');
                    // Znovu načíst POI
                    loadPoiByFilters();
                } else {
                    addLog(`❌ Chyba: ${response.data}`, 'error');
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = 'Chyba při importu CSV';
                if (status === 'timeout' || xhr.status === 504) {
                    errorMsg = '❌ Gateway Timeout (504): Import trval příliš dlouho a server ho přerušil.';
                    addLog(errorMsg, 'error');
                    addLog('', 'info');
                    addLog('💡 ŘEŠENÍ:', 'warning');
                    addLog('1. Pro velké soubory použijte CLI import:', 'info');
                    addLog('   wp db-poi import-csv /cesta/k/souboru.csv', 'info');
                    addLog('', 'info');
                    addLog('2. Nebo zkuste rozdělit CSV soubor na menší části (např. po 1000 řádcích)', 'info');
                    addLog('', 'info');
                    addLog('3. Zkontrolujte PHP logy na serveru pro více informací', 'info');
                    addLog('   (Možná se import stále zpracovává na pozadí)', 'info');
                } else if (xhr.status === 0) {
                    errorMsg = '❌ Přerušení spojení: Možná došlo k timeoutu nebo přerušení spojení.';
                    addLog(errorMsg, 'error');
                    addLog('Zkontrolujte logy na serveru pro více informací.', 'warning');
                } else if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMsg = xhr.responseJSON.data;
                    addLog(`❌ ${errorMsg}`, 'error');
                } else if (error) {
                    errorMsg = error;
                    addLog(`❌ ${errorMsg}`, 'error');
                } else {
                    addLog(`❌ ${errorMsg} (HTTP ${xhr.status})`, 'error');
                }
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(originalText);
                // Vyčistit formulář
                e.target.reset();
                addLog('Import dokončen.', 'info');
            }
        });
    }

    // Aktualizace ikony typu
    function updateTypeIcon() {
        const typeId = $(this).data('type');
        const iconInput = $(this).siblings('input');
        const icon = iconInput.val();

        if (!icon) {
            alert('Zadejte název ikony');
            return;
        }

        // Zobrazit loading
        const btn = $(this);
        const originalText = btn.text();
        btn.prop('disabled', true).text('Aktualizuji...');

        $.ajax({
            url: dbPoiAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_update_poi_type_icon',
                type_id: typeId,
                icon: icon,
                nonce: dbPoiAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Ikona typu aktualizována');
                } else {
                    alert('Chyba: ' + response.data);
                }
            },
            error: function() {
                alert('Chyba při aktualizaci ikony typu');
            },
            complete: function() {
                btn.prop('disabled', false).text(originalText);
            }
        });
    }

    // Aktualizace všech ikon
    function updateAllIcons() {
        if (!confirm('Opravdu chcete aktualizovat ikony pro všechny POI? Tato operace může trvat delší dobu.')) {
            return;
        }

        // Zobrazit loading
        const btn = $('#update-all-icons-btn');
        const originalText = btn.text();
        btn.prop('disabled', true).text('Aktualizuji všechny ikony...');

        $.ajax({
            url: dbPoiAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_update_all_poi_icons',
                nonce: dbPoiAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    // Znovu načíst POI
                    loadPoiByFilters();
                } else {
                    alert('Chyba: ' + response.data);
                }
            },
            error: function() {
                alert('Chyba při aktualizaci ikon');
            },
            complete: function() {
                btn.prop('disabled', false).text(originalText);
            }
        });
    }

    // Event handler pro checkboxy
    $(document).on('change', '.poi-checkbox', function() {
        const poiId = $(this).val();
        
        if ($(this).is(':checked')) {
            if (selectedPoiIds.indexOf(poiId) === -1) {
                selectedPoiIds.push(poiId);
            }
        } else {
            selectedPoiIds = selectedPoiIds.filter(id => id !== poiId);
        }
        
        updateSelectAllState();
        updateBatchEditState();
    });

    // Event handler pro rychlou úpravu
    $(document).on('click', '.edit-poi', function() {
        const poiId = $(this).data('poi-id');
        // Otevřít editaci v novém okně
        window.open(`post.php?post=${poiId}&action=edit`, '_blank');
    });
});

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

    // Parsovat CSV řádky s respektováním quotes a embedded newlines
    function parseCSVRows(csvText) {
        const rows = [];
        let currentRow = '';
        let inQuotes = false;
        let i = 0;
        
        while (i < csvText.length) {
            const char = csvText[i];
            const nextChar = i + 1 < csvText.length ? csvText[i + 1] : '';
            
            if (char === '"') {
                if (inQuotes && nextChar === '"') {
                    // Escaped quote ("") - zachovat jako "" (neupravovat obsah CSV)
                    currentRow += '""';
                    i += 2;
                } else {
                    // Toggle quote state
                    inQuotes = !inQuotes;
                    currentRow += char;
                    i++;
                }
            } else if (char === '\n' && !inQuotes) {
                // End of row (only if not in quotes)
                rows.push(currentRow);
                currentRow = '';
                i++;
            } else if (char === '\r' && nextChar === '\n' && !inQuotes) {
                // Windows line ending (\r\n)
                rows.push(currentRow);
                currentRow = '';
                i += 2;
            } else if (char === '\r' && !inQuotes) {
                // CR-only line ending (Mac classic)
                rows.push(currentRow);
                currentRow = '';
                i++;
            } else {
                currentRow += char;
                i++;
            }
        }
        
        // Přidat poslední řádek (pokud není prázdný)
        if (currentRow.length > 0 || csvText.endsWith('\n')) {
            rows.push(currentRow);
        }
        
        return rows;
    }

    // Import CSV s chunked processing
    function handleImportCsv(e) {
        e.preventDefault();

        // Vymazat předchozí logy
        $('#db-import-log').val('');
        $('#db-import-log-section').show();
        $('#db-import-progress-container').hide();
        addLog('Začíná import CSV souboru...', 'info');

        // Získat form element (e.currentTarget je form, na který je handler připojen)
        const formElement = e.currentTarget || $(e.target).closest('form')[0] || $('#db-import-form')[0];
        const $form = $(formElement);
        
        const submitBtn = $form.find('button[type="submit"]');
        const originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text('Připravuji...');

        const fileInput = $form.find('input[type="file"][name="poi_csv"]')[0];
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            addLog('Chyba: Nenašel jsem soubor ve vstupu', 'error');
            submitBtn.prop('disabled', false).text(originalText);
            return;
        }

        const file = fileInput.files[0];
        const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
        addLog(`Soubor: ${file.name}, velikost: ${fileSizeMB} MB`, 'info');

        // Načíst soubor jako text
        const reader = new FileReader();
        reader.onload = function(e) {
            const csvText = e.target.result;
            // Použít správný CSV parser, který respektuje quotes
            const lines = parseCSVRows(csvText);
            
            // Kontrola, že CSV není prázdný (musí mít alespoň hlavičku a jeden řádek dat)
            if (lines.length < 2) {
                addLog('❌ Chyba: CSV soubor je prázdný nebo obsahuje pouze hlavičku bez dat', 'error');
                submitBtn.prop('disabled', false).text(originalText);
                return;
            }
            
            const header = lines[0]; // První řádek je hlavička
            
            // Rozdělit na chunky (po 500 řádcích - optimalizováno pro rychlé zpracování)
            const CHUNK_SIZE = 500;
            const chunks = [];
            let currentChunk = [header]; // První chunk obsahuje hlavičku
            
            for (let i = 1; i < lines.length; i++) {
                currentChunk.push(lines[i]);
                if (currentChunk.length > CHUNK_SIZE) {
                    chunks.push(currentChunk.join('\n'));
                    currentChunk = []; // Další chunky bez hlavičky
                }
            }
            
            // Přidat poslední chunk
            if (currentChunk.length > 0) {
                chunks.push(currentChunk.join('\n'));
            }

            const totalChunks = chunks.length;
            addLog(`Soubor rozdělen na ${totalChunks} balíčků (po ${CHUNK_SIZE} řádcích)`, 'info');
            addLog(`Celkem řádků: ${lines.length - 1}`, 'info');
            
            // Debug: zkontrolovat první chunk
            if (chunks.length > 0) {
                const firstChunkPreview = chunks[0].substring(0, 100);
                addLog(`První chunk preview: ${firstChunkPreview}...`, 'info');
            }

            // Zobrazit progress bar
            $('#db-import-progress-container').show();
            updateProgress(0, totalChunks, 0);

            // Spustit chunked import (retryCount = 0 pro první pokus)
            processChunks(chunks, 0, totalChunks, submitBtn, originalText, formElement, 0);
        };

        reader.onerror = function() {
            addLog('❌ Chyba při čtení souboru', 'error');
            submitBtn.prop('disabled', false).text(originalText);
        };

        reader.readAsText(file);
    }

    // Zpracovat chunky postupně
    function processChunks(chunks, currentIndex, totalChunks, submitBtn, originalText, form, retryCount) {
        // Pomocná funkce pro reset formu
        function resetForm() {
            if (form && typeof form.reset === 'function') {
                form.reset();
            } else if (form) {
                const formEl = form[0] || form;
                if (formEl && typeof formEl.reset === 'function') {
                    formEl.reset();
                }
            }
        }
        
        // Inicializovat retry count pokud není nastaven
        if (typeof retryCount === 'undefined') {
            retryCount = 0;
        }
        
        // Maximální počet retry pokusů
        const MAX_RETRIES = 3;
        
        if (currentIndex >= chunks.length) {
            // Hotovo
            submitBtn.prop('disabled', false).text(originalText);
            resetForm();
            return;
        }

        const chunk = chunks[currentIndex];
        const isFirst = currentIndex === 0;
        const isLast = currentIndex === chunks.length - 1;

        submitBtn.text(`Importuji balíček ${currentIndex + 1}/${totalChunks}...`);

        const startTime = Date.now();
        updateProgress(currentIndex, totalChunks, 0);

        $.ajax({
            url: dbPoiAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_import_poi_csv_chunk',
                nonce: dbPoiAdmin.nonce,
                chunk_data: chunk,
                chunk_index: currentIndex,
                total_chunks: totalChunks,
                is_first: isFirst ? '1' : '0',
                is_last: isLast ? '1' : '0'
            },
            timeout: 180000, // 3 minuty na chunk (zvýšeno kvůli velkým souborům)
            success: function(response) {
                if (response.success && response.data) {
                    const elapsed = (Date.now() - startTime) / 1000;
                    const chunkResult = response.data.chunk_result || {};
                    const totalStats = response.data.total_stats || {};

                    // Aktualizovat progress
                    const progress = response.data.progress || ((currentIndex + 1) / totalChunks * 100);
                    updateProgress(currentIndex + 1, totalChunks, elapsed);

                    // Logovat výsledek chunku
                    addLog(`✅ Balíček ${currentIndex + 1}/${totalChunks} dokončen (${elapsed.toFixed(1)}s)`, 'success');
                    addLog(`   - Nové: ${chunkResult.imported || 0}, Aktualizované: ${chunkResult.updated || 0}, Řádky: ${chunkResult.total_rows || 0}`, 'info');

                    if (isLast) {
                        // Finální výsledek
                        addLog('', 'info');
                        addLog('🎉 Import úspěšně dokončen!', 'success');
                        addLog(`Celkem importováno: ${totalStats.imported || 0} nových POI`, 'success');
                        addLog(`Celkem aktualizováno: ${totalStats.updated || 0} existujících POI`, 'success');
                        addLog(`Celkem řádků: ${totalStats.total_rows || 0}`, 'info');
                        addLog(`Přeskočeno prázdných: ${totalStats.skipped_rows || 0}`, 'info');
                        
                        if (response.data.enqueued_count > 0) {
                            addLog(`Zařazeno ${response.data.enqueued_count} POI do fronty pro nearby recompute`, 'info');
                            addLog(`Aktualizováno ${response.data.affected_count} charging locations v okolí`, 'info');
                        }
                        
                        if (totalStats.errors && totalStats.errors.length > 0) {
                            addLog(`\n⚠️ Nalezeno ${totalStats.errors.length} chyb:`, 'warning');
                            totalStats.errors.slice(0, 20).forEach(function(error, index) {
                                addLog(`  ${index + 1}. ${error}`, 'error');
                            });
                            if (totalStats.errors.length > 20) {
                                addLog(`  ... a dalších ${totalStats.errors.length - 20} chyb`, 'error');
                            }
                        }

                        updateProgress(totalChunks, totalChunks, 0);
                        submitBtn.prop('disabled', false).text(originalText);
                        resetForm();
                        loadPoiByFilters();
                    } else {
                        // Pokračovat s dalším chunkem (reset retry count)
                        setTimeout(function() {
                            processChunks(chunks, currentIndex + 1, totalChunks, submitBtn, originalText, form, 0);
                        }, 100); // Malá pauza mezi chunky
                    }
                } else {
                    const errorMsg = response.data || 'Neznámá chyba';
                    addLog(`❌ Chyba v balíčku ${currentIndex + 1}: ${errorMsg}`, 'error');
                    submitBtn.prop('disabled', false).text(originalText);
                    resetForm();
                }
            },
            error: function(xhr, status, error) {
                let errorMsg = `Chyba při zpracování balíčku ${currentIndex + 1}`;
                if (status === 'timeout' || xhr.status === 504) {
                    errorMsg = `❌ Timeout při zpracování balíčku ${currentIndex + 1}`;
                    
                    // Zkontrolovat, zda jsme dosáhli maximálního počtu retry pokusů
                    if (retryCount >= MAX_RETRIES) {
                        addLog(`${errorMsg}`, 'error');
                        addLog(`❌ Balíček ${currentIndex + 1} selhal po ${MAX_RETRIES} pokusech. Import přerušen.`, 'error');
                        addLog('💡 Zkuste rozdělit CSV soubor na menší části nebo použijte CLI import.', 'info');
                        submitBtn.prop('disabled', false).text(originalText);
                        resetForm();
                        return;
                    }
                    
                    addLog(`${errorMsg}`, 'error');
                    addLog(`Zkouším znovu za 2 sekundy... (pokus ${retryCount + 1}/${MAX_RETRIES})`, 'warning');
                    
                    // Retry mechanismus pro timeouty - zkusit znovu po 2 sekundách
                    setTimeout(function() {
                        addLog(`Opakuji balíček ${currentIndex + 1}...`, 'info');
                        processChunks(chunks, currentIndex, totalChunks, submitBtn, originalText, form, retryCount + 1);
                    }, 2000);
                    return; // Neukončit import, ale zkusit znovu
                } else if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMsg = xhr.responseJSON.data;
                }
                addLog(`${errorMsg}`, 'error');
                submitBtn.prop('disabled', false).text(originalText);
                // Bezpečné resetování formu
                try {
                    resetForm();
                } catch (e) {
                    // Pokud resetForm() není dostupná, zkusit přímo
                    if (form && typeof form.reset === 'function') {
                        form.reset();
                    } else if (form) {
                        const formEl = form[0] || form;
                        if (formEl && typeof formEl.reset === 'function') {
                            formEl.reset();
                        }
                    }
                }
            }
        });
    }

    // Aktualizovat progress bar
    function updateProgress(current, total, elapsedTime) {
        const percent = Math.round((current / total) * 100);
        $('#db-import-progress-bar').css('width', percent + '%');
        $('#db-import-progress-percent').text(percent + '%');
        $('#db-import-progress-text').text(`Zpracováno ${current} z ${total} balíčků`);

        // Odhad zbývajícího času
        if (current > 0 && elapsedTime > 0) {
            const avgTimePerChunk = elapsedTime / current;
            const remainingChunks = total - current;
            const estimatedSeconds = Math.round(avgTimePerChunk * remainingChunks);
            const minutes = Math.floor(estimatedSeconds / 60);
            const seconds = estimatedSeconds % 60;
            let timeText = '';
            if (minutes > 0) {
                timeText = `Přibližně ${minutes} min ${seconds} s`;
            } else {
                timeText = `Přibližně ${seconds} s`;
            }
            $('#db-import-time-estimate').text(`Zbývá: ${timeText}`);
        } else {
            $('#db-import-time-estimate').text('');
        }
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

/**
 * JavaScript pro pokročilé admin rozhraní nabíjecích lokalit
 * @package DobityBaterky
 */

jQuery(document).ready(function($) {
    'use strict';

    // Globální proměnné
    let selectedChargingIds = [];
    let currentFilters = {};

    // Inicializace
    init();

    function init() {
        bindEvents();
        setupAjaxDefaults();
    }

    function bindEvents() {
        // Filtry
        $('#load-charging-btn').on('click', loadChargingByFilters);
        
        // Batch operace
        $('#select-all-charging').on('change', toggleSelectAll);
        $('#db-batch-edit-form').on('submit', handleBatchUpdate);
        $('#bulk-delete-btn').on('click', handleBulkDelete);
        
        // Import/Export
        $('#export-csv-btn').on('click', handleExportCsv);
        $('#db-import-form').on('submit', handleImportCsv);
        
        // Aktualizace ikon
        $('.update-type-icon').on('click', updateTypeIcon);
        $('#update-all-icons-btn').on('click', updateAllIcons);
        
        // Rychlé akce
        $('#fix-missing-coords').on('click', fixMissingCoords);
        $('#normalize-power-values').on('click', normalizePowerValues);
        $('#update-ocm-data').on('click', updateOcmData);
        $('#cleanup-duplicates').on('click', cleanupDuplicates);
    }

    function setupAjaxDefaults() {
        $.ajaxSetup({
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', dbChargingAdmin.nonce);
            }
        });
    }

    // Načtení nabíjecích lokalit podle filtrů
    function loadChargingByFilters() {
        const filters = {
            charger_type: $('#charger_type_filter').val(),
            provider: $('#provider_filter').val(),
            coords_status: $('#coords_status_filter').val(),
            icon_status: $('#icon_status_filter').val(),
            limit: $('#limit_filter').val()
        };

        currentFilters = filters;

        // Zobrazit loading
        $('#load-charging-btn').prop('disabled', true).text('Načítám...');

        $.ajax({
            url: dbChargingAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_load_charging_by_filters',
                filters: filters,
                nonce: dbChargingAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayChargingList(response.data.charging);
                    $('#db-charging-list').show();
                    $('#db-batch-edit').show();
                } else {
                    alert('Chyba: ' + response.data);
                }
            },
            error: function() {
                alert('Chyba při načítání nabíjecích lokalit');
            },
            complete: function() {
                $('#load-charging-btn').prop('disabled', false).text('Načíst nabíjecí lokality podle filtrů');
            }
        });
    }

    // Zobrazení seznamu nabíjecích lokalit
    function displayChargingList(chargingList) {
        const tbody = $('#db-charging-table-body');
        tbody.empty();
        selectedChargingIds = [];

        chargingList.forEach(function(charging) {
            const row = createChargingRow(charging);
            tbody.append(row);
        });

        updateSelectAllState();
    }

    // Vytvoření řádku nabíjecí lokality
    function createChargingRow(charging) {
        const coords = charging.lat && charging.lng ? `${charging.lat}, ${charging.lng}` : 'Chybí';
        const icon = charging.icon || 'Chybí';
        const chargerType = charging.charger_type || 'Neznámý';
        const provider = charging.provider || 'Neznámý';
        const power = charging.power ? `${charging.power} kW` : 'Neznámý';

        return `
            <tr data-charging-id="${charging.id}">
                <td>
                    <input type="checkbox" class="charging-checkbox" value="${charging.id}">
                </td>
                <td>
                    <strong>${charging.title}</strong>
                    <div class="row-actions">
                        <span class="edit">
                            <a href="post.php?post=${charging.id}&action=edit">Upravit</a> |
                        </span>
                        <span class="view">
                            <a href="${charging.url}" target="_blank">Zobrazit</a>
                        </span>
                    </div>
                </td>
                <td>${chargerType}</td>
                <td>${provider}</td>
                <td>${coords}</td>
                <td>${icon}</td>
                <td>${power}</td>
                <td>
                    <button type="button" class="button button-small edit-charging" data-charging-id="${charging.id}">
                        Rychlá úprava
                    </button>
                </td>
            </tr>
        `;
    }

    // Výběr všech nabíjecích lokalit
    function toggleSelectAll() {
        const isChecked = $('#select-all-charging').is(':checked');
        $('.charging-checkbox').prop('checked', isChecked);
        
        if (isChecked) {
            selectedChargingIds = $('.charging-checkbox').map(function() {
                return $(this).val();
            }).get();
        } else {
            selectedChargingIds = [];
        }
        
        updateBatchEditState();
    }

    // Aktualizace stavu select all
    function updateSelectAllState() {
        const totalCheckboxes = $('.charging-checkbox').length;
        const checkedCheckboxes = $('.charging-checkbox:checked').length;
        
        if (checkedCheckboxes === 0) {
            $('#select-all-charging').prop('indeterminate', false).prop('checked', false);
        } else if (checkedCheckboxes === totalCheckboxes) {
            $('#select-all-charging').prop('indeterminate', false).prop('checked', true);
        } else {
            $('#select-all-charging').prop('indeterminate', true);
        }
    }

    // Aktualizace stavu batch edit
    function updateBatchEditState() {
        const hasSelection = selectedChargingIds.length > 0;
        $('#db-batch-edit-form button[type="submit"]').prop('disabled', !hasSelection);
        $('#bulk-delete-btn').prop('disabled', !hasSelection);
    }

    // Batch update
    function handleBatchUpdate(e) {
        e.preventDefault();

        if (selectedChargingIds.length === 0) {
            alert(dbChargingAdmin.strings.selectItems);
            return;
        }

        if (!confirm(dbChargingAdmin.strings.confirmUpdate)) {
            return;
        }

        const updates = {
            charger_type: $('select[name="batch_charger_type"]').val(),
            provider: $('select[name="batch_provider"]').val(),
            icon: $('input[name="batch_icon"]').val(),
            power: $('input[name="batch_power"]').val(),
            status: $('select[name="batch_status"]').val(),
            availability: $('select[name="batch_availability"]').val(),
            price: $('input[name="batch_price"]').val()
        };

        // Skrýt prázdné hodnoty
        Object.keys(updates).forEach(key => {
            if (!updates[key]) {
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
        submitBtn.prop('disabled', true).text(dbChargingAdmin.strings.updating);

        $.ajax({
            url: dbChargingAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_batch_update_charging',
                charging_ids: selectedChargingIds.join(','),
                updates: updates,
                nonce: dbChargingAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    if (response.data.errors && response.data.errors.length > 0) {
                        console.warn('Chyby při aktualizaci:', response.data.errors);
                    }
                    // Znovu načíst nabíjecí lokality
                    loadChargingByFilters();
                } else {
                    alert('Chyba: ' + response.data);
                }
            },
            error: function() {
                alert('Chyba při aktualizaci nabíjecích lokalit');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(originalText);
            }
        });
    }

    // Bulk delete
    function handleBulkDelete() {
        if (selectedChargingIds.length === 0) {
            alert(dbChargingAdmin.strings.selectItems);
            return;
        }

        if (!confirm(dbChargingAdmin.strings.confirmDelete)) {
            return;
        }

        // Zobrazit loading
        const deleteBtn = $('#bulk-delete-btn');
        const originalText = deleteBtn.text();
        deleteBtn.prop('disabled', true).text(dbChargingAdmin.strings.deleting);

        $.ajax({
            url: dbChargingAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_bulk_delete_charging',
                charging_ids: selectedChargingIds.join(','),
                nonce: dbChargingAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    // Znovu načíst nabíjecí lokality
                    loadChargingByFilters();
                } else {
                    alert('Chyba: ' + response.data);
                }
            },
            error: function() {
                alert('Chyba při mazání nabíjecích lokalit');
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
            action: dbChargingAdmin.ajaxUrl,
            target: '_blank'
        });

        form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'db_export_charging_csv'
        }));

        form.append($('<input>', {
            type: 'hidden',
            name: 'filters',
            value: JSON.stringify(filters)
        }));

        form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: dbChargingAdmin.nonce
        }));

        $('body').append(form);
        form.submit();
        form.remove();
    }

    // Import CSV
    function handleImportCsv(e) {
        e.preventDefault();

        const formData = new FormData(e.target);
        formData.append('action', 'db_import_charging_csv');
        formData.append('nonce', dbChargingAdmin.nonce);

        // Zobrazit loading
        const submitBtn = $(e.target).find('button[type="submit"]');
        const originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text('Importuji...');

        $.ajax({
            url: dbChargingAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    if (response.data.errors && response.data.errors.length > 0) {
                        console.warn('Chyby při importu:', response.data.errors);
                    }
                    // Znovu načíst nabíjecí lokality
                    loadChargingByFilters();
                } else {
                    alert('Chyba: ' + response.data);
                }
            },
            error: function() {
                alert('Chyba při importu CSV');
            },
            complete: function() {
                submitBtn.prop('disabled', false).text(originalText);
                // Vyčistit formulář
                e.target.reset();
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
            url: dbChargingAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_update_charger_type_icon',
                type_id: typeId,
                icon: icon,
                nonce: dbChargingAdmin.nonce
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
        if (!confirm('Opravdu chcete aktualizovat ikony pro všechny nabíjecí lokality? Tato operace může trvat delší dobu.')) {
            return;
        }

        // Zobrazit loading
        const btn = $('#update-all-icons-btn');
        const originalText = btn.text();
        btn.prop('disabled', true).text('Aktualizuji všechny ikony...');

        $.ajax({
            url: dbChargingAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'db_update_all_charger_icons',
                nonce: dbChargingAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    // Znovu načíst nabíjecí lokality
                    loadChargingByFilters();
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

    // Rychlé akce
    function fixMissingCoords() {
        if (!confirm('Pokusit se opravit chybějící koordináty pomocí geocodingu?')) {
            return;
        }
        
        const btn = $('#fix-missing-coords');
        const originalText = btn.text();
        btn.prop('disabled', true).text('Opravuji koordináty...');
        
        // Implementace geocodingu by byla zde
        setTimeout(function() {
            alert('Funkce geocodingu bude implementována v další verzi');
            btn.prop('disabled', false).text(originalText);
        }, 1000);
    }

    function normalizePowerValues() {
        if (!confirm('Normalizovat hodnoty výkonu (převést na jednotky kW)?')) {
            return;
        }
        
        const btn = $('#normalize-power-values');
        const originalText = btn.text();
        btn.prop('disabled', true).text('Normalizuji výkon...');
        
        // Implementace normalizace by byla zde
        setTimeout(function() {
            alert('Funkce normalizace bude implementována v další verzi');
            btn.prop('disabled', false).text(originalText);
        }, 1000);
    }

    function updateOcmData() {
        if (!confirm('Aktualizovat data z OpenChargeMap? Tato operace může trvat delší dobu.')) {
            return;
        }
        
        const btn = $('#update-ocm-data');
        const originalText = btn.text();
        btn.prop('disabled', true).text('Aktualizuji OCM data...');
        
        // Implementace OCM update by byla zde
        setTimeout(function() {
            alert('Funkce OCM aktualizace bude implementována v další verzi');
            btn.prop('disabled', false).text(originalText);
        }, 1000);
    }

    function cleanupDuplicates() {
        if (!confirm('Vyčistit duplicitní nabíjecí lokality? POZOR: Tato akce je nevratná!')) {
            return;
        }
        
        const btn = $('#cleanup-duplicates');
        const originalText = btn.text();
        btn.prop('disabled', true).text('Čistím duplicity...');
        
        // Implementace cleanup by byla zde
        setTimeout(function() {
            alert('Funkce čištění duplicit bude implementována v další verzi');
            btn.prop('disabled', false).text(originalText);
        }, 1000);
    }

    // Event handler pro checkboxy
    $(document).on('change', '.charging-checkbox', function() {
        const chargingId = $(this).val();
        
        if ($(this).is(':checked')) {
            if (selectedChargingIds.indexOf(chargingId) === -1) {
                selectedChargingIds.push(chargingId);
            }
        } else {
            selectedChargingIds = selectedChargingIds.filter(id => id !== chargingId);
        }
        
        updateSelectAllState();
        updateBatchEditState();
    });

    // Event handler pro rychlou úpravu
    $(document).on('click', '.edit-charging', function() {
        const chargingId = $(this).data('charging-id');
        // Otevřít editaci v novém okně
        window.open(`post.php?post=${chargingId}&action=edit`, '_blank');
    });
});

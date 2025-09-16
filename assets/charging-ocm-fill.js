/**
 * Charging OCM Fill - Integrace s Gutenberg Block Editor
 * Automaticky vyplní název a featured image z OpenChargeMap API
 */

(function() {
    'use strict';

    /**
     * Zobrazí notifikaci uživateli
     */
    window.showNotification = function(message, type) {
        // Vytvořit notifikační element
        var notification = document.createElement('div');
        notification.className = 'db-notification db-notification-' + type;
        notification.style.cssText = `
            position: fixed;
            top: 32px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 4px;
            color: white;
            font-weight: 500;
            z-index: 999999;
            max-width: 400px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateX(100%);
            transition: transform 0.3s ease;
        `;
        
        // Nastavit barvu podle typu
        if (type === 'success') {
            notification.style.background = '#46b450';
            notification.style.borderLeft = '4px solid #389a3f';
        } else if (type === 'error') {
            notification.style.background = '#dc3232';
            notification.style.borderLeft = '4px solid #a00';
        } else if (type === 'warning') {
            notification.style.background = '#ffb900';
            notification.style.borderLeft = '4px solid #cc9200';
            notification.style.color = '#333';
        } else {
            notification.style.background = '#0073aa';
            notification.style.borderLeft = '4px solid #005a87';
        }
        
        notification.innerHTML = message;
        
        // Přidat do DOM
        document.body.appendChild(notification);
        
        // Zobrazit s animací
        setTimeout(function() {
            notification.style.transform = 'translateX(0)';
        }, 100);
        
        // Automaticky skrýt po 5 sekundách
        setTimeout(function() {
            notification.style.transform = 'translateX(100%)';
            setTimeout(function() {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, 5000);
        
        // Možnost zavřít kliknutím
        notification.addEventListener('click', function() {
            notification.style.transform = 'translateX(100%)';
            setTimeout(function() {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        });
    };

    /**
     * Přijal jsem data z vyhledávače v metaboxu
     */
    window.addEventListener('charging:stationSelected', async function(e) {
        const station = e.detail;
        if (!station) {
            console.warn('[CHARGING DEBUG] stationSelected event bez dat');
            return;
        }

        try {
            console.info('[DB][MPO][DEBUG] stationSelected payload:', station);
        } catch (err) {
            console.error('[CHARGING DEBUG] Chyba při logování payload:', err);
        }

        /* 1) Název - stačí jeden dispatch na core/editor */
        if (station.name) {
            try {
                wp.data.dispatch('core/editor').editPost({
                    title: station.name
                });
                console.log('[CHARGING DEBUG] Název nastaven:', station.name);
            } catch (error) {
                console.error('[CHARGING DEBUG] Chyba při nastavování názvu:', error);
            }
        }

        /* 2) Obrázek - uložit URL pro pozdější nahrání při uložení příspěvku */
        if (station.media && station.media.length > 0) {
            const media = station.media[0];
            if (media.url) {
                console.log('[CHARGING DEBUG] Obrázek bude nahrán při uložení příspěvku:', media.url);
                
                // Uložit URL obrázku do skrytého pole pro pozdější zpracování
                const imageUrlField = document.getElementById('_ocm_image_url');
                const imageCommentField = document.getElementById('_ocm_image_comment');
                
                if (imageUrlField) {
                    imageUrlField.value = media.url;
                } else {
                    console.warn('[CHARGING DEBUG] Pole _ocm_image_url nenalezeno');
                }
                
                if (imageCommentField) {
                    imageCommentField.value = media.comment || '';
                } else {
                    console.warn('[CHARGING DEBUG] Pole _ocm_image_comment nenalezeno');
                }
                
                // Zobrazit informaci uživateli
                console.log('[CHARGING DEBUG] Obrázek bude automaticky nahrán při uložení příspěvku');
            }
        }
        
        /* 3) Kontrola operátora pro poskytovatele */
        if (station.operator && station.operator.Title) {
            console.log('[CHARGING DEBUG] Operátor nalezen:', station.operator.Title);
        } else {
            console.warn('[CHARGING DEBUG] Operátor nenalezen v datech stanice');
        }
    });

    /**
     * Funkce pro vyvolání události při výběru stanice
     */
    window.chargingOcmFill = {
        selectStation: function(stationData) {
            const event = new CustomEvent('charging:stationSelected', {
                detail: stationData
            });
            window.dispatchEvent(event);
        }
    };

    // Debug: na edit screen vypiš klíčová meta pole
    document.addEventListener('DOMContentLoaded', function(){
        try {
            const postTypeInput = document.getElementById('post_type');
            if (postTypeInput && postTypeInput.value === 'charging_location') {
                const mpoUid = document.getElementById('_mpo_uniq_key');
                const lat = document.getElementById('_db_lat');
                const lng = document.getElementById('_db_lng');
                const total = document.getElementById('_db_total_stations');
                const opening = document.getElementById('_mpo_opening_hours');
                const source = document.querySelector('input[name="_data_source"]') || null;
                try {
                    const mpoConnectors = window.wp && wp.data ? null : null; // placeholder
                } catch (e) {}
                console.info('[DB][MPO][DEBUG] Meta:', {
                    mpo_uid: mpoUid ? mpoUid.value : null,
                    lat: lat ? lat.value : null,
                    lng: lng ? lng.value : null,
                    total_stations: total ? total.value : null,
                    opening_hours: opening ? opening.value : null,
                    data_source: source ? source.value : null
                });
                try {
                    const mpo = document.getElementById('_mpo_connectors_json');
                    if (mpo && mpo.value) {
                        console.info('[DB][MPO][DEBUG] MPO connectors (raw):', JSON.parse(mpo.value));
                    }
                } catch (e) {}
            }
        } catch (e) {
            console.warn('[DB][MPO][DEBUG] Meta debug error:', e);
        }
    });

})(); 
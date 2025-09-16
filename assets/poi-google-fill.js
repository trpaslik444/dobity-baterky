/**
 * POI Google Fill - Integrace s Gutenberg Block Editor
 * Automaticky vyplní název a featured image z Google Places API
 */

(function() {
    'use strict';

    /**
     * Přijal jsem data z vyhledávače v metaboxu
     */
    window.addEventListener('poi:placeSelected', async function(e) {
        const place = e.detail;
        if (!place) {
            return;
        }

        /* 1) Název - stačí jeden dispatch na core/editor */
        if (place.displayName && place.displayName.text) {
            try {
                wp.data.dispatch('core/editor').editPost({
                    title: place.displayName.text
                });
            } catch (error) {
                console.error('[POI DEBUG] Chyba při nastavování názvu:', error);
            }
        }

        /* 2) Obrázek - nejprve ho musíme nahrát jako attachment */
        if (place.photos && place.photos.length > 0) {
            const photo = place.photos[0];
            if (photo.photoReference) {
                try {
                    // Použít REST endpoint pro nahrání fotky
                    const response = await wp.apiFetch({
                        path: '/poi/v1/photo',
                        method: 'POST',
                        data: {
                            photo_reference: photo.photoReference
                        }
                    });

                    if (response && response.id) {
                        // Nastavit jako featured image
                        wp.data.dispatch('core/editor').editPost({
                            featured_media: response.id
                        });
                    }
                } catch (error) {
                    console.error('[POI DEBUG] Chyba při nahrávání fotky:', error);
                }
            }
        }
    });
})(); 
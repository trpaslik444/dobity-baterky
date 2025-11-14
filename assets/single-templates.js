/**
 * JavaScript funkcionalita pro single template šablony
 * Dobitý Baterky Plugin
 */

(function() {
    'use strict';

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Počkáme na načtení DOM
    document.addEventListener('DOMContentLoaded', function() {
        initNavigationDropdown();
        initMaps();
        initSmoothScrolling();
        initLazyLoading();
    });

    /**
     * Inicializace navigačního dropdown menu
     */
    function initNavigationDropdown() {
        const navBtn = document.getElementById('db-nav-btn');
        const navMenu = document.getElementById('db-nav-menu');
        
        if (!navBtn || !navMenu) return;

        navBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const isVisible = navMenu.style.display === 'block';
            navMenu.style.display = isVisible ? 'none' : 'block';
            
            // Zavření menu při kliknutí mimo
            const closeMenu = function(ev) {
                if (!navMenu.contains(ev.target) && ev.target !== navBtn) {
                    navMenu.style.display = 'none';
                    document.removeEventListener('click', closeMenu);
                }
            };
            
            setTimeout(function() {
                document.addEventListener('click', closeMenu);
            }, 0);
        });

        // Hover efekty pro položky menu
        const navItems = navMenu.querySelectorAll('.db-nav-item');
        navItems.forEach(function(item) {
            item.addEventListener('mouseenter', function() {
                this.style.background = '#f3f4f6';
            });
            item.addEventListener('mouseleave', function() {
                this.style.background = 'transparent';
            });
        });
    }

    /**
     * Inicializace map
     */
    function initMaps() {
        const detailMapContainer = document.getElementById('db-detail-map');
        if (detailMapContainer && typeof window.DBDetail === 'object') {
            initDetailMap(detailMapContainer, window.DBDetail);
        }

        const legacyMapContainer = document.getElementById('db-single-map');
        if (!legacyMapContainer) return;

        const lat = parseFloat(legacyMapContainer.dataset.lat);
        const lng = parseFloat(legacyMapContainer.dataset.lng);
        const title = legacyMapContainer.dataset.title || 'Lokalita';

        if (isNaN(lat) || isNaN(lng)) return;

        loadLeafletResources().then(function() {
            initStandardMap(legacyMapContainer, lat, lng, title);
        }).catch(function(error) {
            console.error('Chyba při načítání mapy:', error);
            showMapError(legacyMapContainer);
        });
    }

    /**
     * Načtení Leaflet knihovny
     */
    function loadLeafletResources() {
        return new Promise(function(resolve, reject) {
            // Kontrola, zda je Leaflet již načten
            if (window.L) {
                resolve();
                return;
            }

            // Načtení CSS
            const cssLink = document.createElement('link');
            cssLink.rel = 'stylesheet';
            cssLink.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            cssLink.onload = function() {
                // Načtení JS
                const script = document.createElement('script');
                script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            };
            cssLink.onerror = reject;
            document.head.appendChild(cssLink);
        });
    }

    /**
     * Vytvoření mapy
     */
    function initStandardMap(container, lat, lng, title) {
        try {
            const map = L.map(container.id).setView([lat, lng], 15);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            const marker = L.marker([lat, lng]).addTo(map);
            marker.bindPopup(title);

            setTimeout(function() {
                map.invalidateSize();
            }, 200);

            window.addEventListener('resize', function() {
                map.invalidateSize();
            });

        } catch (error) {
            console.error('Chyba při vytváření mapy:', error);
            showMapError(container);
        }
    }

    /**
     * Zobrazení chyby mapy
     */
    function showMapError(container) {
        container.innerHTML = `
            <div style="display: flex; align-items: center; justify-content: center; height: 100%; background: #f8fafc; border-radius: 16px;">
                <div style="text-align: center; color: #64748b;">
                    <div style="font-size: 3rem; margin-bottom: 1rem;">🗺️</div>
                    <p>Mapa se nepodařilo načíst</p>
                    <small>Zkuste obnovit stránku</small>
                </div>
            </div>
        `;
    }

    /**
     * Inicializace plynulého scrollování
     */
    function initSmoothScrolling() {
        const links = document.querySelectorAll('a[href^="#"]');
        
        links.forEach(function(link) {
            link.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href === '#') return;

                const target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }

    /**
     * Inicializace lazy loadingu obrázků
     */
    function initLazyLoading() {
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver(function(entries, observer) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('lazy');
                        imageObserver.unobserve(img);
                    }
                });
            });

            const lazyImages = document.querySelectorAll('img[data-src]');
            lazyImages.forEach(function(img) {
                imageObserver.observe(img);
            });
        }
    }

    /**
     * Utility funkce pro formátování čísel
     */
    function formatNumber(num, decimals = 0) {
        if (isNaN(num)) return '0';
        return parseFloat(num).toLocaleString('cs-CZ', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }

    /**
     * Utility funkce pro formátování vzdálenosti
     */
    function formatDistance(meters) {
        if (meters < 1000) {
            return Math.round(meters) + ' m';
        } else {
            return (meters / 1000).toFixed(1) + ' km';
        }
    }

    /**
     * Utility funkce pro formátování času
     */
    function formatTime(minutes) {
        if (minutes < 60) {
            return minutes + ' min';
        } else {
            const hours = Math.floor(minutes / 60);
            const mins = minutes % 60;
            return hours + 'h ' + (mins > 0 ? mins + 'm' : '');
        }
    }

    /**
     * Přidání loading stavu pro tlačítka
     */
    function addLoadingState(button, text = 'Načítání...') {
        const originalText = button.innerHTML;
        button.disabled = true;
        button.innerHTML = `<span class="spinner"></span> ${text}`;
        button.classList.add('loading');
        
        return function() {
            button.disabled = false;
            button.innerHTML = originalText;
            button.classList.remove('loading');
        };
    }

    /**
     * Zobrazení notifikace
     */
    function showNotification(message, type = 'info', duration = 3000) {
        const notification = document.createElement('div');
        notification.className = `db-notification db-notification-${type}`;
        notification.innerHTML = `
            <div class="db-notification-content">
                <span class="db-notification-message">${message}</span>
                <button class="db-notification-close">&times;</button>
            </div>
        `;

        // Přidání do DOM
        document.body.appendChild(notification);

        // Animace vstupu
        setTimeout(function() {
            notification.classList.add('show');
        }, 100);

        // Automatické skrytí
        setTimeout(function() {
            hideNotification(notification);
        }, duration);

        // Zavření kliknutím
        const closeBtn = notification.querySelector('.db-notification-close');
        closeBtn.addEventListener('click', function() {
            hideNotification(notification);
        });
    }

    /**
     * Skrytí notifikace
     */
    function hideNotification(notification) {
        notification.classList.remove('show');
        setTimeout(function() {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }

    /**
     * Přidání CSS pro notifikace
     */
    function addNotificationStyles() {
        if (document.getElementById('db-notification-styles')) return;

        const style = document.createElement('style');
        style.id = 'db-notification-styles';
        style.textContent = `
            .db-notification {
                position: fixed;
                top: 20px;
                right: 20px;
                background: white;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                padding: 1rem;
                transform: translateX(100%);
                transition: transform 0.3s ease;
                z-index: 10000;
                max-width: 400px;
            }
            .db-notification.show {
                transform: translateX(0);
            }
            .db-notification-content {
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }
            .db-notification-message {
                flex: 1;
                color: #1e293b;
            }
            .db-notification-close {
                background: none;
                border: none;
                font-size: 1.5rem;
                color: #64748b;
                cursor: pointer;
                padding: 0;
                line-height: 1;
            }
            .db-notification-close:hover {
                color: #1e293b;
            }
            .db-notification-info {
                border-left: 4px solid #049FE8;
            }
            .db-notification-success {
                border-left: 4px solid #10b981;
            }
            .db-notification-warning {
                border-left: 4px solid #f59e0b;
            }
            .db-notification-error {
                border-left: 4px solid #ef4444;
            }
            .spinner {
                display: inline-block;
                width: 16px;
                height: 16px;
                border: 2px solid #f3f3f3;
                border-top: 2px solid #049FE8;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    }

    function initDetailMap(container, detailSettings) {
        const settings = detailSettings || {};
        const lat = (typeof settings.lat === 'number') ? settings.lat : parseFloat(container.dataset.lat);
        const lng = (typeof settings.lng === 'number') ? settings.lng : parseFloat(container.dataset.lng);
        const title = settings.title || container.dataset.title || 'Nabíjecí bod';
        const postId = settings.postId;
        const restNonce = settings.restNonce;

        if (!isFinite(lat) || !isFinite(lng)) {
            showMapError(container);
            return;
        }

        loadLeafletResources().then(function() {
            const map = L.map(container, { zoomControl: false }).setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            const focusBounds = L.latLngBounds([[lat, lng]]);
            const originMarker = L.circleMarker([lat, lng], {
                radius: 8,
                color: '#049FE8',
                weight: 3,
                fillColor: '#049FE8',
                fillOpacity: 0.85
            }).addTo(map);
            originMarker.bindPopup(escapeHtml(title));
            focusBounds.extend(originMarker.getLatLng());

            renderDetailIsochrones(map, lat, lng, focusBounds);
            if (postId) {
                renderDetailNearby(map, postId, restNonce, focusBounds);
            }

            setTimeout(function() {
                map.invalidateSize();
            }, 250);
        }).catch(function(error) {
            console.error('Chyba při načítání mapy:', error);
            showMapError(container);
        });
    }

    function renderDetailIsochrones(map, lat, lng, focusBounds) {
        const restBase = (window.DBDetail && window.DBDetail.restUrl) || '/wp-json/db/v1/';
        const isochronesUrl = restBase.replace(/\/$/, '') + '/isochrones?lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng);
        fetch(isochronesUrl)
            .then(function(response) {
                if (!response.ok) {
                    throw new Error(response.statusText || 'isochrones_fetch_failed');
                }
                return response.json();
            })
            .then(function(data) {
                if (!data || !data.geojson || !Array.isArray(data.geojson.features)) {
                    return;
                }

                const ranges = Array.isArray(data.ranges) ? data.ranges.slice().sort(function(a, b) { return a - b; }) : [];
                const colorPalette = [
                    { fill: 'rgba(4,159,232,0.16)', stroke: '#049FE8' },
                    { fill: 'rgba(79,70,229,0.18)', stroke: '#4F46E5' },
                    { fill: 'rgba(147,51,234,0.18)', stroke: '#9333EA' }
                ];
                const valueIndexMap = {};
                ranges.forEach(function(value, index) {
                    valueIndexMap[value] = index;
                });

                const layer = L.geoJSON(data.geojson, {
                    style: function(feature) {
                        const value = feature && feature.properties ? feature.properties.value : null;
                        const paletteIndex = (value !== null && valueIndexMap[value] !== undefined) ? valueIndexMap[value] : 0;
                        const palette = colorPalette[paletteIndex % colorPalette.length];
                        return {
                            color: palette.stroke,
                            weight: 1.2,
                            fillColor: palette.fill,
                            fillOpacity: 0.28,
                            opacity: 0.65
                        };
                    }
                }).addTo(map);

                try {
                    const layerBounds = layer.getBounds();
                    if (layerBounds && layerBounds.isValid()) {
                        focusBounds.extend(layerBounds);
                        map.fitBounds(focusBounds.pad(0.18));
                    }
                } catch (error) {
                    // ignore fit errors
                }
            })
            .catch(function(error) {
                console.warn('Nepodařilo se načíst isochrony:', error);
            });
    }

    function renderDetailNearby(map, postId, restNonce, focusBounds) {
        const listEl = document.getElementById('db-detail-nearby-list');
        if (!listEl) {
            return;
        }

        const headers = {};
        if (restNonce) {
            headers['X-WP-Nonce'] = restNonce;
        }

        const restBase = (window.DBDetail && window.DBDetail.restUrl) || '/wp-json/db/v1/';
        const nearbyUrl = restBase.replace(/\/$/, '') + '/nearby?origin_id=' + encodeURIComponent(postId) + '&type=poi&limit=6';
        fetch(nearbyUrl, { headers: headers })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error(response.statusText || 'nearby_fetch_failed');
                }
                return response.json();
            })
            .then(function(data) {
                if (!data || !Array.isArray(data.items) || data.items.length === 0) {
                    listEl.innerHTML = '<div class="db-detail-placeholder">V okolí jsme nenašli žádná zajímavá místa.</div>';
                    return;
                }

                listEl.innerHTML = '';
                data.items.slice(0, 6).forEach(function(item) {
                    const title = escapeHtml(item.title || item.name || 'Neznámé místo');
                    const distanceText = typeof item.distance_m === 'number' ? formatDistance(item.distance_m) : null;
                    const durationText = typeof item.duration_s === 'number' ? formatTime(Math.max(1, Math.round(item.duration_s / 60))) : null;

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'db-detail-nearby-item';
                    button.innerHTML = `
                        <span class="db-detail-nearby-title">${title}</span>
                        <span class="db-detail-nearby-meta">
                            ${distanceText ? `<span>${escapeHtml(distanceText)}</span>` : ''}
                            ${durationText ? `<span>${escapeHtml(durationText)}</span>` : ''}
                        </span>
                    `;

                    const detailUrl = item.permalink || item.link || item.url || null;
                    if (detailUrl) {
                        button.addEventListener('click', function() {
                            window.open(detailUrl, '_blank', 'noopener');
                        });
                    }

                    listEl.appendChild(button);

                    if (typeof item.lat === 'number' && typeof item.lng === 'number') {
                        const poiMarker = L.circleMarker([item.lat, item.lng], {
                            radius: 6,
                            color: '#FF8DAA',
                            weight: 2,
                            fillColor: 'rgba(255,141,170,0.7)',
                            fillOpacity: 0.7
                        }).addTo(map);
                        poiMarker.bindPopup(title);
                        focusBounds.extend(poiMarker.getLatLng());
                    }
                });

                try {
                    map.fitBounds(focusBounds.pad(0.18));
                } catch (error) {
                    // ignore fit errors
                }
            })
            .catch(function(error) {
                console.warn('Nepodařilo se načíst body v okolí:', error);
                listEl.innerHTML = '<div class="db-detail-placeholder">Nepodařilo se načíst body v okolí.</div>';
            });
    }

    // Přidání stylů pro notifikace
    addNotificationStyles();

    // Export funkcí pro použití v šablonách
    window.DBSingleTemplates = {
        showNotification: showNotification,
        formatNumber: formatNumber,
        formatDistance: formatDistance,
        formatTime: formatTime,
        addLoadingState: addLoadingState
    };

})();

/**
 * JavaScript funkcionalita pro single template šablony
 * Dobitý Baterky Plugin
 */

(function() {
    'use strict';

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
        const mapContainer = document.getElementById('db-single-map');
        if (!mapContainer) return;

        // Kontrola, zda máme souřadnice
        const lat = parseFloat(mapContainer.dataset.lat);
        const lng = parseFloat(mapContainer.dataset.lng);
        const title = mapContainer.dataset.title || 'Lokalita';

        if (isNaN(lat) || isNaN(lng)) return;

        // Načtení Leaflet CSS a JS
        loadLeafletResources().then(function() {
            createMap(mapContainer, lat, lng, title);
        }).catch(function(error) {
            console.error('Chyba při načítání mapy:', error);
            showMapError(mapContainer);
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
    function createMap(container, lat, lng, title) {
        try {
            const map = L.map(container.id).setView([lat, lng], 15);
            
            // Přidání OpenStreetMap vrstvy
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            // Přidání markeru
            const marker = L.marker([lat, lng]).addTo(map);
            marker.bindPopup(title);

            // Oprava velikosti mapy
            setTimeout(function() {
                map.invalidateSize();
            }, 200);

            // Přidání event listeneru pro resize
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

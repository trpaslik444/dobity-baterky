// db-map.js – moderní frontend pro Dobitý Baterky
//

// Globální proměnné pro isochrones
let isochronesCache = null;
let isochronesLayer = null;
let currentIsochronesRequestId = 0;
let isochronesLocked = false;
let lockedIsochronesPayload = null;
let lastIsochronesPayload = null;
let isochronesUnlockButton = null;

/**
 * Upravit isochrones podle frontend nastavení rychlosti chůze
 */
function adjustIsochronesForFrontendSpeed(geojson, originalRanges, frontendSettings) {
  // Standardní rychlost ORS je ~5 km/h, frontend rychlost
  const standardSpeed = 5.0; // km/h (ORS default)
  const frontendSpeed = parseFloat(frontendSettings.walking_speed || 4.5);
  
  // Pokud je rychlost stejná, vrátit původní data
  if (Math.abs(frontendSpeed - standardSpeed) < 0.1) {
    return geojson;
  }
  
  // Vypočítat koeficient úpravy
  const speedRatio = frontendSpeed / standardSpeed;
  
  // Zkopírovat GeoJSON a upravit hodnoty
  const adjustedGeojson = JSON.parse(JSON.stringify(geojson)); // Deep copy
  
  if (adjustedGeojson.features) {
    adjustedGeojson.features.forEach(feature => {
      if (feature.properties && feature.properties.value) {
        // Upravit čas podle rychlosti
        const originalTime = feature.properties.value;
        const adjustedTime = Math.round(originalTime * speedRatio);
        feature.properties.value = adjustedTime;
        
        // Přidat informaci o úpravě
        feature.properties.frontend_original_value = originalTime;
        feature.properties.frontend_speed_adjusted = true;
        feature.properties.frontend_speed_kmh = frontendSpeed;
      }
    });
  }
  
  return adjustedGeojson;
}

/**
 * Vykreslí isochrones na mapu
 */
function renderIsochrones(geojson, ranges, userSettings = null, options = {}) {
  const { featureId = null, force = false } = options;

  if (isochronesLocked && !force && lockedIsochronesPayload && lockedIsochronesPayload.featureId !== featureId) {
    return false;
  }

  // Odstranit předchozí vrstvu
  const shouldForceClear = force || (isochronesLocked && lockedIsochronesPayload && lockedIsochronesPayload.featureId === featureId);
  clearIsochrones(shouldForceClear);
  
  // Vytvořit novou vrstvu
  isochronesLayer = L.geoJSON(geojson, {
    style: function(feature) {
      const range = feature.properties.value;
      let color = '#10b981'; // default green
      
      // Barvy podle času (10min = zelená, 20min = žlutá, 30min = červená)
      if (ranges && ranges.length >= 3) {
        if (range <= ranges[0]) color = '#10b981'; // 10min - zelená
        else if (range <= ranges[1]) color = '#f59e0b'; // 20min - žlutá  
        else color = '#ef4444'; // 30min - červená
      }
      
      return {
        fillColor: color,
        color: color,
        weight: 2,
        opacity: 0.8,
        fillOpacity: 0.25,
        dashArray: '5, 5'
      };
    }
  });
  
  // Přidat na mapu
  isochronesLayer.addTo(window.map);
  
  // Přidat legendu s hezkými časy (použít user_settings pokud jsou k dispozici)
  if (!document.getElementById('db-isochrones-legend')) {
    const legend = document.createElement('div');
    legend.id = 'db-isochrones-legend';
    
    // Získat zobrazované časy z user_settings nebo použít defaultní
    const displayTimes = userSettings?.display_times_min || [10, 20, 30];
    
    legend.innerHTML = `
      <div style="background: white; padding: 8px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); font-size: 12px;">
        <strong>Dochozí okruhy:</strong><br>
        <span style="color: #10b981;">●</span> ~${displayTimes[0]} min<br>
        <span style="color: #f59e0b;">●</span> ~${displayTimes[1]} min<br>
        <span style="color: #ef4444;">●</span> ~${displayTimes[2]} min
      </div>
    `;
    legend.style.position = 'absolute';
    legend.style.bottom = '10px';
    legend.style.right = '10px';
    // Pokud je otevřen detail modal, vykreslit legendu do overlaye nad mapou a pod kartou
    // Jinak vykreslit do mapového kontejneru
    const modal = document.getElementById('db-detail-modal');
    const isModalOpen = modal && modal.classList.contains('open');
    if (isModalOpen) {
      legend.style.zIndex = '1';
      modal.appendChild(legend);
    } else {
      legend.style.zIndex = '1000';
      document.getElementById('db-map').appendChild(legend);
    }
    // Označit body class, aby se modal karta posunula výše
    try { document.body.classList.add('has-isochrones'); } catch(_) {}
  }
  
  // Přidat atribuci
  addIsochronesAttribution();

  return true;
}

/**
 * Odstraní isochrones z mapy
 */
function clearIsochrones(force = false) {
  if (isochronesLocked && !force) {
    return;
  }

  if (isochronesLayer && window.map) {
    window.map.removeLayer(isochronesLayer);
    isochronesLayer = null;
  }
  
  // Odstranit legendu (ať už je v mapě, nebo v modalu) a flag na body
  const legend = document.getElementById('db-isochrones-legend');
  if (legend) { legend.remove(); }
  try { document.body.classList.remove('has-isochrones'); } catch(_) {}
  
  removeIsochronesAttribution();
}

function ensureIsochronesUnlockButton() {
  if (isochronesUnlockButton) {
    return isochronesUnlockButton;
  }

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.id = 'db-isochrones-unlock';
  btn.setAttribute('aria-label', 'Zrušit zamknuté isochrony');
  btn.style.position = 'fixed';
  btn.style.right = '16px';
  btn.style.top = '50%';
  btn.style.transform = 'translateY(-50%)';
  btn.style.zIndex = '10010';
  btn.style.width = '56px';
  btn.style.height = '56px';
  btn.style.borderRadius = '50%';
  btn.style.border = '1px solid rgba(4, 159, 232, 0.45)';
  btn.style.background = 'rgba(255, 255, 255, 0.3)';
  btn.style.backdropFilter = 'blur(6px)';
  btn.style.boxShadow = '0 6px 18px rgba(4, 159, 232, 0.25)';
  btn.style.display = 'none';
  btn.style.alignItems = 'center';
  btn.style.justifyContent = 'center';
  btn.style.padding = '0';
  btn.style.cursor = 'pointer';

  btn.innerHTML = `
    <svg width="80%" height="80%" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <style>
          .c{stroke:#049FE8;}
          .c,.d,.e,.f{fill:none;stroke-linecap:round;stroke-linejoin:round;}
          .d,.e,.f{stroke:#FF6A4B;}
          .e{stroke-dasharray:0 0 3 3;}
          .f{stroke-dasharray:0 0 3.34 3.34;}
        </style>
      </defs>
      <g>
        <g>
          <polyline class="d" points="7.99 19.5 2.5 19.5 2.5 16.5"/>
          <line class="e" x1="2.5" x2="2.5" y1="13.5" y2="9"/>
          <polyline class="d" points="2.5 7.5 2.5 4.5 5.5 4.5"/>
          <line class="f" x1="8.83" x2="13.84" y1="4.5" y2="4.5"/>
          <polyline class="d" points="15.5 4.5 18.5 4.5 18.5 7"/>
        </g>
        <path class="c" d="M18.8,13.39c0,2.98-2.42,5.4-5.4,5.4s-5.4-2.42-5.4-5.4,2.42-5.4,5.4-5.4,5.4,2.42,5.4,5.4Z"/>
        <line class="c" x1="17.35" x2="21.5" y1="17.31" y2="21.5"/>
        <line class="d" x1="10.5" y1="10.5" x2="16.5" y2="16.5"/>
        <line class="d" x1="16.5" y1="10.5" x2="10.5" y2="16.5"/>
      </g>
    </svg>
  `;

  btn.addEventListener('click', () => unlockIsochrones());

  document.body.appendChild(btn);
  isochronesUnlockButton = btn;
  return btn;
}

function showIsochronesUnlockButton() {
  const btn = ensureIsochronesUnlockButton();
  btn.style.display = 'flex';
}

function hideIsochronesUnlockButton() {
  if (isochronesUnlockButton) {
    isochronesUnlockButton.style.display = 'none';
  }
}

function updateIsochronesLockButtons(featureId = null) {
  const selectorBase = '[data-db-action="lock-isochrones"]';
  const selector = featureId === null ? selectorBase : `${selectorBase}[data-feature-id="${featureId}"]`;
  const buttons = document.querySelectorAll(selector);

  buttons.forEach(btn => {
    const btnFeatureId = parseInt(btn.getAttribute('data-feature-id') || '', 10);
    const isoAvailable = !!(lastIsochronesPayload && lastIsochronesPayload.featureId === btnFeatureId);
    const isLockedFeature = !!(isochronesLocked && lockedIsochronesPayload && lockedIsochronesPayload.featureId === btnFeatureId);
    const shouldDisable = (!isoAvailable && !isLockedFeature) || (isochronesLocked && !isLockedFeature);

    btn.disabled = shouldDisable;
    btn.classList.toggle('is-active', isLockedFeature);
    btn.setAttribute('aria-pressed', isLockedFeature ? 'true' : 'false');

    if (isLockedFeature) {
      btn.title = 'Isochrony jsou uzamčeny';
    } else if (!isoAvailable) {
      btn.title = 'Isochrony se načítají…';
    } else {
      btn.title = 'Zamknout isochrony';
    }
  });
}

function lockIsochrones(payload = null) {
  const targetPayload = payload || lastIsochronesPayload;
  if (!targetPayload) {
    return;
  }

  isochronesLocked = true;
  lockedIsochronesPayload = targetPayload;
  showIsochronesUnlockButton();
  try { document.body.classList.add('db-isochrones-locked'); } catch (_) {}

  renderIsochrones(
    targetPayload.geojson,
    targetPayload.ranges,
    targetPayload.userSettings,
    { featureId: targetPayload.featureId, force: true }
  );

  updateIsochronesLockButtons(targetPayload.featureId);
}

function unlockIsochrones() {
  if (!isochronesLocked) {
    return;
  }

  const previousFeatureId = lockedIsochronesPayload?.featureId ?? null;

  isochronesLocked = false;
  lockedIsochronesPayload = null;
  hideIsochronesUnlockButton();
  try { document.body.classList.remove('db-isochrones-locked'); } catch (_) {}

  clearIsochrones(true);

  if (previousFeatureId !== null) {
    updateIsochronesLockButtons(previousFeatureId);
  } else {
    updateIsochronesLockButtons();
  }
}

function handleIsochronesLockButtonClick(featureId) {
  if (isochronesLocked) {
    if (lockedIsochronesPayload && lockedIsochronesPayload.featureId === featureId) {
      unlockIsochrones();
    }
    return;
  }

  if (!lastIsochronesPayload || lastIsochronesPayload.featureId !== featureId) {
    return;
  }

  lockIsochrones(lastIsochronesPayload);
}

/**
 * Přidá ORS/OSM atribuci na mapu
 */
function ensureAttributionBar() {
  const mapContainer = document.getElementById('db-map');
  if (!mapContainer) return null;
  let bar = document.getElementById('db-attribution-bar');
  if (!bar) {
    bar = document.createElement('div');
    bar.id = 'db-attribution-bar';
    bar.style.position = 'absolute';
    bar.style.left = '8px';
    bar.style.bottom = '8px';
    bar.style.zIndex = '1002';
    bar.style.background = 'rgba(255,255,255,0.9)';
    bar.style.backdropFilter = 'blur(6px)';
    bar.style.border = '1px solid rgba(0,0,0,0.1)';
    bar.style.borderRadius = '4px';
    bar.style.padding = '4px 6px';
    bar.style.fontSize = '11px';
    bar.style.lineHeight = '1';
    bar.style.color = '#333';
    bar.style.pointerEvents = 'auto';
    mapContainer.appendChild(bar);
    try {
    } catch(_) {}
    try { document.body.classList.add('has-attribution'); } catch(_) {}
  }
  return bar;
}
function ensureLicenseModal() {
  let modal = document.getElementById('db-license-modal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'db-license-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
      <div class="db-license-modal__backdrop" data-close="true"></div>
      <div class="db-license-modal__content" role="document">
        <button type="button" class="db-license-modal__close" aria-label="Zavřít">&times;</button>
        <h2 class="db-license-modal__title">Licence</h2>
        <div class="db-license-modal__body"></div>
      </div>
    `;
    document.body.appendChild(modal);

    const close = () => hideLicenseModal();
    const closeButton = modal.querySelector('.db-license-modal__close');
    const backdrop = modal.querySelector('.db-license-modal__backdrop');

    if (closeButton) {
      closeButton.addEventListener('click', close);
    }
    if (backdrop) {
      backdrop.addEventListener('click', close);
    }

    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        hideLicenseModal();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        hideLicenseModal();
      }
    });
  }
  return modal;
}

function hideLicenseModal() {
  const modal = document.getElementById('db-license-modal');
  if (!modal) return;
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('db-license-modal-open');
}

function showLicenseModal() {
  const modal = ensureLicenseModal();
  if (!modal) return;
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('db-license-modal-open');
  try {
    const closeButton = modal.querySelector('.db-license-modal__close');
    if (closeButton) {
      closeButton.focus({ preventScroll: true });
    }
  } catch(_) {}
}

function updateLicenseModalContent(entries) {
  const modal = ensureLicenseModal();
  if (!modal) return;
  const body = modal.querySelector('.db-license-modal__body');
  if (!body) return;

  const listItems = entries.map((entry) => {
    const link = entry.url ? `<a href="${entry.url}" target="_blank" rel="noopener">${entry.title}</a>` : entry.title;
    const description = entry.description ? `<div>${entry.description}</div>` : '';
    return `<li>${link}${description}</li>`;
  }).join('');

  body.innerHTML = `
    <p>Mapa Dobijte baterky využívá tyto otevřené služby a zdroje. Děkujeme komunitám, které je vytvářejí a udržují.</p>
    <ul>${listItems}</ul>
  `;
}

function positionAttributionBar(bar) {
  if (!bar) return;
  const isMobile = window.innerWidth <= 900;
  const mapEl = document.getElementById('db-map');
  const modal = document.getElementById('db-detail-modal');
  const mobileSheet = document.getElementById('db-mobile-sheet');
  const modalOpen = !!(modal && modal.classList.contains('open'));
  const sheetOpen = !!(mobileSheet && mobileSheet.classList.contains('open'));

  if (isMobile) {
    // Na mobilu vykreslit globálně nad mapou, aby nebyl omezen z-indexem mapy
    if (bar.parentElement !== document.body) {
      document.body.appendChild(bar);
    }
    bar.style.position = 'fixed';
    bar.style.left = '8px';
    // Rezerva nad spodními prvky
    const baseBottom = 8;
    bar.style.bottom = baseBottom + 'px'; // bar je vždy u spodku okna
    // Umístění ve vrstvení: pod mobile-sheet (10003), ale nad mapou
    bar.style.zIndex = '10002';
  } else {
    // Na desktopu stačí absolutně do mapy
    if (bar.parentElement !== mapEl && mapEl) {
      mapEl.appendChild(bar);
    }
    bar.style.position = 'absolute';
    bar.style.left = '8px';
    bar.style.bottom = '8px';
    // Licenční lišta má být NAD detail modalem i na desktopu
    // Detail modal má z-index ~10001 v CSS, proto nastavíme bar výše
    bar.style.zIndex = modalOpen ? '10005' : '1002';
  }

  // Debug informace o stacking contextu
  try {
    const csBar = window.getComputedStyle(bar);
    const csMap = mapEl ? window.getComputedStyle(mapEl) : null;
  } catch(_) {}
}

function updateAttributionBar(options) {
  const { includeORS } = options || {};
  const bar = ensureAttributionBar();
  if (!bar) return;
  const entries = [
    {
      title: 'Leaflet',
      url: 'https://leafletjs.com',
      description: 'Open-source knihovna pro interaktivní mapy.',
    },
    {
      title: 'OpenStreetMap',
      url: 'https://www.openstreetmap.org/copyright',
      description: '© OpenStreetMap contributors',
    },
  ];

  if (includeORS) {
    entries.push({
      title: 'openrouteservice',
      url: 'https://openrouteservice.org/terms-of-service/',
      description: 'Routovací a isochronní data poskytovaná Heidelberg Institute for Geoinformation Technology.',
    });
  }

  bar.innerHTML = '<button type="button" class="db-license-trigger">Licence</button>';
  const trigger = bar.querySelector('.db-license-trigger');
  if (trigger) {
    trigger.addEventListener('click', (event) => {
      event.preventDefault();
      showLicenseModal();
    });
  }

  updateLicenseModalContent(entries);
  positionAttributionBar(bar);
  try {
  } catch(_) {}
}

function addIsochronesAttribution() {
  updateAttributionBar({ includeORS: true });
}

/**
 * Odstraní isochrones atribuci
 */
function removeIsochronesAttribution() {
  // Při vypnutí ORS jen aktualizovat bar bez ORS
  updateAttributionBar({ includeORS: false });
}

// Repozicionovat při resize a po načtení
try {
  window.addEventListener('resize', () => {
    const bar = document.getElementById('db-attribution-bar');
    positionAttributionBar(bar);
  });
  window.addEventListener('DOMContentLoaded', () => {
    const bar = document.getElementById('db-attribution-bar');
    positionAttributionBar(bar);
  });
} catch(_) {}

// Sledovat změny tříd pro modal/sheet a logovat změny pozic
try {
  const observer = new MutationObserver(() => {
    const bar = document.getElementById('db-attribution-bar');
    positionAttributionBar(bar);
  });
  observer.observe(document.body, { attributes: true, childList: true, subtree: true });
} catch(_) {}

document.addEventListener('DOMContentLoaded', async function() {
  // Inicializovat globální proměnné pro isochrones
  if (!isochronesCache) {
    isochronesCache = new Map();
  }
  // Přidat CSS pro loading spinner
  const style = document.createElement('style');
  style.textContent = `
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
  `;
  document.head.appendChild(style);

  // Ochrana proti dvojí inicializaci
  if (window.DB_MAP_V2_INIT) {
    return;
  }
  window.DB_MAP_V2_INIT = true;

  // Debug: sledování načítání skriptu
  if (!window.__DB_MAP_LOADED__) { window.__DB_MAP_LOADED__ = 0; }
  window.__DB_MAP_LOADED__++;

  // Základní proměnné
  let mapDiv = document.getElementById('db-map');
  
  // Pokud element #db-map neexistuje, neinicializuj mapu
  if (!mapDiv) {
    return;
  }

  // Detekce user gesture pro geolokaci
  let userGestureDetected = false;
  const detectUserGesture = () => {
    userGestureDetected = true;
    window.userGestureDetected = true;
    // Odstranit event listenery po detekci
    document.removeEventListener('click', detectUserGesture);
    document.removeEventListener('touchstart', detectUserGesture);
    document.removeEventListener('keydown', detectUserGesture);
  };
  
  // Přidat event listenery pro detekci user gesture
  document.addEventListener('click', detectUserGesture, { once: true });
  document.addEventListener('touchstart', detectUserGesture, { once: true });
  document.addEventListener('keydown', detectUserGesture, { once: true });

  // Inicializace globálních proměnných
    let markers = [];
    let features = [];
    window.features = features; // Nastavit globální přístup pro isochrones funkce
    // Jednoduchý per-session cache načtených feature podle ID
    const featureCache = new Map(); // id -> feature
    const internalSearchCache = new Map();
    const externalSearchCache = new Map();
    let mobileSearchController = null;
    let desktopSearchController = null;
  let lastRenderedFeatures = [];
  function selectFeaturesForView() {
    try {
      if (!map) return [];
      const viewBounds = map.getBounds().pad(0.35); // mírné rozšíření viewportu, aby neblikalo prázdno
      const center = lastSearchCenter;
      const radiusKm = lastSearchRadiusKm;
      const out = [];
      featureCache.forEach((f) => {
        const c = f?.geometry?.coordinates; if (!c || c.length < 2) return;
        const ll = L.latLng(c[1], c[0]);
        if (center && radiusKm) {
          const d = haversineKm(center, { lat: ll.lat, lng: ll.lng });
          if (d > radiusKm * 1.1) return; // mimo poslední fetch kruh (s malou rezervou)
        }
        if (!viewBounds.contains(ll)) return; // mimo aktuální viewport
        out.push(f);
      });
      return out;
    } catch (_) { return []; }
  }
  let showOnlyRecommended = false;
  let sortMode = 'distance';
  let searchAddressCoords = null;
  let searchSortLocked = false;
  let searchAddressMarker = null;
  let lastSearchResults = [];
  let activeIdxGlobal = null;
  let activeFeatureId = null;
  // --- DEBUG utility odstraněna ---
  
  
  // Nearby data se načítají pouze pokud jsou k dispozici (batch zpracování)

  // --- RADIUS FILTER STATE (20 km kolem středu mapy) ---
  // Radius mode zrušen
  // Radius zrušen
  // Fetching radius zrušeno
  // Moveend debounce timer zrušen

  // Haversine funkce zrušena

  // Radius filtr zrušen

  // Server-side načtení bodů zrušeno

  // ===== RADIUS FETCH FUNKTIONALITA =====
  let inFlightController = null;
  const RADIUS_KM = 75; // Výchozí fallback (bude nahrazen dle režimu)
  const MIN_FETCH_ZOOM = (typeof window.DB_MIN_FETCH_ZOOM !== 'undefined') ? window.DB_MIN_FETCH_ZOOM : 9; // pod tímto zoomem nerefreshujeme
  const FIXED_RADIUS_KM = (typeof window.DB_FIXED_RADIUS_KM !== 'undefined') ? window.DB_FIXED_RADIUS_KM : 75; // fixní okruh pro radius režim
  // Feature flags
  window.DB_RADIUS_LIMIT = window.DB_RADIUS_LIMIT || 1000;
  window.DB_RADIUS_HYSTERESIS_KM = window.DB_RADIUS_HYSTERESIS_KM || 5; // minimální posun centra pro refetch
  // Debounce helper
  function debounce(fn, wait) {
    let t;
    return function(...args){
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), wait);
    };
  }

  // DOČASNÁ FUNKCE: Zobrazení středu mapy pro debugging
  let centerDebugMarker = null;
  let centerDebugCircle = null;
  
  function getRadiusForRequest() {
    // Dynamický radius dle aktuálního viewportu (polovina diagonály bounds)
    try {
      if (!map || !map.getBounds) return RADIUS_KM;
      const bounds = map.getBounds();
      const center = map.getCenter();
      const ne = bounds.getNorthEast();
      const sw = bounds.getSouthWest();
      const d1 = haversineKm({ lat: center.lat, lng: center.lng }, { lat: ne.lat, lng: ne.lng });
      const d2 = haversineKm({ lat: center.lat, lng: center.lng }, { lat: sw.lat, lng: sw.lng });
      const raw = Math.max(d1, d2) * 1.1; // malá rezerva
      const minKm = Number.isFinite(window.DB_RADIUS_MIN_KM) ? Math.max(1, window.DB_RADIUS_MIN_KM) : 1;
      const maxKm = Number.isFinite(window.DB_RADIUS_MAX_KM) ? Math.max(5, window.DB_RADIUS_MAX_KM) : 150;
      return Math.min(Math.max(raw, minKm), maxKm);
    } catch(_) {
      return RADIUS_KM;
    }
  }
  function showMapCenterDebug(center, radiusKmOverride) {
    // Zkontrolovat, jestli je checkbox zaškrtnutý
    const centerDebugCheckbox = document.querySelector('#db-show-center-debug');
    if (!centerDebugCheckbox || !centerDebugCheckbox.checked) {
      // Odstranit existující markery pokud checkbox není zaškrtnutý
      if (centerDebugMarker) {
        map.removeLayer(centerDebugMarker);
        centerDebugMarker = null;
      }
      if (centerDebugCircle) {
        map.removeLayer(centerDebugCircle);
        centerDebugCircle = null;
      }
      return;
    }
    
    // Odstranit předchozí debug markery pokud existují
    if (centerDebugMarker) {
      map.removeLayer(centerDebugMarker);
      centerDebugMarker = null;
    }
    if (centerDebugCircle) {
      map.removeLayer(centerDebugCircle);
      centerDebugCircle = null;
    }
    
    // Vytvořit kříž marker
    const crossIcon = L.divIcon({
      className: 'db-center-cross',
      html: `
        <div style="
          width: 30px;
          height: 30px;
          position: relative;
          transform: translate(-50%, -50%);
        ">
          <div style="
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 3px;
            background: #ff0000;
            transform: translateY(-50%);
            box-shadow: 0 0 5px rgba(255,0,0,0.8);
          "></div>
          <div style="
            position: absolute;
            left: 50%;
            top: 0;
            bottom: 0;
            width: 3px;
            background: #ff0000;
            transform: translateX(-50%);
            box-shadow: 0 0 5px rgba(255,0,0,0.8);
          "></div>
          <div style="
            position: absolute;
            top: 50%;
            left: 50%;
            width: 8px;
            height: 8px;
            background: #ff0000;
            border: 2px solid white;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            box-shadow: 0 0 5px rgba(255,0,0,0.8);
          "></div>
        </div>
      `,
      iconSize: [30, 30],
      iconAnchor: [15, 15]
    });
    
    // Přidat kříž na mapu
    centerDebugMarker = L.marker([center.lat, center.lng], { icon: crossIcon }).addTo(map);
    
    // Přidat kruh pro radius
    const debugRadiusKm = Number.isFinite(radiusKmOverride) ? radiusKmOverride : getRadiusForRequest();
    centerDebugCircle = L.circle([center.lat, center.lng], {
      radius: debugRadiusKm * 1000, // převod km na metry
      color: '#ff0000',
      weight: 2,
      opacity: 0.6,
      fillOpacity: 0.1
    }).addTo(map);
    
    // Automaticky odstranit po 15 sekundách
    setTimeout(() => {
      if (centerDebugMarker) {
        map.removeLayer(centerDebugMarker);
        centerDebugMarker = null;
      }
      if (centerDebugCircle) {
        map.removeLayer(centerDebugCircle);
        centerDebugCircle = null;
      }
    }, 15000);
  }
  
  // ===== NOVÉ STAVOVÉ PROMĚNNÉ PRO FLOATING SEARCH =====
  let lastSearchCenter = null;        // {lat, lng} středu posledního vyhledávání
  let lastSearchRadiusKm = 15;        // tlačítko se zobrazí po přesunu mimo 15 km od středu posledního vyhledávání
  
  // Globální stav režimu načítání
  let loadMode = localStorage.getItem('dbLoadMode') || 'radius'; // 'radius' | 'all'
  
  // ===== POMOCNÉ FUNKCE PRO FLOATING SEARCH =====
  // Haversine funkce pro výpočet vzdálenosti v km
  function haversineKm(a, b) {
    const toRad = d => d * Math.PI / 180;
    const R = 6371;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const lat1 = toRad(a.lat);
    const lat2 = toRad(b.lat);
    const x = Math.sin(dLat/2)**2 + Math.cos(lat1)*Math.cos(lat2)*Math.sin(dLng/2)**2;
    return 2 * R * Math.asin(Math.sqrt(x));
  }
  
  // Utilita pro výpis okolí středu mapy
  function logAroundCenter(centerLatLng) {
    const center = { lat: centerLatLng.lat, lng: centerLatLng.lng };
    const radiusKm = 50; // Pouze body v okruhu 50km
    
    try {
      const rows = [];
      [clusterChargers, clusterRV, clusterPOI].forEach((grp) => {
        if (!grp) return;
        grp.eachLayer((m) => {
          if (!m?.getLatLng) return;
          const ll = m.getLatLng();
          const dist = haversineKm(center, { lat: ll.lat, lng: ll.lng });
          
          // Přidat pouze body v okruhu 50km
          if (dist <= radiusKm) {
            rows.push({ 
              title: m?.options?.title || m?.feature?.properties?.title || 'Bez názvu',
              lat: +ll.lat.toFixed(6),
              lng: +ll.lng.toFixed(6),
              distKm: +dist.toFixed(2)
            });
          }
        });
      });
      
      rows.sort((a,b) => a.distKm - b.distKm);
      
      if (rows.length === 0) {
        
      } else {
        
      }
    } catch(_) {}
  }
  

  function buildRestUrlForRadius(center, includedTypesCsv = null, radiusKmOverride = null) {
    const base = (window.dbMapData?.restUrl) || '/wp-json/db/v1/map';
    
    const url = new URL(base, window.location.origin);
    // Přidání oddělených lat/lng parametrů (robustnější než center="lat,lng")
    if (center && center.lat && center.lng) {
      url.searchParams.set('lat', center.lat.toFixed(6));
      url.searchParams.set('lng', center.lng.toFixed(6));
    }
    // Dynamický radius dle viewportu (fallback na RADIUS_KM)
    const dynRadius = Number.isFinite(radiusKmOverride) ? radiusKmOverride : getRadiusForRequest();
    url.searchParams.set('radius_km', String(dynRadius));
    // Explicitně nastavíme všechny typy pro férové porovnání s ALL režimem
    url.searchParams.set('included', includedTypesCsv || 'charging_location,rv_spot,poi');
    // Limit pro server (konfigurovatelné)
    const lim = parseInt(window.DB_RADIUS_LIMIT || 1000, 10);
    if (Number.isFinite(lim) && lim > 0) url.searchParams.set('limit', String(lim));
    
    const finalUrl = url.toString();

    return finalUrl;
  }

  async function fetchAndRenderRadius(center, includedTypesCsv = null) {
    const previousCenter = lastSearchCenter ? { ...lastSearchCenter } : null;

    
    if (inFlightController) { 
      try { inFlightController.abort(); } catch(_) {} 
    }
    inFlightController = new AbortController();

    // Dynamický radius dle aktuálního viewportu – polovina diagonály bounds
    // (původně fixních 75 km i při přiblížení způsobovalo truncaci výsledků v hustých oblastech)
    const radiusKm = getRadiusForRequest();
    const url = buildRestUrlForRadius(center, includedTypesCsv, radiusKm);
    
    // Zobrazení středu mapy na obrazovce (s aktuálním radiusem)
    showMapCenterDebug(center, radiusKm);

    // Zpožděný spinner: zobraz až když request trvá déle než 200 ms
    let spinnerShown = false;
    const spinnerTimer = setTimeout(() => { document.body.classList.add('db-loading'); spinnerShown = true; }, 200);
    const t0 = performance.now?.() || Date.now();
    try {
      const res = await fetch(url, {
        signal: inFlightController.signal,
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-WP-Nonce': dbMapData.restNonce
        }
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const geo = await res.json();
      const incoming = Array.isArray(geo?.features) ? geo.features : [];
      // Sloučit do cache
      for (let i = 0; i < incoming.length; i++) {
        const f = incoming[i];
        const id = f?.properties?.id;
        if (id != null) featureCache.set(id, f);
      }
      lastSearchCenter = { lat: center.lat, lng: center.lng };
      lastSearchRadiusKm = radiusKm;
      // Výběr pro aktuální zobrazení: pouze body uvnitř posledního radiusu a aktuálního viewportu
        const visibleNow = selectFeaturesForView();
        features = (visibleNow && visibleNow.length > 0) ? visibleNow : (lastRenderedFeatures.length > 0 ? lastRenderedFeatures : incoming);
        window.features = features;

      // FALLBACK: Pokud radius vrátí 0 bodů, stáhneme ALL a vyfiltrujeme klientsky
      if (features.length === 0) {
        try {
          const allUrl = new URL((dbMapData?.restUrl) || '/wp-json/db/v1/map', window.location.origin);
          const allRes = await fetch(allUrl.toString(), { 
            signal: inFlightController.signal,
            credentials: 'same-origin',
            headers: { 
              'Accept': 'application/json',
              'X-WP-Nonce': dbMapData.restNonce
            }
          });
          if (allRes.ok) {
            const allData = await allRes.json();
            const allFeatures = Array.isArray(allData?.features) ? allData.features : [];
            
            // Klientské filtrování do 50 km
            const filteredFeatures = allFeatures.filter(f => {
              const coords = f?.geometry?.coordinates;
              if (!Array.isArray(coords) || coords.length < 2) return false;
              const [lng, lat] = coords;
              if (typeof lat !== 'number' || typeof lng !== 'number') return false;
              const distance = haversineKm({ lat: center.lat, lng: center.lng }, { lat, lng });
              return distance <= RADIUS_KM;
            });
              
              features = filteredFeatures;
              window.features = features;
          }
        } catch (fallbackErr) {
        }
      }


      if (typeof clearMarkers === 'function') clearMarkers();
      renderCards('', null, false);
      lastRenderedFeatures = Array.isArray(features) ? features.slice(0) : [];
      // Zachovej stabilní viewport po fetchi: bez auto-fit/auto-pan.
      // Poloha mapy je výhradně řízena uživatelem; přesuny provádíme
      // pouze na explicitní akce (klik na pin, potvrzení vyhledávání, moje poloha).
      // Intencionálně no-op zde.
      // map.setView(center, Math.max(map.getZoom() || 9, 9)); // vypnuto: neposouvat mapu po načtení v režimu okruhu
    } catch (err) {
      if (err.name !== 'AbortError') {
      }
    } finally {
      clearTimeout(spinnerTimer);
      if (spinnerShown) document.body.classList.remove('db-loading');
      inFlightController = null;
      // noop
    }
  }
  
  // Funkce pro načtení všech dat (bez radius filtru)
  async function fetchAndRenderAll() {
    const base = (dbMapData?.restUrl) || '/wp-json/db/v1/map';
    const url = new URL(base, window.location.origin);
    url.searchParams.set('limit', '5000');
    
    document.body.classList.add('db-loading');
    try {
      const res = await fetch(url.toString(), { 
        signal: inFlightController?.signal, 
        headers: { 
          'Accept': 'application/json',
          'X-WP-Nonce': dbMapData.restNonce
        } 
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      features = Array.isArray(data?.features) ? data.features : [];


      if (typeof clearMarkers === 'function') clearMarkers();
      renderCards('', null, false);
    } catch (err) {
      if (err.name !== 'AbortError') {
        
      }
    } finally {
      document.body.classList.remove('db-loading');
    }
  }
  
  // ===== KONEC RADIUS FUNKTIONALITY =====

  // Vytvořím nový root wrapper, pokud ještě neexistuje
  let root = document.querySelector('.db-map-root');
  if (!root) {
    root = document.createElement('div');
    root.className = 'db-map-root';
    mapDiv.parentNode.insertBefore(root, mapDiv);
  }
  // Flex wrap
  let wrap = document.querySelector('.db-map-wrap');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.className = 'db-map-wrap';
    root.appendChild(wrap);
  }
  // Panel vlevo
  let list = document.getElementById('db-map-list');
  if (!list) {
    list = document.createElement('div');
    list.id = 'db-map-list';
    wrap.appendChild(list);
  }
  // Mapa vpravo
  if (mapDiv.parentNode !== wrap) {
    wrap.appendChild(mapDiv);
  }
  // Sort by dropdown
  const sortWrap = document.createElement('div');
  sortWrap.className = 'db-map-list-sort-wrap';
  sortWrap.style.margin = '0 0.4em 0.5em 0.4em';
  const sortSelect = document.createElement('select');
  sortSelect.id = 'db-map-list-sort';
  sortSelect.style.width = '100%';
  sortSelect.style.padding = '0.5em';
  sortSelect.style.border = '1px solid #e5e7eb';
  sortSelect.style.borderRadius = '6px';
  sortSelect.style.fontSize = 'clamp(0.8rem, 2.5vw, 0.9rem)';
  sortSelect.innerHTML = `
    <option value="distance-active">Vzdálenost od vybraného bodu</option>
    <option value="distance-address">Vzdálenost od adresy…</option>
  `;
  sortWrap.appendChild(sortSelect);
  list.appendChild(sortWrap);
  // Input pro adresu (skrytý)
  const addressInput = document.createElement('input');
  addressInput.type = 'text';
  addressInput.placeholder = 'Hledám víc než jen cíl cesty…';
  addressInput.style.display = 'none';
  addressInput.style.margin = '0.5em 0.4em 0.5em 0.4em';
  addressInput.style.width = 'calc(100% - 0.8em)';
  addressInput.style.fontSize = 'clamp(0.8rem, 2.5vw, 1rem)';
  addressInput.style.minWidth = '280px';
  addressInput.style.boxSizing = 'border-box';
  addressInput.style.padding = '0.6em 0.8em';
  addressInput.style.border = '1px solid #e5e7eb';
  addressInput.style.borderRadius = '8px';
  addressInput.style.backgroundColor = '#ffffff';
  list.appendChild(addressInput);
  
  // Funkce pro responzivní přizpůsobení input pole
  function adjustInputResponsiveness() {
    const listWidth = list.offsetWidth;
    const availableWidth = listWidth - 20; // 20px pro padding
    
    if (availableWidth < 320) {
      // Malé obrazovky - menší font a padding
      addressInput.style.fontSize = '0.75rem';
      addressInput.style.padding = '0.4em 0.6em';
      addressInput.style.minWidth = 'auto';
      sortSelect.style.fontSize = '0.75rem';
      sortSelect.style.padding = '0.4em';
    } else if (availableWidth < 480) {
      // Střední obrazovky - střední font
      addressInput.style.fontSize = '0.85rem';
      addressInput.style.padding = '0.5em 0.7em';
      addressInput.style.minWidth = 'auto';
      sortSelect.style.fontSize = '0.8rem';
      sortSelect.style.padding = '0.45em';
    } else {
      // Velké obrazovky - plný font a padding
      addressInput.style.fontSize = '1rem';
      addressInput.style.padding = '0.6em 0.8em';
      addressInput.style.minWidth = '280px';
      sortSelect.style.fontSize = '0.9rem';
      sortSelect.style.padding = '0.5em';
    }
    
    // Dynamicky upravit šířku podle dostupného prostoru
    addressInput.style.width = `min(100%, ${Math.max(280, availableWidth)}px)`;
  }
  
  // Spustit responzivní úpravu při načtení a změně velikosti
  adjustInputResponsiveness();
  window.addEventListener('resize', adjustInputResponsiveness);
  
  // Responzivní úprava pro hlavní vyhledávací pole
  function adjustSearchInputResponsiveness() {
    const searchInput = document.getElementById('db-map-search-input');
    if (!searchInput) return;
    
    const topbarWidth = topbar.offsetWidth;
    const availableWidth = topbarWidth - 200; // 200px pro tlačítka
    
    if (availableWidth < 350) {
      searchInput.style.minWidth = '280px';
      searchInput.style.fontSize = '0.8rem';
      searchInput.style.padding = '0.5em 0.6em';
    } else if (availableWidth < 500) {
      searchInput.style.minWidth = '320px';
      searchInput.style.fontSize = '0.9rem';
      searchInput.style.padding = '0.55em 0.7em';
    } else {
      searchInput.style.minWidth = '400px';
      searchInput.style.fontSize = '1rem';
      searchInput.style.padding = '0.6em 0.8em';
    }
    
    // Dynamicky upravit šířku podle dostupného prostoru
    searchInput.style.width = `min(100%, ${Math.max(280, availableWidth)}px)`;
  }
  
  // Spustit responzivní úpravu pro vyhledávací pole
  setTimeout(adjustSearchInputResponsiveness, 100); // Počkat na načtení DOM
  window.addEventListener('resize', adjustSearchInputResponsiveness);
  
  const cardsWrap = document.createElement('div');
  list.appendChild(cardsWrap);

  // Inicializace mapy
  let map;
  
  // Kontrola, zda je Leaflet načten
  if (typeof L === 'undefined') {
    mapDiv.innerHTML = '<div style="padding:2rem;text-align:center;color:#666;">Chyba: Mapa se nemohla načíst. Zkuste obnovit stránku.</div>';
    return;
  }
     try {
       map = L.map('db-map', {
         zoomControl: true,
         dragging: true,
         touchZoom: true,
         tap: false,
         scrollWheelZoom: true,
         wheelDebounceTime: 20,
         wheelPxPerZoomLevel: 120
       }).setView([50.08, 14.42], 12);
       window.map = map; // Nastavit globální přístup pro isochrones funkce
       L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
         attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
         maxZoom: 19
       }).addTo(map);
       // Inicializovat náš attribution bar hned po vytvoření mapy
       try {
       } catch(_) {}
       // Zjistit, zda je defaultně zaplé zobrazení isochrones
       let includeORSInitial = false;
       try {
         const saved = JSON.parse(localStorage.getItem('db-isochrones-settings') || '{}');
         includeORSInitial = !!saved.enabled;
       } catch(_) {}
       updateAttributionBar({ includeORS: includeORSInitial });
       // Pro jistotu přepočítat pozici s mírným zpožděním
       setTimeout(function(){
         const bar = document.getElementById('db-attribution-bar');
         positionAttributionBar(bar);
       }, 200);


      map.on('click', () => {
        clearActiveFeature();
      });


  } catch (error) {
    mapDiv.innerHTML = '<div style="padding:2rem;text-align:center;color:#666;">Chyba při načítání mapy: ' + error.message + '</div>';
    return;
  }

  // Ovládací prvek „Moje poloha" pod zoom ovladačem
  const LocateControl = L.Control.extend({
    options: { position: 'topleft' },
    onAdd: function() {
      const container = L.DomUtil.create('div', 'leaflet-bar');
      const btn = L.DomUtil.create('a', '', container);
      btn.href = '#';
      btn.title = 'Moje poloha';
      btn.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="9"/><line x1="12" y1="2" x2="12" y2="5"/><line x1="12" y1="19" x2="12" y2="22"/><line x1="2" y1="12" x2="5" y2="12"/><line x1="19" y1="12" x2="22" y2="12"/></svg>';
      L.DomEvent.on(btn, 'click', (e) => {
        L.DomEvent.stop(e);
        
        // Kontrola „secure context" – geolokace funguje pouze na HTTPS nebo localhost
        const isSecure = (window.isSecureContext === true) || (location.protocol === 'https:') || (location.hostname === 'localhost') || (location.hostname === '127.0.0.1');
        if (!isSecure) {
          const httpsUrl = 'https://' + location.host + location.pathname + location.search + location.hash;
          
          try {
            L.popup({ closeOnClick: true, autoClose: true })
              .setLatLng(map.getCenter())
              .setContent('<div style="min-width:260px">Prohlížeč vyžaduje <b>HTTPS</b> (nebo <b>localhost</b>) pro zjištění polohy. Otevřete prosím stránku přes HTTPS.<br/><br/>' +
                '<a href="'+httpsUrl+'" style="text-decoration:underline;">Přejít na HTTPS verzi</a></div>')
              .openOn(map);
          } catch(_) {}
          return;
        }
        const applyCoords = (lat, lng) => {
          const coords = [lat, lng];
          
          map.setView(coords, 13, { animate: true });
          searchAddressCoords = coords;
          sortMode = 'distance_from_address';
          searchSortLocked = true;
          renderCards('', null, false);
          addOrMoveSearchAddressMarker(coords);
        };
        const fail = (err) => {
          let reason = 'neznámý důvod';
          if (err && typeof err.code !== 'undefined') {
            if (err.code === 1) reason = 'permission_denied';
            else if (err.code === 2) reason = 'position_unavailable';
            else if (err.code === 3) reason = 'timeout';
          }
          
          // Druhý pokus: Leaflet locate (stále automaticky)
          const onLocFound = (e2) => {
            map.off('locationfound', onLocFound);
            map.off('locationerror', onLocErr);
            const lat = e2.latitude || (e2.latlng && e2.latlng.lat);
            const lng = e2.longitude || (e2.latlng && e2.latlng.lng);
            if (lat && lng) applyCoords(lat, lng);
          };
          const onLocErr = (e3) => {
            map.off('locationfound', onLocFound);
            map.off('locationerror', onLocErr);
            
            try {
              L.popup({ closeOnClick: true, autoClose: true })
                .setLatLng(map.getCenter())
                .setContent('<div style="min-width:220px">Nepodařilo se zjistit vaši polohu. Zkontrolujte oprávnění prohlížeče.</div>')
                .openOn(map);
            } catch(_) {}
          };
          try {
            map.on('locationfound', onLocFound);
            map.on('locationerror', onLocErr);
            map.locate({ setView: false, enableHighAccuracy: true, timeout: 8000, maximumAge: 0 });
          } catch(e4) {
            onLocErr(e4);
          }
        };
        if (!navigator.geolocation) {
          fail(new Error('Geolokace není podporována'));
          return;
        }
        navigator.geolocation.getCurrentPosition((pos) => {
          applyCoords(pos.coords.latitude, pos.coords.longitude);
        }, fail, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
      });
      return container;
    }
  });
  
  // Kontrola, zda se mapa vytvořila
  if (!map) {
    return;
  }
  
  // Přidání LocateControl přesunuto níže po definici isMobile
  function makeClusterGroup(style) {
    return L.markerClusterGroup({
      spiderfyOnMaxZoom: false,
      showCoverageOnHover: false,
      zoomToBoundsOnClick: false,
      disableClusteringAtZoom: 13,
      maxClusterRadius: 80,
      chunkedLoading: true,
      chunkInterval: 200,
      chunkDelay: 50,
      iconCreateFunction: function(cluster) {
        const count = cluster.getChildCount();
        let hasRecommended = false;
        cluster.getAllChildMarkers().forEach(m => {
          if (m.options && m.options._dbProps && (m.options._dbProps.db_recommended === true || m.options._dbProps.db_recommended === 1 || m.options._dbProps.db_recommended === '1' || m.options._dbProps.db_recommended === 'true')) {
            hasRecommended = true;
          }
        });
    const size = 36; const badgeSize = 16;
    let bg = '#049FE8'; let color = '#fff';
    const acColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.ac) || '#049FE8';
    const dcColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.dc) || '#FFACC4';
    const poiColor = (dbMapData && dbMapData.poiColor) || '#FCE67D';
    if (style === 'charger') {
          // Hladší prolínání (více mezikroků mezi modrou a růžovou)
      bg = 'linear-gradient(135deg, ' + acColor + ' 0%, ' + dcColor + ' 100%)';
          color = '#ffffff';
        }
    else if (style === 'rv') { bg = (dbMapData && dbMapData.rvColor) ? dbMapData.rvColor : '#FCE67D'; color = '#333333'; }
    else if (style === 'poi') { bg = poiColor; color = '#333333'; }
        const dbBadge = hasRecommended ? `<div style="position:absolute;right:-4px;top:-4px;width:${badgeSize}px;height:${badgeSize}px;">${getDbLogoHtml(badgeSize)}</div>` : '';
        const clusterHtml = `
          <div style="position:relative;width:${size}px;height:${size}px;display:flex;align-items:center;justify-content:center;background:${bg};color:${color};border-radius:50%;box-shadow:0 1px 4px rgba(0,0,0,.25);font-weight:700;">
            ${count}
            ${dbBadge}
          </div>`;
        return L.divIcon({ html: clusterHtml, className: 'db-cluster', iconSize: L.point(36,36) });
      }
    });
  }
  
  // Přidání event handlerů pro clustery
  function setupClusterEvents(clusterGroup, style) {
    clusterGroup.on('clusterclick', function(e) {
      try {
        const cluster = e.layer;
        const childMarkers = cluster.getAllChildMarkers();
        const bounds = childMarkers.length > 0
          ? L.latLngBounds(childMarkers.map(m => m.getLatLng()))
          : cluster.getBounds();
        // Jednorázové přiblížení bez rekurze; max na hranici rozpadnutí clusterů
        map.fitBounds(bounds.pad(0.1), { padding: [40, 40], maxZoom: 13, animate: true });
      } catch(_) {}
    });
  }
  
  const clusterChargers = makeClusterGroup('charger');
  const clusterRV = makeClusterGroup('rv');
  const clusterPOI = makeClusterGroup('poi');
  
  setupClusterEvents(clusterChargers, 'charger');
  setupClusterEvents(clusterRV, 'rv');
  setupClusterEvents(clusterPOI, 'poi');
  
  map.addLayer(clusterChargers);
  map.addLayer(clusterRV);
    map.addLayer(clusterPOI);
    
    // Vytvořit globální markersLayer pro isochrones funkce
    window.markersLayer = L.layerGroup([clusterChargers, clusterRV, clusterPOI]);
    
    // DEBUG bloky odstraněny
  

  
  setTimeout(() => map.invalidateSize(), 100);

  // Po inicializaci mapy přidám spacer a topbar s vyhledáváním a tlačítky
  
  // Kontrola, zda se mapa vytvořila
  if (!map) {
    return;
  }
  // Spacer pro WP menu - odstraněn, používá se původní CSS
  
  // Pak vytvořím topbar
  const topbar = document.createElement('div');
  topbar.className = 'db-map-topbar';
  topbar.setAttribute('data-db-feedback', 'map.topbar');
  topbar.style.zIndex = '1001';
  topbar.style.pointerEvents = 'auto';
  
  // Desktop vs mobilní obsah topbaru
  const isMobile = window.innerWidth <= 900;
  let filterPanel;
  let mapOverlay;

  
  if (isMobile) {
    // Mobilní verze - s tlačítkem "Moje poloha" a lupou
    topbar.innerHTML = `
      <button class="db-map-topbar-btn" title="Menu" type="button" id="db-menu-toggle">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <button class="db-map-topbar-btn" title="Vyhledávání" type="button" id="db-search-toggle">
        <svg fill="currentColor" width="20px" height="20px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="m22.241 24-7.414-7.414c-1.559 1.169-3.523 1.875-5.652 1.885h-.002c-.032 0-.07.001-.108.001-5.006 0-9.065-4.058-9.065-9.065 0-.038 0-.076.001-.114v.006c0-5.135 4.163-9.298 9.298-9.298s9.298 4.163 9.298 9.298c-.031 2.129-.733 4.088-1.904 5.682l.019-.027 7.414 7.414zm-12.942-21.487c-3.72.016-6.73 3.035-6.73 6.758 0 3.732 3.025 6.758 6.758 6.758s6.758-3.025 6.758-6.758c0-1.866-.756-3.555-1.979-4.778-1.227-1.223-2.92-1.979-4.79-1.979-.006 0-.012 0-.017 0h.001z"/></svg>
      </button>
      <button class="db-map-topbar-btn" title="Seznam" type="button" id="db-list-toggle">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3" cy="6" r="1"/><circle cx="3" cy="12" r="1"/><circle cx="3" cy="18" r="1"/></svg>
      </button>
      <button class="db-map-topbar-btn" title="Moje poloha" type="button" id="db-locate-btn">
        <svg width="20px" height="20px" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M249.6 417.088l319.744 43.072 39.168 310.272L845.12 178.88 249.6 417.088zm-129.024 47.168a32 32 0 01-7.68-61.44l777.792-311.04a32 32 0 0141.6 41.6l-310.336 775.68a32 32 0 01-61.44-7.808L512 516.992l-391.424-52.736z"/></svg>
      </button>
      <button class="db-map-topbar-btn" title="Filtry" type="button" id="db-filter-btn">
        <svg fill="currentColor" width="20px" height="20px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M4.45,4.66,10,11V21l4-2V11l5.55-6.34A1,1,0,0,0,18.8,3H5.2A1,1,0,0,0,4.45,4.66Z" style="fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2;"></path></svg>
      </button>
      <button class="db-map-topbar-btn" title="Oblíbené" type="button" id="db-favorites-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      </button>
    `;
  } else {
    // Desktop verze - bez tlačítka "Moje poloha" (je v Leaflet controls)
    topbar.innerHTML = `
      <button class="db-map-topbar-btn" title="Menu" type="button" id="db-menu-toggle">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <form class="db-map-searchbox" style="margin:0;flex:1;min-width:0;">
        <input type="text" id="db-map-search-input" placeholder="Objevujeme víc než jen cíl cesty..." autocomplete="off" style="width:100%;min-width:320px;font-size:clamp(0.8rem, 2.5vw, 1rem);padding:0.6em 0.8em;border:none;border-radius:8px;box-sizing:border-box;background:transparent;outline:none;" />
        <button type="submit" id="db-map-search-btn" tabindex="0" style="background:none;border:none;padding:0;cursor:pointer;outline:none;display:flex;align-items:center;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </button>
      </form>
      <button class="db-map-topbar-btn" title="Seznam" type="button" id="db-list-toggle">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3" cy="6" r="1"/><circle cx="3" cy="12" r="1"/><circle cx="3" cy="18" r="1"/></svg>
      </button>
      <button class="db-map-topbar-btn" title="Filtry" type="button" id="db-filter-btn">
        <svg fill="currentColor" width="20px" height="20px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M4.45,4.66,10,11V21l4-2V11l5.55-6.34A1,1,0,0,0,18.8,3H5.2A1,1,0,0,0,4.45,4.66Z" style="fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2;"></path></svg>
      </button>
      <button class="db-map-topbar-btn" title="Oblíbené" type="button" id="db-favorites-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      </button>
    `;
  }
  mapDiv.style.position = 'relative';
  mapDiv.style.zIndex = '1';
  mapDiv.appendChild(topbar);

  // Centralizovaný handler topbar tlačítek - díky delegaci zůstává funkční i po výměně obsahu
  topbar.addEventListener('click', (event) => {
    const button = event.target.closest('.db-map-topbar-btn');
    if (!button || !topbar.contains(button)) {
      return;
    }

    switch (button.id) {
      case 'db-menu-toggle':
        handleMenuToggle(event);
        break;
      case 'db-list-toggle':
        handleListToggle(event);
        break;
      case 'db-locate-btn':
        handleLocate(event);
        break;
      case 'db-filter-btn':
        handleFilterToggle(event);
        break;
      default:
        break;
    }
  });

  // Přidat LocateControl pouze na desktopu (na mobilu se používá tlačítko v topbaru)
  try {
    if (!isMobile) {
      map.addControl(new LocateControl());
    }
  } catch(_) {}

  // Vyhledávací pole se vytvoří automaticky v mobilní verzi

  // Event listener pro změnu velikosti okna - přepínání mezi mobilní a desktop verzí
  // Přidáme s delay, aby se nespustil hned po vytvoření topbaru
  setTimeout(() => {
    window.addEventListener('resize', () => {
      const currentIsMobile = window.innerWidth <= 900;
      const topbarExists = document.querySelector('.db-map-topbar');
      
      if (topbarExists) {

        
        // Přepni obsah topbaru
        if (currentIsMobile) {
          // Mobilní verze
          topbar.innerHTML = `
            <button class="db-map-topbar-btn" title="Menu" type="button" id="db-menu-toggle">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <button class="db-map-topbar-btn" title="Vyhledávání" type="button" id="db-search-toggle">
              <svg fill="currentColor" width="20px" height="20px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="m22.241 24-7.414-7.414c-1.559 1.169-3.523 1.875-5.652 1.885h-.002c-.032 0-.07.001-.108.001-5.006 0-9.065-4.058-9.065-9.065 0-.038 0-.076.001-.114v.006c0-5.135 4.163-9.298 9.298-9.298s9.298 4.163 9.298 9.298c-.031 2.129-.733 4.088-1.904 5.682l.019-.027 7.414 7.414zm-12.942-21.487c-3.72.016-6.73 3.035-6.73 6.758 0 3.732 3.025 6.758 6.758 6.758s6.758-3.025 6.758-6.758c0-1.866-.756-3.555-1.979-4.778-1.227-1.223-2.92-1.979-4.79-1.979-.006 0-.012 0-.017 0h.001z"/></svg>
            </button>
            <button class="db-map-topbar-btn" title="Seznam" type="button" id="db-list-toggle">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3" cy="6" r="1"/><circle cx="3" cy="12" r="1"/><circle cx="3" cy="18" r="1"/></svg>
            </button>
            <button class="db-map-topbar-btn" title="Moje poloha" type="button" id="db-locate-btn">
              <svg width="20px" height="20px" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M249.6 417.088l319.744 43.072 39.168 310.272L845.12 178.88 249.6 417.088zm-129.024 47.168a32 32 0 01-7.68-61.44l777.792-311.04a32 32 0 0141.6 41.6l-310.336 775.68a32 32 0 01-61.44-7.808L512 516.992l-391.424-52.736z"/></svg>
            </button>
            <button class="db-map-topbar-btn" title="Filtry" type="button" id="db-filter-btn">
              <svg fill="currentColor" width="20px" height="20px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M4.45,4.66,10,11V21l4-2V11l5.55-6.34A1,1,0,0,0,18.8,3H5.2A1,1,0,0,0,4.45,4.66Z" style="fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2;"></path></svg>
            </button>
            <button class="db-map-topbar-btn" title="Oblíbené" type="button" id="db-favorites-btn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </button>
          `;
        } else {
          // Desktop verze
          topbar.innerHTML = `
            <button class="db-map-topbar-btn" title="Menu" type="button" id="db-menu-toggle">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <form class="db-map-searchbox" style="margin:0;flex:1;min-width:0;">
              <input type="text" id="db-map-search-input" placeholder="Objevujeme víc než jen cíl cesty..." autocomplete="off" style="width:100%;min-width:320px;font-size:clamp(0.8rem, 2.5vw, 1rem);padding:0.6em 0.8em;border:none;border-radius:8px;box-sizing:border-box;background:transparent;outline:none;" />
              <button type="submit" id="db-map-search-btn" tabindex="0" style="background:none;border:none;padding:0;cursor:pointer;outline:none;display:flex;align-items:center;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              </button>
            </form>
            <button class="db-map-topbar-btn" title="Seznam" type="button" id="db-list-toggle">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="3" cy="6" r="1"/><circle cx="3" cy="12" r="1"/><circle cx="3" cy="18" r="1"/></svg>
            </button>
            <button class="db-map-topbar-btn" title="Filtry" type="button" id="db-filter-btn">
              <svg fill="currentColor" width="20px" height="20px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M4.45,4.66,10,11V21l4-2V11l5.55-6.34A1,1,0,0,0,18.8,3H5.2A1,1,0,0,0,4.45,4.66Z" style="fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2;"></path></svg>
            </button>
            <button class="db-map-topbar-btn" title="Oblíbené" type="button" id="db-favorites-btn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            </button>
          `;
        }
      }
    });
  }, 500); // 500ms delay před přidáním resize listeneru

  // Menu toggle - slide-out menu panel (funguje na všech zařízeních)
  function handleMenuToggle(event) {
    event.preventDefault();
    event.stopPropagation();

    let menuPanel = document.querySelector('.db-menu-panel');

    if (!menuPanel) {
      menuPanel = document.createElement('div');
      menuPanel.className = 'db-menu-panel';
      menuPanel.innerHTML = `
          <div class="db-menu-header">
            <div class="db-menu-title">DB mapa</div>
            <button class="db-menu-close" type="button" title="Zavřít menu">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
              </svg>
            </button>
            </div>
          <div class="db-menu-content">
            <div class="db-menu-toggle-section">
              <div class="db-menu-toggle-item">
                <label class="db-menu-toggle-label">
                  <input type="radio" name="map-mode" value="all" class="db-menu-toggle-radio">
                  <span class="db-menu-toggle-text">Zobrazit všechny body</span>
                </label>
              </div>
              <div class="db-menu-toggle-item">
                <label class="db-menu-toggle-label">
                  <input type="radio" name="map-mode" value="radius" class="db-menu-toggle-radio" checked>
                  <span class="db-menu-toggle-text">Radius 75 km</span>
                </label>
              </div>
              <div class="db-menu-toggle-item">
                <label class="db-menu-toggle-label">
                  <input type="checkbox" id="db-show-center-debug" class="db-menu-toggle-checkbox">
                  <span class="db-menu-toggle-text">Zobrazit střed mapy</span>
                </label>
              </div>
            </div>
            </div>
          `;
      document.body.appendChild(menuPanel);
    }

    const closePanel = () => {
      root.classList.remove('db-menu-open');
      const mp = document.querySelector('.db-menu-panel');
      if (mp) {
        mp.style.transform = 'translateX(-100%)';
        mp.style.visibility = 'hidden';
        mp.style.pointerEvents = 'none';
        mp.classList.remove('db-menu-panel--open');
      }
    };

    const closeBtn = menuPanel.querySelector('.db-menu-close');
    if (closeBtn && !closeBtn.dataset.dbListenerAttached) {
      closeBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        closePanel();
      });
      closeBtn.dataset.dbListenerAttached = '1';
    }

    if (!menuPanel.dataset.dbBackdropListenerAttached) {
      menuPanel.addEventListener('click', (e) => {
        if (e.target === menuPanel) {
          closePanel();
        }
      });
      menuPanel.dataset.dbBackdropListenerAttached = '1';
    }

    const modeRadios = menuPanel.querySelectorAll('input[name="map-mode"]');
    modeRadios.forEach(radio => {
      if (radio.dataset.dbListenerAttached) {
        return;
      }
      radio.addEventListener('change', (e) => {
        if (e.target.value === 'all') {
          fetchAndRenderAll();
        } else if (e.target.value === 'radius') {
          const center = map.getCenter();
          fetchAndRenderRadius(center);
        }
      });
      radio.dataset.dbListenerAttached = '1';
    });

    const centerDebugCheckbox = menuPanel.querySelector('#db-show-center-debug');
    if (centerDebugCheckbox && !centerDebugCheckbox.dataset.dbListenerAttached) {
      centerDebugCheckbox.addEventListener('change', (e) => {
        if (e.target.checked) {
          const center = map.getCenter();
          showMapCenterDebug(center);
        } else {
          if (centerDebugMarker) {
            map.removeLayer(centerDebugMarker);
            centerDebugMarker = null;
          }
          if (centerDebugCircle) {
            map.removeLayer(centerDebugCircle);
            centerDebugCircle = null;
          }
        }
      });
      centerDebugCheckbox.dataset.dbListenerAttached = '1';
    }

    root.classList.toggle('db-menu-open');

    const ensureMenuPanelState = () => {
      const mp = document.querySelector('.db-menu-panel');
      if (!mp) return;
      const isOpen = root.classList.contains('db-menu-open');
      if (isOpen) {
        mp.classList.add('db-menu-panel--open');
        mp.style.transform = 'translate3d(0,0,0)';
        mp.style.visibility = 'visible';
        mp.style.pointerEvents = 'auto';
        mp.style.zIndex = '10010';
      } else {
        mp.classList.remove('db-menu-panel--open');
        mp.style.transform = '';
        mp.style.visibility = '';
        mp.style.pointerEvents = '';
        mp.style.zIndex = '';
      }
    };

    ensureMenuPanelState();
    setTimeout(ensureMenuPanelState, 0);

    if (root.classList.contains('db-menu-open')) {
      setTimeout(() => {
        root.classList.remove('db-menu-open');
        ensureMenuPanelState();
        setTimeout(() => {
          root.classList.add('db-menu-open');
          ensureMenuPanelState();

          const panel = document.querySelector('.db-menu-panel');
          if (panel) {
            const computedStyle = window.getComputedStyle(panel);
            if (computedStyle.transform && computedStyle.transform !== 'none' && computedStyle.transform.includes('-')) {
              panel.style.transform = 'translate3d(0,0,0)';
              panel.style.transition = 'transform 0.3s ease';
              panel.classList.add('db-menu-panel--open');
              panel.style.visibility = 'visible';
              panel.style.pointerEvents = 'auto';
              panel.style.zIndex = '10010';
            }
          }
        }, 10);
      }, 50);
    }
  }
  
  // Mobilní přepínač seznamu
  function handleListToggle(event) {
    if (window.innerWidth > 900) {
      return;
    }
    event.preventDefault();
    const willShowList = !root.classList.contains('db-list-mode');
    root.classList.toggle('db-list-mode');
    if (willShowList) {
      ensureUserLocationAndSort();
      ensureListHeader();
    } else {
      try { document.getElementById('db-mobile-sheet')?.classList.remove('open'); } catch(_) {}
    }
    setTimeout(() => map.invalidateSize(), 200);
  }
  // Tlačítko "Moje poloha" - pouze na mobilu
  function handleLocate(event) {
    if (window.innerWidth > 900) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    if (!map) {
      return;
    }

    const isSecure = (window.isSecureContext === true) || (location.protocol === 'https:') || (location.hostname === 'localhost') || (location.hostname === '127.0.0.1');
    if (!isSecure) {
      return;
    }

    const onLocFound = (e) => {
      map.off('locationfound', onLocFound);
      map.off('locationerror', onLocErr);

      const lat = e.latitude || (e.latlng && e.latlng.lat);
      const lng = e.longitude || (e.latlng && e.latlng.lng);

      if (lat && lng) {
        const coords = [lat, lng];
        map.setView(coords, 15, { animate: true, duration: 0.5 });
        searchAddressCoords = coords;
        sortMode = 'distance_from_address';
        searchSortLocked = true;
        renderCards('', null, false);
        addOrMoveSearchAddressMarker(coords);
      }
    };

    const onLocErr = () => {
      map.off('locationfound', onLocFound);
      map.off('locationerror', onLocErr);

      if (window.L && window.L.control) {
        const notification = L.control({position: 'topright'});
        notification.onAdd = function() {
          const div = L.DomUtil.create('div', 'db-geolocation-notification');
          div.innerHTML = '<div style="background: #ff9800; color: white; padding: 8px 12px; border-radius: 4px; font-size: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">📍 Poloha není dostupná</div>';
          return div;
        };
        notification.addTo(map);

        setTimeout(() => {
          if (notification.remove) {
            notification.remove();
          }
        }, 3000);
      }
    };

    map.on('locationfound', onLocFound);
    map.on('locationerror', onLocErr);
    map.locate({ setView: false, enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
  }
  // ===== PANEL FILTRŮ A DALŠÍ FUNKTIONALITA =====
  // Panel filtrů (otevíraný tlačítkem Filtry)
  filterPanel = document.createElement('div');
  filterPanel.id = 'db-map-filter-panel';
  filterPanel.style.cssText = 'position:absolute;right:12px;top:64px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.12);padding:12px;z-index:9999;min-width:240px;max-width:320px;max-height:calc(100vh - 120px);display:none;overflow-y:auto;pointer-events:auto;';
  // Transparentní overlay pro blokování interakce s mapou
  mapOverlay = document.createElement('div');
  mapOverlay.id = 'db-map-overlay';
  mapOverlay.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;background:transparent;z-index:999;display:none;pointer-events:auto;';
  filterPanel.innerHTML = `
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5em;">
      <strong>Filtry</strong>
      <button type="button" id="db-map-filter-close" style="background:none;border:none;cursor:pointer;font-size:18px;line-height:1;">×</button>
    </div>
    <div style="display:flex;gap:.5em;margin-top:.6em;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:.4em;"><input type="checkbox" id="db-filter-dc" checked /> DC</label>
      <label style="display:flex;align-items:center;gap:.4em;"><input type="checkbox" id="db-filter-ac" checked /> AC</label>
    </div>
    <div style="margin-top:.6em;">
      <div style="font-size:.9em;color:#444;margin-bottom:.4em;">Výkon (kW)</div>
      <div style="position:relative;height:40px;margin:10px 0;">
        <div style="position:absolute;top:50%;left:0;right:0;height:4px;background:#e5e7eb;border-radius:2px;transform:translateY(-50%);"></div>
        <div style="position:absolute;top:50%;left:0;right:0;height:4px;background:#FF6A4B;border-radius:2px;transform:translateY(-50%);" id="db-power-range-fill"></div>
        <input type="range" id="db-power-min" min="0" max="400" step="1" value="0" style="position:absolute;top:50%;left:0;width:50%;height:4px;background:transparent;appearance:none;transform:translateY(-50%);z-index:3;pointer-events:auto;" />
        <input type="range" id="db-power-max" min="0" max="400" step="1" value="400" style="position:absolute;top:50%;right:0;width:50%;height:4px;background:transparent;appearance:none;transform:translateY(-50%);z-index:3;pointer-events:auto;" />


      </div>
      <div style="display:flex;justify-content:space-between;font-size:.8em;color:#666;">
        <span id="db-power-min-value">0 kW</span>
        <span id="db-power-max-value">400 kW</span>
      </div>
    </div>

    <div style="margin-top:.8em;">
      <div style="font-size:.9em;color:#444;margin-bottom:.4em;">Typ konektoru</div>
      <div id="db-filter-connector" style="width:100%;max-height:120px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:8px;"></div>
    </div>
    <div style="margin-top:.8em;opacity:.6;">
      <div style="font-size:.9em;color:#444;margin-bottom:.4em;">Amenity v okolí</div>
      <div id="db-filter-amenity" style="width:100%;max-height:120px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:8px;opacity:0.6;" disabled title="Připravujeme"></div>
    </div>
    <div style="margin-top:.8em;opacity:.6;">
      <div style="font-size:.9em;color:#444;margin-bottom:.4em;">Přístup</div>
      <div id="db-filter-access" style="width:100%;max-height:120px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:8px;opacity:0.6;" disabled title="Připravujeme"></div>
    </div>
    <div style="display:flex;gap:.5em;margin-top:.8em;justify-content:space-between;padding-bottom:8px;">
      <button type="button" id="db-filter-reset" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:.4em .8em;cursor:pointer;">Vymazat</button>
      <button type="button" id="db-filter-apply" style="background:#049FE8;color:#fff;border:0;border-radius:8px;padding:.4em .8em;cursor:pointer;">Použít</button>
    </div>
    <label style="display:flex;align-items:center;gap:.5em;margin-top:.6em;">
      <input type="checkbox" id="db-map-toggle-recommended" /> Jen DB doporučuje
    </label>
  `;
  mapDiv.appendChild(filterPanel);
  mapDiv.appendChild(mapOverlay);
  
  // Zabránit posuvání mapy při interakci s filter panelem
  filterPanel.addEventListener('touchstart', function(e) { e.stopPropagation(); }, { passive: true });
  filterPanel.addEventListener('touchmove', function(e) { e.stopPropagation(); }, { passive: true });
  filterPanel.addEventListener('touchend', function(e) { e.stopPropagation(); }, { passive: true });
  filterPanel.addEventListener('mousedown', function(e) { e.stopPropagation(); });
  filterPanel.addEventListener('mousemove', function(e) { e.stopPropagation(); });
  filterPanel.addEventListener('mouseup', function(e) { e.stopPropagation(); });
  
  function handleFilterToggle(event) {
    if (!filterPanel) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    const isVisible = filterPanel.style.display === 'block';
    filterPanel.style.display = isVisible ? 'none' : 'block';

    if (mapOverlay) {
      const newDisplay = isVisible ? 'none' : 'block';
      mapOverlay.style.display = newDisplay;
    }

    if (mapDiv) {
      if (isVisible) {
        mapDiv.style.zIndex = '1';
        mapDiv.classList.remove('filters-open');
      } else {
        mapDiv.style.zIndex = '0';
        mapDiv.classList.add('filters-open');
      }
    }
  }
  const filterClose = filterPanel.querySelector('#db-map-filter-close');
  if (filterClose) filterClose.addEventListener('click', () => {
    filterPanel.style.display = 'none';
    if (mapOverlay) mapOverlay.style.display = 'none';
    if (mapDiv) {
      mapDiv.style.zIndex = '1';
      mapDiv.classList.remove('filters-open');
    }
  });

  // ===== KONEC PANELU FILTRŮ =====

  // Pomocné funkce pro filtry
  function fillOptions(select, values) {
    if (!select) return;
    select.innerHTML = '';
    Array.from(values).sort((a,b)=>String(a).localeCompare(String(b))).forEach(v => {
      if (!v) return;
      const opt = document.createElement('option');
      opt.value = String(v);
      opt.textContent = String(v);
      select.appendChild(opt);
    });
  }
  function fillConnectorIcons(container, values) {
    if (!container) return;
    container.innerHTML = '';
    Array.from(values).sort((a,b)=>String(a).localeCompare(String(b))).forEach(v => {
      if (!v) return;
      const iconDiv = document.createElement('div');
      iconDiv.className = 'db-connector-icon';
      iconDiv.dataset.value = String(v);
      iconDiv.style.cssText = 'display:inline-block;width:32px;height:32px;margin:4px;border:2px solid #e5e7eb;border-radius:6px;cursor:pointer;transition:all 0.2s;background:transparent;';
      iconDiv.title = String(v);
      // Zde by se načetla ikona konektoru z adminu
      iconDiv.innerHTML = `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:12px;color:#666;">${String(v).substring(0,3)}</div>`;
      iconDiv.addEventListener('click', () => {
        iconDiv.classList.toggle('selected');
        if (iconDiv.classList.contains('selected')) {
          iconDiv.style.background = '#FF6A4B';
          iconDiv.style.borderColor = '#FF6A4B';
          iconDiv.style.color = '#fff';
        } else {
          iconDiv.style.background = 'transparent';
          iconDiv.style.borderColor = '#e5e7eb';
          iconDiv.style.color = '#666';
        }
        // Aktualizovat filterState
        filterState.connectors = new Set(Array.from(container.querySelectorAll('.selected')).map(el => el.dataset.value));
        renderCards('', null, false);
      });
      container.appendChild(iconDiv);
    });
  }

  function normalizeConnectorType(str) {
    let s = (str || '').toString().toLowerCase();

    // odstraň diakritiku
    try { s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); } catch (_) {}

    s = s.replace(/\s+/g, ' ').trim();

    // časté přezdívky / zápisy → normalizace
    s = s.replace(/(iec\s*62196[-\s]*)/g, '');
    s = s.replace(/mennekes/g, 'type 2');
    s = s.replace(/type[-_\s]*2|type2/g, 'type 2');
    s = s.replace(/ccs\s*combo\s*2|combo\s*2|ccs\s*2/g, 'ccs2');
    s = s.replace(/gbt|gb\s*\/\s*t/g, 'gb/t');
    s = s.replace(/domaci zasuvka|domaci\s+zasuvka|household|europlug/g, 'domaci zasuvka');
    
    // Google API typy se nepoužívají pro zobrazení konektorů

    return s;
  }

  function getConnectorTypeKey(c) {
    const raw = (c && (c.connector_standard || c.charge_type || c.type || c.typ || c.name || c.slug || '')) + '';
    return normalizeConnectorType(raw);
  }
  function getStationMaxKw(p) {
    const direct = parseFloat(p.max_power_kw || p.maxPowerKw || p.max_kw || p.maxkw || '');
    let maxKw = isFinite(direct) ? direct : 0;
    const arr = Array.isArray(p.connectors) ? p.connectors : (Array.isArray(p.konektory) ? p.konektory : []);
    arr.forEach(c => {
      const pv = parseFloat(c.power_kw || c.power || c.vykon || c.max_power_kw || '');
      if (isFinite(pv)) maxKw = Math.max(maxKw, pv);
    });
    if (!maxKw && typeof p.speed === 'string') {
      const s = p.speed.toLowerCase();
      if (s.includes('dc')) maxKw = 50;
      else if (s.includes('ac')) maxKw = 22;
    }
    return maxKw || 0;
  }
  function populateFilterOptions() {
    
    const connectorSet = new Set();
    let minPower = 0;
    let maxPower = 400;
    
    
    
    features.forEach(f => {
      const p = f.properties || {};
      if (p.post_type === 'charging_location') {
        const arr = Array.isArray(p.connectors) ? p.connectors : (Array.isArray(p.konektory) ? p.konektory : []);
        arr.forEach(c => { const key = getConnectorTypeKey(c); if (key) connectorSet.add(key); });
        
        // Najít min/max výkon pro dynamický rozsah
        const power = getStationMaxKw(p);
        if (power > 0) {
          minPower = Math.min(minPower, power);
          maxPower = Math.max(maxPower, power);
        }
      }
    });
    
    
    
    // Aktualizovat rozsah jezdce podle dat
    updatePowerRange(minPower, maxPower);
    
    const connectorContainer = document.getElementById('db-filter-connector');
    
    
    
    fillConnectorIcons(connectorContainer, connectorSet);
  }
  
  function updatePowerRange(minPower, maxPower) {
    const pMinR = document.getElementById('db-power-min');
    const pMaxR = document.getElementById('db-power-max');
    const pMinValue = document.getElementById('db-power-min-value');
    const pMaxValue = document.getElementById('db-power-max-value');
    
    if (pMinR && pMaxR) {
      // Nastavit min/max hodnoty jezdce
      pMinR.min = Math.floor(minPower);
      pMaxR.min = Math.floor(minPower);
      pMinR.max = Math.ceil(maxPower);
      pMaxR.max = Math.ceil(maxPower);
      
      // Nastavit výchozí hodnoty
      pMinR.value = Math.floor(minPower);
      pMaxR.value = Math.ceil(maxPower);
      
      // Aktualizovat filterState
      filterState.powerMin = Math.floor(minPower);
      filterState.powerMax = Math.ceil(maxPower);
      
      // Aktualizovat zobrazení
      if (pMinValue) pMinValue.textContent = `${Math.floor(minPower)} kW`;
      if (pMaxValue) pMaxValue.textContent = `${Math.ceil(maxPower)} kW`;
      
      // Aktualizovat vizuální vyplnění
      const pRangeFill = document.getElementById('db-power-range-fill');
      if (pRangeFill) {
        pRangeFill.style.left = '0%';
        pRangeFill.style.width = '100%';
      }
    }
  }
  function readMulti(selectId) {
    const el = document.getElementById(selectId);
    if (!el) return new Set();
    const s = new Set();
    Array.from(el.selectedOptions || []).forEach(o => s.add(o.value));
    return s;
  }
  function attachFilterHandlers() {
    const acEl = document.getElementById('db-filter-ac');
    const dcEl = document.getElementById('db-filter-dc');
    const pMinR = document.getElementById('db-power-min');
    const pMaxR = document.getElementById('db-power-max');
    const pMinValue = document.getElementById('db-power-min-value');
    const pMaxValue = document.getElementById('db-power-max-value');
    const pRangeFill = document.getElementById('db-power-range-fill');
    const resetBtn = document.getElementById('db-filter-reset');
    const applyBtn = document.getElementById('db-filter-apply');

    // Jezdec s vizuálním vyplněním
    function updatePowerSlider() {
      const minVal = parseInt(pMinR.value || '0', 10);
      const maxVal = parseInt(pMaxR.value || '400', 10);
      
      // Aktualizovat vizuální vyplnění jezdce
      if (pRangeFill) {
        const minPercent = (minVal / 400) * 100;
        const maxPercent = (maxVal / 400) * 100;
        pRangeFill.style.left = `${minPercent}%`;
        pRangeFill.style.width = `${maxPercent - minPercent}%`;
      }
      
      // Aktualizovat hodnoty
      if (pMinValue) pMinValue.textContent = `${minVal} kW`;
      if (pMaxValue) pMaxValue.textContent = `${maxVal} kW`;
      
      // Aktualizovat filterState
      filterState.powerMin = minVal;
      filterState.powerMax = maxVal;
      
      // Překreslit karty
      if (typeof renderCards === 'function') {
        renderCards('', null, false);
      }
    }

    if (acEl) acEl.addEventListener('change', () => { 
      filterState.ac = !!acEl.checked; 
      if (typeof renderCards === 'function') {
        renderCards('', null, false); 
      }
    });
    if (dcEl) dcEl.addEventListener('change', () => { 
      filterState.dc = !!dcEl.checked; 
      if (typeof renderCards === 'function') {
        renderCards('', null, false); 
      }
    });
    
    if (pMinR) pMinR.addEventListener('input', updatePowerSlider);
    if (pMaxR) pMaxR.addEventListener('input', updatePowerSlider);
    
    // Zabránit posuvání mapy při používání posuvníků
    if (pMinR) {
      pMinR.addEventListener('touchstart', function(e) { e.stopPropagation(); }, { passive: true });
      pMinR.addEventListener('touchmove', function(e) { e.stopPropagation(); }, { passive: true });
      pMinR.addEventListener('touchend', function(e) { e.stopPropagation(); }, { passive: true });
      pMinR.addEventListener('mousedown', function(e) { e.stopPropagation(); });
      pMinR.addEventListener('mousemove', function(e) { e.stopPropagation(); });
      pMinR.addEventListener('mouseup', function(e) { e.stopPropagation(); });
    }
    
    if (pMaxR) {
      pMaxR.addEventListener('touchstart', function(e) { e.stopPropagation(); }, { passive: true });
      pMaxR.addEventListener('touchmove', function(e) { e.stopPropagation(); }, { passive: true });
      pMaxR.addEventListener('touchend', function(e) { e.stopPropagation(); }, { passive: true });
      pMaxR.addEventListener('mousedown', function(e) { e.stopPropagation(); });
      pMaxR.addEventListener('mousemove', function(e) { e.stopPropagation(); });
      pMaxR.addEventListener('mouseup', function(e) { e.stopPropagation(); });
    }
    
    if (resetBtn) resetBtn.addEventListener('click', () => {
      filterState.ac = true; filterState.dc = true;
      filterState.powerMin = 0; filterState.powerMax = 400;
      filterState.connectors = new Set();
      
      if (acEl) acEl.checked = true; 
      if (dcEl) dcEl.checked = true;
      
      if (pMinR && pMaxR) { 
        pMinR.value = '0'; 
        pMaxR.value = '400'; 
        updatePowerSlider();
      }
      
      // Resetovat connector ikony
      const connectorContainer = document.getElementById('db-filter-connector');
      if (connectorContainer) {
        Array.from(connectorContainer.querySelectorAll('.db-connector-icon')).forEach(el => {
          el.classList.remove('selected');
          el.style.background = 'transparent';
          el.style.borderColor = '#e5e7eb';
          el.style.color = '#666';
        });
      }
      
      if (typeof renderCards === 'function') {
        renderCards('', null, false);
      }
    });
    
    if (applyBtn) applyBtn.addEventListener('click', () => { 
      if (typeof renderCards === 'function') {
        renderCards('', null, false); 
      }
    });
    

    
    // Inicializace jezdce
    if (pMinR && pMaxR) {
      updatePowerSlider();
    }
    

  }

  // Mobilní bottom sheet pro detail - nový design jako plovoucí karta
  let mobileSheet = document.getElementById('db-mobile-sheet');
  if (!mobileSheet) {
    mobileSheet = document.createElement('div');
    mobileSheet.id = 'db-mobile-sheet';
    mobileSheet.setAttribute('data-db-feedback', 'map.mobile_sheet');
    mobileSheet.innerHTML = '<div class="sheet-content"></div>';
    mapDiv.appendChild(mobileSheet);
  }
  const sheetContentEl = mobileSheet.querySelector('.sheet-content');
  
  const closeMobileSheet = () => {
    mobileSheet.classList.remove('open');
  };
  
  // Event listener pro zavření sheetu při kliknutí mimo
  document.addEventListener('click', (e) => {
    if (mobileSheet.classList.contains('open') && 
        !mobileSheet.contains(e.target) && 
        !e.target.closest('[data-db-action="open-mobile-sheet"]')) {
      closeMobileSheet();
    }
  });
  
  // Generování sekce konektorů pro mobile sheet
  function generateMobileConnectorsSection(p) {
    // Použít konektory z původních mapových dat - nezávisle na cache
    const mapConnectors = Array.isArray(p.connectors) ? p.connectors : (Array.isArray(p.konektory) ? p.konektory : []);
    const dbConnectors = Array.isArray(p.db_connectors) ? p.db_connectors : [];
    
    // Preferovat db_connectors z REST API (obsahuje power), pak mapConnectors jako fallback
    let connectors = [];
    if (dbConnectors.length > 0) {
      connectors = dbConnectors;
    } else if (mapConnectors.length > 0) {
      connectors = mapConnectors;
    }
    
    if (!connectors || connectors.length === 0) {
      return '';
    }
    
    // Seskupit konektory podle typu a spočítat
    const connectorCounts = {};
    connectors.forEach(c => {
      const typeKey = getConnectorTypeKey(c);
      if (typeKey) {
        const power = c.power || c.connector_power_kw || c.power_kw || c.vykon || '';
        const quantity = parseInt(c.quantity || c.count || c.connector_count || 1);
        
        if (!connectorCounts[typeKey]) {
          connectorCounts[typeKey] = { count: 0, power: power };
        }
        connectorCounts[typeKey].count += quantity;
      }
    });
    
        // Vytvořit zjednodušené HTML pro mobile sheet header
        const connectorItems = Object.entries(connectorCounts).map(([typeKey, info]) => {
          const connector = connectors.find(c => {
            const cType = getConnectorTypeKey(c);
            return cType === typeKey;
          });
          
          const iconUrl = connector ? getConnectorIconUrl(connector) : null;
          const powerText = info.power ? `${info.power} kW` : '';
          
          // Zkontrolovat live dostupnost z API
          let availabilityText = info.count;
          let isOutOfService = false;
          
          // Zkontrolovat stav "mimo provoz" z Google API
          if (p.business_status === 'CLOSED_TEMPORARILY' || p.business_status === 'CLOSED_PERMANENTLY') {
            isOutOfService = true;
          }
          
          // Zobrazit pouze počet konektorů z databáze - bez dostupnosti z Google API
          if (isOutOfService) {
            availabilityText = 'MIMO PROVOZ';
          } else {
            // Zobrazit pouze celkový počet z databáze
            availabilityText = info.count.toString();
          }
          
          // Zjednodušený styl bez pozadí
          const textStyle = isOutOfService 
            ? 'font-weight: 600; color: #c33; font-size: 0.75em;'
            : 'font-weight: 600; color: #333; font-size: 0.75em;';
          
          if (iconUrl) {
            return `<div style="display: inline-flex; flex-direction: column; align-items: center; gap: 2px; margin: 0 4px 0 0;">
              <div style="display: flex; align-items: center; gap: 3px;">
                <img src="${iconUrl}" style="width: 14px; height: 14px; object-fit: contain;" alt="${typeKey}">
                <span style="${textStyle}">${availabilityText}</span>
              </div>
              ${powerText ? `<span style="color: #666; font-size: 0.7em;">${powerText}</span>` : ''}
            </div>`;
          } else {
            return `<div style="display: inline-flex; flex-direction: column; align-items: center; gap: 2px; margin: 0 4px 0 0;">
              <span style="${textStyle}">${typeKey.toUpperCase()}: ${availabilityText}</span>
              ${powerText ? `<span style="color: #666; font-size: 0.7em;">${powerText}</span>` : ''}
            </div>`;
          }
        }).join('');
        
        if (connectorItems) {
          return `
            <div class="sheet-connectors" style="margin-top: 4px;">
              <div style="display: flex; flex-wrap: wrap; gap: 2px;">
                ${connectorItems}
              </div>
            </div>
          `;
        }
    
    return '';
  }

  function openMobileSheet(feature) {
    if (window.innerWidth > 900) return;
    
    const p = feature.properties || {};
    const coords = feature.geometry && feature.geometry.coordinates ? feature.geometry.coordinates : null;
    const lat = coords ? coords[1] : null;
    const lng = coords ? coords[0] : null;
    
  // Získat barvu čtverečku podle typu místa (stejně jako piny na mapě)
  const getSquareColor = (props) => {
    if (props.post_type === 'charging_location') {
      // Pro nabíječky použít stejnou logiku jako piny
      const mode = getChargerMode(props);
      const acColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.ac) || '#049FE8';
      const dcColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.dc) || '#FFACC4';
      if (mode === 'hybrid') {
        return `linear-gradient(135deg, ${acColor} 0%, ${acColor} 30%, ${dcColor} 70%, ${dcColor} 100%)`;
      }
      return mode === 'dc' ? dcColor : acColor;
    } else if (props.post_type === 'rv_spot') {
      return '#FCE67D'; // Žlutá pro RV místa
    } else if (props.post_type === 'poi') {
      // Pozadí u POI dědí centrální barvu pinu
      return props.icon_color || '#FCE67D';
    }
    return '#049FE8'; // Modrá jako fallback
  };

  // Získat originální ikonu pro typ bodu
  const getTypeIcon = (props) => {
    if (props.svg_content) {
      // Pro POI použít SVG obsah
      return props.svg_content;
    } else if (props.icon_slug) {
      // Pro ostatní typy použít icon_slug
      const iconUrl = getIconUrl(props.icon_slug);
      return iconUrl ? `<img src="${iconUrl}" style="width:100%;height:100%;object-fit:contain;" alt="">` : '📍';
    } else if (props.post_type === 'charging_location') {
      // Fallback pro nabíječky
      return '🔌';
    } else if (props.post_type === 'rv_spot') {
      // Fallback pro RV
      return '🚐';
    }
    return '📍';
  };
    
    // Nový obsah s kompaktním designem
    const finalHTML = `
      <div class="sheet-header">
        <div class="sheet-icon" style="background: ${getSquareColor(p)}; width: 48px; height: 48px;">
          ${getTypeIcon(p)}
        </div>
        <div class="sheet-content-wrapper">
          <div class="sheet-title">${p.title || ''}</div>
          ${p.post_type === 'charging_location' ? generateMobileConnectorsSection(p) : ''}
        </div>
      </div>
      
      <div class="sheet-actions-row">
        <button class="btn-icon" type="button" data-db-action="open-navigation" title="Navigace">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="12 2 19 21 12 17 5 21 12 2"/>
            <line x1="12" y1="17" x2="12" y2="22"/>
          </svg>
        </button>
        <button class="btn-icon" type="button" data-db-action="lock-isochrones" data-feature-id="${p.id}" title="Zamknout isochrony">
          <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <style>
                .c{stroke:#049FE8;}
                .c,.d,.e,.f{fill:none;stroke-linecap:round;stroke-linejoin:round;}
                .d,.e,.f{stroke:#FF6A4B;}
                .e{stroke-dasharray:0 0 3 3;}
                .f{stroke-dasharray:0 0 3.34 3.34;}
              </style>
            </defs>
            <g>
              <g>
                <polyline class="d" points="7.99 19.5 2.5 19.5 2.5 16.5"/>
                <line class="e" x1="2.5" x2="2.5" y1="13.5" y2="9"/>
                <polyline class="d" points="2.5 7.5 2.5 4.5 5.5 4.5"/>
                <line class="f" x1="8.83" x2="13.84" y1="4.5" y2="4.5"/>
                <polyline class="d" points="15.5 4.5 18.5 4.5 18.5 7"/>
              </g>
              <path class="c" d="M18.8,13.39c0,2.98-2.42,5.4-5.4,5.4s-5.4-2.42-5.4-5.4,2.42-5.4,5.4-5.4,5.4,2.42,5.4,5.4Z"/>
              <line class="c" x1="17.35" x2="21.5" y1="17.31" y2="21.5"/>
            </g>
          </svg>
        </button>
        <button class="btn-icon" type="button" data-db-action="open-detail" title="Detail">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="16" x2="12" y2="12"/>
            <line x1="12" y1="8" x2="12" y2="8"/>
          </svg>
        </button>
        
        <div class="sheet-nearby">
          <div class="sheet-nearby-list" data-feature-id="${p.id}">
            <div style="text-align: center; padding: 8px; color: #049FE8; font-size: 0.8em;">
              <div style="font-size: 16px; margin-bottom: 4px;">⏳</div>
              <div>Načítání...</div>
            </div>
          </div>
        </div>
      </div>
    `;
    
    sheetContentEl.innerHTML = finalHTML;
    mobileSheet.classList.add('open');
    
    // Event listener pro navigační tlačítko
    const navBtn = mobileSheet.querySelector('[data-db-action="open-navigation"]');
    if (navBtn) navBtn.addEventListener('click', () => openNavigationMenu(lat, lng));

    const lockBtn = mobileSheet.querySelector('[data-db-action="lock-isochrones"]');
    if (lockBtn) {
      lockBtn.addEventListener('click', () => handleIsochronesLockButtonClick(p.id));
      updateIsochronesLockButtons(p.id);
    }

    const detailBtn = mobileSheet.querySelector('[data-db-action="open-detail"]');
    if (detailBtn) detailBtn.addEventListener('click', () => openDetailModal(feature));

    // Otevřít sheet
    requestAnimationFrame(() => mobileSheet.classList.add('open'));
    
    // Centrovat bod na mapu
    if (lat !== null && lng !== null) {
      map.setView([lat, lng], map.getZoom(), { animate: true, duration: 0.5 });
    }
    
    // Načíst nearby data pro mobilní sheet
    setTimeout(() => {
      const nearbyContainer = mobileSheet.querySelector('.sheet-nearby-list');
      if (nearbyContainer) {
        loadNearbyForMobileSheet(nearbyContainer, p.id, lat, lng);
      }
    }, 100);
    
    // Také načíst nearby data pro desktop verzi (pokud je dostupná)
    setTimeout(() => {
      loadAndRenderNearby(feature);
    }, 200);
  }
  // Funkce pro načítání nearby dat pro mobile sheet (3 nejbližší body)
  async function loadNearbyForMobileSheet(containerEl, centerId, centerLat, centerLng) {
    if (!containerEl || !centerId) return;
    
    // Najít feature podle ID
    const feature = features.find(f => f.properties.id == centerId);
    if (!feature) {
      containerEl.innerHTML = `
        <div style="text-align: center; padding: 8px; color: #FF8DAA; font-size: 0.8em;">
          <div style="font-size: 16px; margin-bottom: 4px;">⚠️</div>
          <div>Chyba při načítání</div>
        </div>
      `;
      return;
    }
    
    const p = feature.properties || {};
    const type = (p.post_type === 'charging_location') ? 'charging_location' : 'poi';
    
    // Zkontrolovat, zda má bod nearby data
    const hasNearbyData = await checkNearbyDataAvailable(centerId, type);
    
    if (!hasNearbyData) {
      containerEl.innerHTML = '<div style="text-align:center;padding:10px;color:#999;">Blízká místa nejsou k dispozici</div>';
      return;
    }
    // Pokus o načtení s retry logikou (stejně jako původní loadNearbyForCard)
    let attempts = 0;
    const maxAttempts = 3;
    
    const tryLoad = async () => {
      const data = await fetchNearby(centerId, type, 3);
      
      if (Array.isArray(data.items) && data.items.length > 0) {
        // Zobrazit 3 nejbližší v novém formátu
        const items = data.items.slice(0, 3);
        const nearbyItems = items.map(item => {
          const distKm = ((item.distance_m || 0) / 1000).toFixed(1);
          const mins = Math.round((item.duration_s || 0) / 60);
          
          // Získat originální ikonu podle typu místa
          const getItemIcon = (props) => {
            if (props.svg_content) {
              // Pro POI použít SVG obsah
              return props.svg_content;
            } else if (props.icon_slug) {
              // Pro ostatní typy použít icon_slug
              const iconUrl = getIconUrl(props.icon_slug);
              return iconUrl ? `<img src="${iconUrl}" style="width:100%;height:100%;object-fit:contain;" alt="">` : '📍';
            } else if (props.post_type === 'charging_location') {
              // Fallback pro nabíječky
              return getChargerColoredSvg() || '⚡';
            } else if (props.post_type === 'rv_spot') {
              // Fallback pro RV
              return '🏕️';
            }
            return '📍';
          };
          
          // Získat barvu čtverečku pro blízké body
          const getNearbySquareColor = (props) => {
            if (props.post_type === 'charging_location') {
              const mode = getChargerMode(props);
              const acColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.ac) || '#049FE8';
              const dcColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.dc) || '#FFACC4';
              if (mode === 'hybrid') {
                return `linear-gradient(135deg, ${acColor} 0%, ${acColor} 30%, ${dcColor} 70%, ${dcColor} 100%)`;
              }
              return mode === 'dc' ? dcColor : acColor;
            } else if (props.post_type === 'rv_spot') {
              return '#FCE67D';
            } else if (props.post_type === 'poi') {
              return item.icon_color || '#FCE67D';
            }
            return '#049FE8';
          };

          return `
            <div class="nearby-item" data-id="${item.id}" onclick="const target=featureCache.get(${item.id});if(target){highlightMarkerById(${item.id});map.setView([target.geometry.coordinates[1],target.geometry.coordinates[0]],15,{animate:true});sortMode='distance-active';renderCards('',${item.id});if(window.innerWidth <= 900){openMobileSheet(target);}else{openDetailModal(target);}}">
              <div class="nearby-item-icon" style="background: ${getNearbySquareColor(item)};">
                ${getItemIcon(item)}
              </div>
              <div class="nearby-item-info">
                <div class="nearby-item-distance">${distKm} km</div>
                <div class="nearby-item-time">${mins} min</div>
              </div>
            </div>
          `;
        }).join('');
        
        containerEl.innerHTML = nearbyItems;
        return;
      }
      
      // Pokud nemáme data, ale běží recompute, zkus znovu
      if ((data.running || data.partial || data.stale) && attempts < maxAttempts) {
        attempts++;
        containerEl.innerHTML = `
          <div style="text-align: center; padding: 8px; color: #049FE8; font-size: 0.8em;">
            <div style="font-size: 16px; margin-bottom: 4px;">⏳</div>
            <div>Načítání... (${attempts}/${maxAttempts})</div>
          </div>
        `;
        setTimeout(tryLoad, 2000);
        return;
      }
      
      // Fallback: zobrazit prázdný stav
      containerEl.innerHTML = `
        <div style="text-align: center; padding: 8px; color: #049FE8; font-size: 0.8em;">
          <div style="font-size: 16px; margin-bottom: 4px;">🔍</div>
          <div>Žádná blízká místa</div>
        </div>
      `;
    };
    
    tryLoad();
  }

  async function enrichPOIFeature(feature) {
    if (!feature || !feature.properties || feature.properties.post_type !== 'poi') {
      return feature;
    }

    const props = feature.properties;
    try { console.debug('[DB Map][POI enrich] start', { id: props.id, title: props.title, providerPref: props.poi_primary_external_source, google_place_id: props.poi_google_place_id, ta_id: props.poi_tripadvisor_location_id }); } catch(_) {}
    if (!props.poi_external_expires_at && props.poi_external_cached_until) {
      try {
        const providerKey = props.poi_external_provider || props.poi_primary_external_source || 'google_places';
        const expires = props.poi_external_cached_until[providerKey];
        if (expires) {
          props.poi_external_expires_at = expires;
        }
      } catch (_) {}
    }
    try {
      // Cache-first: přeskočit jen pokud máme klíčová data (web, fotky, otevírací doba)
      if (props.poi_external_expires_at) {
        const expires = new Date(props.poi_external_expires_at).getTime();
        const missingHours = !props.poi_opening_hours;
        const missingWebsite = !props.poi_website;
        const missingPhotos = !(Array.isArray(props.poi_photos) && props.poi_photos.length > 0);
        const shouldSkip = expires && Date.now() < (expires - 5000) && !(missingHours || missingWebsite || missingPhotos);
        try { console.debug('[DB Map][POI enrich] cache state', { id: props.id, expiresAt: props.poi_external_expires_at, missingHours, missingWebsite, missingPhotos, shouldSkip }); } catch(_) {}
        if (shouldSkip) {
          return feature;
        }
      }

      const restBase = (dbMapData?.poiExternalUrl || '/wp-json/db/v1/poi-external').replace(/\/$/, '');
      const nonce = dbMapData?.restNonce || '';
      try { console.debug('[DB Map][POI enrich] fetching', { url: `${restBase}/${props.id}`, hasNonce: !!nonce }); } catch(_) {}
      const response = await fetch(`${restBase}/${props.id}`, {
        headers: {
          'X-WP-Nonce': nonce,
          'Content-Type': 'application/json'
        }
      });

      if (!response.ok) {
        try { console.warn('[DB Map][POI enrich] poi-external failed', response.status); } catch(_) {}
        return feature;
      }

      const payload = await response.json();
      try { console.debug('[DB Map][POI enrich] payload', { id: props.id, provider: payload?.provider, hasData: !!payload?.data, status: payload?.status }); } catch(_) {}
      // Obsluha stavů bez dat
      if (!payload || !payload.data) {
        if (payload && payload.status === 'review_required') {
          props.poi_status = 'review_required';
          props.poi_status_message = 'Podrobnosti čekají na potvrzení administrátorem.';
        } else if (payload && payload.status === 'quota_blocked') {
          props.poi_status = 'quota_blocked';
          props.poi_status_message = 'Podrobnosti jsou dočasně nedostupné (limit API). Zkuste to později.';
        }
        return feature;
      }

      const enriched = { ...feature, properties: { ...props } };
      const enrichedProps = enriched.properties;
      const data = payload.data || {};

      if (data.phone) enrichedProps.poi_phone = data.phone;
      if (data.internationalPhone) enrichedProps.poi_international_phone = data.internationalPhone;
      if (data.address) enrichedProps.poi_address = data.address;
      if (data.website) enrichedProps.poi_website = data.website;
      if (typeof data.rating !== 'undefined' && data.rating !== null) enrichedProps.poi_rating = data.rating;
      if (typeof data.userRatingCount !== 'undefined' && data.userRatingCount !== null) enrichedProps.poi_user_rating_count = data.userRatingCount;
      if (data.priceLevel) enrichedProps.poi_price_level = data.priceLevel;
      if (data.mapUrl) enrichedProps.poi_url = data.mapUrl;
      if (data.openingHours) {
        let oh = data.openingHours;
        // Normalizace na weekdayDescriptions
        if (oh && typeof oh === 'object' && !oh.weekdayDescriptions && Array.isArray(oh.weekday_text)) {
          oh = { weekdayDescriptions: oh.weekday_text };
        }
        enrichedProps.poi_opening_hours = typeof oh === 'string' ? oh : JSON.stringify(oh);
        try { console.debug('[DB Map][POI enrich] openingHours set', { id: enrichedProps.id, oh: enrichedProps.poi_opening_hours }); } catch(_) {}
      } else {
        try { console.debug('[DB Map][POI enrich] openingHours missing', { id: enrichedProps.id }); } catch(_) {}
      }
      // Základní služby/nabídka
      if (typeof data.dineIn !== 'undefined') enrichedProps.poi_dine_in = !!data.dineIn;
      if (typeof data.takeout !== 'undefined') enrichedProps.poi_takeout = !!data.takeout;
      if (typeof data.delivery !== 'undefined') enrichedProps.poi_delivery = !!data.delivery;
      if (typeof data.servesBeer !== 'undefined') enrichedProps.poi_serves_beer = !!data.servesBeer;
      if (typeof data.servesWine !== 'undefined') enrichedProps.poi_serves_wine = !!data.servesWine;
      if (typeof data.servesBreakfast !== 'undefined') enrichedProps.poi_serves_breakfast = !!data.servesBreakfast;
      if (typeof data.servesLunch !== 'undefined') enrichedProps.poi_serves_lunch = !!data.servesLunch;
      if (typeof data.servesDinner !== 'undefined') enrichedProps.poi_serves_dinner = !!data.servesDinner;
      if (typeof data.wheelchairAccessibleEntrance !== 'undefined') enrichedProps.poi_wheelchair = !!data.wheelchairAccessibleEntrance;
      // Preferovat fallback metadata, pak standardní fotky
      if (payload.data?.fallback_metadata && payload.data.fallback_metadata.photos) {
        enrichedProps.poi_photos = payload.data.fallback_metadata.photos;
        if (!enrichedProps.image && payload.data.fallback_metadata.photos[0]) {
          const firstPhoto = payload.data.fallback_metadata.photos[0];
          if (firstPhoto.street_view_url) {
            enrichedProps.image = firstPhoto.street_view_url;
          }
        }
      } else if (Array.isArray(payload.data?.photos) && payload.data.photos.length) {
        enrichedProps.poi_photos = payload.data.photos;
        if (!enrichedProps.image) {
          const firstPhoto = payload.data.photos[0];
          if (firstPhoto && typeof firstPhoto === 'object') {
            if (firstPhoto.url) {
              enrichedProps.image = firstPhoto.url;
            } else if ((firstPhoto.photo_reference || firstPhoto.photoReference) && dbMapData?.googleApiKey) {
              const ref = firstPhoto.photo_reference || firstPhoto.photoReference;
              if (ref === 'streetview' && firstPhoto.street_view_url) {
                // Street View obrázek
                enrichedProps.image = firstPhoto.street_view_url;
              } else if (ref !== 'streetview') {
                // Google Places foto
                enrichedProps.image = `https://maps.googleapis.com/maps/api/place/photo?maxwidth=800&photo_reference=${ref}&key=${dbMapData.googleApiKey}`;
              }
            }
          } else if (typeof firstPhoto === 'string') {
            enrichedProps.image = firstPhoto;
          }
        }
      }
      // Preferuj přímo vygenerovanou photoUrl z backendu (bez nutnosti klíče na FE)
      if (!enrichedProps.image && data.photoUrl) {
        enrichedProps.image = data.photoUrl;
      }
      if (data.socialLinks && typeof data.socialLinks === 'object') {
        enrichedProps.poi_social_links = data.socialLinks;
      }

      enrichedProps.poi_external_provider = payload.provider || enrichedProps.poi_external_provider || null;
      if (payload.expiresAt) {
        enrichedProps.poi_external_expires_at = payload.expiresAt;
      }

      try { console.debug('[DB Map][POI enrich] enriched props applied', { id: enrichedProps.id, hasPhotos: Array.isArray(enrichedProps.poi_photos) && enrichedProps.poi_photos.length > 0, hasWebsite: !!enrichedProps.poi_website }); } catch(_) {}
      return enriched;
    } catch (error) {
      try { console.error('[DB Map][POI enrich] chyba', error); } catch(_) {}
      return feature;
    }
  }

  async function enrichChargingFeature(feature) {
    if (!feature || !feature.properties || feature.properties.post_type !== 'charging_location') {
      return feature;
    }

    const props = feature.properties;
    try { console.debug('[DB Map][Charging enrich] start', { id: props.id, title: props.title, hasGoogleDetails: !!props.charging_google_details, hasOcmDetails: !!props.charging_ocm_details }); } catch (_) {}

    const hasFreshLive = props.charging_live_expires_at && Date.parse(props.charging_live_expires_at) > Date.now();
    const hasMeta = !!(props.charging_google_details || props.charging_ocm_details);
    const hasDbConnectors = !!(props.db_connectors && props.db_connectors.length > 0);
    
    // Volat REST endpoint pokud nemáme fresh live data, metadata, nebo db_connectors
    if (hasFreshLive && hasMeta && hasDbConnectors) {
      return feature;
    }

    const restBase = (dbMapData?.chargingExternalUrl || '/wp-json/db/v1/charging-external').replace(/\/$/, '');
    const nonce = dbMapData?.restNonce || '';
    try { console.debug('[DB Map][Charging enrich] fetching', { url: `${restBase}/${props.id}`, hasNonce: !!nonce }); } catch (_) {}
    const response = await fetch(`${restBase}/${props.id}`, {
      headers: {
        'X-WP-Nonce': nonce,
        'Content-Type': 'application/json'
      }
    });

    if (!response.ok) {
      try { console.warn('[DB Map][Charging enrich] failed', response.status); } catch (_) {}
      return feature;
    }

    const payload = await response.json();
    const enriched = { ...feature, properties: { ...props } };
    const enrichedProps = enriched.properties;
    const metadata = payload?.metadata || {};

    if (metadata.google) {
      enrichedProps.charging_google_details = metadata.google;
      if (!enrichedProps.image && metadata.google.photos && metadata.google.photos.length > 0) {
        const first = metadata.google.photos[0];
        if (first.url) {
          enrichedProps.image = first.url;
        } else if (first.photo_reference === 'streetview' && first.street_view_url) {
          // Street View obrázek
          enrichedProps.image = first.street_view_url;
        } else if (first.photo_reference && first.photo_reference !== 'streetview' && dbMapData?.googleApiKey) {
          // Nové Google Places API v1 foto
          if (first.photo_reference.startsWith('places/')) {
            // Nové API v1 formát
            enrichedProps.image = `https://places.googleapis.com/v1/${first.photo_reference}/media?maxWidthPx=1200&key=${dbMapData.googleApiKey}`;
          } else if (first.photo_reference !== 'streetview') {
            // Staré API formát (fallback)
            enrichedProps.image = `https://maps.googleapis.com/maps/api/place/photo?maxwidth=1200&photo_reference=${first.photo_reference}&key=${dbMapData.googleApiKey}`;
          }
        }
      }
      // Přidat fallback metadata (Street View pro nabíječky ve frontě)
      if (payload.data?.fallback_metadata && payload.data.fallback_metadata.photos) {
        enrichedProps.poi_photos = payload.data.fallback_metadata.photos;
        if (!enrichedProps.image && payload.data.fallback_metadata.photos[0]) {
          const firstPhoto = payload.data.fallback_metadata.photos[0];
          if (firstPhoto.street_view_url) {
            enrichedProps.image = firstPhoto.street_view_url;
          }
        }
      }
      
      if (metadata.google.photos) {
        enrichedProps.poi_photos = (metadata.google.photos || []).map((photo) => {
          if (photo.url) return photo;
          if (photo.photo_reference === 'streetview' && photo.street_view_url) {
            return {
              url: photo.street_view_url,
              source: 'street_view'
            };
          }
          if ((photo.photo_reference || photo.photoReference) && photo.photo_reference !== 'streetview' && dbMapData?.googleApiKey) {
            const ref = photo.photo_reference || photo.photoReference;
            let url;
            if (ref.startsWith('places/')) {
              // Nové API v1 formát
              url = `https://places.googleapis.com/v1/${ref}/media?maxWidthPx=1200&key=${dbMapData.googleApiKey}`;
            } else if (ref !== 'streetview') {
              // Staré API formát (fallback)
              url = `https://maps.googleapis.com/maps/api/place/photo?maxwidth=1200&photo_reference=${ref}&key=${dbMapData.googleApiKey}`;
            }
            return {
              url: url,
              source: 'google_places'
            };
          }
          return photo;
        });
      }
      if (metadata.google.formatted_address && !enrichedProps.address) {
        enrichedProps.address = metadata.google.formatted_address;
      }
    }

    if (metadata.open_charge_map) {
      enrichedProps.charging_ocm_details = metadata.open_charge_map;
      if ((!Array.isArray(enrichedProps.connectors) || !enrichedProps.connectors.length) && Array.isArray(metadata.open_charge_map.connectors)) {
        enrichedProps.connectors = metadata.open_charge_map.connectors;
      }
      if (!enrichedProps.station_max_power_kw && Array.isArray(metadata.open_charge_map.connectors)) {
        const max = metadata.open_charge_map.connectors.reduce((acc, conn) => Math.max(acc, conn.power_kw || conn.powerKw || 0), 0);
        if (max > 0) enrichedProps.station_max_power_kw = max;
      }
      if (!enrichedProps.evse_count && metadata.open_charge_map.status_summary && typeof metadata.open_charge_map.status_summary.total !== 'undefined') {
        enrichedProps.evse_count = metadata.open_charge_map.status_summary.total;
      }
    }

    const live = payload?.live_status || null;
    if (live) {
      if (typeof live.available === 'number') enrichedProps.charging_live_available = live.available;
      if (typeof live.total === 'number') enrichedProps.charging_live_total = live.total;
      if (live.source) enrichedProps.charging_live_source = live.source;
      if (live.updated_at) enrichedProps.charging_live_updated_at = live.updated_at;
      enrichedProps.charging_live_expires_at = new Date(Date.now() + 90 * 1000).toISOString();
    }
    
    // Přidat konektory z databáze
    if (payload?.data?.db_connectors) {
      enrichedProps.db_connectors = payload.data.db_connectors;
      
      // Převést db_connectors na connectors pro frontend kompatibilitu
      if (!enrichedProps.connectors || !enrichedProps.connectors.length) {
        enrichedProps.connectors = payload.data.db_connectors.map(conn => ({
          type: conn.type,
          count: conn.count,
          power: conn.power,
          quantity: conn.count,
          power_kw: conn.power,
          connector_power_kw: conn.power,
          source: 'database'
        }));
      }
    }
    
    // Google konektory se nepoužívají - pouze pro porovnání počtů a fotky
    
    // Přidat flag o dostupnosti live dat
    if (payload?.data?.charging_live_data_available !== undefined) {
      enrichedProps.charging_live_data_available = payload.data.charging_live_data_available;
    }
    
    // Zpracovat fallback metadata (Street View) i když není Google metadata
    if (payload?.data?.fallback_metadata && payload.data.fallback_metadata.photos && !enrichedProps.image) {
      enrichedProps.poi_photos = payload.data.fallback_metadata.photos;
      const firstPhoto = payload.data.fallback_metadata.photos[0];
      if (firstPhoto && firstPhoto.street_view_url) {
        enrichedProps.image = firstPhoto.street_view_url;
      }
    }

    enrichedProps.charging_external_expires_at = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString();
    try { console.debug('[DB Map][Charging enrich] enriched props applied', { id: enrichedProps.id, hasLive: typeof enrichedProps.charging_live_available !== 'undefined', hasImage: !!enrichedProps.image, hasPhotos: !!enrichedProps.poi_photos }); } catch (_) {}
    return enriched;
  }

  function shouldFetchChargingDetails(props) {
    if (!props) return false;
    const liveExpire = props.charging_live_expires_at ? Date.parse(props.charging_live_expires_at) : 0;
    const needLive = !(typeof props.charging_live_available === 'number' && typeof props.charging_live_total === 'number');
    const needMeta = !(props.charging_google_details || props.charging_ocm_details);
    const liveFresh = liveExpire && Date.now() < (liveExpire - 1000);
    const shouldFetch = needMeta || needLive || !liveFresh;
    
    // Debug výpis
    try { 
      console.debug('[DB Map][Charging] shouldFetchChargingDetails', {
        id: props.id,
        needMeta,
        needLive,
        liveFresh,
        shouldFetch,
        hasGoogleDetails: !!props.charging_google_details,
        hasOcmDetails: !!props.charging_ocm_details
      }); 
    } catch(_) {}
    
    return shouldFetch;
  }

  function formatRelativeLiveTime(dateString) {
    if (!dateString) return '';
    const ts = Date.parse(dateString);
    if (!ts) return '';
    const diffMs = Date.now() - ts;
    if (diffMs < 60000) return 'před chvílí';
    const diffMinutes = Math.round(diffMs / 60000);
    if (diffMinutes === 1) return 'před minutou';
    if (diffMinutes < 60) return `před ${diffMinutes} minutami`;
    const diffHours = Math.round(diffMinutes / 60);
    if (diffHours === 1) return 'před hodinou';
    if (diffHours < 6) return `před ${diffHours} hodinami`;
    return new Date(ts).toLocaleString('cs-CZ', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' });
  }

  // Určí, zda má smysl volat REST pro doplnění detailu (kvůli loaderu)
  function shouldFetchPOIDetails(props) {
    if (!props) return false;
    const expiresAt = props.poi_external_expires_at ? Date.parse(props.poi_external_expires_at) : 0;
    const missingHours = !props.poi_opening_hours;
    const missingWebsite = !props.poi_website;
    const hasPhotos = Array.isArray(props.poi_photos) && props.poi_photos.length > 0;
    const hasAnyPhoto = hasPhotos || !!props.poi_photo_url || !!props.image;
    const missingPhotos = !hasAnyPhoto;
    const notExpired = expiresAt && Date.now() < (expiresAt - 5000);
    const need = (missingHours || missingWebsite || missingPhotos);
    return !notExpired || need;
  }

  // Funkce pro načítání detailu POI
  async function loadPOIDetail(poiId, lat, lng) {
    try {
      const nonce = dbMapData?.restNonce || '';
      const response = await fetch(`/wp-json/db/v1/map?lat=${lat}&lng=${lng}&radius=0.1&post_types=poi&limit=1`, {
        headers: {
          'X-WP-Nonce': nonce,
          'Content-Type': 'application/json'
        }
      });
      const data = await response.json();

      if (data.features && data.features.length > 0) {
        let feature = data.features[0];
        feature = await enrichPOIFeature(feature);
        featureCache.set(feature.properties.id, feature);
        openDetailModal(feature);
      }
    } catch (error) {
    }
  }


  // loadNearbyPOIs function removed

  // ===== NEARBY PLACES FUNCTIONALITY =====
  
  // Konfigurace nearby places
  window.DB_NEARBY = window.DB_NEARBY || {
    radiusKmChargers: 5,
    radiusKmPOI: 2,
    limitChargers: 6,
    limitPOI: 6,
    respectFilters: false
  };

  /**
   * Vrátí pole kandidátů poblíž centerFeature se spočtenou vzdáleností v metrech.
   */
  function computeNearby(centerFeature, { preferTypes = ['poi'], radiusKm = 2, limit = 6 } = {}) {
    
    const center = {
      lat: centerFeature.geometry.coordinates[1],
      lng: centerFeature.geometry.coordinates[0]
    };

    // Kandidáti = vše z featureCache
    const all = [];
    featureCache.forEach(f => all.push(f));

    const typed = all.filter(f => {
      const p = f.properties || {};
      if (p.id === centerFeature.properties.id) return false;
      if (!f.geometry || !Array.isArray(f.geometry.coordinates)) return false;
      return preferTypes.includes(p.post_type);
    });

    // Spočítej vzdálenost stejnou funkcí jako panel/list (metry)
    typed.forEach(f => {
      const [lng, lat] = f.geometry.coordinates;
      f._distance = getDistance(center.lat, center.lng, lat, lng); // **stejné jako v panelu**
    });

    // Radius & limit
    const maxM = Math.round((radiusKm || 2) * 1000);
    const nearby = typed
      .filter(f => f._distance <= maxM)
      .sort((a,b) => (a._distance||1e12) - (b._distance||1e12))
      .slice(0, limit || 6);

    return nearby;
  }

  /**
   * Vyrenderuje položky z walking distance cache
   */
  function renderNearbyFromCache(containerEl, items) {
    if (!items || !items.length) {
      containerEl.innerHTML = `
        <div style="color:#666;text-align:center;padding:30px 20px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;">
          <div style="font-size:24px;margin-bottom:8px;">🔍</div>
          <div style="font-weight:500;font-size:14px;">V okolí nic nenašli</div>
          <div style="font-size:12px;color:#9ca3af;margin-top:4px;">Zkuste zvětšit radius nebo se podívat jinde</div>
        </div>`;
      return;
    }

    containerEl.innerHTML = items.map(item => {
      const distKm = (item.walk_m / 1000).toFixed(2);
      const mins = Math.round(item.secs / 60);
      
      // Určit ikonu podle typu (musíme načíst z featureCache)
      let typeBadge = '📍';
      const cachedFeature = featureCache.get(item.id);
      if (cachedFeature) {
        const postType = cachedFeature.properties?.post_type;
        if (postType === 'charging_location') {
          typeBadge = '⚡';
        } else if (postType === 'rv_spot') {
          typeBadge = '🏕️';
        }
      }

      // Získat barvu čtverečku podle typu místa (stejně jako piny na mapě)
      const getCacheItemSquareColor = (props) => {
        if (props.post_type === 'charging_location') {
          const mode = getChargerMode(props);
          const acColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.ac) || '#049FE8';
          const dcColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.dc) || '#FFACC4';
          if (mode === 'hybrid') {
            return `linear-gradient(135deg, ${acColor} 0%, ${acColor} 30%, ${dcColor} 70%, ${dcColor} 100%)`;
          }
          return mode === 'dc' ? dcColor : acColor;
        } else if (props.post_type === 'rv_spot') {
          return '#FCE67D';
        } else if (props.post_type === 'poi') {
          return props.icon_color || '#FCE67D';
        }
        return '#049FE8';
      };

      const squareColor = cachedFeature ? getCacheItemSquareColor(cachedFeature.properties) : '#049FE8';

      return `
        <button type="button" class="db-nearby-item" data-id="${item.id}"
          style="width:100%;text-align:left;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:4px 0;display:flex;gap:12px;align-items:center;cursor:pointer;transition:all 0.2s;box-shadow:0 1px 3px rgba(0,0,0,0.1);"
          onmouseover="this.style.backgroundColor='#f8fafc';this.style.borderColor='#049FE8';this.style.transform='translateY(-1px)';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.15)';"
          onmouseout="this.style.backgroundColor='#fff';this.style.borderColor='#e5e7eb';this.style.transform='translateY(0)';this.style.boxShadow='0 1px 3px rgba(0,0,0,0.1)';">
          <div style="font-size:20px;flex-shrink:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:${squareColor};border-radius:4px;">${typeBadge}</div>
          <div style="flex:1 1 auto;min-width:0;">
            <div style="font-weight:600;color:#111;font-size:14px;line-height:1.3;margin-bottom:2px;word-wrap:break-word;">${item.title || item.name || '(bez názvu)'}</div>
            <div style="color:#10b981;font-weight:600;font-size:12px;">🚶 ${distKm} km • ${mins} min</div>
          </div>
        </button>`;
    }).join('');

    // Click → otevře detail
    containerEl.querySelectorAll('.db-nearby-item').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = +btn.dataset.id;
        const target = featureCache.get(id);
        if (target) {
          closeDetailModal();
          openDetailModal(target);
        }
      });
    });
  }
  /**
   * Vyrenderuje položky do kontejneru #nearby-pois-list.
   */
  function renderNearbyList(containerEl, items, options = {}) {
    if (!containerEl) {
      return;
    }
    
    if (!items || !items.length) {
      containerEl.innerHTML = `
        <div style="color:#666;text-align:center;padding:30px 20px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;">
          <div style="font-size:24px;margin-bottom:8px;">🔍</div>
          <div style="font-weight:500;font-size:14px;">V okolí nic nenašli</div>
          <div style="font-size:12px;color:#9ca3af;margin-top:4px;">Zkuste zvětšit radius nebo se podívat jinde</div>
        </div>`;
      return;
    }

    // Progress indicator pro partial data
    let progressHtml = '';
    if (options.partial && options.progress) {
      const { done, total } = options.progress;
      const percent = Math.round((done / total) * 100);
      progressHtml = `
        <div style="background:#e0f2fe;border:1px solid #81d4fa;border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:12px;color:#0277bd;">
          <div style="display:flex;align-items:center;gap:8px;">
            <div style="width:16px;height:16px;border:2px solid #0277bd;border-top:2px solid transparent;border-radius:50%;animation:spin 1s linear infinite;"></div>
            <span>Načítání... ${done}/${total} (${percent}%)</span>
          </div>
        </div>`;
    }

    containerEl.innerHTML = progressHtml + items.map(item => {
      const distKm = ((item.distance_m || 0) / 1000).toFixed(1);
      const mins = Math.round((item.duration_s || 0) / 60);
      const walkText = item.distance_m ? `${distKm}km • ${mins}min` : `≈ ${distKm}km`;
      
      // Určit ikonu podle typu a dostupných dat
      let typeBadge = '';
      if (item.svg_content) {
        // Pro POI použít SVG obsah
        typeBadge = item.svg_content;
      } else if (item.icon_slug) {
        // Pro ostatní typy použít icon_slug
        const iconUrl = getIconUrl(item.icon_slug);
        typeBadge = iconUrl ? `<img src="${iconUrl}" style="width:100%;height:100%;object-fit:contain;" alt="">` : '📍';
      } else if (item.post_type === 'charging_location') {
        typeBadge = getChargerColoredSvg() || '⚡';
      } else if (item.post_type === 'poi') {
        typeBadge = '📍';
      } else if (item.post_type === 'rv_spot') {
        typeBadge = '🏕️';
      } else {
        typeBadge = '📍';
      }

      // Získat barvu čtverečku podle typu místa (stejně jako piny na mapě)
      const getNearbyItemSquareColor = (props) => {
        if (props.post_type === 'charging_location') {
          const mode = getChargerMode(props);
          const acColor = '#049FE8';
          const dcColor = '#FFACC4';
          if (mode === 'hybrid') {
            return `linear-gradient(135deg, ${acColor} 0%, ${acColor} 30%, ${dcColor} 70%, ${dcColor} 100%)`;
          }
          return mode === 'dc' ? dcColor : acColor;
        } else if (props.post_type === 'rv_spot') {
          return '#FCE67D';
        } else if (props.post_type === 'poi') {
          return props.icon_color || '#FCE67D';
        }
        return '#049FE8';
      };

      return `
        <button type="button" class="db-nearby-item" data-id="${item.id}"
          style="width:100%;text-align:left;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:4px 0;display:flex;gap:12px;align-items:center;cursor:pointer;transition:all 0.2s;box-shadow:0 1px 3px rgba(0,0,0,0.1);"
          onmouseover="this.style.backgroundColor='#f8fafc';this.style.borderColor='#049FE8';this.style.transform='translateY(-1px)';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.15)';"
          onmouseout="this.style.backgroundColor='#fff';this.style.borderColor='#e5e7eb';this.style.transform='translateY(0)';this.style.boxShadow='0 1px 3px rgba(0,0,0,0.1)';">
          <div style="font-size:20px;flex-shrink:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:${getNearbyItemSquareColor(item)};border-radius:4px;">${typeBadge}</div>
          <div style="flex:1 1 auto;min-width:0;">
            <div style="font-weight:600;color:#111;font-size:14px;line-height:1.3;margin-bottom:2px;word-wrap:break-word;">${item.name || item.title || '(bez názvu)'}</div>
            <div style="color:#10b981;font-weight:600;font-size:12px;">${walkText}</div>
          </div>
        </button>`;
    }).join('');

    // Click → otevře detail
    containerEl.querySelectorAll('.db-nearby-item').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = +btn.dataset.id;
        const target = featureCache.get(id);
        if (target) {
          closeDetailModal();
          openDetailModal(target);
        }
      });
    });
  }


  /**
   * Načte nearby data pro kartu v bočním panelu
   */
  async function loadNearbyForCard(containerEl, featureId) {
    if (!containerEl || !featureId) return;
    
    // Zobrazit loading stav okamžitě
    containerEl.innerHTML = `
      <div style="text-align: center; padding: 10px; color: #666; font-size: 0.8em;">
        <div style="font-size: 16px; margin-bottom: 4px;">⏳</div>
        <div>Načítání...</div>
      </div>
    `;
    
    // Najít feature podle ID
    const feature = features.find(f => f.properties.id == featureId);
    if (!feature) {
      containerEl.innerHTML = '<div style="text-align:center;padding:8px;color:#999;font-size:0.75em;">Chyba při načítání</div>';
      return;
    }
    
    const p = feature.properties || {};
    const type = (p.post_type === 'charging_location') ? 'charging_location' : 'poi';
    
    // Pokus o načtení s retry logikou
    let attempts = 0;
    const maxAttempts = 3;
    
    const tryLoad = async () => {
      const data = await fetchNearby(featureId, type, 3);
      
      if (Array.isArray(data.items) && data.items.length > 0) {
        // Zobrazit 3 nejbližší
        const items = data.items.slice(0, 3);
        containerEl.innerHTML = items.map(item => {
          const distKm = ((item.distance_m || 0) / 1000).toFixed(1);
          const mins = Math.round((item.duration_s || 0) / 60);
          const walkText = item.distance_m ? `${distKm}km • ${mins}min` : `≈ ${distKm}km`;
          
          // Určit ikonu podle typu a dostupných dat
          let typeIcon = '📍';
          if (item.svg_content) {
            // Pro POI použít SVG obsah
            typeIcon = item.svg_content;
          } else if (item.icon_slug) {
            // Pro ostatní typy použít icon_slug
            const iconUrl = getIconUrl(item.icon_slug);
            typeIcon = iconUrl ? `<img src="${iconUrl}" style="width:100%;height:100%;object-fit:contain;" alt="">` : '📍';
          } else if (item.post_type === 'charging_location') { 
            typeIcon = getChargerColoredSvg() || '⚡'; 
          } else if (item.post_type === 'rv_spot') { 
            typeIcon = '🏕️'; 
          }
          
          return `
            <div class="db-card-nearby-item" data-id="${item.id}"
              style="display:flex;align-items:center;gap:6px;padding:4px 6px;background:#f8fafc;border-radius:4px;margin:2px 0;cursor:pointer;transition:all 0.2s;font-size:0.75em;"
              onmouseover="this.style.backgroundColor='#e2e8f0';"
              onmouseout="this.style.backgroundColor='#f8fafc';"
              onclick="const target=featureCache.get(${item.id});if(target){highlightMarkerById(${item.id});map.setView([target.geometry.coordinates[1],target.geometry.coordinates[0]],15,{animate:true});sortMode='distance-active';renderCards('',${item.id});if(window.innerWidth <= 900){openMobileSheet(target);}else{openDetailModal(target);}}">
              <div style="font-size:12px;flex-shrink:0;">${typeIcon}</div>
              <div style="flex:1;min-width:0;">
                <div style="font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${item.name || item.title || '(bez názvu)'}</div>
                <div style="color:#10b981;font-weight:600;">${walkText}</div>
              </div>
            </div>`;
        }).join('');
        return;
      }
      
      // Pokud nemáme data, ale běží recompute, zkus znovu
      if ((data.running || data.partial || data.stale) && attempts < maxAttempts) {
        attempts++;
        containerEl.innerHTML = `
          <div style="text-align: center; padding: 10px; color: #666; font-size: 0.8em;">
            <div style="font-size: 16px; margin-bottom: 4px;">⏳</div>
            <div>Načítání... (${attempts}/${maxAttempts})</div>
          </div>
        `;
        setTimeout(tryLoad, 2000);
        return;
      }
      
      // Fallback: zobrazit prázdný stav
      containerEl.innerHTML = '<div style="text-align:center;padding:8px;color:#999;font-size:0.75em;">Žádná blízká místa</div>';
    };
    
    tryLoad();
  }

  /**
   * Zkontrolovat, zda má bod nearby data k dispozici
   */
  async function checkNearbyDataAvailable(originId, type) {
    try {
      const response = await fetch(`/wp-json/db/v1/nearby?origin_id=${originId}&type=${type}&limit=1`, {
        headers: {
          'X-WP-Nonce': dbMapData?.restNonce || ''
        }
      });
      
      if (!response.ok) {
        return false;
      }
      
      const data = await response.json();
      return data && data.items && data.items.length > 0;
    } catch (error) {
      return false;
    }
  }

  /**
   * Načíst nearby places pro detail modal s jemným pollováním
   */
  async function loadAndRenderNearby(centerFeature) {
    // Invalidate předchozí isochrones request a vyčistit mapu hned při změně výběru
    currentIsochronesRequestId++;
    const requestId = currentIsochronesRequestId;
    clearIsochrones();
    const p = centerFeature.properties;
    const type = (p.post_type === 'charging_location') ? 'charging_location' : 'poi';
    
    // Zkontrolovat, zda má bod nearby data
    const hasNearbyData = await checkNearbyDataAvailable(p.id, type);
    
    if (!hasNearbyData) {
      // Skrýt nearby sekci v kartě
      const nearbySection = document.querySelector(`[data-feature-id="${p.id}"]`)?.closest('.db-map-card-nearby');
      if (nearbySection) {
        nearbySection.style.display = 'none';
      }
      return;
    }
    
    // Zobrazit nearby sekci v kartě
    const nearbySection = document.querySelector(`[data-feature-id="${p.id}"]`)?.closest('.db-map-card-nearby');
    if (nearbySection) {
      nearbySection.style.display = 'block';
    }

    let attempts = 0;
    const maxAttempts = 4; // ~8s celkem

    // Zobrazit loading stav okamžitě - počkat až bude kontejner dostupný
    let nearbyContainer = document.getElementById('nearby-pois-list');
    if (!nearbyContainer) {
      // Pokud kontejner není dostupný, počkat chvilku a zkusit znovu
      setTimeout(() => {
        nearbyContainer = document.getElementById('nearby-pois-list');
        if (nearbyContainer) {
          nearbyContainer.innerHTML = `
            <div style="text-align: center; padding: 20px; color: #666;">
              <div style="font-size: 24px; margin-bottom: 8px;">⏳</div>
              <div>Načítání blízkých míst...</div>
            </div>
          `;
        }
      }, 50);
    } else {
      nearbyContainer.innerHTML = `
        <div style="text-align: center; padding: 20px; color: #666;">
          <div style="font-size: 24px; margin-bottom: 8px;">⏳</div>
          <div>Načítání blízkých míst...</div>
        </div>
      `;
    }

    const tick = async () => {
      // Pokud mezitím došlo ke změně výběru, ukončit tento cyklus
      if (requestId !== currentIsochronesRequestId) return;
      const data = await fetchNearby(p.id, type, 9);
      
      // Získat aktuální kontejner (může se změnit)
      const currentContainer = document.getElementById('nearby-pois-list');
      
      // Zobrazit data nebo pokračovat v načítání
      if (requestId !== currentIsochronesRequestId) return;
      if (Array.isArray(data.items) && data.items.length > 0) {
        if (currentContainer) {
          renderNearbyList(currentContainer, data.items, { partial: data.partial, progress: data.progress });
        }
        
        // Zobrazit isochrones pokud jsou k dispozici a povoleny (kombinace backend + frontend nastavení)
        const frontendSettings = JSON.parse(localStorage.getItem('db-isochrones-settings') || '{"enabled": true, "walking_speed": 4.5}');
        const backendEnabled = data.isochrones?.user_settings?.enabled;
        const frontendEnabled = frontendSettings.enabled;
        
        if (requestId === currentIsochronesRequestId && data.isochrones && backendEnabled && frontendEnabled && data.isochrones.geojson && data.isochrones.geojson.features && data.isochrones.geojson.features.length > 0) {

          // Aplikovat frontend nastavení rychlosti chůze
          const adjustedGeojson = adjustIsochronesForFrontendSpeed(data.isochrones.geojson, data.isochrones.ranges_s, frontendSettings);
          const mergedSettings = {
            ...data.isochrones.user_settings,
            ...frontendSettings
          };

          const payload = {
            geojson: adjustedGeojson,
            ranges: data.isochrones.ranges_s,
            userSettings: mergedSettings,
            featureId: p.id
          };

          const didRender = renderIsochrones(adjustedGeojson, data.isochrones.ranges_s, mergedSettings, { featureId: p.id });
          if (didRender) {
            lastIsochronesPayload = payload;
            if (isochronesLocked && lockedIsochronesPayload && lockedIsochronesPayload.featureId === p.id) {
              lockedIsochronesPayload = payload;
            }
          }

          updateIsochronesLockButtons(p.id);
        } else {
          // Pokud nejsou isochrones v cache nebo jsou vypnuty, vyčistit mapu
          if (!isochronesLocked) {
            lastIsochronesPayload = null;
          }
          clearIsochrones();
          updateIsochronesLockButtons(p.id);
          if (data.isochrones && (!backendEnabled || !frontendEnabled)) {
          }
        }
        
        // Pokud máme data, ale jsou stale nebo partial, pokračuj v načítání
        if ((data.running || data.partial || data.stale) && attempts < maxAttempts) {
          attempts++;
          setTimeout(() => { if (requestId === currentIsochronesRequestId) tick(); }, 2000);
        }
      } else if ((data.running || data.partial || data.stale) && attempts < maxAttempts) {
        // Zobrazit progress stav
        if (currentContainer) {
          const progress = data.progress || { done: 0, total: 0 };
          currentContainer.innerHTML = `
            <div style="text-align: center; padding: 20px; color: #666;">
              <div style="font-size: 24px; margin-bottom: 8px;">⏳</div>
              <div>Načítání blízkých míst...</div>
              <div style="font-size: 12px; margin-top: 4px; color: #999;">
                ${progress.done}/${progress.total} míst
              </div>
            </div>
          `;
        }
        attempts++;
        setTimeout(() => { if (requestId === currentIsochronesRequestId) tick(); }, 2000);
      } else {
        // Zobrazit prázdný stav
        if (currentContainer) {
          currentContainer.innerHTML = `
            <div style="text-align: center; padding: 20px; color: #999;">
              <div style="font-size: 24px; margin-bottom: 8px;">📍</div>
              <div>Žádná blízká místa</div>
            </div>
          `;
        }
      }
    };
    tick();
  }

  function toggleIsochronesForFeature(centerFeature) {
    try {
      if (!centerFeature || !centerFeature.properties) return;
      // Pokud už jsou isochrony pro tento prvek zobrazené, smaž je, jinak je načti
      const alreadyVisible = !!document.querySelector(`.leaflet-interactive[data-iso-of="${centerFeature.properties.id}"]`);
      if (alreadyVisible) {
        clearIsochrones();
        return;
      }
      loadAndRenderNearby(centerFeature);
    } catch(_) {}
  }
  
  /**
   * Univerzální fetch funkce pro nearby data
   */
  async function fetchNearby(originId, type, limit) {
    const url = `/wp-json/db/v1/nearby?origin_id=${originId}&type=${type}&limit=${limit}`;
    const res = await fetch(url);
    return await res.json();
  }
  // Funkce pro kontrolu otevírací doby
  function checkIfOpen(openingHours) {
    if (!openingHours) return false;
    
    try {
      // Pokud je openingHours string, pokusíme se ho parsovat
      let hours;
      if (typeof openingHours === 'string') {
        hours = JSON.parse(openingHours);
      } else {
        hours = openingHours;
      }
      
      if (!hours || !hours.weekdayDescriptions || !Array.isArray(hours.weekdayDescriptions)) {
        return false;
      }
      
      const now = new Date();
      const currentDay = now.getDay(); // 0 = neděle, 1 = pondělí, atd.
      const currentTime = now.getHours() * 100 + now.getMinutes();
      
      // Najít aktuální den v otevírací době
      const todayHours = hours.weekdayDescriptions[currentDay];
      if (!todayHours) return false;
      
      // Jednoduchá kontrola - pokud obsahuje "Closed" nebo "Zavřeno", je zavřeno
      if (todayHours.toLowerCase().includes('closed') || todayHours.toLowerCase().includes('zavřeno')) {
        return false;
      }
      
      // Pokud obsahuje časové rozmezí, pokusíme se ho parsovat
      const timeMatch = todayHours.match(/(\d{1,2}):(\d{2})\s*[–-]\s*(\d{1,2}):(\d{2})/);
      if (timeMatch) {
        const openTime = parseInt(timeMatch[1]) * 100 + parseInt(timeMatch[2]);
        const closeTime = parseInt(timeMatch[3]) * 100 + parseInt(timeMatch[4]);
        return currentTime >= openTime && currentTime <= closeTime;
      }
      
      // Pokud neobsahuje časové rozmezí, ale neobsahuje "Closed", považujeme za otevřeno
      return true;
    } catch (error) {
      return false;
    }
  }

  // Funkce pro otevření navigačního menu s 3 mapovými aplikacemi
  function openNavigationMenu(lat, lng) {
    if (!lat || !lng) return;
    
    // Vytvořit navigační menu
    const navMenu = document.createElement('div');
    navMenu.className = 'db-nav-menu-mobile';
    navMenu.style.cssText = `
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.3);
      padding: 20px;
      z-index: 10006;
      min-width: 280px;
      max-width: 90vw;
    `;
    
    navMenu.innerHTML = `
      <div style="text-align: center; margin-bottom: 16px; font-weight: 600; color: #049FE8;">Vyberte navigační aplikaci</div>
      <div style="display: flex; flex-direction: column; gap: 12px;">
        <a href="${gmapsUrl(lat, lng)}" target="_blank" rel="noopener" 
           style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8f9fa; border-radius: 8px; text-decoration: none; color: #333;">
          <span style="font-size: 20px;">🗺️</span>
          <span>Google Maps</span>
        </a>
        <a href="${appleMapsUrl(lat, lng)}" target="_blank" rel="noopener"
           style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8f9fa; border-radius: 8px; text-decoration: none; color: #333;">
          <span style="font-size: 20px;">🍎</span>
          <span>Apple Maps</span>
        </a>
        <a href="${mapyCzUrl(lat, lng)}" target="_blank" rel="noopener"
           style="display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8f9fa; border-radius: 8px; text-decoration: none; color: #333;">
          <span style="font-size: 20px;">🇨🇿</span>
          <span>Mapy.cz</span>
        </a>
      </div>
      <button type="button" style="width: 100%; margin-top: 16px; padding: 12px; background: #e5e7eb; border: none; border-radius: 8px; color: #666; cursor: pointer;">Zavřít</button>
    `;
    
    document.body.appendChild(navMenu);
    
    // Event listener pro zavření
    const closeBtn = navMenu.querySelector('button');
    closeBtn.addEventListener('click', () => navMenu.remove());
    
    // Zavřít při kliknutí mimo
    navMenu.addEventListener('click', (e) => {
      if (e.target === navMenu) navMenu.remove();
    });
  }

  // Immerzivní režim body na mobilech (zamezí posunu stránky)
  function applyImmersiveClass() {
    try {
      if (window.innerWidth <= 900) document.body.classList.add('db-immersive');
      else document.body.classList.remove('db-immersive');
    } catch(_) {}
  }
  applyImmersiveClass();
  window.addEventListener('resize', () => applyImmersiveClass());

  // Detail modal (90% výšky) – místo otevírání nové záložky
  const detailModal = document.createElement('div');
  detailModal.id = 'db-detail-modal';
  detailModal.setAttribute('data-db-feedback', 'map.detail_modal');
  // Modal musí být mimo #db-map, aby fungoval i v list režimu, kde je mapa skrytá
  document.body.appendChild(detailModal);
  function closeDetailModal(){ 
    detailModal.classList.remove('open'); 
    detailModal.innerHTML = ''; 
    // Odstranit třídu pro scroll lock
    try { document.body.classList.remove('db-modal-open'); } catch(_) {}
    // Vyčistit isochrones při zavření modalu
    clearIsochrones();
  }
  detailModal.addEventListener('click', (e) => { if (e.target === detailModal) closeDetailModal(); });
  async function openDetailModal(feature) {
    // Přidat třídu pro scroll lock
    try { document.body.classList.add('db-modal-open'); } catch(_) {}
    try { console.debug('[DB Map][Detail] open', { id: feature?.properties?.id, post_type: feature?.properties?.post_type, title: feature?.properties?.title }); } catch(_) {}

    // Pokud je to POI, pokus se před renderem obohatit (pokud chybí data)
    if (feature && feature.properties && feature.properties.post_type === 'poi') {
      const needsEnrich = shouldFetchPOIDetails(feature.properties);
      if (needsEnrich) {
        try { console.debug('[DB Map][Detail] enriching now', { id: feature.properties.id }); } catch(_) {}
        try {
          const enriched = await enrichPOIFeature(feature);
          if (enriched && enriched !== feature) {
            feature = enriched;
            featureCache.set(enriched.properties.id, enriched);
            try { console.debug('[DB Map][Detail] enriched', { id: enriched.properties.id, hasWebsite: !!enriched.properties.poi_website, hasPhotos: Array.isArray(enriched.properties.poi_photos) && enriched.properties.poi_photos.length>0 }); } catch(_) {}
          }
        } catch(err) {
          try { console.warn('[DB Map][Detail] enrich failed', err); } catch(_) {}
        }
      }
    }

    if (feature && feature.properties && feature.properties.post_type === 'charging_location') {
      const needsChargingEnrich = shouldFetchChargingDetails(feature.properties);
      try { console.debug('[DB Map][Detail] charging_location detected', { id: feature.properties.id, needsChargingEnrich, hasGoogleDetails: !!feature.properties.charging_google_details, hasOcmDetails: !!feature.properties.charging_ocm_details }); } catch(_) {}
      if (needsChargingEnrich) {
        try { console.debug('[DB Map][Detail] enriching charging now', { id: feature.properties.id }); } catch(_) {}
        try {
          const enrichedCharging = await enrichChargingFeature(feature);
          if (enrichedCharging && enrichedCharging !== feature) {
            feature = enrichedCharging;
            featureCache.set(enrichedCharging.properties.id, enrichedCharging);
            try { console.debug('[DB Map][Detail] charging enriched', { id: enrichedCharging.properties.id, live: enrichedCharging.properties.charging_live_available }); } catch(_) {}
          }
        } catch (err) {
          try { console.warn('[DB Map][Detail] charging enrich failed', err); } catch(_) {}
        }
      } else {
        try { console.debug('[DB Map][Detail] charging enrich skipped', { id: feature.properties.id, reason: 'has fresh data' }); } catch(_) {}
      }
    }

    const p = feature.properties || {};
    const coords = feature.geometry && feature.geometry.coordinates ? feature.geometry.coordinates : null;
    const lat = coords ? coords[1] : null;
    const lng = coords ? coords[0] : null;
    const distanceText = (typeof feature._distance !== 'undefined') ? (feature._distance/1000).toFixed(2) + ' km' : '';
    const label = getMainLabel(p);
    const subtitle = [distanceText, p.address || '', label].filter(Boolean).join(' • ');
    // Preferuj hlavní fotku jako hero (image z enrichmentu nebo první z poi_photos)
    let heroImageUrl = p.image || '';
    if (!heroImageUrl && Array.isArray(p.poi_photos) && p.poi_photos.length > 0) {
      const firstPhoto = p.poi_photos[0];
      if (firstPhoto && typeof firstPhoto === 'object') {
        if (firstPhoto.url) {
          heroImageUrl = firstPhoto.url;
        } else if ((firstPhoto.photo_reference || firstPhoto.photoReference) && dbMapData?.googleApiKey) {
          const ref = firstPhoto.photo_reference || firstPhoto.photoReference;
          if (ref === 'streetview' && firstPhoto.street_view_url) {
            // Street View obrázek
            heroImageUrl = firstPhoto.street_view_url;
          } else if (ref.startsWith('places/')) {
            // Nové API v1 formát
            heroImageUrl = `https://places.googleapis.com/v1/${ref}/media?maxWidthPx=1200&key=${dbMapData.googleApiKey}`;
          } else if (ref !== 'streetview') {
            // Staré API formát (fallback)
            heroImageUrl = `https://maps.googleapis.com/maps/api/place/photo?maxwidth=1200&photo_reference=${ref}&key=${dbMapData.googleApiKey}`;
          }
        }
      } else if (typeof firstPhoto === 'string') {
        heroImageUrl = firstPhoto;
      }
    }
    // Fallback: použij uložené meta pole poi_photo_url, pokud existuje
    if (!heroImageUrl && p.poi_photo_url) {
      heroImageUrl = p.poi_photo_url;
    }
    try { console.log('[DB Map][Detail] heroImageUrl', heroImageUrl, 'p.image:', p.image, 'p.poi_photos:', p.poi_photos); } catch(_) {}
    const img = heroImageUrl 
      ? `<img class="hero-img" src="${heroImageUrl}" alt="" style="width:100%;height:100%;object-fit:cover;display:block;">`
      : '';

    // Připrav seznam max 3 fotek (pro jednoduchý carousel)
    const photoUrls = [];
    if (Array.isArray(p.poi_photos)) {
      for (const ph of p.poi_photos) {
        let u = '';
        if (ph && typeof ph === 'object') {
          if (ph.url) u = ph.url;
          else if ((ph.photo_reference || ph.photoReference) && dbMapData?.googleApiKey) {
            const ref = ph.photo_reference || ph.photoReference;
            if (ref === 'streetview' && ph.street_view_url) {
              u = ph.street_view_url;
            } else if (ref !== 'streetview') {
              u = `https://maps.googleapis.com/maps/api/place/photo?maxwidth=800&photo_reference=${ref}&key=${dbMapData.googleApiKey}`;
            }
          }
        } else if (typeof ph === 'string') {
          u = ph;
        }
        if (u && !photoUrls.includes(u)) photoUrls.push(u);
      }
    }
    if (p.poi_photo_url && !photoUrls.includes(p.poi_photo_url)) {
      photoUrls.push(p.poi_photo_url);
    }
    // Mini-náhledy pod hlavní fotkou – malé čtverce jako ikony, řazené zleva (maximálně na šířku hero)
    const thumbPhotos = photoUrls.slice(1, 9); // Všechny fotky kromě první (hero), max 8 náhledů
    const thumbsHtml = thumbPhotos.length > 0
      ? `<div class="hero-thumbs" style="display:flex;gap:6px;margin:8px 0 0 0;align-items:center;">
           ${thumbPhotos.map(u => `<div style="width:32px;height:32px;border-radius:6px;overflow:hidden;flex-shrink:0;">
               <img class="hero-thumb" data-url="${u}" src="${u}" alt="" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" />
             </div>`).join('')}
         </div>`
      : '';
    
    // Generování detailních informací o konektorech pro nabíječky
    let connectorsDetail = '';
    if (p.post_type === 'charging_location') {
      // Použít konektory z původních mapových dat - nezávisle na cache
      const mapConnectors = Array.isArray(p.connectors) ? p.connectors : (Array.isArray(p.konektory) ? p.konektory : []);
      const dbConnectors = Array.isArray(p.db_connectors) ? p.db_connectors : [];
      
      // Preferovat db_connectors z REST API (obsahuje power), pak mapConnectors jako fallback
      let connectors = [];
      if (dbConnectors.length > 0) {
        connectors = dbConnectors;
      } else if (mapConnectors.length > 0) {
        connectors = mapConnectors;
      }
      
      if (connectors && connectors.length) {
        // Seskupit konektory podle typu a spočítat (stejná logika jako v mobile sheet)
        const connectorCounts = {};
        connectors.forEach(c => {
          const typeKey = getConnectorTypeKey(c);
          if (typeKey) {
            // power – vem cokoliv rozumného (číselo nebo str s číslem)
            const power = c.power || c.connector_power_kw || c.power_kw || c.vykon || '';
            // quantity – použij reálný počet z dat, nebo 1 jako fallback
            const quantity = parseInt(c.quantity || c.count || c.connector_count || 1);
            
            if (!connectorCounts[typeKey]) {
              connectorCounts[typeKey] = { count: 0, power: power };
            }
            connectorCounts[typeKey].count += quantity;
          }
        });
        
        // Vytvořit HTML pro konektory jako ikony s čísly na jednom řádku
        const connectorItems = Object.entries(connectorCounts).map(([typeKey, info]) => {
          const connector = connectors.find(c => {
            const cType = getConnectorTypeKey(c);
            return cType === typeKey;
          });
          
          const iconUrl = connector ? getConnectorIconUrl(connector) : null;
          const powerText = info.power ? `${info.power} kW` : '';
          
          // Zkontrolovat live dostupnost z API
          let availabilityText = info.count;
          let isOutOfService = false;
          
          // Zkontrolovat stav "mimo provoz" z Google API
          if (p.business_status === 'CLOSED_TEMPORARILY' || p.business_status === 'CLOSED_PERMANENTLY') {
            isOutOfService = true;
          }
          
          // Zobrazit pouze počet konektorů z databáze - bez dostupnosti z Google API
          if (isOutOfService) {
            availabilityText = 'MIMO PROVOZ';
          } else {
            // Zobrazit pouze celkový počet z databáze
            availabilityText = info.count.toString();
          }
          
          // Určit styly podle stavu
          const containerStyle = isOutOfService 
            ? 'display: inline-flex; align-items: center; gap: 6px; margin: 4px 8px 4px 0; padding: 8px 12px; background: #fee; border-radius: 6px; border: 1px solid #fcc; opacity: 0.7;'
            : 'display: inline-flex; align-items: center; gap: 6px; margin: 4px 8px 4px 0; padding: 8px 12px; background: #f8f9fa; border-radius: 6px; border: 1px solid #e9ecef;';
          
          const textStyle = isOutOfService 
            ? 'font-weight: 600; color: #c33; font-size: 0.9em;'
            : 'font-weight: 600; color: #333; font-size: 0.9em;';
          
          if (iconUrl) {
            // Zobraz jako ikonu s číslem (ikona + počet horizontálně, výkon pod nimi)
            return `<div style="display: inline-flex; flex-direction: column; align-items: center; gap: 2px; margin: 4px 8px 4px 0; padding: 8px 12px; background: #f8f9fa; border-radius: 6px; border: 1px solid #e9ecef;">
              <div style="display: flex; align-items: center; gap: 4px;">
                <img src="${iconUrl}" style="width: 20px; height: 20px; object-fit: contain;" alt="${typeKey}">
                <span style="${textStyle}">${availabilityText}</span>
              </div>
              ${powerText ? `<span style="color: #666; font-size: 0.8em;">${powerText}</span>` : ''}
            </div>`;
          } else {
            // Fallback - pouze text
            return `<div style="display: inline-flex; flex-direction: column; align-items: center; gap: 2px; margin: 4px 8px 4px 0; padding: 8px 12px; background: #f8f9fa; border-radius: 6px; border: 1px solid #e9ecef;">
              <span style="${textStyle}">${typeKey.toUpperCase()}: ${availabilityText}</span>
              ${powerText ? `<span style="color: #666; font-size: 0.8em;">${powerText}</span>` : ''}
            </div>`;
          }
        }).join('');
        
        connectorsDetail = `
          <div style="margin: 16px; display: flex; flex-wrap: wrap; gap: 4px;">
            ${connectorItems}
          </div>
        `;
      }
    }
    
    // Informace o poskytovateli
    let providerDetail = '';
    if (p.operator_original || p.provider) {
      providerDetail = `
        <div style="margin: 16px; padding: 12px; background: #e8f4fd; border-radius: 8px; border-left: 4px solid #049FE8;">
          <div style="font-weight: 600; color: #049FE8;">Poskytovatel</div>
          <div style="color: #333; margin-top: 4px;">${p.operator_original || p.provider}</div>
        </div>
      `;
    }
    
    // Dodatečné informace
    let additionalInfo = '';
    if (p.post_type === 'charging_location') {
      const infoItems = [];
      
      if (p.opening_hours) {
        infoItems.push(`<div style="margin: 4px 0;"><strong>Otevírací doba:</strong> ${p.opening_hours}</div>`);
      }
      
      if (p.station_max_power_kw) {
        infoItems.push(`<div style="margin: 4px 0;"><strong>Maximální výkon:</strong> ${p.station_max_power_kw} kW</div>`);
      }
      
      if (p.evse_count) {
        infoItems.push(`<div style="margin: 4px 0;"><strong>Počet nabíjecích bodů:</strong> ${p.evse_count}</div>`);
      }
      
      if (infoItems.length > 0) {
        additionalInfo = `
          <div style="margin: 16px; padding: 16px; background: #f8f9fa; border-radius: 12px;">
            <div style="font-weight: 700; color: #049FE8; margin-bottom: 12px; font-size: 1.1em;">Dodatečné informace</div>
            ${infoItems.join('')}
          </div>
        `;
      }
    }
    
    // Rating (pokud je dostupný)
    let ratingInfo = '';
    const ratingValue = p.post_type === 'poi' ? (p.poi_rating || p.rating) : p.rating;
    const ratingCount = p.post_type === 'poi' ? (p.poi_user_rating_count || '') : (p.user_rating_count || '');
    if (ratingValue) {
      const countText = ratingCount ? `<span style="font-size:12px;color:#684c0f;margin-left:8px;">(${ratingCount} hodnocení)</span>` : '';
      ratingInfo = `
        <div style="margin: 16px; padding: 12px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
          <div style="font-weight: 600; color: #856404;">Hodnocení</div>
          <div style="color: #856404; margin-top: 4px; display:flex;align-items:center;gap:6px;">
            <span>★★★★☆ ${parseFloat(ratingValue).toFixed(1)}</span>
            ${countText}
          </div>
        </div>
      `;
    }

    // Fotky a média
    let photosSection = '';
    if (p.poi_photos && Array.isArray(p.poi_photos) && p.poi_photos.length > 0) {
      const photoItems = p.poi_photos.slice(0, 6).map(photo => {
        let photoUrl = '';
        if (photo && typeof photo === 'object') {
          if (photo.url) {
            photoUrl = photo.url;
          } else if ((photo.photo_reference || photo.photoReference) && dbMapData?.googleApiKey) {
            const ref = photo.photo_reference || photo.photoReference;
            if (ref === 'streetview' && photo.street_view_url) {
              photoUrl = photo.street_view_url;
            } else if (ref !== 'streetview') {
              photoUrl = `https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photo_reference=${ref}&key=${dbMapData?.googleApiKey || ''}`;
            }
          }
        } else if (typeof photo === 'string') {
          photoUrl = photo;
        }
        if (!photoUrl) {
          return '';
        }
        return `<div class="photo-item" style="flex: 0 0 calc(50% - 8px); margin: 4px; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
          <img src="${photoUrl}" alt="Foto" style="width: 100%; height: 120px; object-fit: cover; display: block;" loading="lazy">
        </div>`;
      }).join('');

      photosSection = ''; // Odstraněno - fotky se zobrazují jen jako náhledy pod hero
    }
    
    // Kontaktní informace
    let contactSection = '';
    const contactItems = [];

    if (p.poi_phone || p.rv_phone || p.phone) {
      const phone = p.poi_phone || p.rv_phone || p.phone;
      contactItems.push(`<div style="margin: 8px 0; display: flex; align-items: center; gap: 8px;">
        <span style="color: #049FE8; font-size: 1.2em;">📞</span>
        <a href="tel:${phone}" style="color: #049FE8; text-decoration: none; font-weight: 500;">${phone}</a>
      </div>`);
    }
    
    if (p.poi_website || p.rv_website || p.website) {
      const website = p.poi_website || p.rv_website || p.website;
      contactItems.push(`<div style="margin: 8px 0; display: flex; align-items: center; gap: 8px;">
        <span style="color: #049FE8; font-size: 1.2em;">🌐</span>
        <a href="${website}" target="_blank" rel="noopener" style="color: #049FE8; text-decoration: none; font-weight: 500;">${website.replace(/^https?:\/\//, '')}</a>
      </div>`);
    }
    
    if (p.poi_address || p.rv_address || p.address) {
      const address = p.poi_address || p.rv_address || p.address;
      contactItems.push(`<div style="margin: 8px 0; display: flex; align-items: flex-start; gap: 8px;">
        <span style="color: #049FE8; font-size: 1.2em; margin-top: 2px;">📍</span>
        <span style="color: #666; line-height: 1.4;">${address}</span>
      </div>`);
    }

    let socialLinks = p.poi_social_links;
    if (typeof socialLinks === 'string') {
      try { socialLinks = JSON.parse(socialLinks); } catch (_) { socialLinks = null; }
    }
    if (socialLinks && typeof socialLinks === 'object') {
      Object.entries(socialLinks).forEach(([network, url]) => {
        if (!url) return;
        const icon = network === 'facebook' ? '📘' : network === 'instagram' ? '📸' : network === 'email' ? '✉️' : '🔗';
        const href = network === 'email' ? `mailto:${url}` : url;
        const label = network === 'instagram' ? 'Instagram' : network === 'facebook' ? 'Facebook' : network === 'email' ? 'E‑mail' : 'Webové stránky';
        contactItems.push(`<div style="margin: 8px 0; display:flex; align-items:center; gap:8px;">
          <span style="color:#049FE8;font-size:1.2em;">${icon}</span>
          <a href="${href}" target="_blank" rel="noopener" style="color:#049FE8;text-decoration:none;font-weight:500;">${label}</a>
        </div>`);
      });
    }

    if (p.poi_opening_hours) {
      let hoursHtml = '';
      try {
        const hours = typeof p.poi_opening_hours === 'string' ? JSON.parse(p.poi_opening_hours) : p.poi_opening_hours;
        if (hours && hours.weekdayDescriptions && Array.isArray(hours.weekdayDescriptions)) {
          const isOpen = checkIfOpen(p.poi_opening_hours);
          const statusText = isOpen ? 'Otevřeno' : 'Zavřeno';
          const statusColor = isOpen ? '#10b981' : '#ef4444';
          hoursHtml = `
            <div style="margin: 8px 0; display:flex; align-items:flex-start; gap:8px;">
              <span style="font-size:1.2em;color:${statusColor}">${isOpen ? '🟢' : '🔴'}</span>
              <div>
                <div style="font-weight:600;color:${statusColor};margin-bottom:4px;">${statusText}</div>
                <div style="font-size:0.9em;color:#666;">${hours.weekdayDescriptions.map(day => `<div style=\"margin:2px 0;\">${day}</div>`).join('')}</div>
            </div>
            </div>`;
        }
      } catch (error) {
        hoursHtml = `<div style="margin: 8px 0; color: #666;">${p.poi_opening_hours}</div>`;
      }
      if (hoursHtml) contactItems.push(hoursHtml);
    }
    if (contactItems.length > 0) {
      contactSection = `
          <div style="margin: 16px; padding: 16px; background: #f8f9fa; border-radius: 12px;">
          ${contactItems.join('')}
          </div>
        `;
      }
    // Kompatibilita: proměnná zůstává deklarovaná kvůli pozdějšímu použití v sestavení infoRows
    let openingHoursSection = '';

    // Blízké POI (načítáme asynchronně)
    let nearbyPOISection = '';
    if (lat && lng) {
      nearbyPOISection = `
        <div style="margin: 16px; padding: 16px; background: #f8f9fa; border-radius: 12px;">
          <div style="font-weight: 700; color: #049FE8; margin-bottom: 12px; font-size: 1.1em;">${p.post_type === 'charging_location' ? 'Blízká zajímavá místa' : 'Blízké nabíjecí stanice'}</div>
          
          <!-- Detail seznam -->
          <div id="nearby-pois-list" style="min-height: 60px; display: block; color: #666;">
            <div style="text-align: center; padding: 20px;">
              <div style="font-size: 24px; margin-bottom: 8px;">⏳</div>
              <div style="font-weight: 500;">Načítání blízkých míst...</div>
            </div>
          </div>
        </div>
      `;
    }

    // Admin panel HTML (pouze pro adminy/editory)
    const adminPanel = (dbMapData && dbMapData.isAdmin) ? `
      <div class="db-admin-panel">
        <h3><i class="db-icon-admin"></i>Admin panel</h3>
        <div class="db-admin-actions">
          <button class="db-btn db-btn-primary" type="button" data-db-action="open-admin-edit">
            <i class="db-icon-edit"></i>Upravit v admin rozhraní
          </button>
          <div class="db-admin-toggle">
            <label class="db-toggle-label">
              <input type="checkbox" id="db-recommended-toggle" ${p.db_recommended ? 'checked' : ''}>
              <span class="db-toggle-slider"></span>
              DB doporučuje
            </label>
          </div>
          <div class="db-admin-photos">
            <label class="db-photo-label">
              <i class="db-icon-camera"></i>Přidat fotku
              <input type="file" id="db-photo-upload" accept="image/*" multiple style="display: none;">
            </label>
            <div class="db-photo-preview" id="db-photo-preview"></div>
          </div>
        </div>
      </div>
    ` : '';

    // Získat barvu čtverečku pro Detail Modal (stejně jako piny na mapě)
    const getDetailSquareColor = (props) => {
      if (props.post_type === 'charging_location') {
        const mode = getChargerMode(props);
        const acColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.ac) || '#049FE8';
        const dcColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.dc) || '#FFACC4';
        if (mode === 'hybrid') {
          return `linear-gradient(135deg, ${acColor} 0%, ${acColor} 30%, ${dcColor} 70%, ${dcColor} 100%)`;
        }
        return mode === 'dc' ? dcColor : acColor;
      } else if (props.post_type === 'rv_spot') {
        return '#FCE67D';
      } else if (props.post_type === 'poi') {
        // Použij barvu z REST (centrální nastavení Icon_Registry)
        return props.icon_color || '#FCE67D';
      }
      return '#049FE8';
    };

    // Získat originální ikonu pro Detail Modal
    const getDetailIcon = (props) => {
      if (props.svg_content) {
        // Pro POI použít SVG obsah
        return props.svg_content;
      } else if (props.icon_slug) {
        // Pro ostatní typy použít icon_slug
        const iconUrl = getIconUrl(props.icon_slug);
        return iconUrl ? `<img src="${iconUrl}" style="width:100%;height:100%;object-fit:contain;" alt="">` : '📍';
      } else if (props.post_type === 'charging_location') {
        // Fallback pro nabíječky
        return getChargerColoredSvg() || '🔌';
      } else if (props.post_type === 'rv_spot') {
        // Fallback pro RV
        return '🚐';
      }
      return '📍';
    };

    // Sestavení čistého, soběstačného layoutu bez admin odkazů
    // Základní info blok
    const infoRows = [];
    if (ratingInfo) infoRows.push(ratingInfo);
    if (connectorsDetail) infoRows.push(connectorsDetail);
    if (openingHoursSection) infoRows.push(openingHoursSection);
    if (contactSection) infoRows.push(contactSection);

    detailModal.innerHTML = `
      <div class="modal-card">
        <button class="close-btn" aria-label="Zavřít" type="button">✕</button>
        <div class="hero">
          ${img}
        </div>
        ${thumbsHtml}
        <div class="title-row">
          <div class="title-icon" style="background: ${getDetailSquareColor(p)};">
            ${getDetailIcon(p)}
        </div>
        <div class="title">${p.title || ''}</div>
        </div>
        <div class="subtitle">${subtitle}</div>
        ${infoRows.join('')}
        ${photosSection}
        ${nearbyPOISection}
        <div class="actions">
          <button class="btn-outline" type="button" data-db-action="open-navigation-detail" style="margin-bottom: 8px;">Navigace (3 aplikace)</button>
        </div>
        <div class="desc">${p.description || '<span style="color:#aaa;">(Popis zatím není k dispozici)</span>'}</div>
      </div>`;
    
    // Fallback injekce hero obrázku po renderu (případ, kdy heroImageUrl nebyl k dispozici při sestavení)
    (async () => {
      try {
        const heroEl = detailModal.querySelector('.hero');
        if (!heroEl) return;
        const hasImg = !!heroEl.querySelector('img');
        if (hasImg) return;

        // Zkus z props
        let url = p.image || '';
        if (!url && Array.isArray(p.poi_photos) && p.poi_photos.length > 0) {
          const fp = p.poi_photos[0];
          if (fp && typeof fp === 'object') {
            if (fp.url) url = fp.url;
            else if ((fp.photo_reference || fp.photoReference) && dbMapData?.googleApiKey) {
              const ref = fp.photo_reference || fp.photoReference;
              if (ref.startsWith('places/')) {
                // Nové API v1 formát
                url = `https://places.googleapis.com/v1/${ref}/media?maxWidthPx=1200&key=${dbMapData.googleApiKey}`;
              } else if (ref !== 'streetview') {
                // Staré API formát (fallback)
                url = `https://maps.googleapis.com/maps/api/place/photo?maxwidth=1200&photo_reference=${ref}&key=${dbMapData.googleApiKey}`;
              }
            }
          } else if (typeof fp === 'string') {
            url = fp;
          }
        }
        if (!url && p.poi_photo_url) url = p.poi_photo_url;

        // Pokud pořád nic, vytáhni z externího endpointu podle typu
        if (!url) {
          const restBase = (p.post_type === 'charging_location' 
            ? (dbMapData?.chargingExternalUrl || '/wp-json/db/v1/charging-external')
            : (dbMapData?.poiExternalUrl || '/wp-json/db/v1/poi-external')
          ).replace(/\/$/, '');
          const nonce = dbMapData?.restNonce || '';
          const r = await fetch(`${restBase}/${p.id}`, { headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' } });
          if (r.ok) {
            const payload = await r.json();
            url = payload?.data?.photoUrl || '';
          }
        }

        if (url) {
          const imgTag = document.createElement('img');
          imgTag.className = 'hero-img';
          imgTag.src = url; imgTag.alt = '';
          imgTag.style.width = '100%'; imgTag.style.height = '100%';
          imgTag.style.objectFit = 'cover'; imgTag.style.display = 'block';
          heroEl.appendChild(imgTag);
          try { console.log('[DB Map][Detail] hero injected', url); } catch(_) {}
        } else {
          try { console.log('[DB Map][Detail] no hero url found'); } catch(_) {}
        }
      } catch (err) {
        try { console.warn('[DB Map][Detail] hero fallback error', err); } catch(_) {}
      }
    })();

    // Handlery pro jednoduchý carousel – klik na miniaturu nastaví hero
    try {
      const bindThumbClicks = () => {
        const thumbs = detailModal.querySelectorAll('.hero-thumb');
        if (thumbs && thumbs.length) {
          thumbs.forEach(t => {
            t.addEventListener('click', () => {
              const u = t.getAttribute('data-url');
              const heroImg = detailModal.querySelector('.hero .hero-img');
              if (u && heroImg) heroImg.setAttribute('src', u);
            });
          });
        }
      };
      bindThumbClicks();
      // Pokud nejsou k dispozici žádné náhledy, zkus je získat z externího endpointu podle typu a doplnit
      if (!detailModal.querySelector('.hero-thumbs') || !detailModal.querySelector('.hero-thumbs .hero-thumb')) {
        (async () => {
          try {
            const restBase = (p.post_type === 'charging_location' 
              ? (dbMapData?.chargingExternalUrl || '/wp-json/db/v1/charging-external')
              : (dbMapData?.poiExternalUrl || '/wp-json/db/v1/poi-external')
            ).replace(/\/$/, '');
            const nonce = dbMapData?.restNonce || '';
            const r = await fetch(`${restBase}/${p.id}`, { headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' } });
            if (!r.ok) return;
            const payload = await r.json();
            const photos = payload?.data?.photos || [];
            const urls = [];
            for (const ph of photos) {
              if (typeof ph === 'object') {
                if (ph.url) urls.push(ph.url);
                else if ((ph.photo_reference || ph.photoReference) && dbMapData?.googleApiKey) {
                  const ref = ph.photo_reference || ph.photoReference;
                  if (ref === 'streetview' && ph.street_view_url) {
                    urls.push(ph.street_view_url);
                  } else if (ref !== 'streetview') {
                    urls.push(`https://maps.googleapis.com/maps/api/place/photo?maxwidth=800&photo_reference=${ref}&key=${dbMapData.googleApiKey}`);
                  }
                }
              }
              if (urls.length >= 3) break;
            }
            if (urls.length) {
              let cont = detailModal.querySelector('.hero-thumbs');
              if (!cont) {
                cont = document.createElement('div');
                cont.className = 'hero-thumbs';
                const heroEl = detailModal.querySelector('.hero');
                if (heroEl) heroEl.insertAdjacentElement('afterend', cont);
              }
              cont.innerHTML = urls.map(u => `<div style="width:32px;height:32px;border-radius:6px;overflow:hidden;flex-shrink:0;display:inline-block;margin-right:6px;"><img class="hero-thumb" data-url="${u}" src="${u}" alt="" style="width:100%;height:100%;object-fit:cover;cursor:pointer;" /></div>`).join('');
              bindThumbClicks();
            }
          } catch(_) {}
        })();
      }
    } catch(_) {}
    
    // Jistota: vlož hero obrázek i po renderu (pokud chybí <img>)
    try {
      const heroEl = detailModal.querySelector('.hero');
      if (heroEl) {
        const heroIconEl = heroEl.querySelector('.hero-icon');
        if (heroIconEl) heroIconEl.style.pointerEvents = 'none';

        let existingImg = heroEl.querySelector('img');
        if (!existingImg) {
          // Zkonstruovat URL z props ještě jednou (bezpečná fallback logika)
          let url = p.image || '';
          if (!url && Array.isArray(p.poi_photos) && p.poi_photos.length > 0) {
            const fp = p.poi_photos[0];
            if (fp && typeof fp === 'object') {
              if (fp.url) url = fp.url;
              else if ((fp.photo_reference || fp.photoReference) && dbMapData?.googleApiKey) {
                const ref = fp.photo_reference || fp.photoReference;
                if (ref === 'streetview' && fp.street_view_url) {
                  url = fp.street_view_url;
                } else if (ref !== 'streetview') {
                  url = `https://maps.googleapis.com/maps/api/place/photo?maxwidth=1200&photo_reference=${ref}&key=${dbMapData.googleApiKey}`;
                }
              }
            } else if (typeof fp === 'string') {
              url = fp;
            }
          }
          if (!url && p.poi_photo_url) url = p.poi_photo_url;
          if (url) {
            const imgTag = document.createElement('img');
            imgTag.src = url;
            imgTag.alt = '';
            imgTag.style.width = '100%';
            imgTag.style.height = '180px';
            imgTag.style.objectFit = 'cover';
            imgTag.style.display = 'block';
            heroEl.insertBefore(imgTag, heroEl.firstChild);
          }
        }
      }
    } catch (_) {}

    detailModal.classList.add('open');
    
    // Event listener pro close tlačítko
    const closeBtn = detailModal.querySelector('.close-btn');
    if (closeBtn) closeBtn.addEventListener('click', closeDetailModal);
    
    // Event listener pro navigační tlačítko
    const navBtn = detailModal.querySelector('[data-db-action="open-navigation-detail"]');
    if (navBtn) navBtn.addEventListener('click', () => openNavigationMenu(lat, lng));
    
    // Admin panel odstraněn z modalu – žádné admin odkazy v UI
    
    // Centrovat bod na mapu při otevření detail modalu
    if (lat !== null && lng !== null) {
      map.setView([lat, lng], map.getZoom(), { animate: true, duration: 0.5 });
    }
    
    // Načíst blízká místa s malým zpožděním, aby byl modal plně vykreslen
    setTimeout(() => {
      try { 
        // Plná sekce
        loadAndRenderNearby(feature); 
      } catch(e) { 
      }
    }, 100);
  }

  // Sdílená geolokace pro mobilní list
  let userCoords = null;
  async function getUserLocationOnce() {
    if (userCoords) return userCoords;
    const isSecure = (window.isSecureContext === true) || (location.protocol === 'https:') || (location.hostname === 'localhost') || (location.hostname === '127.0.0.1');
    if (!isSecure || !navigator.geolocation) return null;
    try {
      const pos = await new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
      });
      userCoords = [pos.coords.latitude, pos.coords.longitude];
      return userCoords;
    } catch(err) {
      // fallback: Leaflet locate
      try {
        const coords = await new Promise((resolve, reject) => {
          const onOk = (e) => { map.off('locationfound', onOk); map.off('locationerror', onErr); resolve([e.latitude || e.latlng.lat, e.longitude || e.latlng.lng]); };
          const onErr = (e) => { map.off('locationfound', onOk); map.off('locationerror', onErr); resolve(null); };
          map.on('locationfound', onOk);
          map.on('locationerror', onErr);
          map.locate({ setView: false, enableHighAccuracy: true, timeout: 8000, maximumAge: 0 });
        });
        userCoords = coords;
        return coords;
      } catch(_) { return null; }
    }
  }
  async function ensureUserLocationAndSort() {
    const coords = await getUserLocationOnce();
    if (coords) {
      searchAddressCoords = coords;
      sortMode = 'distance_from_address';
      searchSortLocked = true;
      try { const sel = document.getElementById('db-map-list-sort'); if (sel) sel.value = 'distance-address'; } catch(_) {}
      // Kontrola, zda jsou features načtené
      if (features && features.length > 0) {
        renderCards('', null, false);
      }
    } else {
      // Bez polohy zobrazíme vše bez řazení a sdělíme hint (jednorázově)
      if (!document.getElementById('db-list-location-hint')) {
        const hint = document.createElement('div');
        hint.id = 'db-list-location-hint';
        hint.className = 'db-map-nores';
        hint.textContent = 'Povolte prosím zjištění polohy pro seřazení podle vzdálenosti.';
        list.prepend(hint);
      }
      // Kontrola, zda jsou features načtené
      if (features && features.length > 0) {
        renderCards('', null, false);
      }
    }
  }

  // Sticky header v list režimu se stejnými tlačítky + přepínač zpět na mapu
  let listHeader = null;
  function ensureListHeader() {
    if (window.innerWidth > 900) return;
    if (listHeader) return;
    listHeader = document.createElement('div');
    listHeader.id = 'db-list-header';
    listHeader.innerHTML = `
      <button class="db-map-topbar-btn" title="Menu" type="button" id="db-list-menu-toggle">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <button class="db-map-topbar-btn" title="Mapa" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 9 18 15 22 23 18 23 2 15 6 9 2 1 6"/></svg>
      </button>
      <button class="db-map-topbar-btn" title="Moje poloha" type="button" id="db-list-locate-btn">
        <svg width="20px" height="20px" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M249.6 417.088l319.744 43.072 39.168 310.272L845.12 178.88 249.6 417.088zm-129.024 47.168a32 32 0 01-7.68-61.44l777.792-311.04a32 32 0 0141.6 41.6l-310.336 775.68a32 32 0 01-61.44-7.808L512 516.992l-391.424-52.736z"/></svg>
      </button>
      <div style="flex:1"></div>
      <button class="db-map-topbar-btn" title="Filtry" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="12" y2="3"/></svg>
      </button>
      <button class="db-map-topbar-btn" title="Oblíbené" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      </button>
    `;
    // vložím na začátek listu (nad sort)
    if (list && list.firstChild) list.insertBefore(listHeader, list.firstChild); else list.appendChild(listHeader);
    // Handlery
    const listMenuBtn = listHeader.querySelector('#db-list-menu-toggle');
    if (listMenuBtn) listMenuBtn.addEventListener('click', () => {
      // Použij stejnou logiku jako hlavní menu toggle
      const mainMenuBtn = document.querySelector('#db-menu-toggle');
      if (mainMenuBtn) {
        mainMenuBtn.click();
      }
    });
    
    const mapBtn = listHeader.querySelector('.db-map-topbar-btn[title="Mapa"]');
    if (mapBtn) mapBtn.addEventListener('click', () => {
      root.classList.remove('db-list-mode');
      setTimeout(() => map.invalidateSize(), 200);
    });
    
    const listLocateBtn = listHeader.querySelector('#db-list-locate-btn');
    if (listLocateBtn) listLocateBtn.addEventListener('click', function(){
      // Získat aktuální polohu uživatele
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            
            // Centrovat mapu na polohu uživatele
            map.setView([lat, lng], 15, { animate: true, duration: 0.5 });
            
            // Přepnout zpět na mapu
            root.classList.remove('db-list-mode');
            setTimeout(() => map.invalidateSize(), 200);
          },
          function(error) {
            
          },
          {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 60000
          }
        );
      } else {
        
      }
    });
    
    const filterBtn2 = listHeader.querySelector('.db-map-topbar-btn[title="Filtry"]');
    if (filterBtn2) filterBtn2.addEventListener('click', () => {
      filterPanel.style.display = (filterPanel.style.display === 'none' || !filterPanel.style.display) ? 'block' : 'none';
    });
    const favBtn2 = listHeader.querySelector('.db-map-topbar-btn[title="Oblíbené"]');
    if (favBtn2) favBtn2.addEventListener('click', () => {
      favBtn2.classList.toggle('active');
      // Placeholder: zde lze napojit na skutečné oblíbené
    });
  }
  // Vyhledávání na mapě
  let searchQuery = '';
  const searchForm = topbar.querySelector('form.db-map-searchbox');
  const searchInput = topbar.querySelector('#db-map-search-input');
  const searchBtn = topbar.querySelector('#db-map-search-btn');
  // lastSearchResults už inicializováno na začátku
  // Kontrola, zda existují elementy před přidáním event listenerů
  if (searchForm && searchInput && searchBtn) {
    function doSearch(e) {
      if (e) e.preventDefault();
      removeDesktopAutocomplete();
      searchQuery = searchInput.value.trim().toLowerCase();
      renderCards(searchQuery, null, true);
      // Pokud je nalezeno přesně jedno místo, přibliž a zvýrazni
      if (lastSearchResults.length === 1) {
        const idx = features.indexOf(lastSearchResults[0]);
        highlightMarker(idx);
        map.setView([
          lastSearchResults[0].geometry.coordinates[1],
          lastSearchResults[0].geometry.coordinates[0]
        ], 15, {animate:true});
      }
    }
    
    // Přidat autocomplete pro desktop
    const handleDesktopAutocompleteInput = debounce((value) => {
      showDesktopAutocomplete(value, searchInput);
    }, 250);

    searchInput.addEventListener('input', function() {
      const query = this.value.trim();
      if (query.length >= 2) {
        handleDesktopAutocompleteInput(query);
      } else {
        removeDesktopAutocomplete();
      }
    });

    searchInput.addEventListener('focus', function() {
      const query = this.value.trim();
      if (query.length >= 2) {
        showDesktopAutocomplete(query, searchInput);
      }
    });

    searchInput.addEventListener('blur', function() {
      // Dát malé zpoždění, aby kliknutí na autocomplete položku fungovalo
      setTimeout(() => {
        removeDesktopAutocomplete();
      }, 200);
    });
    
    searchForm.addEventListener('submit', doSearch);
    searchBtn.addEventListener('click', doSearch);
    searchInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        removeDesktopAutocomplete();
        doSearch(e);
      }
      if (e.key === 'Escape') {
        removeDesktopAutocomplete();
      }
    });
  }

  // Načti GeoJSON body
  const restUrl = dbMapData?.restUrl || '/wp-json/db/v1/map';
  // Zkusit najít správnou cestu k ikonám
  let iconsBase = dbMapData?.iconsBase || 'assets/icons/';
  
  // Pokud je cesta relativní, použít WordPress plugin URL
  if (iconsBase.startsWith('assets/')) {
    // Zkusit najít WordPress plugin URL
    const scripts = document.querySelectorAll('script[src*="dobity-baterky"]');
    if (scripts.length > 0) {
      const scriptSrc = scripts[0].src;
      const pluginUrl = scriptSrc.substring(0, scriptSrc.lastIndexOf('/assets/'));
      iconsBase = pluginUrl + '/assets/icons/';

    } else {
      // Fallback na aktuální cestu
      const currentPath = window.location.pathname;
      const basePath = currentPath.substring(0, currentPath.lastIndexOf('/'));
      iconsBase = basePath + '/' + iconsBase;

    }
  }
  
  // Charger inline SVG recolor – načtení a cache (abychom nemuseli mít <img> s bílou výplní)
  let __dbChargerSvgColored = null;
  let __dbChargerSvgLoading = false;
  function ensureChargerSvgColoredLoaded() {
    if (__dbChargerSvgColored !== null || __dbChargerSvgLoading) return;
    try {
      const color = (dbMapData && dbMapData.chargerIconColor) || '#ffffff';
      // Nový název souboru bez vnitřního fill: "charger ivon no fill.svg"
      const chargerSvgFile = 'charger ivon no fill.svg';
      const url = (iconsBase || '') + encodeURIComponent(chargerSvgFile);
      if (!url) return;
      __dbChargerSvgLoading = true;
      fetch(url).then(r => r.text()).then(svg => {
        try {
          let s = svg
            .replace(/<svg([^>]*)width="[^"]*"/, '<svg$1')
            .replace(/<svg([^>]*)height="[^"]*"/, '<svg$1')
            .replace(/<svg /, '<svg width="100%" height="100%" style="display:block;" ', 1)
            // Překolorovat pouze vnořené elementy, nikoliv hlavní <svg>
            .replace(/(<(path|g|rect|circle|polygon|ellipse|line|polyline)[^>]*?)\sfill="[^"]*"/gi, `$1`)
            .replace(/(<(path|g|rect|circle|polygon|ellipse|line|polyline)[^>]*?)\sstroke="[^"]*"/gi, `$1`)
            .replace(/<(path|g|rect|circle|polygon|ellipse|line|polyline)([^>]*)>/gi, `<$1$2 fill="${color}" stroke="${color}">`);
          __dbChargerSvgColored = s;
        } catch(_) {}
      }).catch(() => {}).finally(() => { __dbChargerSvgLoading = false; });
    } catch(_) {}
  }
  function getChargerColoredSvg() {
    ensureChargerSvgColoredLoaded();
    return __dbChargerSvgColored;
  }
  
  // Stav filtrů
  const filterState = {
    ac: true,
    dc: true,
    powerMin: 0,
    powerMax: 400,
    connectors: new Set(),
    amenities: new Set(),
    access: new Set(),
  };
  
  // Funkce pro počáteční načtení bodů - zjednodušeno
  async function loadInitialPoints() {
    if (!map) return;
    
    try {
      // Načíst všechny body bez radius filtru
      const restUrl = dbMapData?.restUrl || '/wp-json/db/v1/map';
      const url = new URL(restUrl, window.location.origin);
      
      const response = await fetch(url, {
        headers: { 
          'Accept': 'application/json',
          'X-WP-Nonce': dbMapData.restNonce
        }
      });
      
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      
      const data = await response.json();
      
        if (data.features && Array.isArray(data.features)) {
          features = data.features;
          window.features = features;
          renderCards('', null, false);

      } else {
        features = [];
        window.features = features;
      }
    } catch (error) {
      features = [];
      window.features = features;
    }
  }
  


  // Pomocné funkce
  function getIconUrl(iconSlug) {
    if (!iconSlug) {
        return null;
    }
    
    // Pokud je iconSlug už plná URL, vrátíme ji (s HTTPS opravou pouze pro produkci)
    if (iconSlug.startsWith('http://') || iconSlug.startsWith('https://')) {
        // Na localhost zachovat HTTP, jinak převést na HTTPS
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            return iconSlug;
        }
        return iconSlug.replace(/^http:\/\//, 'https://');
    }
    
    // Pokud je iconSlug jen název souboru bez přípony, přidáme .svg
    let fileName = iconSlug;
    if (!fileName.includes('.')) {
        fileName = fileName + '.svg';
    }
    
    // Pokud je fileName jen název souboru (bez cesty), přidáme cestu k ikonám
    if (!fileName.includes('/')) {
        const iconUrl = `${dbMapData.pluginUrl}assets/icons/${fileName}`;
        // Na localhost zachovat HTTP, jinak převést na HTTPS
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            return iconUrl;
        }
        return iconUrl.replace(/^http:\/\//, 'https://');
    }
    
    // Pokud je fileName s cestou, použijeme ho přímo
    const iconUrl = `${dbMapData.pluginUrl}${fileName}`;
    // Na localhost zachovat HTTP, jinak převést na HTTPS
    if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
        return iconUrl;
    }
    return iconUrl.replace(/^http:\/\//, 'https://');
  }
  
  // Získání ikony konektoru - preferovat SVG z databáze
  function getConnectorIconUrl(connector) {
    if (!connector) return '';

    // 1. Preferovat SVG ikonu z databáze (nový systém)
    if (connector.svg_icon) {
      console.log('[DEBUG] Using database SVG icon');
      return 'data:image/svg+xml;base64,' + btoa(connector.svg_icon);
    }

    // 2. Fallback na ikonu z databáze (WordPress uploads)
    if (connector.icon && connector.icon.trim()) {
      const url = getIconUrl(connector.icon.trim());
      if (url) {
        return url;
      }
    }

    // 3. Fallback na SVG ikony podle typu - ZAKÁZÁNO (generické ikony)
    // const typeKey = getConnectorTypeKey(connector);
    // if (typeKey) {
    //   const iconFile = getConnectorIconByType(typeKey);
    //   if (iconFile) {
    //     // Použít iconsBase z dbMapData (nastaveno v PHP)
    //     const base = dbMapData?.iconsBase || '/wp-content/plugins/dobity-baterky/assets/icons/';
    //     const fullUrl = base + iconFile;
    //     
    //     console.log('[DEBUG] Using fallback SVG icon:', {
    //         typeKey: typeKey,
    //         iconFile: iconFile,
    //         fullUrl: fullUrl
    //     });
    //     
    //     // Na localhost zachovat HTTP, jinak převést na HTTPS
    //     if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
    //       return fullUrl;
    //     }
    //     return fullUrl.replace(/^http:\/\//, 'https://');
    //   }
    // }

    return '';
  }
  
  // Fallback mapování pro typy konektorů - ZAKÁZÁNO (generické ikony)
  // function getConnectorIconByType(connectorType) {
  //   if (!connectorType) return '';
  //   const type = connectorType.toLowerCase().trim();

  //   // Mapování názvů konektorů na soubory
  //   const connectorIconMap = {
  //     'type 2': 'charger_type-11.svg',
  //     'type-2': 'charger_type-11.svg',
  //     'mennekes': 'charger_type-11.svg',
  //     'iec 62196 type 2': 'charger_type-11.svg',

  //     'ccs': 'charger_type-12.svg',
  //     'ccs2': 'charger_type-12.svg',
  //     'ccs combo 2': 'charger_type-12.svg',
  //     'combo 2': 'charger_type-12.svg',

  //     'chademo': 'charger_type-13.svg',

  //     'schuko': 'charger_type-14.svg',
  //     'domaci zasuvka': 'charger_type-14.svg',

  //     'gb/t': 'charger_type-36.svg'
  //   };

  //   return connectorIconMap[type] || '';
  // }
  function getDbLogoHtml(size) {
    const url = (dbMapData && dbMapData.dbLogoUrl) ? dbMapData.dbLogoUrl : null;
    // Bílý podklad pro čitelnost, oranžový obrys dle brandbooku - čtvercový
    if (!url) return '<div style="width:'+size+'px;height:'+size+'px;border-radius:4px;background:#ffffff;border:2px solid #FF6A4B;color:#FF6A4B;display:flex;align-items:center;justify-content:center;font-weight:700;pointer-events:none;">DB</div>';
    return '<div style="width:'+size+'px;height:'+size+'px;border-radius:4px;background:#ffffff;border:2px solid #FF6A4B;display:flex;align-items:center;justify-content:center;pointer-events:none;">'
         +   '<img src="'+encodeURI(url)+'" style="width:'+(Math.max(10, Math.round(size*0.78)))+'px;height:'+(Math.max(10, Math.round(size*0.78)))+'px;object-fit:contain;display:block;" alt="DB" />'
         + '</div>';
  }
  function getFavoriteBadgeHtml(size) {
    const starSvg = '<svg viewBox="0 0 24 24" width="'+Math.max(10, Math.round(size*0.7))+'" height="'+Math.max(10, Math.round(size*0.7))+'" fill="#FF6A4B" xmlns="http://www.w3.org/2000/svg"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    return '<div style="width:'+size+'px;height:'+size+'px;border-radius:4px;background:#ffffff;border:2px solid #FF6A4B;display:flex;align-items:center;justify-content:center;pointer-events:none;">'+starSvg+'</div>';
  }
  function isRecommended(props){
    const v = props && props.db_recommended;
    return v === true || v === 1 || v === '1' || v === 'true';
  }
  
  // Funkce pro převod hex barvy na hue
  function getHueFromColor(hexColor) {
    if (!hexColor || !hexColor.startsWith('#')) return 0;

    const r = parseInt(hexColor.slice(1, 3), 16) / 255;
    const g = parseInt(hexColor.slice(3, 5), 16) / 255;
    const b = parseInt(hexColor.slice(5, 7), 16) / 255;

    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const delta = max - min;

    if (delta === 0) return 0;

    let hue = 0;
    if (max === r) {
      hue = ((g - b) / delta) % 6;
    } else if (max === g) {
      hue = (b - r) / delta + 2;
    } else {
      hue = (r - g) / delta + 4;
    }

    hue = hue * 60;
    if (hue < 0) hue += 360;

    return hue;
  }
  // Paleta dvojic z brandbooku Dobitý Baterky pro zvýraznění aktivních pinů
  const DB_BRAND_HIGHLIGHT_COMBOS = [
    {primary: '#FF6A4B', halo: '#FCE67D'},
    {primary: '#049FE8', halo: '#FFACC4'},
    {primary: '#024B9B', halo: '#FCE67D'},
    {primary: '#FF8DAA', halo: '#049FE8'},
    {primary: '#FFACC4', halo: '#024B9B'}
  ];
  function normalizeHexColor(color) {
    if (typeof color !== 'string') return null;
    const trimmed = color.trim();
    const match = trimmed.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
    if (!match) return null;
    let hex = match[1];
    if (hex.length === 3) {
      hex = hex.split('').map(ch => ch + ch).join('');
    }
    return '#' + hex.toUpperCase();
  }
  function hexToRgbComponents(hex) {
    const normalized = normalizeHexColor(hex);
    if (!normalized) return null;
    const value = normalized.slice(1);
    const r = parseInt(value.slice(0, 2), 16);
    const g = parseInt(value.slice(2, 4), 16);
    const b = parseInt(value.slice(4, 6), 16);
    return [r, g, b];
  }
  function hexToRgba(hex, alpha) {
    const rgb = hexToRgbComponents(hex);
    if (!rgb) return '';
    const a = typeof alpha === 'number' ? Math.min(Math.max(alpha, 0), 1) : 1;
    return `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, ${a})`;
  }
  function getRelativeLuminance(hex) {
    const rgb = hexToRgbComponents(hex);
    if (!rgb) return 0;
    const srgb = rgb.map(v => {
      const channel = v / 255;
      return channel <= 0.03928 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * srgb[0] + 0.7152 * srgb[1] + 0.0722 * srgb[2];
  }
  function getContrastRatio(colorA, colorB) {
    const lumA = getRelativeLuminance(colorA);
    const lumB = getRelativeLuminance(colorB);
    const [lighter, darker] = lumA >= lumB ? [lumA, lumB] : [lumB, lumA];
    return (lighter + 0.05) / (darker + 0.05);
  }
  function buildHighlightFromCombo(combo) {
    const ringHex = normalizeHexColor(combo && combo.primary) || '#FF6A4B';
    const haloHex = normalizeHexColor(combo && combo.halo) || '#FCE67D';
    return {
      ringColor: ringHex,
      haloColor: hexToRgba(haloHex, 0.75),
      glowColor: hexToRgba(ringHex, 0.5),
      innerColor: hexToRgba(ringHex, 0.65),
      haloBase: haloHex
    };
  }
  function getBrandHighlightColors(opts = {}) {
    const { baseColor = null, mode = null } = opts;
    if (mode === 'hybrid') {
      return buildHighlightFromCombo(DB_BRAND_HIGHLIGHT_COMBOS[0]);
    }
    const reference = normalizeHexColor(baseColor)
      || (mode === 'dc' ? '#FFACC4' : mode === 'ac' ? '#049FE8' : null);
    if (reference) {
      let bestCombo = null;
      let bestContrast = -1;
      DB_BRAND_HIGHLIGHT_COMBOS.forEach(combo => {
        const primary = normalizeHexColor(combo.primary);
        if (!primary) return;
        const contrast = getContrastRatio(reference, primary);
        if (contrast > bestContrast) {
          bestContrast = contrast;
          bestCombo = combo;
        }
      });
      if (!bestCombo) {
        return buildHighlightFromCombo(DB_BRAND_HIGHLIGHT_COMBOS[0]);
      }
      if (normalizeHexColor(bestCombo.primary) === reference) {
        const alternative = DB_BRAND_HIGHLIGHT_COMBOS.find(combo => normalizeHexColor(combo.primary) !== reference);
        if (alternative) {
          bestCombo = alternative;
        }
      }
      return buildHighlightFromCombo(bestCombo);
    }
    return buildHighlightFromCombo(DB_BRAND_HIGHLIGHT_COMBOS[0]);
  }
  function getMainLabel(props) {
    if (props.post_type === 'charging_location') {
      return [props.provider, props.speed?.toUpperCase()].filter(Boolean).join(' • ');
    }
    if (props.post_type === 'rv_spot') {
      return props.rv_type || '';
    }
    if (props.post_type === 'poi') {
      return props.poi_type || '';
    }
    return '';
  }
  function haversine(lat1, lng1, lat2, lng2) {
    const R = 6371000;
    const toRad = x => x * Math.PI / 180;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLng/2)**2;
    return Math.round(R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)) / 10) * 10;
  }
  function gmapsUrl(lat, lng) {
    return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
  }
  function appleMapsUrl(lat, lng) {
    return `https://maps.apple.com/?daddr=${lat},${lng}`;
  }
  function mapyCzUrl(lat, lng) {
    // Otevře bod na Mapy.cz; pro většinu uživatelů snadno spustitelné i pro navigaci
    const x = lng, y = lat;
    return `https://mapy.cz/zakladni?source=coor&id=${x},${y}&x=${x}&y=${y}&z=16`;
  }

  // --- AUTOCOMPLETE ADRESY (NOMINATIM) ---
  function createAddressAutocomplete(input, onSelect) {
    if (!input || !input.parentNode) {
      return; // Tichý return bez console.warn
    }
    
    let acWrap = document.createElement('div');
    acWrap.className = 'db-map-ac-wrap';
    acWrap.style.cssText = 'position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e5e7eb;border-radius:0 0 1em 1em;box-shadow:0 2px 8px #0002;display:none;max-height:220px;overflow-y:auto;z-index:1000;';
    input.parentNode.style.position = 'relative';
    input.parentNode.appendChild(acWrap);
    let lastResults = [];
    let lastTimeout = null;
    input.addEventListener('input', function() {
      let q = input.value.trim();
      if (lastTimeout) clearTimeout(lastTimeout);
      if (!q) { acWrap.style.display = 'none'; return; }
      lastTimeout = setTimeout(async () => {
        try {
          // Získat lokalitu prohlížeče
          const locale = await getBrowserLocale();
          const userCoords = locale.coords;
          
          // Sestavit URL s geografickými omezeními podle detekované lokality
          let searchUrl = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&addressdetails=1&limit=10&accept-language=cs`;
          
          // Dynamické omezení podle detekované země
          const countryConfig = getCountrySearchConfig(locale.country);
          searchUrl += `&countrycodes=${countryConfig.countrycodes}`;
          searchUrl += `&bounded=1&viewbox=${countryConfig.viewbox}`;
          
          // Pokud máme pozici uživatele, přidat ji pro prioritizaci
          if (userCoords) {
            searchUrl += `&lat=${userCoords[0]}&lon=${userCoords[1]}`;
          }
          
          const response = await fetch(searchUrl);
          const results = await response.json();
          
          // Prioritizace výsledků
          const prioritizedResults = prioritizeSearchResults(results, userCoords);
          lastResults = prioritizedResults;
          
            acWrap.innerHTML = '';
          if (!prioritizedResults.length) { acWrap.style.display = 'none'; return; }
          
          prioritizedResults.slice(0, 6).forEach((r, i) => {
              let d = document.createElement('div');
              d.className = 'db-map-ac-item';
            
            // Přidat informace o vzdálenosti a zemi
            const distance = r._distance ? ` (${Math.round(r._distance)} km)` : '';
            const country = r._country ? ` - ${r._country}` : '';
            d.innerHTML = `
              <div style="font-weight: 500;">${r.display_name.split(',')[0]}</div>
              <div style="font-size: 0.9em; color: #666; margin-top: 2px;">${r.display_name}${distance}${country}</div>
            `;
            
              d.tabIndex = 0;
              d.addEventListener('mousedown', e => { e.preventDefault(); onSelect(r); acWrap.style.display = 'none'; });
              d.addEventListener('keydown', e => { if (e.key === 'Enter') { onSelect(r); acWrap.style.display = 'none'; } });
              acWrap.appendChild(d);
            });
            acWrap.style.display = 'block';
        } catch (error) {
          acWrap.style.display = 'none';
        }
      }, 250);
    });
    input.addEventListener('blur', () => setTimeout(() => acWrap.style.display = 'none', 200));
  }

  // --- LOGIKA VYHLEDÁVÁNÍ A ŘAZENÍ PODLE ADRESY ---
  // searchAddressCoords a searchSortLocked už inicializovány na začátku
  function sortByDistanceFrom(lat, lon) {
    features.forEach(f => {
      let d = getDistance(lat, lon, f.geometry.coordinates[1], f.geometry.coordinates[0]);
      f._distance = d;
    });
    features.sort((a, b) => (a._distance||1e9)-(b._distance||1e9));
  }
  function getDistance(lat1, lon1, lat2, lon2) {
    // Haversine
    const R = 6371000;
    const toRad = x => x * Math.PI / 180;
    const dLat = toRad(lat2-lat1);
    const dLng = toRad(lon2-lon1);
    const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLng/2)**2;
    return Math.round(R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)));
  }

  // --- GLOBÁLNÍ PROMĚNNÁ PRO VYHLEDÁVACÍ PIN ---
  // searchAddressMarker už inicializován na začátku

  // --- ÚPRAVA VYHLEDÁVACÍHO ŘÁDKU ---
  if (searchInput && searchInput.parentNode) {
    createAddressAutocomplete(searchInput, function(result) {
      searchInput.value = result.display_name;
      searchAddressCoords = [parseFloat(result.lat), parseFloat(result.lon)];
      sortByDistanceFrom(searchAddressCoords[0], searchAddressCoords[1]);
      sortMode = 'distance_from_address';
      searchSortLocked = true;
      renderCards('', null, false);
      // Přibliž mapu na adresu
      map.setView(searchAddressCoords, 13, {animate:true});
      // Přidej/obnov vyhledávací pin
      addOrMoveSearchAddressMarker(searchAddressCoords);
    });
  }

  async function doAddressSearch(e) {
    if (e) e.preventDefault();
    if (!searchInput) return; // Kontrola existence searchInput
    
    let q = searchInput.value.trim();
    if (!q) return;
    
    try {
      // Získat lokalitu prohlížeče
      const locale = await getBrowserLocale();
      const userCoords = locale.coords;
      
      // Sestavit URL s geografickými omezeními podle detekované lokality
      let searchUrl = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&addressdetails=1&limit=10&accept-language=cs`;
      
      // Dynamické omezení podle detekované země
      const countryConfig = getCountrySearchConfig(locale.country);
      searchUrl += `&countrycodes=${countryConfig.countrycodes}`;
      searchUrl += `&bounded=1&viewbox=${countryConfig.viewbox}`;
      
      // Pokud máme pozici uživatele, přidat ji pro prioritizaci
      if (userCoords) {
        searchUrl += `&lat=${userCoords[0]}&lon=${userCoords[1]}`;
      }
      
      const response = await fetch(searchUrl);
      const results = await response.json();
      
        if (!results.length) return;
      
      // Prioritizace výsledků
      const prioritizedResults = prioritizeSearchResults(results, userCoords);
      const result = prioritizedResults[0];
      
        searchInput.value = result.display_name;
        searchAddressCoords = [parseFloat(result.lat), parseFloat(result.lon)];
        sortByDistanceFrom(searchAddressCoords[0], searchAddressCoords[1]);
        sortMode = 'distance_from_address';
        searchSortLocked = true;
        renderCards('', null, false);
        map.setView(searchAddressCoords, 13, {animate:true});
        addOrMoveSearchAddressMarker(searchAddressCoords);
    } catch (error) {
    }
  }
  
  // Kontrola existence elementů před přidáním event listenerů
  if (searchForm && searchBtn && searchInput) {
    searchForm.addEventListener('submit', doAddressSearch);
    searchBtn.addEventListener('click', doAddressSearch);
  }

  // --- FUNKCE PRO VYHLEDÁVACÍ PIN ---
  function addOrMoveSearchAddressMarker(coords) {
    if (!map) return; // Kontrola existence mapy
    
    if (searchAddressMarker) {
      map.removeLayer(searchAddressMarker);
      searchAddressMarker = null;
    }
    
    // Vytvořit marker pro aktuální polohu uživatele
    searchAddressMarker = L.marker(coords, {
      icon: L.divIcon({
        className: 'db-map-search-marker',
        iconSize: [36, 36],
        html: `<svg width="36" height="36" viewBox="0 0 36 36">
          <circle cx="18" cy="18" r="16" fill="#0074d9" stroke="#fff" stroke-width="2"/>
          <circle cx="18" cy="18" r="6" fill="#fff"/>
          <circle cx="18" cy="18" r="2" fill="#0074d9"/>
        </svg>`
      }),
      interactive: false,
      zIndexOffset: 1000
    }).addTo(map);
    
    // Přidat popup s informací o poloze
    searchAddressMarker.bindPopup('Vaše aktuální poloha', {
      closeButton: false,
      autoClose: false,
      closeOnClick: false
    });
  }

  // --- SORTBY: pokud uživatel změní sortby, zruš searchSortLocked a smaž pin ---
  const sortbySelect = document.querySelector('#db-map-list-sort');
  // activeIdxGlobal už inicializován na začátku
  if (sortbySelect) {
    sortbySelect.addEventListener('change', function() {
      if (sortMode !== 'distance-address') searchSortLocked = false;
      if (!searchSortLocked) {
        searchAddressCoords = null;
        if (searchAddressMarker && map) {
          map.removeLayer(searchAddressMarker);
          searchAddressMarker = null;
        }
      }
      sortMode = sortbySelect.value;
      // Pokud je režim vzdálenost od aktivního bodu, použijeme poslední aktivní index
      if (sortMode === 'distance-active' && activeIdxGlobal !== null) {
        if (typeof renderCards === 'function') {
          renderCards('', activeIdxGlobal, false);
        }
      } else {
        if (typeof renderCards === 'function') {
          renderCards('', null, false);
        }
      }
    });
  }

  // Pomocná funkce: najdi index feature podle ID
  function findFeatureIndexById(id) {
    return features.findIndex(f => f.properties.id === id);
  }

  // Globální pole markerů - už inicializováno na začátku

  // Funkce pro odstranění všech markerů z mapy
  function clearMarkers() {
    if (typeof clusterChargers !== 'undefined') {
      clusterChargers.clearLayers();
    }
    if (typeof clusterRV !== 'undefined') {
      clusterRV.clearLayers();
    }
    if (typeof clusterPOI !== 'undefined') {
      clusterPOI.clearLayers();
    }
    
    markers = [];
  }
  // Upravíme renderCards, aby synchronizovala markery s panelem
  function renderCards(filterText = '', activeId = null, isSearch = false) {

    // Kontrola, zda jsou potřebné proměnné inicializované
    if (typeof markers === 'undefined' || !Array.isArray(markers)) {

      return;
    }

    // Kontrola cardsWrap
    if (typeof cardsWrap === 'undefined') {
      return;
    }

    const normalizedIncomingActiveId = (typeof activeId === 'string') ? parseInt(activeId, 10) : activeId;
    const hasIncomingActive = Number.isFinite(normalizedIncomingActiveId);
    if (hasIncomingActive) {
      let resolvedId = normalizedIncomingActiveId;
      if (findFeatureIndexById(normalizedIncomingActiveId) === -1) {
        const candidate = features[normalizedIncomingActiveId];
        const candidateIdRaw = candidate && candidate.properties ? candidate.properties.id : null;
        const candidateId = (typeof candidateIdRaw === 'string') ? parseInt(candidateIdRaw, 10) : candidateIdRaw;
        if (Number.isFinite(candidateId)) {
          resolvedId = candidateId;
        }
      }
      activeFeatureId = resolvedId;
    }

    const effectiveActiveId = Number.isFinite(activeFeatureId) ? activeFeatureId : null;
    if (effectiveActiveId !== null) {
      const idx = findFeatureIndexById(effectiveActiveId);
      activeIdxGlobal = idx >= 0 ? idx : null;
    }

    cardsWrap.innerHTML = '';
    let filtered = features.filter(f => f.properties.title.toLowerCase().includes(filterText.toLowerCase()));

    if (showOnlyRecommended) {
      filtered = filtered.filter(f => !!f.properties.db_recommended);
    }
    // Aplikovat filtry pro nabíječky
    filtered = filtered.filter(f => {
      const p = f.properties || {};
      if (p.post_type !== 'charging_location') return true;
      const mode = getChargerMode(p);
      const allowAc = filterState.ac; const allowDc = filterState.dc;
      const modePass = (mode === 'ac' && allowAc) || (mode === 'dc' && allowDc) || (mode === 'hybrid' && (allowAc || allowDc));
      if (!modePass) return false;
      const maxKw = getStationMaxKw(p);
      if (maxKw < filterState.powerMin || maxKw > filterState.powerMax) return false;

      if (filterState.connectors && filterState.connectors.size > 0) {
        const arr = Array.isArray(p.connectors) ? p.connectors : (Array.isArray(p.konektory) ? p.konektory : []);
        const keys = new Set(arr.map(getConnectorTypeKey));
        let ok = false; filterState.connectors.forEach(sel => { if (keys.has(String(sel).toLowerCase())) ok = true; });
        if (!ok) return false;
      }
      return true;
    });
    
    // Radius filtr zrušen - necháváme všechny body
    lastSearchResults = filtered;
    if (isSearch && filterText && filtered.length === 0) {
      const nores = document.createElement('div');
      nores.style.padding = '2em 1em';
      nores.style.textAlign = 'center';
      nores.style.color = '#888';
      nores.textContent = 'Zkus to jinak - objevujeme víc než jen cíl cesty.';
      cardsWrap.appendChild(nores);
      setTimeout(() => map.invalidateSize(), 50);
      // Kontrola před voláním clearMarkers
      if (typeof clearMarkers === 'function') {
        clearMarkers();
      }
      return;
    }

    if (effectiveActiveId !== null) {
      const activeStillVisible = filtered.some(f => f.properties.id === effectiveActiveId);
      if (!activeStillVisible) {
        activeFeatureId = null;
        activeIdxGlobal = null;
      }
    }

    const renderActiveId = Number.isFinite(activeFeatureId) ? activeFeatureId : null;

    // Řazení podle sortMode
    let sort = searchSortLocked ? 'distance_from_address' : sortMode;
    // Sjednocený výpočet vzdálenosti
    if ((sort === 'distance_from_address' && searchAddressCoords) || (sort === 'distance-address' && addressCoords)) {
      // Vzdálenost od adresy
      const coords = searchAddressCoords || addressCoords;
      filtered.forEach(f => {
        f._distance = getDistance(coords[0], coords[1], f.geometry.coordinates[1], f.geometry.coordinates[0]);
      });
      filtered.sort((a, b) => (a._distance||1e9)-(b._distance||1e9));
    } else if (sort === 'distance-active' && (renderActiveId !== null || activeIdxGlobal !== null)) {
      // Vzdálenost od aktivního bodu (po kliknutí na pin/kartu)
      const idx = activeIdxGlobal !== null ? activeIdxGlobal : findFeatureIndexById(renderActiveId);
      const active = features[idx];
      if (active) {
        filtered.forEach(f => {
          f._distance = getDistance(
            active.geometry.coordinates[1], active.geometry.coordinates[0],
            f.geometry.coordinates[1], f.geometry.coordinates[0]
          );
        });
        filtered.sort((a, b) => (a._distance||1e9)-(b._distance||1e9));
      }
    }
    // Pokud je aktivní ID, přesuneme aktivní bod na začátek
    if (renderActiveId !== null && filtered.length > 1 && sort === 'distance-active') {
      const idxInFiltered = filtered.findIndex(f => f.properties.id === renderActiveId);
      if (idxInFiltered > 0) {
        const [active] = filtered.splice(idxInFiltered, 1);
        filtered.unshift(active);
      }
    }
    // Odstraníme staré markery - kontrola před voláním
    if (typeof clearMarkers === 'function') {
      clearMarkers();
    }
    

    
    // Vytvoříme nové markery pouze pro aktuální filtered body
    filtered.forEach((f, i) => {
      const {geometry, properties: p} = f;
      if (!geometry || !geometry.coordinates) {
        
        return;
      }
      const [lng, lat] = geometry.coordinates;
      
      function getMarkerHtml(active) {
        const size = active ? 48 : 32;
        const overlaySize = active ? 24 : 16;
        const overlayPos = active ? 12 : 8;
        const markerMode = p.post_type === 'charging_location' ? getChargerMode(p) : null;

        // Výplň markeru: pro nabíječky AC/DC/hybrid gradient; jiné typy berou icon_color
        let fill = p.icon_color || '#049FE8';
        let defs = '';
        const pinPath = 'M16 2C9.372 2 4 7.372 4 14c0 6.075 8.06 14.53 11.293 17.293a1 1 0 0 0 1.414 0C19.94 28.53 28 20.075 28 14c0-6.628-5.372-12-12-12z';

        if (p.post_type === 'charging_location') {
          const cf = getChargerFill(p, active);
          fill = cf.fill;
          defs = cf.defs;
        }

        const baseColorForHighlight = (() => {
          if (p.post_type === 'charging_location') {
            if (markerMode === 'dc') return '#FFACC4';
            if (markerMode === 'ac') return '#049FE8';
            return null;
          }
          return p.icon_color || null;
        })();
        const highlightColors = active ? getBrandHighlightColors({ baseColor: baseColorForHighlight, mode: markerMode }) : null;
        let strokeColor = 'none';
        let strokeWidth = 0;
        if (active) {
          // Sjednocená barva rámečku pro všechny piny
          const borderColorUnified = '#FF6A4B';
          strokeColor = borderColorUnified;
          // Zúžit tloušťku o 50 % oproti předchozí (3.5 → 1.75)
          strokeWidth = 1.75;
        }
        const styleParts = [
          'position:relative',
          `width:${size}px`,
          `height:${size}px`,
          'display:inline-block'
        ];
        const styleAttr = styleParts.join(';');
        const dbLogo = isRecommended(p) ? `<div style="position:absolute;right:-4px;bottom:-4px;width:${overlaySize}px;height:${overlaySize}px;">${getDbLogoHtml(overlaySize)}</div>` : '';
        const markerClass = active ? 'db-marker db-marker-active' : 'db-marker';
        return `
          <div class="${markerClass}" data-idx="${i}" style="${styleAttr}">
            <svg class="db-marker-pin" width="${size}" height="${size}" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
              ${defs}
              <path class="db-marker-pin-outline" d="${pinPath}" fill="${fill}" stroke="${strokeColor}" stroke-width="${strokeWidth}" stroke-linejoin="round" stroke-linecap="round"/>
            </svg>
            <div style="position:absolute;left:${overlayPos}px;top:${overlayPos-2}px;width:${overlaySize}px;height:${overlaySize}px;display:flex;align-items:center;justify-content:center;">
              ${p.svg_content ? p.svg_content : (p.icon_slug ? `<img src="${getIconUrl(p.icon_slug)}" style="width:100%;height:100%;display:block;" alt="">` : (p.post_type === 'charging_location' ? (getChargerColoredSvg() || '') : ''))}
            </div>
            ${dbLogo}
          </div>`;
      }
      const isActiveMarker = renderActiveId !== null && p.id === renderActiveId;
      const defaultIcon = L.divIcon({
        className: 'db-marker',
        iconSize: [32, 32],
        html: getMarkerHtml(false)
      });
      const activeIcon = L.divIcon({
        className: 'db-marker db-marker-active',
        iconSize: [48, 48],
        html: getMarkerHtml(true)
      });
      const marker = L.marker([lat, lng], {icon: isActiveMarker ? activeIcon : defaultIcon, _dbProps: p});
      if (typeof clusterChargers !== 'undefined' && typeof clusterRV !== 'undefined' && typeof clusterPOI !== 'undefined') {
        if (p.post_type === 'charging_location') {
          clusterChargers.addLayer(marker);
        } else if (p.post_type === 'rv_spot') {
          clusterRV.addLayer(marker);
        } else if (p.post_type === 'poi') {
          clusterPOI.addLayer(marker);
      } else {
          clusterPOI.addLayer(marker);
        }
      } else {
        // Fallback: přidat přímo na mapu
        if (typeof map !== 'undefined') {
          marker.addTo(map);
        }
      }
      marker._defaultIcon = defaultIcon;
      marker._activeIcon = activeIcon;
      marker._featureId = p.id;
      if (isActiveMarker) {
        marker.setZIndexOffset(1001);
      }
      marker.on('click', (e) => {
        if (e.originalEvent && typeof e.originalEvent.stopPropagation === 'function') {
          e.originalEvent.stopPropagation();
        }
        if (e.originalEvent && (e.originalEvent.metaKey || e.originalEvent.ctrlKey)) {
          // Ctrl/Cmd+click otevře detail jako modal
          openDetailModal(f);
          return;
        }
        // Primárně otevři spodní náhled (sheet) a zvýrazni pin; modal jen když to uživatel vyžádá
        highlightCardById(p.id);
        
        // Otevři mobile sheet na mobilu nebo detail modal na desktopu
        if (window.innerWidth <= 900) {
          openMobileSheet(f);
        } else {
          openDetailModal(f);
        }
        map.setView([lat, lng], 15, {animate:true});
        sortMode = 'distance-active';
        renderCards('', p.id);
      });
      // Double-click na marker: otevři modal s detailem
      marker.on('dblclick', () => {
        openDetailModal(f);
      });
      markers.push(marker);
    });
    // Panel karet
    filtered.forEach((f, i) => {
      const p = f.properties;
      let imgHtml = '';
      if (p.image) {
        imgHtml = `<img class="db-map-card-img" src="${p.image}" alt="">`;
      } else {
        // Fallback na SVG ikonu z pinu nebo default ikonu
        let fallbackIcon = '';
        if (p.svg_content) {
          fallbackIcon = p.svg_content;
        } else if (p.icon_slug) {
          fallbackIcon = `<img src="${getIconUrl(p.icon_slug)}" style="width:100%;height:100%;object-fit:contain;" alt="">`;
        } else if (p.post_type === 'charging_location') {
          // Pro nabíjecí místa použít hybridní pin ikonu
          const mode = getChargerMode(p);
          const acColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.ac) || '#049FE8';
          const dcColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.dc) || '#FFACC4';
          if (mode === 'hybrid') {
            fallbackIcon = `<svg width="100%" height="100%" viewBox="0 0 32 32"><defs><linearGradient id="card-grad-${p.id}" x1="0" y1="0" x2="1" y2="0"><stop offset="0%" stop-color="${acColor}"/><stop offset="100%" stop-color="${dcColor}"/></linearGradient></defs><path d="M16 2C9.372 2 4 7.372 4 14c0 6.075 8.06 14.53 11.293 17.293a1 1 0 0 0 1.414 0C19.94 28.53 28 20.075 28 14c0-6.628-5.372-12-12-12z" fill="url(#card-grad-${p.id})"/></svg>`;
          } else {
            const color = mode === 'dc' ? dcColor : acColor;
            fallbackIcon = `<svg width="100%" height="100%" viewBox="0 0 32 32"><path d="M16 2C9.372 2 4 7.372 4 14c0 6.075 8.06 14.53 11.293 17.293a1 1 0 0 0 1.414 0C19.94 28.53 28 20.075 28 14c0-6.628-5.372-12-12-12z" fill="${color}"/></svg>`;
          }
        } else {
          // Default ikona pro ostatní typy – použij centrální barvy
          if (p.post_type === 'rv_spot') {
            const rvColor = (dbMapData && dbMapData.rvColor) || '#FCE67D';
            fallbackIcon = `<svg width="100%" height="100%" viewBox="0 0 32 32"><path d="M16 2C9.372 2 4 7.372 4 14c0 6.075 8.06 14.53 11.293 17.293a1 1 0 0 0 1.414 0C19.94 28.53 28 20.075 28 14c0-6.628-5.372-12-12-12z" fill="${rvColor}"/></svg>`;
          } else if (p.post_type === 'poi') {
            const poiColor = (dbMapData && dbMapData.poiColor) || '#FCE67D';
            fallbackIcon = `<svg width="100%" height="100%" viewBox="0 0 32 32"><path d="M16 2C9.372 2 4 7.372 4 14c0 6.075 8.06 14.53 11.293 17.293a1 1 0 0 0 1.414 0C19.94 28.53 28 20.075 28 14c0-6.628-5.372-12-12-12z" fill="${poiColor}"/></svg>`;
          } else {
            const acColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.ac) || '#049FE8';
            fallbackIcon = `<svg width="100%" height="100%" viewBox="0 0 32 32"><path d="M16 2C9.372 2 4 7.372 4 14c0 6.075 8.06 14.53 11.293 17.293a1 1 0 0 0 1.414 0C19.94 28.53 28 20.075 28 14c0-6.628-5.372-12-12-12z" fill="${acColor}"/></svg>`;
          }
        }
        const bgColor = (p.post_type === 'rv_spot')
          ? ((dbMapData && dbMapData.rvColor) || '#FCE67D')
          : (p.post_type === 'poi')
            ? ((dbMapData && dbMapData.poiColor) || '#FCE67D')
            : ((dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.ac) || '#049FE8');
        imgHtml = `<div class="db-map-card-img" style="background:${bgColor};display:flex;align-items:center;justify-content:center;border:1px solid #e5e7eb;">${fallbackIcon}</div>`;
      }
      const card = document.createElement('div');
      card.className = 'db-map-card';
      card.tabIndex = 0;
      card.dataset.featureId = String(f.properties.id);
      if (renderActiveId !== null && f.properties.id === renderActiveId) card.classList.add('active');
      // Vzdálenost v km, výrazně vlevo pod obrázkem
      let distHtml = '';
      if (f._distance !== undefined) {
        const distKm = (f._distance / 1000).toFixed(2);
        distHtml = `<div class="db-map-card-distance"><span style="font-weight:700;font-size:1.3em;">${distKm}</span> <span style="font-weight:400;font-size:1em;">km</span></div>`;
      }
      // Akce: navigace a info SVG ikony pod sebe
      const navIcon = `<button class="db-map-card-action-btn" title="Navigovat">`
        + `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 19 21 12 17 5 21 12 2"/><line x1="12" y1="17" x2="12" y2="22"/></svg>`
        + `</button>`;
      const infoIcon = `<button class="db-map-card-action-btn" title="Více informací">`
        + `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="8"/></svg>`
        + `</button>`;
      // Po vykreslení karty zavoláme loader až po vložení HTML níže
      // Typ POI nebo info o nabíječce
      let typeHtml = '';
      if (p.post_type === 'poi') {
        typeHtml = `<div class="db-map-card-label">${p.poi_type || ''}</div>`;
      } else if (p.post_type === 'charging_location') {
        // Pro nabíjecí místa: pouze ikony konektorů + počet
        let connectors = Array.isArray(p.connectors) ? p.connectors : (Array.isArray(p.konektory) ? p.konektory : null);
        if (connectors && connectors.length) {
  
          
          // Seskupit konektory podle typu a spočítat
          const connectorCounts = {};
          connectors.forEach(c => {
            const type = c.type || c.typ || '';
            if (type) {
              connectorCounts[type] = (connectorCounts[type] || 0) + 1;
            }
          });
          
          // Vytvořit ikony s počtem - použít celé objekty konektorů
          const connectorIcons = Object.entries(connectorCounts).map(([type, count]) => {
            // Najít první konektor daného typu pro získání ikony
            const connector = connectors.find(c => (c.type || c.typ || '') === type);
            
            const iconUrl = getConnectorIconUrl(connector);
            
            // Fallback text pro případ, že se ikona nenačte
            const fallbackText = type.length > 3 ? type.substring(0, 3).toUpperCase() : type.toUpperCase();
            
            if (iconUrl) {
              return `<div style="display:inline-flex;align-items:center;gap:4px;margin-right:8px;">
                <img src="${iconUrl}" style="width:16px;height:16px;object-fit:contain;" alt="${type}" onerror="this.innerHTML='<span style=\\'color:#666;font-size:0.8em;\\'>${fallbackText}</span>'">
                <span style="font-size:0.8em;color:#666;">${count}</span>
              </div>`;
            } else {
              // Pouze text, pokud není ikona
              return `<div style="display:inline-flex;align-items:center;gap:4px;margin-right:8px;padding:2px 6px;background:#f0f0f0;border-radius:4px;">
                <span style="color:#666;font-size:0.8em;">${fallbackText}</span>
                <span style="font-size:0.8em;color:#666;">${count}</span>
              </div>`;
            }
          }).join('');
          
          typeHtml = `<div class="db-map-card-connectors" style="display:flex;align-items:center;flex-wrap:wrap;gap:4px;margin-bottom:0.3em;">${connectorIcons}</div>`;
        }
      } else if (p.post_type === 'rv_spot') {
        typeHtml = `<div class="db-map-card-label">${p.rv_type || ''}</div>`;
      }
      const titleHtml = p.permalink
        ? `<a class="db-map-card-title" href="${p.permalink}" target="_blank" rel="noopener">${p.title}</a>`
        : `<div class="db-map-card-title">${p.title}</div>`;
      card.innerHTML = `
        <div style="display:flex;align-items:flex-start;gap:1em;">
          <div style="display:flex;flex-direction:column;align-items:center;min-width:64px;">
            ${imgHtml}
            ${distHtml}
            <div class="db-map-card-rating" style="margin:0.3em 0;display:flex;align-items:center;justify-content:center;color:#FF6A4B;font-size:0.8em;">
              <span style="margin-right:2px;">★</span>
              <span>${p.rating || '4.2'}</span>
            </div>
            <div style="margin:0.3em 0;">${navIcon}</div>
            <div>${infoIcon}</div>
          </div>
                      <div class="db-map-card-content">
              ${titleHtml}
              ${typeHtml}
              <div class="db-map-card-desc">${p.description || '<span style="color:#aaa;">(Popis zatím není k&nbsp;dispozici)</span>'}</div>
              ${p.post_type === 'charging_location' ? (() => {
                let additionalInfo = '';

                // Poskytovatel/operátor
                if (p.operator_original || p.provider) {
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Poskytovatel:</strong> ${p.operator_original || p.provider}</div>`;
                }

                if (!p.operator_original && !p.provider && p.charging_ocm_details && p.charging_ocm_details.data_provider) {
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Poskytovatel:</strong> ${p.charging_ocm_details.data_provider}</div>`;
                }

                // Maximální výkon stanice
                if (p.station_max_power_kw) {
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Max. výkon:</strong> ${p.station_max_power_kw} kW</div>`;
                }

                // Počet nabíjecích bodů
                if (p.evse_count) {
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Nabíjecí body:</strong> ${p.evse_count}</div>`;
                }

                if (typeof p.charging_live_available === 'number' && typeof p.charging_live_total === 'number') {
                  const available = Math.max(0, p.charging_live_available);
                  const total = Math.max(0, p.charging_live_total);
                  const ratio = total > 0 ? (available / total) : 0;
                  let color = '#ef4444';
                  if (ratio >= 0.5) color = '#10b981';
                  else if (ratio > 0) color = '#f59e0b';
                  const updatedText = formatRelativeLiveTime(p.charging_live_updated_at);
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Dostupnost:</strong> <span style="color:${color}; font-weight: 600;">${available}/${total} volných</span>${updatedText ? `<span style=\"display:block;color:#94a3b8;font-size:0.75em;\">Aktualizace ${updatedText}</span>` : ''}</div>`;
                }

                if (p.charging_google_details && p.charging_google_details.phone) {
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Telefon:</strong> <a href="tel:${p.charging_google_details.phone}" style="color: #049FE8; text-decoration: none;">${p.charging_google_details.phone}</a></div>`;
                }

                if (p.charging_google_details && p.charging_google_details.website) {
                  const web = p.charging_google_details.website;
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Web:</strong> <a href="${web}" target="_blank" rel="noopener" style="color: #049FE8; text-decoration: none;">${web.replace(/^https?:\/\//, '')}</a></div>`;
                }

                return additionalInfo ? `<div class="db-map-card-amenities" style="margin-top:0.5em;padding-top:0.5em;border-top:1px solid #f0f0f0;" data-debug="amenities-container-${p.id}">${additionalInfo}</div>` : '';
              })() : ''}
              ${p.post_type === 'poi' ? (() => {
                let additionalInfo = '';
                
                // Otevírací doba (aktuální stav)
                if (p.poi_opening_hours) {
                  const isOpen = checkIfOpen(p.poi_opening_hours);
                  const statusText = isOpen ? 'Otevřeno' : 'Zavřeno';
                  const statusColor = isOpen ? '#10b981' : '#ef4444';
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em;"><strong>Otevírací doba:</strong> <span style="color: ${statusColor}; font-weight: 600;">${statusText}</span></div>`;
                }
                
                // Cena (price level)
                if (p.poi_price_level) {
                  const priceLevel = '€'.repeat(parseInt(p.poi_price_level) || 1);
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Cena:</strong> ${priceLevel}</div>`;
                }
                
                // Telefon
                if (p.poi_phone) {
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Telefon:</strong> <a href="tel:${p.poi_phone}" style="color: #049FE8; text-decoration: none;">${p.poi_phone}</a></div>`;
                }
                
                // Web
                if (p.poi_website) {
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Web:</strong> <a href="${p.poi_website}" target="_blank" rel="noopener" style="color: #049FE8; text-decoration: none;">${p.poi_website.replace(/^https?:\/\//, '')}</a></div>`;
                }
                
                return additionalInfo ? `<div class="db-map-card-amenities" style="margin-top:0.5em;padding-top:0.5em;border-top:1px solid #f0f0f0;">${additionalInfo}</div>` : '';
              })() : ''}

              <div class="sheet-nearby">
                <div class="sheet-nearby-list" data-feature-id="${p.id}">
                  <div style="text-align: center; padding: 8px; color: #049FE8; font-size: 0.8em;">
                    <div style="font-size: 16px; margin-bottom: 4px;">⏳</div>
                    <div>Načítání...</div>
                  </div>
                </div>
              </div>
              ${p.post_type === 'rv_spot' ? (() => {
                let additionalInfo = '';
                
                // Služby
                if (p.amenities && Array.isArray(p.amenities)) {
                  const serviceNames = p.amenities.map(a => a.name || a).join(', ');
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Služby:</strong> ${serviceNames}</div>`;
                }
                
                // Cena
                if (p.rv_price) {
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Cena:</strong> ${p.rv_price}</div>`;
                }
                
                // Telefon
                if (p.rv_phone) {
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Telefon:</strong> <a href="tel:${p.rv_phone}" style="color: #049FE8; text-decoration: none;">${p.rv_phone}</a></div>`;
                }
                
                // Web
                if (p.rv_website) {
                  additionalInfo += `<div style="margin: 4px 0; font-size: 0.85em; color: #666;"><strong>Web:</strong> <a href="${p.rv_website}" target="_blank" rel="noopener" style="color: #049FE8; text-decoration: none;">${p.rv_website.replace(/^https?:\/\//, '')}</a></div>`;
                }
                
                return additionalInfo ? `<div class="db-map-card-amenities" style="margin-top:0.5em;padding-top:0.5em;border-top:1px solid #f0f0f0;">${additionalInfo}</div>` : '';
              })() : ''}
              
              <!-- Blízké body - zobrazit pouze pokud jsou data k dispozici -->
              <div class="db-map-card-nearby" style="margin-top:0.5em;padding-top:0.5em;border-top:1px solid #f0f0f0;display:none;">
                <div style="font-size:0.85em;color:#666;margin-bottom:0.5em;font-weight:600;">
                  ${p.post_type === 'charging_location' ? 'Blízká zajímavá místa' : 'Blízké nabíjecí stanice'}
                </div>
                <div class="db-map-card-nearby-list" data-feature-id="${p.id}" style="min-height:20px;color:#999;font-size:0.8em;">
                  <div style="text-align:center;padding:10px;">
                    <div style="font-size:16px;margin-bottom:4px;">⏳</div>
                    <div>Načítání...</div>
                  </div>
                </div>
              </div>
            </div>
        </div>
      `;
      // Klik na titulek (anchor) nesmí bublat, aby neaktivoval zvýraznění karty
      const titleAnchor = card.querySelector('.db-map-card-title[href]');
      if (titleAnchor) {
        titleAnchor.addEventListener('click', (ev) => {
          if (window.innerWidth > 900) {
            // Desktop: ponecháme default (cílový <a> má target="_blank")
            return;
          }
          // Mobil: modal
          ev.preventDefault();
          ev.stopPropagation();
          openDetailModal(f);
        });
      }
      // Klik na kartu: normální klik zvýrazní, „Detail"/ikona i/klikatelný název otevře modal
      card.addEventListener('click', (ev) => {
        highlightMarkerById(f.properties.id);
        map.setView([f.geometry.coordinates[1], f.geometry.coordinates[0]], 15, {animate:true});
        sortMode = 'distance-active';
        renderCards('', f.properties.id);
        openMobileSheet(f);
        // Po přerenderování panelu vždy posuň panel na začátek (první kartu)
        if (cardsWrap && cardsWrap.firstElementChild) {
          cardsWrap.scrollTo({ top: 0, behavior: 'smooth' });
        }
      });
      // Tlačítka: detail/navigovat
      card.querySelectorAll('.db-map-card-action-btn').forEach(btn => {
        btn.addEventListener('click', (ev) => {
          const title = btn.getAttribute('title');
          if ((title === 'Více informací' || title === 'Detail')) {
            ev.stopPropagation();
            if (window.innerWidth > 900 && p.permalink) {
              window.open(p.permalink, '_blank');
            } else {
              openDetailModal(f);
            }
          } else if (title === 'Navigovat') {
            // Otevřít nabídku možností navigace
            ev.stopPropagation();
            const lat = f.geometry.coordinates[1];
            const lng = f.geometry.coordinates[0];
            const wrapper = btn.parentElement;
            if (!wrapper) return;
            wrapper.style.position = 'relative';
            let menu = wrapper.querySelector('.db-nav-menu');
            if (!menu) {
              menu = document.createElement('div');
              menu.className = 'db-nav-menu';
              menu.style.cssText = 'position:absolute;top:100%;left:0;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.12);padding:6px;display:none;min-width:170px;z-index:10000;';
              const linkStyle = 'display:block;padding:8px 10px;color:#111;text-decoration:none;border-radius:6px;';
              menu.innerHTML = `
                <a class="db-nav-item" href="${gmapsUrl(lat, lng)}" target="_blank" rel="noopener" style="${linkStyle}">Google Maps</a>
                <a class="db-nav-item" href="${appleMapsUrl(lat, lng)}" target="_blank" rel="noopener" style="${linkStyle}">Apple Maps</a>
                <a class="db-nav-item" href="${mapyCzUrl(lat, lng)}" target="_blank" rel="noopener" style="${linkStyle}">Mapy.cz</a>
              `;
              wrapper.appendChild(menu);
              // jednoduchý hover efekt
              menu.querySelectorAll('a').forEach(a => {
                a.addEventListener('mouseenter', () => a.style.background = '#f3f4f6');
                a.addEventListener('mouseleave', () => a.style.background = 'transparent');
              });
            }
            // Toggle zobrazení menu + z-index fix, aby nepřekrývala sousední karty
            const willShow = (menu.style.display === 'none' || !menu.style.display);
            menu.style.display = willShow ? 'block' : 'none';
            const hostCard = wrapper.closest('.db-map-card');
            if (hostCard) hostCard.style.zIndex = willShow ? '10001' : '';
            const closeMenu = (e2) => {
              if (!menu.contains(e2.target) && e2.target !== btn) {
                menu.style.display = 'none';
                if (hostCard) hostCard.style.zIndex = '';
                document.removeEventListener('click', closeMenu);
              }
            };
            setTimeout(() => document.addEventListener('click', closeMenu), 0);
          }
        });
      });
      cardsWrap.appendChild(card);
    });
    
              // Načíst amenities pro nabíjecí místa
    setTimeout(() => {
      
      
      const containers = document.querySelectorAll('.db-map-card-amenities');
      
              containers.forEach((container, index) => {
          // Container processing
        });
  
    }, 100);
    
    // Načíst nearby data pro každou kartu - VYPNUTO pro optimalizaci
    // setTimeout(() => {
    //   const nearbyContainers = document.querySelectorAll('.db-map-card-nearby-list');
    //   nearbyContainers.forEach(container => {
    //     const featureId = container.dataset.featureId;
    //     if (featureId) {
    //       loadNearbyForCard(container, featureId);
    //     }
    //   });
    // }, 200);
    

  // duplicitní tvorba markerů odstraněna – markerů se vytváří jen z filtered výběru výše
    applyActiveHighlight();
    setTimeout(() => map.invalidateSize(), 50);
  }

  function applyActiveHighlight() {
    const activeId = Number.isFinite(activeFeatureId) ? activeFeatureId : null;
    markers.forEach((m) => {
      const mid = (m && m._featureId) ? m._featureId : null;
      if (activeId !== null && mid === activeId) {
        m.setIcon(m._activeIcon);
        m.setZIndexOffset(1001);
      } else {
        m.setIcon(m._defaultIcon);
        m.setZIndexOffset(0);
      }
    });
    document.querySelectorAll('.db-map-card').forEach((el, i) => {
      const cardId = el.dataset && el.dataset.featureId ? parseInt(el.dataset.featureId, 10) : filteredCardIdAtIndex(i);
      el.classList.toggle('active', activeId !== null && cardId === activeId);
    });
  }

  function highlightMarkerById(id) {
    const normalizedId = (typeof id === 'string') ? parseInt(id, 10) : id;
    if (Number.isFinite(normalizedId)) {
      activeFeatureId = normalizedId;
      const idx = findFeatureIndexById(normalizedId);
      activeIdxGlobal = idx >= 0 ? idx : null;
    } else {
      activeFeatureId = null;
      activeIdxGlobal = null;
    }
    applyActiveHighlight();
  }

  function highlightCardById(id) {
    highlightMarkerById(id);
  }

  function clearActiveFeature() {
    if (activeFeatureId === null) {
      return;
    }
    activeFeatureId = null;
    activeIdxGlobal = null;
    applyActiveHighlight();
  }
  // Pomocná funkce pro získání ID karty podle aktuálního pořadí v panelu
  function filteredCardIdAtIndex(idx) {
    const cards = document.querySelectorAll('.db-map-card');
    if (cards[idx] && cards[idx].__featureId !== undefined) return cards[idx].__featureId;
    // fallback: najít ID podle pořadí v posledním renderu
    if (lastSearchResults && lastSearchResults[idx]) return lastSearchResults[idx].properties.id;
    return null;
  }
  // První render
  renderCards();
  // ===== AUTO-FETCH V RADIUS REŽIMU NA MOVE/ZOOM =====
  const onViewportChanged = debounce(async () => {
    try {
      if (loadMode !== 'radius') return;
      if (!map) return;
      // 1) Minimální zoom: pod tímto zoomem nefetchovat (šetření API)
      if (map.getZoom() < MIN_FETCH_ZOOM) { return; }
      const c = map.getCenter();
      // 2) Containment logika: fetchneme znovu až když se přiblížíme k hraně posledního okruhu
      if (lastSearchCenter && lastSearchRadiusKm) {
        const distFromLastCenter = haversineKm(lastSearchCenter, { lat: c.lat, lng: c.lng });
        const thresholdKm = Math.max(1, Math.min(10, lastSearchRadiusKm * 0.4)); // cca 40 % poloměru
        if (distFromLastCenter < (window.DB_RADIUS_HYSTERESIS_KM || thresholdKm)) {
          // Jen překreslit z cache – body uvnitř posledního okruhu zůstanou, ostatní zmizí
          const visible = selectFeaturesForView();
            if (visible && visible.length > 0) {
              features = visible;
              window.features = features;
              if (typeof clearMarkers === 'function') clearMarkers();
              renderCards('', null, false);
            lastRenderedFeatures = features.slice(0);
          }
          return;
        }
      }
      if (inFlightController) {
        try { inFlightController.abort(); } catch(_) {}
      }
      await fetchAndRenderRadius(c, null);
      lastSearchCenter = { lat: c.lat, lng: c.lng };
      lastSearchRadiusKm = FIXED_RADIUS_KM;
      
    } catch(_) {}
  }, 300);

  map.on('moveend', onViewportChanged);
  map.on('zoomend', onViewportChanged);

  // Vyčistit isochrony při kliknutí mimo aktivní bod (pokud nejsou zamčené)
  map.on('click', function(e) {
    if (isochronesLocked) {
      return;
    }

    const originalEvent = e && e.originalEvent ? e.originalEvent : null;
    const target = originalEvent && originalEvent.target ? originalEvent.target : null;
    const shouldSkip = target && typeof target.closest === 'function' && (
      target.closest('.leaflet-marker-icon') ||
      target.closest('.marker-cluster') ||
      target.closest('.leaflet-control') ||
      target.closest('#db-isochrones-legend') ||
      target.closest('#db-isochrones-unlock')
    );

    if (shouldSkip) {
      return;
    }

    if (!isochronesLayer && !lastIsochronesPayload) {
      return;
    }

    lastIsochronesPayload = null;
    clearIsochrones();
    updateIsochronesLockButtons();
  });


  // Toggle „Jen DB doporučuje"
  const toggleRecommended = document.getElementById('db-map-toggle-recommended');
  if (toggleRecommended) {
    // obnova předchozího režimu po vypnutí
    const prevModeKey = 'dbPrevLoadMode';
    toggleRecommended.addEventListener('change', async function(){
      showOnlyRecommended = !!this.checked;
      try {
        const modeRadios = document.querySelectorAll('input[name="map-mode"]');
        const setRadio = (val) => {
          modeRadios.forEach(r => { r.checked = (r.value === val); });
        };

        if (showOnlyRecommended) {
          // Uložit předchozí režim a přepnout na ALL
          localStorage.setItem(prevModeKey, loadMode);
          loadMode = 'all';
          localStorage.setItem('dbLoadMode', 'all');
          setRadio('all');
          await fetchAndRenderAll();
        } else {
          // Po vypnutí vždy vrátit režim radius (požadavek)
          loadMode = 'radius';
          localStorage.setItem('dbLoadMode', 'radius');
          setRadio('radius');
          const c = map.getCenter();
          await fetchAndRenderRadius(c, null);
        }
      } catch (e) {
      } finally {
        renderCards('', null, false);
      }
    });
  }

  // Globální refresh po každém ukončení pohybu mapy – prevence prázdných clusterů
  map.on('moveend', function(){
    try { clusterChargers.refreshClusters(); } catch(_) {}
    try { clusterRV.refreshClusters(); } catch(_) {}
    try { clusterPOI.refreshClusters(); } catch(_) {}
    // Zrušeno auto-refresh na moveend kvůli výkonu
    // noop
  });
  
  // Event listener pro zoom
  map.on('zoomend', function() {});
  
  // Event listener pro počáteční načtení mapy
  map.once('load', async function() {
    // V RADIUS režimu rovnou dotáhni data pro aktuální střed
    try {
      if (loadMode === 'radius') {
        // Fetch pouze pokud jsme dostatečně přiblíženi
        if (map.getZoom() >= MIN_FETCH_ZOOM) {
          const c = map.getCenter();
          await fetchAndRenderRadius(c, null);
          lastSearchCenter = { lat: c.lat, lng: c.lng };
          lastSearchRadiusKm = FIXED_RADIUS_KM;
        }
      }
    } catch(_) {}
  });

  // Určení režimu nabíjení z konektorů: 'ac' | 'dc' | 'hybrid'
  function isConnectorDC(c) {
    const t = (c && (c.current_type || c.current || c.proud || c.typ || c.type || '') + '').toString().toLowerCase();
    return t.includes('dc') || t.includes('chademo') || t.includes('ccs') || t.includes('combo') || t.includes('gb/t dc');
  }
  function isConnectorAC(c) {
    const t = (c && (c.current_type || c.current || c.proud || c.typ || c.type || '') + '').toString().toLowerCase();
    return t.includes('ac') || t.includes('type 2') || t.includes('mennekes') || t.includes('schuko') || t.includes('type2');
  }
  function getChargerMode(p) {
    try {
      const arr = Array.isArray(p.connectors) ? p.connectors : (Array.isArray(p.konektory) ? p.konektory : []);
      let hasAC = false, hasDC = false;
      arr.forEach(c => { if (isConnectorAC(c)) hasAC = true; if (isConnectorDC(c)) hasDC = true; });
      if (hasAC && hasDC) return 'hybrid';
      if (hasDC) return 'dc';
      if (hasAC) return 'ac';
    } catch(_) {}
    // Fallback podle p.speed
    if ((p.speed||'').toLowerCase() === 'dc') return 'dc';
    return 'ac';
  }
  // Výpočet výplně markeru (barva nebo gradient) pro nabíječky
  function getChargerFill(p, active) {
    const mode = getChargerMode(p);
    const acColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.ac) || '#049FE8';
    const dcColor = (dbMapData && dbMapData.chargerColors && dbMapData.chargerColors.dc) || '#FFACC4';
    const blendStart = (dbMapData && dbMapData.chargerColors && Number.isFinite(dbMapData.chargerColors.blendStart)) ? dbMapData.chargerColors.blendStart : 30;
    const blendEnd = (dbMapData && dbMapData.chargerColors && Number.isFinite(dbMapData.chargerColors.blendEnd)) ? dbMapData.chargerColors.blendEnd : 70;
    if (mode === 'hybrid') {
      const gid = 'grad-' + (p.id || Math.random().toString(36).slice(2)) + '-' + (active ? 'a' : 'd');
      const defs = '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="1" y2="0">'
                 + '<stop offset="0%" stop-color="' + acColor + '"/>'
                 + '<stop offset="' + Math.max(0, Math.min(100, blendStart)) + '%" stop-color="' + acColor + '"/>'
                 + '<stop offset="' + Math.max(0, Math.min(100, blendEnd)) + '%" stop-color="' + dcColor + '"/>'
                 + '<stop offset="100%" stop-color="' + dcColor + '"/>'
                 + '</linearGradient></defs>';
      return { fill: 'url(#' + gid + ')', defs };
    }
    // Umožni přebarvení z adminu, pokud není hybrid
    const color = p.icon_color || (mode === 'dc' ? dcColor : acColor);
    return { fill: color, defs: '' };
  }

  // Zavřít filtry při kliknutí mimo panel
  document.addEventListener('click', function(e) {
    if (filterPanel.style.display === 'block' && !filterPanel.contains(e.target) && !filterBtn.contains(e.target)) {
      filterPanel.style.display = 'none';
      if (mapOverlay) mapOverlay.style.display = 'none';
      if (mapDiv) {
        mapDiv.style.zIndex = '1';
        mapDiv.classList.remove('filters-open');
      }
    }
  });

  // Nové vyhledávací pole s lupou ikonou
  function createSearchOverlay() {
    const searchOverlay = document.createElement('div');
    searchOverlay.className = 'db-search-overlay';
    searchOverlay.innerHTML = `
      <div class="db-search-container">
        <input type="text" class="db-search-input" placeholder="Objevuji víc než jen cíl cesty..." />
        <div class="db-search-actions">
          <button type="button" class="db-search-confirm">Hledat</button>
          <button type="button" class="db-search-cancel">Zrušit</button>
        </div>
      </div>
    `;
    
    document.body.appendChild(searchOverlay);
    
    // Event listeners
    const searchInput = searchOverlay.querySelector('.db-search-input');
    const confirmBtn = searchOverlay.querySelector('.db-search-confirm');
    const cancelBtn = searchOverlay.querySelector('.db-search-cancel');
    
    // Zavřít při kliknutí mimo
    searchOverlay.addEventListener('click', (e) => {
      if (e.target === searchOverlay) {
        closeSearchOverlay();
      }
    });
    
    // Zavřít při kliknutí na zrušit
    cancelBtn.addEventListener('click', closeSearchOverlay);
    
    // Potvrdit vyhledávání
    confirmBtn.addEventListener('click', () => {
      const query = searchInput.value.trim();
      if (query) {
        // Zde implementovat vyhledávání

        closeSearchOverlay();
      }
    });
    
    // Enter pro potvrzení
    searchInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        confirmBtn.click();
      }
    });
    
    return searchOverlay;
  }
  
  const searchOverlay = createSearchOverlay();
  
  function openSearchOverlay() {
    searchOverlay.classList.add('open');
    const searchInput = searchOverlay.querySelector('.db-search-input');
    // Vyhnout se auto-fokusu na mobilech kvůli iOS zoomu okna
    const isMobile = /Mobi|Android/i.test(navigator.userAgent);
    if (!isMobile) {
      searchInput.focus();
    }
  }
  
  function closeSearchOverlay() {
    searchOverlay.classList.remove('open');
    const searchInput = searchOverlay.querySelector('.db-search-input');
    searchInput.value = '';
  }
  
  // Přidání lupové ikony do topbaru
  function addSearchIcon() {
    const topbar = document.querySelector('.db-map-topbar');
    if (topbar && !document.querySelector('.db-search-icon')) {
      const searchIcon = document.createElement('button');
      searchIcon.className = 'db-map-topbar-btn db-search-icon';
      searchIcon.setAttribute('data-db-action', 'open-search');
      searchIcon.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"></circle>
          <path d="m21 21-4.35-4.35"></path>
        </svg>
      `;
      searchIcon.addEventListener('click', openSearchOverlay);
      
      // Vložit před poslední tlačítko
      const lastBtn = topbar.querySelector('.db-map-topbar-btn:last-child');
      if (lastBtn) {
        topbar.insertBefore(searchIcon, lastBtn);
      } else {
        topbar.appendChild(searchIcon);
      }
    }
  }
  
  // Spustit po načtení DOM
  document.addEventListener('DOMContentLoaded', addSearchIcon);
  


  // Dynamické přizpůsobení topbaru pod WP menu - odstraněno
  // Topbar se nyní chová podle původního CSS
  
  // Spustit po vytvoření topbaru - odstraněno

  // CSS už má správná pravidla pro pozici topbaru

  // Vytvoření vyhledávacího pole pod topbarem - pouze pro mobilní verzi
  function createMobileSearchField() {
    // Kontrola, zda jsme v mobilní verzi
    if (window.innerWidth <= 900) {
      // Odstranit existující vyhledávací pole
      const existingSearch = document.querySelector('.db-mobile-search-field');
      if (existingSearch) {
        existingSearch.remove();
      }
      
      // Vytvořit nové vyhledávací pole
      const searchField = document.createElement('div');
      searchField.className = 'db-mobile-search-field';
      
      // Nastavení velikosti na 90% šířky displeje
      const width = window.innerWidth * 0.9;
      
      searchField.style.cssText = `
        position: fixed;
        top: 120px;
        width: ${width}px;
        background: transparent;
        padding: 1rem;
        box-shadow: none;
        z-index: 10000;
        border-radius: 0 0 16px 16px;
      `;
      
      searchField.innerHTML = `
        <div style="display: flex; gap: 8px; align-items: center;">
        <input type="text"
               placeholder="Hledám víc než jen cíl cesty.."
                 style="flex: 1; padding: 0.75rem; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 1rem; outline: none;"
               id="db-mobile-search-field-input">
          <button type="button" 
                  style="padding: 0.75rem; background: #049FE8; color: #fff; border: none; border-radius: 8px; cursor: pointer; white-space: nowrap;"
                  id="db-mobile-search-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"></circle>
              <path d="m21 21-4.35-4.35"></path>
            </svg>
          </button>
        </div>
      `;
      
      // Přidat třídu 'hidden' a nastavit display: none pro skrytý stav
      searchField.classList.add('hidden');
      searchField.style.display = 'none';
      
      document.body.appendChild(searchField);
      
      // Event listener pro input
      const searchInput = searchField.querySelector('#db-mobile-search-field-input');
      const handleAutocompleteInput = debounce((value) => {
        showMobileAutocomplete(value, searchInput);
      }, 250);

      searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        if (query.length >= 2) {
          handleAutocompleteInput(query);
        } else {
          removeMobileAutocomplete();
        }
      });
      
      // Event listener pro Enter - spustit vyhledávání
      searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
          const query = this.value.trim();
          if (query) {
            // Spustit vyhledávání na mapě
            performMobileSearch(query);
          }
        }
      });
      
      // Event listener pro focus - zobrazit autocomplete
      searchInput.addEventListener('focus', function() {
        const query = this.value.trim();
        if (query.length >= 2) {
          showMobileAutocomplete(query, searchInput);
        }
      });
      
      // Event listener pro tlačítko vyhledávání
      const searchBtn = searchField.querySelector('#db-mobile-search-btn');
      if (searchBtn) {
        searchBtn.addEventListener('click', function() {
          const query = searchInput.value.trim();
          if (query) {
            performMobileSearch(query);
          }
        });
      }
      
      // Event listener pro kliknutí mimo pole - skrýt pole
      document.addEventListener('click', function(e) {
        if (!searchField.contains(e.target) && !e.target.closest('#db-search-toggle')) {
          if (!searchField.classList.contains('hidden')) {
            closeMobileSearchField();
          }
        }
      });
      
      return searchField;
    } else {
      return null;
    }
  }
  
  // Spustit pouze v mobilní verzi a pokud se mapa vytvořila
  if (isMobile && map) {
    setTimeout(() => {
      createMobileSearchField();
      
      // Přidat event listener na tlačítko lupy pro toggle vyhledávacího pole
      const searchToggleBtn = document.getElementById('db-search-toggle');
      if (searchToggleBtn) {
        searchToggleBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          
          const searchField = document.querySelector('.db-mobile-search-field');
          if (searchField) {
            if (searchField.classList.contains('hidden')) {
              // Zobrazit pole
              searchField.classList.remove('hidden');
              searchField.style.display = 'block';
              
              // Focus na input
              const searchInput = searchField.querySelector('#db-mobile-search-field-input');
              if (searchInput) {
                // Nespouštět auto-focus na mobilech kvůli iOS zoomu
                const isMobile2 = /Mobi|Android/i.test(navigator.userAgent);
                if (!isMobile2) {
                  setTimeout(() => searchInput.focus(), 100);
                }
              }
            } else {
              // Skrýt pole
              closeMobileSearchField();
            }
          }
        });
      }
    }, 100);
  }
  
  // Cache pro IP geolokaci (24 hodin)
  let ipLocationCache = null;
  let ipLocationCacheTime = 0;
  const IP_CACHE_DURATION = 24 * 60 * 60 * 1000; // 24 hodin v ms
  
  // Globální cache pro lokalitu prohlížeče (načte se jednou při startu)
  let browserLocaleCache = null;

  // Funkce pro získání lokality prohlížeče (s globálním cache)
  async function getBrowserLocale() {
    // Pokud už máme cache, použij ho
    if (browserLocaleCache) {
      return browserLocaleCache;
    }
    
    try {
      // 1. Zkusit geolokaci prohlížeče (pouze pokud je to user gesture)
      // Poznámka: geolokace se volá pouze při user gesture, ne automaticky
      if (window.userGestureDetected) {
        const coords = await getUserLocationOnce();
        if (coords) {
          browserLocaleCache = {
            type: 'geolocation',
            coords: coords,
            country: await getCountryFromCoords(coords)
          };
          
          return browserLocaleCache;
        }
      }
    } catch (e) {
      
    }
    
    try {
      // 2. Zkusit IP geolokaci (s cache)
      const now = Date.now();
      if (ipLocationCache && (now - ipLocationCacheTime) < IP_CACHE_DURATION) {
        
        browserLocaleCache = {
          type: 'ip',
          coords: [ipLocationCache.lat, ipLocationCache.lon],
          country: ipLocationCache.country_code
        };
        return browserLocaleCache;
      }
      
      const ipLocation = await getIPLocation();
      if (ipLocation) {
        // Uložit do cache
        ipLocationCache = ipLocation;
        ipLocationCacheTime = now;
        
        browserLocaleCache = {
          type: 'ip',
          coords: [ipLocation.lat, ipLocation.lon],
          country: ipLocation.country_code
        };
        
        return browserLocaleCache;
      }
    } catch (e) {
      
    }
    
    // 3. Fallback na jazyk prohlížeče
    const browserLang = navigator.language || navigator.languages[0];
    const countryFromLang = getCountryFromLanguage(browserLang);
    
    browserLocaleCache = {
      type: 'language',
      coords: getDefaultCoordsForCountry(countryFromLang),
      country: countryFromLang
    };
    
    return browserLocaleCache;
  }
  
  // Funkce pro získání země ze souřadnic
  async function getCountryFromCoords(coords) {
    try {
      const [lat, lng] = coords;
      const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1`);
      const result = await response.json();
      return result.address?.country_code?.toUpperCase() || 'CZ';
    } catch (e) {
      return 'CZ'; // Fallback
    }
  }
  
  // Funkce pro IP geolokaci s fallback službami
  async function getIPLocation() {
    // Seznam IP geolokace služeb s fallback
    const services = [
      'https://ipapi.co/json/',
      'https://ipinfo.io/json'
    ];
    
    for (const service of services) {
      try {
        // Timeout 3 sekundy pro každou službu
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 3000);
        
        const response = await fetch(service, {
          method: 'GET',
          headers: {
            'Accept': 'application/json'
          },
          signal: controller.signal
        });
        
        clearTimeout(timeoutId);
        
        if (!response.ok) { continue; }
        
        const data = await response.json();
        
        // Normalizace dat podle služby
        let result = null;
        if (service.includes('ipapi.co')) {
          result = {
            lat: data.latitude,
            lon: data.longitude,
            country_code: data.country_code
          };
        } else if (service.includes('ipinfo.io')) {
          // ipinfo.io vrací lokaci jako "lat,lng"
          const [lat, lon] = data.loc ? data.loc.split(',') : [null, null];
          result = {
            lat: parseFloat(lat),
            lon: parseFloat(lon),
            country_code: data.country
          };
        }
        
        if (result && result.lat && result.lon && result.country_code) {
          
          return result;
        }
      } catch (e) {
        // Tichá chyba pro CORS a rate limiting
        if (e.message.includes('CORS') || e.message.includes('429')) {
          
        } else {
          
        }
        continue;
      }
    }
    
    
    return null;
  }
  
  // Funkce pro určení země z jazyka prohlížeče
  function getCountryFromLanguage(lang) {
    const langMap = {
      'cs': 'CZ',
      'sk': 'SK', 
      'de': 'DE',
      'en': 'CZ', // Angličtina -> ČR jako fallback
      'pl': 'PL',
      'hu': 'HU',
      'at': 'AT',
      'si': 'SI'
    };
    
    const langCode = lang.split('-')[0].toLowerCase();
    return langMap[langCode] || 'CZ';
  }
  // Inicializace lokality prohlížeče při načtení stránky (po deklaraci funkcí)
  getBrowserLocale().catch(() => {});
  // Funkce pro výchozí souřadnice podle země
  function getDefaultCoordsForCountry(country) {
    const coordsMap = {
      'CZ': [50.0755, 14.4378], // Praha
      'SK': [48.1486, 17.1077], // Bratislava
      'DE': [52.5200, 13.4050], // Berlín
      'AT': [48.2082, 16.3738], // Vídeň
      'PL': [52.2297, 21.0122], // Varšava
      'HU': [47.4979, 19.0402], // Budapešť
      'SI': [46.0569, 14.5058]  // Lublaň
    };
    return coordsMap[country] || [50.0755, 14.4378]; // Praha jako fallback
  }
  // Funkce pro konfiguraci vyhledávání podle země
  function getCountrySearchConfig(country) {
    const configs = {
      'CZ': {
        countrycodes: 'cz,sk,at,de,pl,hu',
        viewbox: '8,46,22,55' // Střední Evropa
      },
      'SK': {
        countrycodes: 'sk,cz,at,hu,pl',
        viewbox: '8,46,22,55' // Střední Evropa
      },
      'DE': {
        countrycodes: 'de,at,cz,ch,pl,fr,be,nl',
        viewbox: '5,47,15,55' // Německo a okolí
      },
      'AT': {
        countrycodes: 'at,de,cz,sk,hu,si,ch,it',
        viewbox: '8,46,17,49' // Rakousko a okolí
      },
      'PL': {
        countrycodes: 'pl,de,cz,sk,lt,by,ua',
        viewbox: '14,49,24,55' // Polsko a okolí
      },
      'HU': {
        countrycodes: 'hu,sk,at,si,hr,ro,rs,ua',
        viewbox: '16,45,23,49' // Maďarsko a okolí
      },
      'SI': {
        countrycodes: 'si,at,it,hr,hu',
        viewbox: '13,45,16,47' // Slovinsko a okolí
      }
    };
    
    return configs[country] || configs['CZ']; // Fallback na ČR
  }
  // Funkce pro prioritizaci výsledků vyhledávání
  function prioritizeSearchResults(results, userCoords) {
    if (!userCoords || results.length === 0) {
      return results;
    }
    
    const [userLat, userLng] = userCoords;
    
    // Definice prioritních zemí a jejich váhy
    const countryPriority = {
      'Czech Republic': 100,    // Nejvyšší priorita
      'Česká republika': 100,
      'Czechia': 100,
      'CZ': 100,                // ISO kód
      'Slovakia': 90,           // Vysoká priorita
      'Slovensko': 90,
      'SK': 90,                 // ISO kód
      'Austria': 80,            // Střední priorita
      'AT': 80,                 // ISO kód
      'Germany': 80,
      'DE': 80,                 // ISO kód
      'Poland': 80,
      'PL': 80,                 // ISO kód
      'Hungary': 80,
      'HU': 80,                 // ISO kód
      'Slovenia': 80,
      'SI': 80,                 // ISO kód
      'Croatia': 70,            // Nižší priorita
      'Italy': 70,
      'France': 70,
      'Spain': 70,
      'Portugal': 70,
      'Netherlands': 70,
      'Belgium': 70,
      'Switzerland': 70,
      'Denmark': 70,
      'Sweden': 70,
      'Norway': 70,
      'Finland': 70,
      'United Kingdom': 60,     // Nejnižší priorita v Evropě
      'Ireland': 60,
      'Iceland': 60,
      'Greece': 60,
      'Turkey': 50,             // Hraniční země
      'Russia': 30,             // Východní Evropa
      'Ukraine': 30,
      'Belarus': 30,
      'Romania': 60,
      'Bulgaria': 60,
      'Serbia': 60,
      'Bosnia and Herzegovina': 60,
      'Montenegro': 60,
      'Albania': 60,
      'North Macedonia': 60,
      'Kosovo': 60,
      'Moldova': 50,
      'Estonia': 60,
      'Latvia': 60,
      'Lithuania': 60
    };
    
    // Funkce pro výpočet vzdálenosti
    function calculateDistance(lat1, lng1, lat2, lng2) {
      const R = 6371; // Poloměr Země v km
      const dLat = (lat2 - lat1) * Math.PI / 180;
      const dLng = (lng2 - lng1) * Math.PI / 180;
      const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng/2) * Math.sin(dLng/2);
      const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
      return R * c;
    }
    
    // Přidat skóre ke každému výsledku
    const scoredResults = results.map(result => {
      const lat = parseFloat(result.lat);
      const lng = parseFloat(result.lon);
      const distance = calculateDistance(userLat, userLng, lat, lng);
      
      // Získat zemi z adresy - zkusit různé varianty
      const address = result.address || {};
      let country = address.country || address.country_code || '';
      
      // Pokud je country_code prázdný, zkusit z display_name
      if (!country && result.display_name) {
        const displayName = result.display_name.toLowerCase();
        if (displayName.includes('česká republika') || displayName.includes('czech republic') || displayName.includes(', cz')) {
          country = 'CZ';
        } else if (displayName.includes('slovakia') || displayName.includes('slovensko') || displayName.includes(', sk')) {
          country = 'SK';
        } else if (displayName.includes('austria') || displayName.includes('rakousko') || displayName.includes(', at')) {
          country = 'AT';
        } else if (displayName.includes('germany') || displayName.includes('německo') || displayName.includes(', de')) {
          country = 'DE';
        } else if (displayName.includes('poland') || displayName.includes('polsko') || displayName.includes(', pl')) {
          country = 'PL';
        } else if (displayName.includes('hungary') || displayName.includes('maďarsko') || displayName.includes(', hu')) {
          country = 'HU';
        }
      }
      
      // Vypočítat skóre
      let score = 0;
      
      // Skóre podle země (0-100)
      const countryScore = countryPriority[country] || 10; // Výchozí nízké skóre pro neznámé země
      score += countryScore;
      
      // Skóre podle vzdálenosti (0-50)
      let distanceScore = 0;
      if (distance < 10) distanceScore = 50;        // Méně než 10km
      else if (distance < 50) distanceScore = 40;   // Méně než 50km
      else if (distance < 100) distanceScore = 30;  // Méně než 100km
      else if (distance < 500) distanceScore = 20;  // Méně než 500km
      else if (distance < 1000) distanceScore = 10; // Méně než 1000km
      else distanceScore = 0;                       // Více než 1000km
      
      score += distanceScore;
      
      // Bonus pro přesné shody názvu - query není dostupná v tomto kontextu
      // if (result.display_name.toLowerCase().includes(query.toLowerCase())) {
      //   score += 10;
      // }
      
      return {
        ...result,
        _score: score,
        _distance: distance,
        _country: country
      };
    });
    
    // Seřadit podle skóre (nejvyšší první)
    scoredResults.sort((a, b) => b._score - a._score);
    
    // Vrátit pouze top 5 výsledků
    return scoredResults.slice(0, 5);
  }

  function removeMobileAutocomplete() {
    const existing = document.querySelector('.db-mobile-autocomplete');
    if (existing) {
      if (existing.__outsideHandler) {
        document.removeEventListener('click', existing.__outsideHandler);
      }
      existing.remove();
    }
  }

  // Desktop autocomplete funkce
  function removeDesktopAutocomplete() {
    const existing = document.querySelector('.db-desktop-autocomplete');
    if (existing) {
      if (existing.__outsideHandler) {
        document.removeEventListener('click', existing.__outsideHandler);
      }
      existing.remove();
    }
  }

  function renderDesktopAutocomplete(data, inputElement) {
    const internal = Array.isArray(data?.internal) ? data.internal : [];
    const external = Array.isArray(data?.external) ? data.external : [];

    if (internal.length === 0 && external.length === 0) {
      removeDesktopAutocomplete();
      return;
    }

    let autocomplete = document.querySelector('.db-desktop-autocomplete');
    const rect = inputElement.getBoundingClientRect();
    if (!autocomplete) {
      autocomplete = document.createElement('div');
      autocomplete.className = 'db-desktop-autocomplete';
      autocomplete.style.position = 'fixed';
      autocomplete.style.background = '#fff';
      autocomplete.style.border = '1px solid #e5e7eb';
      autocomplete.style.borderRadius = '8px';
      autocomplete.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
      autocomplete.style.zIndex = '10001';
      autocomplete.style.maxHeight = '400px';
      autocomplete.style.overflowY = 'auto';
      autocomplete.style.minWidth = '320px';
      document.body.appendChild(autocomplete);
    }

    autocomplete.style.top = `${rect.bottom + 5}px`;
    autocomplete.style.left = `${rect.left}px`;
    autocomplete.style.width = `${Math.max(rect.width, 320)}px`;

    const escapeHtml = (str) => {
      return String(str || '').replace(/[&<>"']/g, (c) => {
        switch (c) {
          case '&': return '&amp;';
          case '<': return '&lt;';
          case '>': return '&gt;';
          case '"': return '&quot;';
          case "'": return '&#39;';
          default: return c;
        }
      });
    };

    const internalItems = internal.map((item, idx) => {
      const title = item?.title || '';
      const address = item?.address || '';
      const typeLabel = item?.type_label || item?.post_type || '';
      const subtitleParts = [];
      if (address) subtitleParts.push(address);
      if (typeLabel) subtitleParts.push(typeLabel);
      const subtitle = subtitleParts.join(' • ');
      const badge = item?.is_recommended ? '<span style="background:#049FE8; color:#fff; font-size:0.7rem; padding:2px 6px; border-radius:999px; margin-left:6px;">DB doporučuje</span>' : '';
      return `
        <div class="db-desktop-ac-item" data-source="internal" data-index="${idx}" style="padding:10px 12px; border-bottom:1px solid #f0f0f0; cursor:pointer; transition:background 0.15s;">
          <div style="font-weight:600; color:#111; display:flex; align-items:center;">
            <span>${escapeHtml(title)}</span>${badge}
          </div>
          ${subtitle ? `<div style="font-size:0.85em; color:#666; margin-top:4px;">${escapeHtml(subtitle)}</div>` : ''}
        </div>
      `;
    }).join('');

    const externalItems = external.map((item, idx) => {
      const display = item?.display_name || '';
      const primary = display.split(',')[0] || display;
      const country = item?._country ? ` – ${item._country}` : '';
      const distance = Number.isFinite(item?._distance) ? ` (${Math.round(item._distance)} km)` : '';
      return `
        <div class="db-desktop-ac-item" data-source="external" data-index="${idx}" style="padding:10px 12px; border-bottom:1px solid #f0f0f0; cursor:pointer; transition:background 0.15s;">
          <div style="font-weight:500; color:#333;">${escapeHtml(primary)}</div>
          <div style="font-size:0.85em; color:#666; margin-top:4px;">${escapeHtml(display)}${distance}${escapeHtml(country)}</div>
        </div>
      `;
    }).join('');

    const sections = [];
    if (internal.length > 0) {
      sections.push(`
        <div class="db-desktop-ac-section" data-section="internal">
          <div style="padding:8px 12px; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; background:#f9fafb;">Dobitý Baterky</div>
          ${internalItems}
        </div>
      `);
    }
    if (external.length > 0) {
      sections.push(`
        <div class="db-desktop-ac-section" data-section="external">
          <div style="padding:8px 12px; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; background:#f9fafb;">OpenStreetMap</div>
          ${externalItems}
        </div>
      `);
    }

    autocomplete.innerHTML = sections.join('');

    if (autocomplete.__outsideHandler) {
      document.removeEventListener('click', autocomplete.__outsideHandler);
    }
    const outsideHandler = (e) => {
      if (!autocomplete.contains(e.target) && e.target !== inputElement) {
        removeDesktopAutocomplete();
      }
    };
    autocomplete.__outsideHandler = outsideHandler;
    setTimeout(() => document.addEventListener('click', outsideHandler), 0);

    autocomplete.querySelectorAll('.db-desktop-ac-item').forEach((itemEl) => {
      itemEl.addEventListener('mouseenter', () => { itemEl.style.background = '#f8f9fa'; });
      itemEl.addEventListener('mouseleave', () => { itemEl.style.background = 'transparent'; });
      itemEl.addEventListener('click', async () => {
        const source = itemEl.getAttribute('data-source');
        const idx = parseInt(itemEl.getAttribute('data-index'), 10);
        removeDesktopAutocomplete();
        if (source === 'internal') {
          const picked = internal[idx];
          if (picked) {
            inputElement.value = picked.title || '';
            await handleDesktopInternalSelection(picked);
          }
        } else if (source === 'external') {
          const picked = external[idx];
          if (picked) {
            inputElement.value = picked.display_name || '';
            await handleDesktopExternalSelection(picked);
          }
        }
      });
    });
  }

  async function handleDesktopInternalSelection(result) {
    try {
      const lat = Number.parseFloat(result?.lat);
      const lng = Number.parseFloat(result?.lng);
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return;
      }

      const targetZoom = Math.max(map.getZoom(), 15);
      map.setView([lat, lng], targetZoom, { animate: true, duration: 0.5 });

      await fetchAndRenderRadius({ lat, lng }, null);

      searchAddressCoords = null;
      searchSortLocked = false;
      sortMode = 'distance-active';
      if (searchAddressMarker) {
        try { map.removeLayer(searchAddressMarker); } catch(_) {}
        searchAddressMarker = null;
      }

      if (result?.id != null) {
        highlightMarkerById(result.id);
        renderCards('', result.id);
        const feature = featureCache.get(result.id);
        if (feature) {
          openDetailModal(feature);
        }
      }
    } catch (error) {
      console.error('Chyba při zobrazení interního výsledku:', error);
    }
  }
  async function handleDesktopExternalSelection(result) {
    try {
      const lat = Number.parseFloat(result?.lat);
      const lng = Number.parseFloat(result?.lon || result?.lng);
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return;
      }

      const targetZoom = Math.max(map.getZoom(), 14);
      map.setView([lat, lng], targetZoom, { animate: true, duration: 0.5 });

      await fetchAndRenderRadius({ lat, lng }, null);

      searchAddressCoords = [lat, lng];
      searchSortLocked = true;
      sortMode = 'distance-active';

      if (searchAddressMarker) {
        try { map.removeLayer(searchAddressMarker); } catch(_) {}
      }
      searchAddressMarker = L.marker([lat, lng], {
        icon: L.divIcon({
          className: 'search-address-marker',
          html: '<div style="background:#dc2626;border:3px solid #fff;border-radius:50%;width:16px;height:16px;box-shadow:0 2px 8px rgba(0,0,0,0.3);"></div>',
          iconSize: [16, 16],
          iconAnchor: [8, 8]
        })
      }).addTo(map);

      renderCards('');
    } catch (error) {
      console.error('Chyba při zobrazení externího výsledku:', error);
    }
  }

  async function showDesktopAutocomplete(query, inputElement) {
    const trimmed = (query || '').trim();
    if (trimmed.length < 2) {
      removeDesktopAutocomplete();
      return;
    }

    const normalized = trimmed.toLowerCase();
    const cachedInternal = internalSearchCache.get(normalized);
    const cachedExternal = externalSearchCache.get(normalized);
    if (cachedInternal || cachedExternal) {
      renderDesktopAutocomplete({
        internal: cachedInternal || [],
        external: (cachedExternal && cachedExternal.results) || []
      }, inputElement);
    }

    if (desktopSearchController) {
      try { desktopSearchController.abort(); } catch(_) {}
    }
    desktopSearchController = new AbortController();
    const signal = desktopSearchController.signal;

    try {
      const [internal, externalPayload] = await Promise.all([
        getInternalSearchResults(trimmed, signal),
        trimmed.length >= 3 ? getExternalSearchResults(trimmed, signal) : Promise.resolve({ results: [], userCoords: null })
      ]);

      if (signal.aborted) {
        return;
      }

      renderDesktopAutocomplete({
        internal,
        external: externalPayload?.results || []
      }, inputElement);
      if (desktopSearchController && desktopSearchController.signal === signal) {
        desktopSearchController = null;
      }
    } catch (error) {
      if (error.name === 'AbortError') {
        return;
      }
      console.error('Chyba při načítání desktop autocomplete:', error);
      if (desktopSearchController && desktopSearchController.signal === signal) {
        desktopSearchController = null;
      }
    }
  }

  function closeMobileSearchField() {
    const searchField = document.querySelector('.db-mobile-search-field');
    if (searchField && !searchField.classList.contains('hidden')) {
      searchField.classList.add('hidden');
      searchField.style.display = 'none';
    }
    removeMobileAutocomplete();
    if (mobileSearchController) {
      try { mobileSearchController.abort(); } catch(_) {}
      mobileSearchController = null;
    }
  }

  async function getInternalSearchResults(query, signal) {
    const normalized = (query || '').trim().toLowerCase();
    if (internalSearchCache.has(normalized)) {
      return internalSearchCache.get(normalized);
    }

    if (!normalized) {
      internalSearchCache.set(normalized, []);
      return [];
    }

    const params = new URLSearchParams({
      query: query.trim(),
      limit: '8'
    });
    const restUrl = dbMapData?.searchUrl || '/wp-json/db/v1/map-search';

    try {
      const res = await fetch(`${restUrl}?${params.toString()}`, {
        signal,
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-WP-Nonce': dbMapData?.restNonce || ''
        }
      });
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      const payload = await res.json();
      const results = Array.isArray(payload?.results) ? payload.results : [];
      internalSearchCache.set(normalized, results);
      return results;
    } catch (error) {
      if (signal && signal.aborted) {
        throw error;
      }
      console.warn('DB internal search failed:', error);
      internalSearchCache.set(normalized, []);
      return [];
    }
  }

  async function getExternalSearchResults(query, signal) {
    const normalized = (query || '').trim().toLowerCase();
    if (externalSearchCache.has(normalized)) {
      return externalSearchCache.get(normalized);
    }

    if ((query || '').trim().length < 3) {
      const payload = { results: [], userCoords: null };
      externalSearchCache.set(normalized, payload);
      return payload;
    }

    try {
      const locale = await getBrowserLocale();
      const userCoords = locale.coords;

      let searchUrl = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&addressdetails=1&limit=10&accept-language=cs`;
      const countryConfig = getCountrySearchConfig(locale.country);
      searchUrl += `&countrycodes=${countryConfig.countrycodes}`;
      searchUrl += `&bounded=1&viewbox=${countryConfig.viewbox}`;
      if (userCoords) {
        searchUrl += `&lat=${userCoords[0]}&lon=${userCoords[1]}`;
      }

      const response = await fetch(searchUrl, { signal });
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const rawResults = await response.json();
      const prioritizedResults = prioritizeSearchResults(Array.isArray(rawResults) ? rawResults : [], userCoords || null);
      const payload = { results: prioritizedResults, userCoords: userCoords || null };
      externalSearchCache.set(normalized, payload);
      return payload;
    } catch (error) {
      if (signal && signal.aborted) {
        throw error;
      }
      console.warn('OSM search failed:', error);
      const fallback = { results: [], userCoords: null };
      externalSearchCache.set(normalized, fallback);
      return fallback;
    }
  }

  function getInternalConfidence(result) {
    const raw = Number(result?.confidence ?? result?.score);
    if (!Number.isFinite(raw)) {
      return 0;
    }

    return Math.max(0, Math.min(100, Math.round(raw)));
  }

  function getExternalConfidence(result) {
    const raw = Number(result?._score ?? result?.importance);
    if (!Number.isFinite(raw)) {
      return 0;
    }

    const clamped = Math.max(0, Math.min(150, raw));
    return Math.max(0, Math.min(100, Math.round(clamped / 1.5)));
  }

  function shouldPreferInternalResult(bestInternal, bestExternal, query) {
    if (!bestInternal) {
      return false;
    }

    const normalizedQuery = (query || '').trim().toLowerCase();
    const titleLc = (bestInternal.title || '').toLowerCase();
    const addressLc = (bestInternal.address || '').toLowerCase();

    if (normalizedQuery && (titleLc === normalizedQuery || addressLc === normalizedQuery)) {
      return true;
    }

    const internalConfidence = getInternalConfidence(bestInternal);

    if (!bestExternal) {
      return internalConfidence > 0;
    }

    const externalConfidence = getExternalConfidence(bestExternal);

    if (internalConfidence >= 85 && internalConfidence >= externalConfidence + 10) {
      return true;
    }

    if (normalizedQuery.length <= 3 && internalConfidence >= 75 && internalConfidence >= externalConfidence + 20) {
      return true;
    }

    return false;
  }

  function renderMobileAutocomplete(data, inputElement) {
    const internal = Array.isArray(data?.internal) ? data.internal : [];
    const external = Array.isArray(data?.external) ? data.external : [];

    if (internal.length === 0 && external.length === 0) {
      removeMobileAutocomplete();
      return;
    }

    let autocomplete = document.querySelector('.db-mobile-autocomplete');
    const rect = inputElement.getBoundingClientRect();
    if (!autocomplete) {
      autocomplete = document.createElement('div');
      autocomplete.className = 'db-mobile-autocomplete';
      autocomplete.style.position = 'fixed';
      autocomplete.style.background = '#fff';
      autocomplete.style.border = '1px solid #e5e7eb';
      autocomplete.style.borderRadius = '8px';
      autocomplete.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
      autocomplete.style.zIndex = '10001';
      autocomplete.style.maxHeight = '260px';
      autocomplete.style.overflowY = 'auto';
      document.body.appendChild(autocomplete);
    }

    autocomplete.style.top = `${rect.bottom + 5}px`;
    autocomplete.style.left = `${rect.left}px`;
    autocomplete.style.width = `${rect.width}px`;

    const escapeHtml = (str) => {
      return String(str || '').replace(/[&<>"']/g, (c) => {
        switch (c) {
          case '&': return '&amp;';
          case '<': return '&lt;';
          case '>': return '&gt;';
          case '"': return '&quot;';
          case "'": return '&#39;';
          default: return c;
        }
      });
    };

    const internalItems = internal.map((item, idx) => {
      const title = item?.title || '';
      const address = item?.address || '';
      const typeLabel = item?.type_label || item?.post_type || '';
      const subtitleParts = [];
      if (address) subtitleParts.push(address);
      if (typeLabel) subtitleParts.push(typeLabel);
      const subtitle = subtitleParts.join(' • ');
      const badge = item?.is_recommended ? '<span style="background:#049FE8; color:#fff; font-size:0.7rem; padding:2px 6px; border-radius:999px;">DB doporučuje</span>' : '';
      return `
        <div class="db-mobile-ac-item" data-source="internal" data-index="${idx}" style="padding:12px; border-bottom:1px solid #f0f0f0; cursor:pointer; transition:background 0.2s;">
          <div style="font-weight:600; color:#111; display:flex; align-items:center; gap:6px;">
            <span>${escapeHtml(title)}</span>${badge}
          </div>
          ${subtitle ? `<div style="font-size:0.85em; color:#555; margin-top:4px;">${escapeHtml(subtitle)}</div>` : ''}
        </div>
      `;
    }).join('');

    const externalItems = external.map((item, idx) => {
      const display = item?.display_name || '';
      const primary = display.split(',')[0] || display;
      const country = item?._country ? ` – ${item._country}` : '';
      const distance = Number.isFinite(item?._distance) ? ` (${Math.round(item._distance)} km)` : '';
      return `
        <div class="db-mobile-ac-item" data-source="external" data-index="${idx}" style="padding:12px; border-bottom:1px solid #f0f0f0; cursor:pointer; transition:background 0.2s;">
          <div style="font-weight:500; color:#333;">${escapeHtml(primary)}</div>
          <div style="font-size:0.85em; color:#666; margin-top:4px;">${escapeHtml(display)}${distance}${escapeHtml(country)}</div>
        </div>
      `;
    }).join('');

    const sections = [];
    if (internal.length > 0) {
      sections.push(`
        <div class="db-mobile-ac-section" data-section="internal">
          <div style="padding:10px 12px; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280;">Dobitý Baterky</div>
          ${internalItems}
        </div>
      `);
    }
    if (external.length > 0) {
      sections.push(`
        <div class="db-mobile-ac-section" data-section="external">
          <div style="padding:10px 12px; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280;">OpenStreetMap</div>
          ${externalItems}
        </div>
      `);
    }

    autocomplete.innerHTML = sections.join('');

    if (autocomplete.__outsideHandler) {
      document.removeEventListener('click', autocomplete.__outsideHandler);
    }
    const outsideHandler = (e) => {
      if (!autocomplete.contains(e.target) && e.target !== inputElement) {
        removeMobileAutocomplete();
      }
    };
    autocomplete.__outsideHandler = outsideHandler;
    setTimeout(() => document.addEventListener('click', outsideHandler), 0);

    autocomplete.querySelectorAll('.db-mobile-ac-item').forEach((itemEl) => {
      itemEl.addEventListener('mouseenter', () => { itemEl.style.background = '#f8f9fa'; });
      itemEl.addEventListener('mouseleave', () => { itemEl.style.background = 'transparent'; });
      itemEl.addEventListener('click', async () => {
        const source = itemEl.getAttribute('data-source');
        const idx = parseInt(itemEl.getAttribute('data-index'), 10);
        removeMobileAutocomplete();
        if (source === 'internal') {
          const picked = internal[idx];
          if (picked) {
            inputElement.value = picked.title || '';
            await handleInternalSelection(picked);
          }
        } else if (source === 'external') {
          const picked = external[idx];
          if (picked) {
            inputElement.value = picked.display_name || '';
            await handleExternalSelection(picked);
          }
        }
      });
    });
  }

  async function handleInternalSelection(result) {
    try {
      const lat = Number.parseFloat(result?.lat);
      const lng = Number.parseFloat(result?.lng);
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        showMobileSearchError('Vybraný bod nemá platné souřadnice.');
        return;
      }

      const targetZoom = Math.max(map.getZoom(), 15);
      map.setView([lat, lng], targetZoom, { animate: true, duration: 0.5 });

      await fetchAndRenderRadius({ lat, lng }, null);

      searchAddressCoords = null;
      searchSortLocked = false;
      sortMode = 'distance-active';
      if (searchAddressMarker) {
        try { map.removeLayer(searchAddressMarker); } catch(_) {}
        searchAddressMarker = null;
      }

      if (result?.id != null) {
        highlightMarkerById(result.id);
        renderCards('', result.id);
        const feature = featureCache.get(result.id);
        if (feature) {
          if (window.innerWidth <= 900) {
            openMobileSheet(feature);
          } else {
            openDetailModal(feature);
          }
        }
      }

      closeMobileSearchField();
      const descriptorParts = [];
      if (result?.title) descriptorParts.push(result.title);
      if (result?.address) descriptorParts.push(result.address);
      const descriptor = descriptorParts.join(' • ') || 'Výsledek vyhledávání';
      showMobileSearchConfirmation(descriptor, { headline: 'Bod z databáze' });
    } catch (error) {
      console.error('Chyba při zobrazení interního výsledku:', error);
      showMobileSearchError('Nepodařilo se zobrazit vybraný bod.');
    }
  }

  async function handleExternalSelection(result) {
    try {
      const lat = Number.parseFloat(result?.lat);
      const lng = Number.parseFloat(result?.lon || result?.lng);
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        showMobileSearchError('Výsledek nemá platné souřadnice.');
        return;
      }

      map.setView([lat, lng], 15, { animate: true, duration: 0.5 });
      searchAddressCoords = [lat, lng];
      sortMode = 'distance_from_address';
      searchSortLocked = true;
      renderCards('', null, false);
      addOrMoveSearchAddressMarker([lat, lng]);
      closeMobileSearchField();
      showMobileSearchConfirmation(result?.display_name || 'Vyhledávání dokončeno');
    } catch (error) {
      console.error('Chyba při zobrazení externího výsledku:', error);
      showMobileSearchError('Nepodařilo se zobrazit vybranou adresu.');
    }
  }

  // Funkce pro mobilní vyhledávání
  async function performMobileSearch(query) {
    const trimmed = (query || '').trim();
    if (!trimmed) {
      return;
    }

    try {
      const [internalResults, externalPayload] = await Promise.all([
        getInternalSearchResults(trimmed),
        getExternalSearchResults(trimmed)
      ]);

      const internalList = Array.isArray(internalResults) ? internalResults : [];
      const externalResults = Array.isArray(externalPayload?.results) ? externalPayload.results : [];
      const bestInternal = internalList.length > 0 ? internalList[0] : null;
      const bestExternal = externalResults.length > 0 ? externalResults[0] : null;

      if (shouldPreferInternalResult(bestInternal, bestExternal, trimmed)) {
        await handleInternalSelection(bestInternal);
        return;
      }

      if (bestExternal) {
        await handleExternalSelection(bestExternal);
        return;
      }

      if (bestInternal) {
        await handleInternalSelection(bestInternal);
        return;
      }

      showMobileSearchError('Nic jsme nenašli. Zkuste upravit dotaz.');
    } catch (error) {
      if (error.name === 'AbortError') {
        return;
      }
      console.error('Chyba při vyhledávání:', error);
      showMobileSearchError('Chyba při vyhledávání. Zkuste to znovu.');
    }
  }

  // Funkce pro zobrazení autocomplete na mobilu
  async function showMobileAutocomplete(query, inputElement) {
    const trimmed = (query || '').trim();
    if (trimmed.length < 2) {
      removeMobileAutocomplete();
      return;
    }

    const normalized = trimmed.toLowerCase();
    const cachedInternal = internalSearchCache.get(normalized);
    const cachedExternal = externalSearchCache.get(normalized);
    if (cachedInternal || cachedExternal) {
      renderMobileAutocomplete({
        internal: cachedInternal || [],
        external: (cachedExternal && cachedExternal.results) || []
      }, inputElement);
    }

    if (mobileSearchController) {
      try { mobileSearchController.abort(); } catch(_) {}
    }
    mobileSearchController = new AbortController();
    const signal = mobileSearchController.signal;

    try {
      const [internal, externalPayload] = await Promise.all([
        getInternalSearchResults(trimmed, signal),
        trimmed.length >= 3 ? getExternalSearchResults(trimmed, signal) : Promise.resolve({ results: [], userCoords: null })
      ]);

      if (signal.aborted) {
        return;
      }

      renderMobileAutocomplete({
        internal,
        external: externalPayload?.results || []
      }, inputElement);
      if (mobileSearchController && mobileSearchController.signal === signal) {
        mobileSearchController = null;
      }
    } catch (error) {
      if (error.name === 'AbortError') {
        return;
      }
      console.error('Chyba při načítání autocomplete:', error);
      if (mobileSearchController && mobileSearchController.signal === signal) {
        mobileSearchController = null;
      }
    }
  }
  
  // Funkce pro zobrazení potvrzení vyhledávání
  function showMobileSearchConfirmation(message, options = {}) {
    const { headline = 'Výsledek nalezen', icon = '✓' } = options || {};
    const confirmation = document.createElement('div');
    confirmation.className = 'db-mobile-search-confirmation';
    confirmation.style.cssText = `
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: #fff;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.15);
      z-index: 10002;
      text-align: center;
      max-width: 300px;
    `;

    const escapeHtml = (str) => String(str || '').replace(/[&<>"']/g, (c) => {
      switch (c) {
        case '&': return '&amp;';
        case '<': return '&lt;';
        case '>': return '&gt;';
        case '"': return '&quot;';
        case "'": return '&#39;';
        default: return c;
      }
    });

    const safeMessage = message ? `<div style="color: #666; font-size: 0.9em; line-height: 1.4;">${escapeHtml(message)}</div>` : '';

    confirmation.innerHTML = `
      <div style="color: #10b981; font-size: 24px; margin-bottom: 12px;">${escapeHtml(icon)}</div>
      <div style="font-weight: 600; color: #333; margin-bottom: 8px;">${escapeHtml(headline)}</div>
      ${safeMessage}
      <button style="margin-top: 16px; padding: 8px 16px; background: #049FE8; color: #fff; border: none; border-radius: 6px; cursor: pointer;">OK</button>
    `;
    
    document.body.appendChild(confirmation);
    
    // Event listener pro tlačítko OK
    confirmation.querySelector('button').addEventListener('click', () => {
      confirmation.remove();
    });
    
    // Automaticky skrýt po 3 sekundách
    setTimeout(() => {
      if (confirmation.parentNode) {
        confirmation.remove();
      }
    }, 3000);
  }
  
  // Funkce pro zobrazení chyby vyhledávání
  function showMobileSearchError(message) {
    const error = document.createElement('div');
    error.className = 'db-mobile-search-error';
    error.style.cssText = `
      position: fixed;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: #fff;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.15);
      z-index: 10002;
      text-align: center;
      max-width: 300px;
      border-left: 4px solid #ef4444;
    `;
    
    error.innerHTML = `
      <div style="color: #ef4444; font-size: 24px; margin-bottom: 12px;">⚠</div>
      <div style="font-weight: 600; color: #333; margin-bottom: 8px;">Chyba</div>
      <div style="color: #666; font-size: 0.9em; line-height: 1.4;">${message}</div>
      <button style="margin-top: 16px; padding: 8px 16px; background: #6b7280; color: #fff; border: none; border-radius: 6px; cursor: pointer;">OK</button>
    `;
    
    document.body.appendChild(error);
    
    // Event listener pro tlačítko OK
    error.querySelector('button').addEventListener('click', () => {
      error.remove();
    });
    
    // Automaticky skrýt po 5 sekundách
    setTimeout(() => {
      if (error.parentNode) {
        error.remove();
      }
    }, 5000);
  }
  
  // Spustit při změně velikosti okna - pouze pokud je mobilní
  let resizeTimeout;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(() => {
      const currentIsMobile = window.innerWidth <= 900;
      
      if (currentIsMobile && map) {
        createMobileSearchField();
        
        // Znovu přidat event listener na tlačítko lupy
        setTimeout(() => {
          const searchToggleBtn = document.getElementById('db-search-toggle');
          if (searchToggleBtn) {
            // Odstranit staré event listenery
            const newBtn = searchToggleBtn.cloneNode(true);
            searchToggleBtn.parentNode.replaceChild(newBtn, searchToggleBtn);
            
            // Přidat nový event listener
            newBtn.addEventListener('click', function(e) {
              e.preventDefault();
              e.stopPropagation();
              
              const searchField = document.querySelector('.db-mobile-search-field');
              if (searchField) {
                if (searchField.classList.contains('hidden')) {
                  // Zobrazit pole
                  searchField.classList.remove('hidden');
                  searchField.style.display = 'block';
                  
                  // Focus na input
                  const searchInput = searchField.querySelector('#db-mobile-search-field-input');
                  if (searchInput) {
                    // Nespouštět auto-focus na mobilech kvůli iOS zoomu
                    const isMobile3 = /Mobi|Android/i.test(navigator.userAgent);
                    if (!isMobile3) {
                      setTimeout(() => searchInput.focus(), 100);
                    }
                  }
                } else {
                  // Skrýt pole
                  searchField.classList.add('hidden');
                  searchField.style.display = 'none';
                }
              }
            });
          }
        }, 100);
      } else {
        // Desktop verze - odstranit mobilní vyhledávací pole
        const existingSearch = document.querySelector('.db-mobile-search-field');
        if (existingSearch) {
          existingSearch.remove();
        }
      }
    }, 100); // Debounce resize event
  });
    // Tlačítko lupy už nemá akci - vyhledávací pole je na pevno


  

  

  

  



  


  // ===== KONEC HLAVNÍ FUNKCE =====
});
// Konec db-map.js 
;(function(){
  try {
    var isStandalone = false;
    try {
      isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || (typeof navigator !== 'undefined' && navigator.standalone === true);
    } catch (e) {}

    if (!isStandalone) return;

    // Označ PWA session pro server
    try {
      document.cookie = 'db_is_pwa=1; path=/; max-age=' + (60 * 60 * 24 * 365) + '; samesite=lax';
    } catch (e) {}

    function pingKeepAlive() {
      try {
        var base = (typeof dbMapData !== 'undefined' && dbMapData.ajaxUrl) ? dbMapData.ajaxUrl : '/wp-admin/admin-ajax.php';
        var url = base + (base.indexOf('?') === -1 ? '?' : '&') + 'action=db_keepalive';
        fetch(url, { credentials: 'include', cache: 'no-store' }).catch(function(){});
      } catch (e) {}
    }

    document.addEventListener('visibilitychange', function(){
      if (document.visibilityState === 'visible') pingKeepAlive();
    });

    // Po startu a pak každé 4 hodiny
    pingKeepAlive();
    setInterval(pingKeepAlive, 4 * 60 * 60 * 1000);
  } catch (e) {}

  // ===== ADMIN PANEL FUNKCE =====
  
  /**
   * Aktualizace DB doporučuje toggle
   */
  function updateDbRecommended(postId, isRecommended) {
    if (!dbMapData || !dbMapData.isAdmin) {
      return;
    }

    fetch(dbMapData.ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({
        action: 'db_update_recommended',
        post_id: postId,
        recommended: isRecommended ? '1' : '0',
        nonce: dbMapData.adminNonce
      })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        
        // Aktualizovat lokální cache - najít feature v features array
        const feature = features.find(f => f.properties && f.properties.id == postId);
        if (feature) {
          feature.properties.db_recommended = isRecommended ? 1 : 0;
        }
        
        // Aktualizovat featureCache
        const cachedFeature = featureCache.get(postId);
        if (cachedFeature) {
          cachedFeature.properties.db_recommended = isRecommended ? 1 : 0;
        }
        
        // Aktualizovat UI
        const toggle = document.querySelector('#db-recommended-toggle');
        if (toggle) {
          toggle.checked = isRecommended;
        }
      } else {
        alert('Chyba při aktualizaci: ' + (data.data || 'Neznámá chyba'));
      }
    })
    .catch(error => {
      alert('Chyba při aktualizaci: ' + error.message);
    });
  }

  /**
   * Nahrávání fotek
   */
  function handlePhotoUpload(files, postId) {
    if (!dbMapData || !dbMapData.isAdmin) {
      return;
    }

    if (!files || files.length === 0) {
      return;
    }

    const formData = new FormData();
    formData.append('action', 'db_upload_photo');
    formData.append('post_id', postId);
    formData.append('nonce', dbMapData.adminNonce);

    // Nahrát všechny vybrané soubory
    Array.from(files).forEach((file, index) => {
      formData.append(`photo_${index}`, file);
    });

    // Zobrazit loading
    const preview = document.querySelector('#db-photo-preview');
    if (preview) {
      preview.innerHTML = '<div class="db-photo-loading">Nahrávám fotky...</div>';
    }

    fetch(dbMapData.ajaxUrl, {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Aktualizovat preview
        if (preview) {
          preview.innerHTML = '<div class="db-photo-success">Fotky nahrány úspěšně!</div>';
          setTimeout(() => {
            preview.innerHTML = '';
          }, 3000);
        }
        // Aktualizovat hlavní obrázek v modalu
        updateModalImage(data.data.thumbnail_url);
      } else {
        alert('Chyba při nahrávání: ' + (data.data || 'Neznámá chyba'));
        if (preview) {
          preview.innerHTML = '';
        }
      }
    })
    .catch(error => {
      alert('Chyba při nahrávání: ' + error.message);
      if (preview) {
        preview.innerHTML = '';
      }
    });
  }

  /**
   * Aktualizace hlavního obrázku v modalu
   */
  function updateModalImage(imageUrl) {
    const heroImg = document.querySelector('.modal-card .hero img');
    if (heroImg && imageUrl) {
      heroImg.src = imageUrl;
    }
  }
  
  // Při zavření modalu vyčistit isochrones
  const originalCloseDetailModal = closeDetailModal;
  closeDetailModal = function() {
    clearIsochrones();
    originalCloseDetailModal();
  };
  
})();
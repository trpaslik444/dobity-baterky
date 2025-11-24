(function () {
  const data = window.dbMapData || {};
  const baseUrl = data.assetsBase || (data.pluginUrl ? data.pluginUrl + 'assets/map/' : '');
  const CACHE_BUST_TAG = '20241120a';
  const baseVersion = data.version ? String(data.version) : '';
  const versionString = baseVersion ? baseVersion + '-' + CACHE_BUST_TAG : CACHE_BUST_TAG;
  const versionSuffix = '?ver=' + encodeURIComponent(versionString);

  if (!baseUrl) {
    console.error('[DB Map][Loader] Neznámá URL k mapovým skriptům.');
    return;
  }

  const originalAddEventListener = document.addEventListener.bind(document);
  const originalRemoveEventListener = document.removeEventListener.bind(document);

  document.addEventListener = function (type, listener, options) {
    if (type === 'DOMContentLoaded' && typeof listener === 'function') {
      if (document.readyState === 'loading') {
        return originalAddEventListener(type, listener, options);
      }
      setTimeout(() => {
        try {
          // Vytvořit syntetický event objekt pro API kompatibilitu
          const syntheticEvent = new Event('DOMContentLoaded', {
            bubbles: false,
            cancelable: false
          });
          listener.call(document, syntheticEvent);
        } catch (error) {
          console.error('[DB Map][Loader] DOMContentLoaded callback selhal', error);
        }
      }, 0);
      return;
    }
    return originalAddEventListener(type, listener, options);
  };

  document.removeEventListener = function (type, listener, options) {
    if (type === 'DOMContentLoaded') {
      return;
    }
    return originalRemoveEventListener(type, listener, options);
  };

  const loaderState = {
    readyPromise: null,
    resolve: null,
    reject: null,
    whenReady(callback) {
      if (typeof callback !== 'function') return;
      this.readyPromise.then(callback).catch(() => {});
    }
  };

  window.dbMapLoader = loaderState;

  loaderState.readyPromise = new Promise((resolve, reject) => {
    loaderState.resolve = resolve;
    loaderState.reject = reject;
  });

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      const fullSrc = src + versionSuffix;
      script.src = fullSrc;
      script.async = false;
      script.onload = () => {
        resolve();
      };
      script.onerror = (event) => {
        console.error('[DB Map][Loader] Chyba načítání skriptu', fullSrc, event);
        reject(new Error('Failed to load ' + fullSrc));
      };
      document.head.appendChild(script);
    });
  }

  function isStandalonePWA() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  }

  function detectTarget() {
    if (isStandalonePWA()) {
      return 'mobile';
    }
    const prefersMobile = window.matchMedia('(max-width: 768px)').matches;
    return prefersMobile ? 'mobile' : 'desktop';
  }

  const target = detectTarget();
  window.dbMapShell = target;

  loadScript(baseUrl + 'core.js')
    .then(() => loadScript(baseUrl + target + '.js'))
    .then(() => {
      if (loaderState.resolve) {
        loaderState.resolve();
      }
      try {
        window.dispatchEvent(new CustomEvent('db-map-ready', { detail: { target } }));
      } catch (_) {
        window.dispatchEvent(new Event('db-map-ready'));
      }
    })
    .catch((error) => {
      if (loaderState.reject) {
        loaderState.reject(error);
      }
      console.error('[DB Map][Loader] Nepodařilo se načíst mapové skripty.', error);
    });
})();


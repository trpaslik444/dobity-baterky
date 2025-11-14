(function () {
  window.dbMapShell = 'desktop';
  document.documentElement.classList.add('db-map-shell', 'db-map-shell--desktop');
  if (window.console && console.debug) {
    console.debug('[DB Map][Desktop] Desktop shell aktivní.');
  }
})();


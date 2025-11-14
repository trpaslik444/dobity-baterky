(function () {
  window.dbMapShell = 'mobile';
  document.documentElement.classList.add('db-map-shell', 'db-map-shell--mobile');
  if (window.console && console.debug) {
    console.debug('[DB Map][Mobile] Mobilní shell aktivní.');
  }
})();


// PWA Helper pro Dobitý Baterky - Nová implementace podle specifikací

(function(){
  // Android Chrome: beforeinstallprompt
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    // TODO: ukaž vlastní UI tlačítko "Instalovat aplikaci"
    // Po kliku:
    // deferredPrompt.prompt();
    // deferredPrompt.userChoice.then(() => deferredPrompt = null);
    
    console.log('PWA instalace dostupná - zobrazit tlačítko');
    showInstallButton();
  });

  // Detekce spuštění jako standalone (bez prohlížečové chromy)
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;

  if (isStandalone) {
    document.documentElement.classList.add('pwa-standalone');
    console.log('PWA běží v standalone módu');
    // Můžeš upravit UI/skrýt prvky, které v PWA nechceš
    optimizeForStandalone();
  }

  // iOS tip: ukaž návod "Sdílet → Přidat na plochu" když není nainstalováno
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  if (isIOS && !isStandalone) {
    console.log('iOS zařízení - zobrazit návod pro přidání na plochu');
    showIOSInstallHint();
  }

  /**
   * Zobrazí tlačítko pro instalaci PWA
   */
  function showInstallButton() {
    // Vytvoř tlačítko pokud neexistuje
    let installBtn = document.getElementById('pwa-install-btn');
    if (!installBtn) {
      installBtn = document.createElement('button');
      installBtn.id = 'pwa-install-btn';
      installBtn.className = 'pwa-install-button';
      installBtn.innerHTML = '📱 Nainstalovat aplikaci';
      installBtn.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        background: #049FE8;
        color: white;
        border: none;
        border-radius: 50px;
        padding: 12px 20px;
        font-family: 'Montserrat', sans-serif;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        box-shadow: 0 4px 16px rgba(4, 159, 232, 0.3);
        transition: all 0.3s ease;
      `;
      
      installBtn.addEventListener('click', async () => {
        if (deferredPrompt) {
          deferredPrompt.prompt();
          const { outcome } = await deferredPrompt.userChoice;
          if (outcome === 'accepted') {
            console.log('Uživatel přijal PWA instalaci');
            installBtn.remove();
          }
          deferredPrompt = null;
        }
      });
      
      document.body.appendChild(installBtn);
    }
  }

  /**
   * Optimalizuje stránku pro standalone mód
   */
  function optimizeForStandalone() {
    // Skrýt WordPress admin bar
    const adminBar = document.getElementById('wpadminbar');
    if (adminBar) {
      adminBar.style.display = 'none';
      document.body.style.paddingTop = '0';
    }
    
    // Skrýt WordPress footer
    const footer = document.querySelector('#wp-footer, .wp-footer, footer');
    if (footer) {
      footer.style.display = 'none';
    }
    
    // Skrýt WordPress sidebar
    const sidebar = document.querySelector('#sidebar, .sidebar, aside');
    if (sidebar) {
      sidebar.style.display = 'none';
    }
    
    // Optimalizace pro PWA
    document.documentElement.classList.add('pwa-mode');
    
    // Přidat CSS třídu pro standalone
    document.body.classList.add('pwa-standalone');
  }

  /**
   * Zobrazí iOS návod pro přidání na plochu
   */
  function showIOSInstallHint() {
    // Vytvoř nenápadný hint
    const hint = document.createElement('div');
    hint.className = 'ios-install-hint';
    hint.innerHTML = `
      <div style="
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(0,0,0,0.8);
        color: white;
        padding: 12px 20px;
        border-radius: 25px;
        font-size: 14px;
        z-index: 10001;
        text-align: center;
        max-width: 300px;
      ">
        <div>📱 <strong>Přidat na plochu:</strong></div>
        <div style="font-size: 12px; margin-top: 5px;">
          Sdílet → Přidat na plochu
        </div>
        <button onclick="this.parentElement.parentElement.remove()" style="
          background: #049FE8;
          border: none;
          color: white;
          padding: 5px 10px;
          border-radius: 15px;
          margin-top: 8px;
          font-size: 12px;
          cursor: pointer;
        ">Zavřít</button>
      </div>
    `;
    
    document.body.appendChild(hint);
    
    // Automaticky skrýt po 10 sekundách
    setTimeout(() => {
      if (hint.parentElement) {
        hint.remove();
      }
    }, 10000);
  }

  /**
   * Detekuje změny display módu
   */
  if (window.matchMedia) {
    const mediaQuery = window.matchMedia('(display-mode: standalone)');
    mediaQuery.addListener((e) => {
      if (e.matches) {
        console.log('PWA přepnul do standalone módu');
        document.documentElement.classList.add('pwa-standalone');
        document.body.classList.add('pwa-standalone');
        optimizeForStandalone();
      } else {
        console.log('PWA přepnul do browser módu');
        document.documentElement.classList.remove('pwa-standalone');
        document.body.classList.remove('pwa-standalone');
        
        // Zobrazit WordPress elementy zpět
        const adminBar = document.getElementById('wpadminbar');
        if (adminBar) {
          adminBar.style.display = 'block';
          document.body.style.paddingTop = '';
        }
      }
    });
  }

  // Debug informace
  console.log('PWA Helper načten:', {
    isStandalone,
    isIOS,
    hasDeferredPrompt: !!deferredPrompt
  });

})();

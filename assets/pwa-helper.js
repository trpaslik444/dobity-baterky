// PWA Helper pro Dobitý Baterky - Nová implementace podle specifikací

(function(){
  // Android Chrome: beforeinstallprompt
  let deferredPrompt = null;
  const DISMISS_KEY = 'db_pwa_install_dismissed_until';
  const INSTALLED_KEY = 'db_pwa_installed';
  const DISMISS_TTL_DAYS = 30;
  
  function nowTs() { return Date.now(); }
  function daysToMs(d) { return d * 24 * 60 * 60 * 1000; }
  function getDismissUntilTs() {
    const raw = localStorage.getItem(DISMISS_KEY);
    const ts = raw ? parseInt(raw, 10) : 0;
    return Number.isFinite(ts) ? ts : 0;
  }
  function setDismissForDays(days) {
    const until = nowTs() + daysToMs(days);
    localStorage.setItem(DISMISS_KEY, String(until));
  }
  function shouldSuppressPrompt(isStandalone) {
    if (isStandalone) return true;
    const installed = localStorage.getItem(INSTALLED_KEY) === '1';
    if (installed) return true;
    const until = getDismissUntilTs();
    return until > nowTs();
  }
  window.addEventListener('appinstalled', () => {
    localStorage.setItem(INSTALLED_KEY, '1');
    const promptEl = document.getElementById('pwa-install-prompt');
    if (promptEl) promptEl.remove();
    const btn = document.getElementById('pwa-install-btn');
    if (btn) btn.remove();
  });
  
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (!shouldSuppressPrompt(isStandalone)) {
      console.log('PWA instalace dostupná - zobrazit tlačítko/prompt');
      showInstallPrompt();
    } else {
      console.log('PWA instalace potlačena (nainstalováno nebo odmítnuto nedávno)');
    }
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
   * Zobrazí prompt pro instalaci PWA ve spodní třetině, uprostřed, s možností odmítnout
   */
  function showInstallPrompt() {
    // Nepokračovat, pokud je potlačeno
    if (shouldSuppressPrompt(isStandalone)) return;
    
    // Pokud existuje staré tlačítko, odstranit
    const oldBtn = document.getElementById('pwa-install-btn');
    if (oldBtn) oldBtn.remove();
    
    // Vytvořit UI prompt
    let promptEl = document.getElementById('pwa-install-prompt');
    if (!promptEl) {
      promptEl = document.createElement('div');
      promptEl.id = 'pwa-install-prompt';
      promptEl.className = 'pwa-install-prompt';
      promptEl.innerHTML = `
        <h3>📱 Nainstalovat aplikaci Dobitý Baterky</h3>
        <p>Aplikace poběží rychleji a bude dostupná z plochy.</p>
        <div class="pwa-install-actions">
          <button class="pwa-install-accept">Nainstalovat</button>
          <button class="pwa-install-dismiss">Ne, díky</button>
        </div>
      `;
      document.body.appendChild(promptEl);
      // Center ve spodní třetině – pomocí existujícího CSS se vykreslí uprostřed dole (left/right 20px).
      requestAnimationFrame(() => promptEl.classList.add('show'));
      
      const acceptBtn = promptEl.querySelector('.pwa-install-accept');
      const dismissBtn = promptEl.querySelector('.pwa-install-dismiss');
      
      if (acceptBtn) {
        acceptBtn.addEventListener('click', async () => {
        if (deferredPrompt) {
          deferredPrompt.prompt();
            try {
          const { outcome } = await deferredPrompt.userChoice;
          if (outcome === 'accepted') {
            console.log('Uživatel přijal PWA instalaci');
                localStorage.setItem(INSTALLED_KEY, '1');
                promptEl.remove();
              } else {
                // Pokud odmítl systémový prompt, nevnucovat hned znovu
                setDismissForDays(DISMISS_TTL_DAYS);
                promptEl.remove();
              }
            } catch (_) {
              setDismissForDays(DISMISS_TTL_DAYS);
              promptEl.remove();
          }
          deferredPrompt = null;
        }
      });
      }
      if (dismissBtn) {
        dismissBtn.addEventListener('click', () => {
          setDismissForDays(DISMISS_TTL_DAYS);
          promptEl.remove();
        });
      }
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

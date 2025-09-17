// Minimal feedback collector
(function(){
  if (!window.DB_FEEDBACK || !DB_FEEDBACK.enabled) return;

  var HATTR = DB_FEEDBACK.highlightAttr || 'data-db-feedback';
  var activeSelector = null;
  var lastDomSelector = '';
  var lastTextSnippet = '';

  // Floating button
  var fab = document.createElement('button');
  fab.className = 'db-feedback-fab';
  fab.type = 'button';
  fab.textContent = 'feedback';
  fab.setAttribute('aria-label', 'Zpětná vazba');
  fab.title = 'Zpětná vazba';
  document.addEventListener('DOMContentLoaded', function(){ document.body.appendChild(fab); });

  // Modal
  var modal = document.createElement('div');
  modal.className = 'db-feedback-modal';
  modal.innerHTML = '<div class="db-feedback-card">\
    <h3>Nahlásit zpětnou vazbu</h3>\
    <div class="row">\
      <div style="flex:1">\
        <label>Typ</label>\
        <select id="dbf-type"><option value="bug">Bug</option><option value="suggestion">Návrh</option><option value="content">Obsah</option></select>\
      </div>\
      <div style="flex:1">\
        <label>Závažnost</label>\
        <select id="dbf-priority"><option value="low">Nízká</option><option value="medium">Střední</option><option value="high">Vysoká</option></select>\
      </div>\
    </div>\
    <label>Komponenta (auto)</label>\
    <input type="text" id="dbf-component" readonly>\
    <label>Popis</label>\
    <textarea id="dbf-description" placeholder="Co je špatně / co navrhujete zlepšit"></textarea>\
    <div class="buttons">\
      <button type="button" class="secondary" onclick="closeFeedbackModal()">Zavřít</button>\
      <button type="button" onclick="submitFeedback()">Odeslat</button>\
    </div>\
  </div>';
  document.addEventListener('DOMContentLoaded', function(){ document.body.appendChild(modal); });

  // Event handlers
  fab.addEventListener('click', function(e) {
    if (activeSelector === null) {
      enableHighlightMode();
    } else {
      disableHighlightMode();
    }
    // Zabraň zachycení stejného kliku dokumentem (který by rovnou otevřel modal)
    if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
    if (e && typeof e.preventDefault === 'function') e.preventDefault();
  });
  document.addEventListener('click', handleElementClick);
  modal.addEventListener('click', function(e) { 
    if (e.target === modal) closeModal(); 
  });

  // Functions
  function enableHighlightMode() {
    activeSelector = 'active';
    document.body.style.cursor = 'crosshair';
    document.querySelectorAll('[' + HATTR + ']').forEach(function(el) {
      el.classList.add('db-feedback-highlight');
    });
    // ponecháme pouze krátký text
    fab.textContent = 'feedback';
  }

  function disableHighlightMode() {
    activeSelector = null;
    document.body.style.cursor = '';
    document.querySelectorAll('.db-feedback-highlight').forEach(function(el) {
      el.classList.remove('db-feedback-highlight');
    });
    // ponecháme pouze krátký text
    fab.textContent = 'feedback';
  }

  function handleElementClick(e) {
    if (!activeSelector) return;
    // Ignoruj klik na samotné tlačítko nebo do modalu
    if (e.target === fab || (modal && modal.contains(e.target))) return;
    var preciseTarget = e.target;
    var el = preciseTarget.closest && preciseTarget.closest('[' + HATTR + ']') ? preciseTarget.closest('[' + HATTR + ']') : preciseTarget;
    // Bezpečnost: pokud je vybraný element stále tlačítko, ignoruj
    if (el === fab) return;
    e.preventDefault();
    e.stopPropagation();
    // Selektor/snippet z přesného cíle, komponenta z nejbližšího rodiče s HATTR (pokud existuje)
    lastDomSelector = getDomSelector(preciseTarget);
    var container = preciseTarget.closest && preciseTarget.closest('[' + HATTR + ']');
    var key = (container && container.getAttribute(HATTR)) || '';
    lastTextSnippet = (preciseTarget && (preciseTarget.innerText || preciseTarget.textContent || '')).trim().slice(0, 280);
    disableHighlightMode();
    openModal();
    var input = document.getElementById('dbf-component');
    if (!input) {
      setTimeout(function(){
        var i2 = document.getElementById('dbf-component');
        if (i2) i2.value = key || lastDomSelector;
      }, 0);
    } else {
      input.value = key || lastDomSelector;
    }
  }

  function openModal() {
    modal.classList.add('open');
    document.body.appendChild(modal);
  }

  function closeModal() {
    modal.classList.remove('open');
    if (modal.parentNode) {
      modal.parentNode.removeChild(modal);
    }
  }

  function getDomSelector(el) {
    if (!el || !el.tagName) return '';
    // Prefer ID
    if (el.id) return '#' + String(el.id).replace(/\s+/g, '\\ ');
    // Handle className being SVGAnimatedString or object
    var cls = '';
    var cn = el.className;
    if (typeof cn === 'string') { cls = cn; }
    else if (cn && typeof cn.baseVal === 'string') { cls = cn.baseVal; }
    if (cls && cls.trim()) {
      var parts = cls.trim().split(/\s+/).map(function(c){
        return c.replace(/[^a-zA-Z0-9_-]/g, function(m){ return '\\' + m; });
      });
      return el.tagName.toLowerCase() + '.' + parts.join('.');
    }
    // Fallback to attribute-based selector
    if (el.getAttribute) {
      var name = el.getAttribute('name');
      if (name) return el.tagName.toLowerCase() + '[name="' + String(name).replace(/"/g, '\\"') + '"]';
      var type = el.getAttribute('type');
      if (type) return el.tagName.toLowerCase() + '[type="' + String(type).replace(/"/g, '\\"') + '"]';
    }
    // Final fallback: nth-child
    var idx = 1; var p = el;
    while (p.previousElementSibling) { idx++; p = p.previousElementSibling; }
    return el.tagName.toLowerCase() + ':nth-child(' + idx + ')';
  }

  function submitFeedback(){
    var type = document.getElementById('dbf-type').value;
    var severity = document.getElementById('dbf-priority').value;
    var message = document.getElementById('dbf-description').value.trim();
    var component = document.getElementById('dbf-component').value.trim();
    if (!message) { alert('Vyplňte prosím popis.'); return; }
    var payload = {
      type: type,
      severity: severity,
      message: message,
      page_url: DB_FEEDBACK && DB_FEEDBACK.page ? DB_FEEDBACK.page.url : window.location.href,
      page_type: DB_FEEDBACK && DB_FEEDBACK.page ? DB_FEEDBACK.page.page_type : '',
      template: DB_FEEDBACK && DB_FEEDBACK.page ? DB_FEEDBACK.page.template : '',
      component_key: component,
      dom_selector: lastDomSelector,
      text_snippet: lastTextSnippet,
      meta_json: { ua: navigator.userAgent }
    };
    fetch((DB_FEEDBACK && DB_FEEDBACK.rest && DB_FEEDBACK.rest.createUrl) ? DB_FEEDBACK.rest.createUrl : '/wp-json/db/v1/feedback', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': (DB_FEEDBACK && DB_FEEDBACK.nonce) ? DB_FEEDBACK.nonce : '' },
      body: JSON.stringify(payload)
    }).then(function(res) {
      return res.json();
    }).then(function(data) {
      if (data.success) {
        // Vyčistit hodnoty ještě před zavřením modalu (elementy po closeModal už nemusí existovat)
        var desc = document.getElementById('dbf-description');
        if (desc) desc.value = '';
        var comp = document.getElementById('dbf-component');
        if (comp) comp.value = '';
        alert('Díky! Zpětná vazba byla uložena.');
        closeModal();
      } else {
        alert('Nepodařilo se uložit: ' + (data && (data.message || data.code) || 'neznámá chyba'));
      }
    }).catch(function(err) {
      alert('Chyba: ' + err.message);
    });
  }

  // Global functions for onclick handlers
  window.closeFeedbackModal = closeModal;
  window.submitFeedback = submitFeedback;

})();



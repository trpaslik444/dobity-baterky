// Minimal feedback collector
(function(){
  if (!window.DB_FEEDBACK || !DB_FEEDBACK.enabled) return;

  var HATTR = DB_FEEDBACK.highlightAttr || 'data-db-feedback';
  var activeSelector = null;

  // Floating button
  var fab = document.createElement('button');
  fab.className = 'db-feedback-fab';
  fab.type = 'button';
  fab.textContent = 'Zpětná vazba';
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
  fab.addEventListener('click', function() {
    if (activeSelector === null) {
      enableHighlightMode();
    } else {
      disableHighlightMode();
    }
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
    fab.textContent = 'Zrušit výběr';
  }

  function disableHighlightMode() {
    activeSelector = null;
    document.body.style.cursor = '';
    document.querySelectorAll('.db-feedback-highlight').forEach(function(el) {
      el.classList.remove('db-feedback-highlight');
    });
    fab.textContent = 'Zpětná vazba';
  }

  function handleElementClick(e) {
    if (!activeSelector) return;
    var el = e.target.closest('[' + HATTR + ']');
    if (!el) return;
    e.preventDefault();
    e.stopPropagation();
    activeSelector = getDomSelector(el);
    var key = el.getAttribute(HATTR) || '';
    document.getElementById('dbf-component').value = key;
    disableHighlightMode();
    openModal();
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
    if (el.id) return '#' + el.id;
    if (el.className) return '.' + el.className.split(' ').join('.');
    return el.tagName.toLowerCase();
  }

  function submitFeedback(){
    var type = document.getElementById('dbf-type').value;
    var severity = document.getElementById('dbf-priority').value;
    var message = document.getElementById('dbf-description').value.trim();
    var component = document.getElementById('dbf-component').value.trim();
    if (!message) { alert('Vyplňte prosím popis.'); return; }
    var payload = {
      type: type,
      priority: severity,
      component: component,
      message: message,
      url: window.location.href,
      user_agent: navigator.userAgent
    };
    fetch('/wp-json/db/v1/feedback', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function(res) {
      return res.json();
    }).then(function(data) {
      if (data.success) {
        alert('Díky! Zpětná vazba byla uložena.');
        closeModal();
        document.getElementById('dbf-description').value = '';
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



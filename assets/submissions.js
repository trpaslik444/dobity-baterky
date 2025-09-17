(function(){
  if (!window.dbMapData || !window.fetch) return;

  const btnId = 'db-add-point-btn';
  const panelId = 'db-submission-panel';
  const overlayId = 'db-submission-overlay';

  const state = {
    post_type: 'poi',
    title: '',
    description: '',
    address: '',
    lat: '',
    lng: '',
    rating: null,
    comment: ''
  };

  function ensureButton(){
    if (document.getElementById(btnId)) return;
    const mapDiv = document.getElementById('db-map');
    if (!mapDiv) return;
    const btn = document.createElement('button');
    btn.id = btnId;
    btn.type = 'button';
    btn.textContent = 'Přidat bod';
    btn.style.position = 'absolute';
    btn.style.zIndex = 1000;
    btn.style.top = '12px';
    btn.style.right = '12px';
    btn.style.padding = '8px 12px';
    btn.style.background = '#0066ff';
    btn.style.color = '#fff';
    btn.style.border = 'none';
    btn.style.borderRadius = '6px';
    btn.style.cursor = 'pointer';
    mapDiv.style.position = mapDiv.style.position || 'relative';
    mapDiv.appendChild(btn);
    btn.addEventListener('click', openWizardPanel);
  }

  function openWizardPanel(){
    if (document.getElementById(panelId)) return;

    const overlay = document.createElement('div');
    overlay.id = overlayId;
    overlay.style.position = 'fixed';
    overlay.style.inset = '0';
    overlay.style.background = 'rgba(0,0,0,0.45)';
    overlay.style.zIndex = 2000;

    const panel = document.createElement('div');
    panel.id = panelId;
    panel.style.position = 'fixed';
    panel.style.right = '0';
    panel.style.top = '0';
    panel.style.height = '100%';
    panel.style.width = 'min(420px, 100%)';
    panel.style.background = '#fff';
    panel.style.boxShadow = '0 0 20px rgba(0,0,0,0.2)';
    panel.style.zIndex = 2001;
    panel.style.display = 'flex';
    panel.style.flexDirection = 'column';

    panel.innerHTML = `
      <div style="padding:14px 16px; border-bottom:1px solid #eee; display:flex; align-items:center; justify-content:space-between;">
        <strong>Přidat bod</strong>
        <button type="button" id="db-subm-close" style="background:none;border:none;font-size:18px;cursor:pointer">×</button>
      </div>
      <div id="db-subm-body" style="padding:14px 16px; flex:1; overflow:auto;"></div>
      <div style="padding:12px 16px; border-top:1px solid #eee; display:flex; gap:8px; justify-content:space-between;">
        <div>
          <button type="button" id="db-subm-prev" class="button">Zpět</button>
        </div>
        <div>
          <button type="button" id="db-subm-next" class="button button-primary">Pokračovat</button>
          <button type="button" id="db-subm-submit" class="button button-primary" style="display:none;">Odeslat</button>
        </div>
      </div>
    `;

    document.body.appendChild(overlay);
    document.body.appendChild(panel);

    document.getElementById('db-subm-close').onclick = closePanel;
    overlay.onclick = closePanel;

    let step = 0;
    renderStep();
    document.getElementById('db-subm-prev').onclick = () => { if (step>0){ step--; renderStep(); } };
    document.getElementById('db-subm-next').onclick = () => { if (validateStep(step)) { step++; renderStep(); } };
    document.getElementById('db-subm-submit').onclick = () => { if (validateStep(step)) submit(state); };

    function renderStep(){
      const body = document.getElementById('db-subm-body');
      const btnNext = document.getElementById('db-subm-next');
      const btnSubmit = document.getElementById('db-subm-submit');
      const btnPrev = document.getElementById('db-subm-prev');
      btnPrev.style.visibility = step === 0 ? 'hidden' : 'visible';
      btnSubmit.style.display = (step === 4) ? 'inline-block' : 'none';
      btnNext.style.display = (step === 4) ? 'none' : 'inline-block';

      if (step === 0) {
        body.innerHTML = `
          <div style="display:flex; flex-direction:column; gap:12px;">
            <label>Typ bodu
              <select id="db-subm-type" style="width:100%">
                <option value="charging_location">Nabíjecí místo</option>
                <option value="poi" selected>POI</option>
                <option value="rv_spot">RV místo</option>
              </select>
            </label>
          </div>`;
        document.getElementById('db-subm-type').value = state.post_type;
        document.getElementById('db-subm-type').onchange = (e)=>{ state.post_type = e.target.value; };
      }
      if (step === 1) {
        const center = (window.dbMap && window.dbMap.getCenter) ? window.dbMap.getCenter() : {lat: '', lng: ''};
        body.innerHTML = `
          <div style="display:flex; flex-direction:column; gap:12px;">
            <div>Určete polohu: vyplňte adresu nebo souřadnice. Můžete též přesně nastavit střed mapy a použít tlačítko.</div>
            <label>Adresa
              <input id="db-subm-address" type="text" style="width:100%" value="${escapeHtml(state.address)}" placeholder="Ulice, město" />
            </label>
            <div style="display:flex; gap:8px;">
              <label style="flex:1;">Lat
                <input id="db-subm-lat" type="text" style="width:100%" value="${state.lat}" placeholder="např. 50.087" />
              </label>
              <label style="flex:1;">Lng
                <input id="db-subm-lng" type="text" style="width:100%" value="${state.lng}" placeholder="např. 14.421" />
              </label>
            </div>
            <button type="button" id="db-subm-use-center" class="button">Použít střed mapy${(center && center.lat)?` (${center.lat.toFixed?center.lat.toFixed(5):center.lat}, ${center.lng.toFixed?center.lng.toFixed(5):center.lng})`:''}</button>
          </div>`;
        document.getElementById('db-subm-address').oninput = (e)=> state.address = e.target.value;
        document.getElementById('db-subm-lat').oninput = (e)=> state.lat = e.target.value;
        document.getElementById('db-subm-lng').oninput = (e)=> state.lng = e.target.value;
        document.getElementById('db-subm-use-center').onclick = ()=>{
          if (window.dbMap && window.dbMap.getCenter){
            const c = window.dbMap.getCenter();
            state.lat = c.lat;
            state.lng = c.lng;
            const latEl = document.getElementById('db-subm-lat');
            const lngEl = document.getElementById('db-subm-lng');
            if (latEl) latEl.value = (c.lat.toFixed ? c.lat.toFixed(7) : c.lat);
            if (lngEl) lngEl.value = (c.lng.toFixed ? c.lng.toFixed(7) : c.lng);
          } else {
            alert('Mapa není dostupná.');
          }
        };
      }
      if (step === 2) {
        body.innerHTML = `
          <div style="display:flex; flex-direction:column; gap:12px;">
            <label>Název
              <input id="db-subm-title" type="text" style="width:100%" value="${escapeHtml(state.title)}" />
            </label>
            <label>Popis
              <textarea id="db-subm-desc" style="width:100%; min-height:90px;">${escapeHtml(state.description)}</textarea>
            </label>
          </div>`;
        document.getElementById('db-subm-title').oninput = (e)=> state.title = e.target.value;
        document.getElementById('db-subm-desc').oninput = (e)=> state.description = e.target.value;
      }
      if (step === 3) {
        body.innerHTML = `
          <div style="display:flex; flex-direction:column; gap:12px;">
            <label>Hodnocení (0–5)
              <input id="db-subm-rating" type="number" min="0" max="5" step="1" value="${state.rating==null?'':state.rating}" style="width:120px" />
            </label>
            <label>Komentář (nepovinný, vyžaduje hodnocení)
              <textarea id="db-subm-comment" style="width:100%; min-height:80px;">${escapeHtml(state.comment)}</textarea>
            </label>
          </div>`;
        document.getElementById('db-subm-rating').oninput = (e)=> state.rating = e.target.value===''?null:parseInt(e.target.value,10);
        document.getElementById('db-subm-comment').oninput = (e)=> state.comment = e.target.value;
      }
      if (step === 4) {
        body.innerHTML = `
          <div style="display:flex; flex-direction:column; gap:8px;">
            <div><strong>Rekapitulace</strong></div>
            <div>Typ: ${escapeHtml(state.post_type)}</div>
            <div>Název: ${escapeHtml(state.title)}</div>
            <div>Popis: ${escapeHtml(state.description)}</div>
            <div>Adresa: ${escapeHtml(state.address)}</div>
            <div>Souřadnice: ${state.lat}, ${state.lng}</div>
            <div>Hodnocení: ${state.rating==null?'—':state.rating}, Komentář: ${escapeHtml(state.comment)}</div>
          </div>`;
      }
    }

    function validateStep(s){
      if (s === 1) {
        if (!state.lat || !state.lng) { alert('Vyplňte souřadnice, nebo použijte střed mapy.'); return false; }
      }
      if (s === 2) {
        if (!state.title) { alert('Zadejte název.'); return false; }
      }
      if (s === 3) {
        if (state.comment && (state.rating==null || state.rating==='')) { alert('Komentář vyžaduje hodnocení.'); return false; }
      }
      return true;
    }
  }

  function escapeHtml(str){
    if (str===null || str===undefined) return '';
    return String(str).replace(/[&<>"]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s]));
  }

  async function submit(payload){
    try{
      const base = (window.wpApiSettings && window.wpApiSettings.root) ? window.wpApiSettings.root : (window.dbMapData.restUrl.replace(/\/map$/, ''));
      const res = await fetch(base + 'submissions',{
        method:'POST',
        headers:{
          'Content-Type':'application/json',
          'X-WP-Nonce': (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : (window.dbMapData && window.dbMapData.restNonce ? window.dbMapData.restNonce : '')
        },
        body: JSON.stringify(payload)
      });
      if (!res.ok){
        const txt = await res.text();
        alert('Chyba odeslání: '+txt);
        return;
      }
      const data = await res.json();
      alert('Podání vytvořeno (#'+data.id+').');
      closePanel();
    }catch(e){
      console.error(e);
      alert('Chyba připojení.');
    }
  }

  function closePanel(){
    const p = document.getElementById(panelId);
    const o = document.getElementById(overlayId);
    if (p) p.remove();
    if (o) o.remove();
  }

  document.addEventListener('DOMContentLoaded', ensureButton);
})();


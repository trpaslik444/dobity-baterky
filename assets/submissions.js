(function(){
  if (!window.dbMapData || !window.fetch) return;
  const btnId = 'db-add-point-btn';
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

    btn.addEventListener('click', openWizard);
  }

  function openWizard(){
    const type = prompt('Typ: charging_location | poi | rv_spot','poi');
    if (!type) return;
    const title = prompt('Název bodu','');
    const desc = prompt('Popis (nepovinné)','');
    const lat = prompt('Zadej lat','');
    const lng = prompt('Zadej lng','');
    const address = prompt('Adresa (nepovinné)','');
    const ratingStr = prompt('Hodnocení 0–5 (nepovinné)','');
    const comment = prompt('Komentář (nepovinné, vyžaduje hodnocení)','');
    const rating = ratingStr ? parseInt(ratingStr,10) : null;
    submit({ post_type: type, title, description: desc, lat, lng, address, rating, comment });
  }

  async function submit(payload){
    try{
      const res = await fetch((window.wpApiSettings && window.wpApiSettings.root ? window.wpApiSettings.root : window.dbMapData.restUrl.replace(/\/map$/, '')) + 'submissions',{
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
    }catch(e){
      console.error(e);
      alert('Chyba připojení.');
    }
  }

  document.addEventListener('DOMContentLoaded', ensureButton);
})();


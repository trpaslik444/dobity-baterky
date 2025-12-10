# Opravy ikon a automatické načítání dat

## Problémy řešené v tomto PR

### 1. 404 chyby při načítání POI ikon

**Problém:**
- V network kartě se objevovaly 404 chyby při načítání ikon typu `poi_type-768414503.svg`
- `icon_slug` byl automaticky nastavován jako `poi_type-{term_id}` místo správného názvu souboru ikony

**Řešení:**
- **Icon_Registry.php:** Přidána validace, která ignoruje `icon_slug` ve formátu `poi_type-{id}` nebo `rv_type-{id}` (fallback hodnoty, ne skutečné názvy souborů)
- **Icon_Admin.php:** Odstraněno automatické nastavení `icon_slug` při ukládání barvy - `icon_slug` se nastavuje pouze při uploadu ikony

**Výsledek:**
- ✅ Opraveny existující špatná data (ignorováním špatného `icon_slug`)
- ✅ Zabráněno budoucím problémům (odstraněním automatického nastavení)
- ✅ POI bez správného `icon_slug` používají `svg_content` fallback

---

### 2. Automatické načtení dat při obnovení stránky

**Problém:**
- Při obnovení stránky se automaticky nevolal fetch z aktuální polohy uživatele
- Pokud měl uživatel povolenou geolokaci, nevyužilo se to pro automatické načtení

**Řešení:**
- **tryGetUserLocation():** Přidán parametr `requestPermission` - pokud je `true`, zeptá se uživatele na geolokaci pokud není povolena
- Kontrola stavu oprávnění geolokace (`granted`, `denied`, `prompt`)
- **initialDataLoad():** Volá `tryGetUserLocation(true)` - automaticky se zeptá uživatele na geolokaci při načtení stránky

**Výsledek:**
- ✅ Při obnovení stránky se automaticky zeptá na geolokaci (pokud není povolena)
- ✅ Pokud má uživatel povolenou polohu, použije se pro centrování a fetch
- ✅ Pokud není povolena, použije se aktuální centrum mapy nebo cache

---

### 3. Automatické načtení dat po vyhledávání podle adresy

**Problém:**
- Při vyhledávání podle adresy/bodu se mapa přesunula na výsledek, ale nevolal se fetch dat z tohoto místa

**Řešení:**
- **doAddressSearch():** Po přesunu mapy na výsledek vyhledávání se automaticky načtou body z tohoto místa
- Používá `map.once('moveend')` pro čekání na dokončení animace
- Používá progressive loading (`fetchAndRenderQuickThenFull`)

**Výsledek:**
- ✅ Po vyhledávání se automaticky načtou body z nového místa
- ✅ Používá progressive loading pro rychlé zobrazení

---

## Změněné soubory

- `includes/Icon_Registry.php` - validace `icon_slug` pro POI a RV spots
- `includes/Icon_Admin.php` - odstranění automatického nastavení `icon_slug`
- `assets/map/core.js` - automatické načtení dat při obnovení stránky a po vyhledávání
- `docs/POI_ICON_SLUG_404_PROBLEM.md` - dokumentace problému a řešení

## Testování

1. ✅ Ověřit, že ikony se zobrazují správně (bez 404 chyb)
2. ✅ Ověřit, že při obnovení stránky se automaticky načtou data z polohy uživatele
3. ✅ Ověřit, že po vyhledávání se automaticky načtou data z nového místa


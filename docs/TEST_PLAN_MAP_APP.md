# Map App Manual Test Plan (EA/admin access)

Průběžný checklist pro testování mapové aplikace na `http://localhost:10005`. Přednostně EA/admin účet. Guest/non‑EA se teď netestují.

## Příprava
- [ ] Platný WP cookie string (EA/admin): `wordpress_logged_in_*`, `wordpress_*`, ideálně i `wordpress_sec_*` → jeden řetězec `name=value; name2=value2`
- [ ] Aktuální `X-WP-Nonce` (dbMapData.restNonce) pro REST volání
- [ ] WP_DEBUG_LOG zapnutý
- [ ] DevTools (Console + Network, disable cache)
- [ ] Playwright: `cd tests/e2e && npm test` (env `WP_COOKIES="..."`, `BASE_URL=http://localhost:10005`)

## Smoke – načtení stránky
- [x] `/mapa/` 200, DOM s mapou
- [x] Assety Leaflet/MarkerCluster/loader.js se načítají
- [x] `dbMapData` obsahuje restNonce/restUrl/favorites/translations/pwaEnabled
- [x] SW endpoint `/db-sw.js` 200 + `Service-Worker-Allowed: /`
- [ ] Console čistá po loadu

## REST API (cookie + nonce)
- [x] `GET /wp-json/db/v1/map?lat=…&lng=…&radius_km=…` → 200 FeatureCollection
- [x] `GET /wp-json/db/v1/map-detail/poi/{id}` → 200 (39743)
- [x] `GET /wp-json/db/v1/map-detail/charging_location/{id}` → 200 (4127)
- [x] `GET /wp-json/db/v1/map-search?query=Praha&limit=5` → 200
- [x] `GET /wp-json/db/v1/filter-options` → 200
- [x] `GET /wp-json/db/v1/providers` → 200
- [x] `GET /wp-json/db/v1/geocode?query=Prague` → 200
- [x] `GET /wp-json/db/v1/poi-external/{id}` → `quota_blocked` (39743)
- [x] `GET /wp-json/db/v1/map` bez parametrů → 400 `missing_required_params`
- [x] `GET /wp-json/db/v1/map-search` bez query → 400 `rest_missing_callback_param`
- [x] `GET /wp-json/db/v1/map-detail/{type}/{id}` neexistující → 404 `not_found`
- [ ] `GET /wp-json/db/v1/map` se špatným nonce → 401 (ověřit)

## UI interakce (EA účet, prohlížeč)
- [ ] Smart loading: posun mimo oblast → CTA „Načíst“, spinner, po kliknutí data
- [ ] Přepínač auto/manual → preference uložená (localStorage)
- [ ] Filtry (typy/provider/poi_type) mění výsledky (REST odpovídá)
- [ ] Vyhledávání → návrhy → klik → zoom na výsledek
- [ ] Klik na pin → detail se načte, žádné JS chyby
- [ ] Favorites přidat/odebrat, reload zachováno
- [ ] Feedback widget odešle hlášku (status 200), záznam v DB/logu
- [ ] Console čistá během interakcí

## PWA / SW
- [ ] Registrace SW proběhne bez errorů
- [ ] SW necacheuje nežádoucí zdroje (rychlá kontrola v Application/Cache Storage)

## Admin sanity (EA/admin)
- [x] Nearby Queue stránka (tools.php?page=db-nearby-queue) načtena (HTTP 200 přes curl); UI chyby zatím neověřeno
- [x] POI Discovery admin načten (HTTP 200 přes curl); UI chyby zatím neověřeno
- [ ] Charging Discovery admin – aktuálně HTTP 403 (ověřit capability/slug)
- [ ] On-demand/isochrones nastavení načteno

## Další ověření / hrany
- [ ] Rate-limit stavy (Nominatim, Google proxy) vrací kontrolované odpovědi, ne 500
- [ ] Resource hints/dequeue: na mapové stránce se nenačítají Woo/Jetpack/Site Kit skripty
- [ ] Při vypnuté PWA (unregister) se mapa načte

## Playwright (tests/e2e)
- `map.spec.js` čte cookies z `WP_COOKIES`; testuje `/mapa/` render (bez console errorů) a admin Nearby Queue (HTTP 200, nepřesměruje na login)
- Spuštění: `WP_COOKIES="name=value; name2=value2" BASE_URL=http://localhost:10005 npm test`

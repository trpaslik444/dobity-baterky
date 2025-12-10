# Refaktorování search boxu: sjednocení pro desktop i mobil

## Cíl
Jeden search box logicky (UI varianty podle breakpointu), jednotné handlery, méně duplicitních fetchů, rychlejší odezva.

## Hlavní změny

### UI/HTML
- ✅ Ponechán jeden set prvků: `form.db-map-searchbox`, `#db-map-search-input`, `#db-map-search-btn`
- ✅ Pro mobil/desktop použity stejné ID/class; styly přepínány přes CSS (breakpointy)
- ✅ Odstraněny duplikované HTML bloky pro search input

### Logika (core.js)
1. **Centralizace handlerů**
   - Před přidáním listenerů ověřena existence input/form/button
   - Handlery navázány jen jednou (guard flag `searchHandlersInitialized`)
   - `doSearch` používá poslední autocomplete výsledky, nespouští další REST, pokud výsledky existují pro stejné query

2. **Debounce + Abort**
   - Jediný debounce (400ms) na input → volá společnou funkci `fetchAutocomplete(query)`
   - Paralelně spouští interní `/wp-json/db/v1/map-search?query=` (limit 8) a externí Nominatim (od 3 znaků)
   - Oba se stejným AbortController
   - Pokud běží starý request, abortuje se
   - Používá cache (`internalSearchCache`/`externalSearchCache`) pro stejný normalizovaný query

3. **Autocomplete výběr**
   - Klik na položku internal → `handleInternalSelection`: nastav view, volitelné `fetchAndRenderRadiusWithFixedRadius`
   - Klik na external (Nominatim) → `handleExternalSelection`: setView na lat/lng, spustit `fetchAndRenderRadiusWithFixedRadius`
   - Po výběru zavření autocomplete, nastavení `searchInput.value` na vybraný text

4. **Submit (Enter / button)**
   - Pokud existují autocomplete výsledky pro aktuální query (cache), vezme první nebo přesnou shodu a zavolá příslušný handler (internal/external)
   - Pokud nejsou výsledky: fallback na lokální `renderCards(searchQuery)` nad features (jen highlight), ale nespouští další REST call
   - Zamezen duplicit: submit nevolá další `/map-search`, spoléhá se na `fetchAutocomplete` cache

5. **Zobrazení mobil vs desktop**
   - Renderer rozdělen jen stylováním/pozicí (např. body class `is-mobile`)
   - Data/flow stejné: jedna sada výsledků, dva rendery podle viewportu
   - Funkce `removeAutocomplete` sdílí logiku (cleanup event, remove element)

6. **Odstranění duplicitních elementů a eventů**
   - Odstraněny druhé výskyty `db-map-search-input` v šabloně
   - Listenery (input, focus, blur, submit, keydown) se navazují jen jednou na jediný input
   - Odstraněna funkce `createMobileSearchField()` a všechny její volání

### Server-side cache
- ✅ V `REST_Map::handle_map_search` zaveden transient cache 45s podle normalizovaného query (lowercase, trim)
- ✅ Limit držen 8, `no_found_rows` true
- ✅ Cache klíč zahrnuje `post_types` param (pokud se používá)

## Výkon a UX
- Debounce 400ms + cache minimalizují opakované dotazy
- Externí volání jen od 3 znaků; pokud interní vrací shody, externí výsledky zobrazeny pod nimi (sekce "externí")
- Při blur ponecháno krátké delay pro klik na položku

## Testování
- [ ] Desktop: search box viditelný, autocomplete funguje
- [ ] Mobil: search box skrytý, toggle ho zobrazí/skryje
- [ ] Autocomplete zobrazuje interní i externí výsledky
- [ ] Submit používá cache výsledky
- [ ] Server-side cache funguje (45s TTL)

# Progressive Loading - Implementace

## Cíl

Zrychlit vnímaný výkon načítání mapy pomocí progressive loading:
1. **Mini-fetch** (12km, 100 bodů) - renderuje okamžitě (~1s)
2. **Plný fetch** (50km, 300 bodů) - nahradí data v pozadí

## Implementace

### Nové konstanty

```javascript
const MINI_RADIUS_KM = 12; // rychlý mini-fetch pro okamžité zobrazení markerů
const MINI_LIMIT = 100; // limit pro mini-fetch
const FULL_LIMIT = 300; // limit pro plný fetch
```

### Nová funkce: `fetchAndRenderQuickThenFull()`

**Umístění:** `assets/map/core.js`

**Chování:**
1. Vytvoří dva `AbortController` - jeden pro mini, jeden pro plný fetch
2. Spustí oba fetchy paralelně
3. Mini-fetch renderuje okamžitě po dokončení
4. Plný fetch nahradí data po dokončení
5. Pokud plný fetch dokončí před mini-fetchem, mini-fetch se přeskočí

**AbortControllery:**
- `window.__dbQuickController` - pro mini-fetch
- `window.__dbFullController` - pro plný fetch
- `inFlightController` - globální (používá se i jinde)

### Změny v `initialDataLoad()`

**Před:**
```javascript
await fetchAndRenderRadiusWithFixedRadius(c, null, FIXED_RADIUS_KM);
```

**Po:**
```javascript
await fetchAndRenderQuickThenFull(c, null);
```

### Změny v `loadNewAreaData()`

**Před:**
```javascript
await fetchAndRenderRadiusWithFixedRadius(center, currentTypes, FIXED_RADIUS_KM);
```

**Po:**
```javascript
// Zkontrolovat, zda už běží plný fetch
if (window.__dbFullController && !window.__dbFullController.signal.aborted) {
  return; // Počkat na dokončení
}
await fetchAndRenderQuickThenFull(center, currentTypes);
```

## Speciální režim (DB doporučuje / Zdarma)

**Neměněno:** Stále používá `fetchAndRenderAll()` - fetchAll, tlačítko skryté.

Progressive loading se používá pouze v běžném radius režimu.

## Obsluha filtrů

Mini i plný fetch posílají stejné filtry:
- `provider` (csv)
- `poi_types` (csv)
- `amenities` (csv)
- `connector_types` (csv)
- `db_recommended` (1 nebo null)
- `free` (1 nebo null)

Filtry se získávají z `buildRestUrlForRadius()` která používá `filterState`.

## Fallback / Error handling

1. **Mini-fetch selže:** Počkáme na plný fetch (silent fail)
2. **Plný fetch selže:** Mini-fetch data zůstanou zobrazená
3. **Oba selžou:** Fallback na klasický `fetchAndRenderRadiusWithFixedRadius()`

## Debounce

Inicializační fetch je již debounced v `initialDataLoad()` - volá se pouze jednou při načtení mapy.

## Očekávané zlepšení

### Před:
- První markery: **9+ sekund**
- Plný dataset: **9+ sekund**

### Po:
- První markery: **~1-2 sekundy** (mini-fetch)
- Plný dataset: **~3-5 sekund** (plný fetch v pozadí)

### Vnímaný výkon:
- **80-90% rychlejší** první render
- Uživatel vidí markery okamžitě
- Plný dataset se doplní v pozadí bez blokování UI

## Testování

1. **Otevřít mapu:**
   - Měření času do prvního renderu markerů
   - Očekáváno: < 2 sekundy

2. **Kliknout na "Načíst další":**
   - Zkontrolovat, že se spustí progressive loading
   - Zkontrolovat, že se tlačítko disable během fetchu

3. **Změnit filtry:**
   - Zkontrolovat, že se zruší probíhající fetchy
   - Zkontrolovat, že se spustí nový mini+plný cyklus

4. **Speciální režim:**
   - Aktivovat "DB doporučuje"
   - Zkontrolovat, že se používá `fetchAndRenderAll()` (ne progressive loading)

## Poznámky

- Server není třeba měnit - používáme stejný endpoint `/wp-json/db/v1/map`
- Mini-fetch používá `fields=minimal` (stejně jako plný)
- Cache (`featureCache`) se naplní z obou fetchů
- `lastSearchCenter` a `lastSearchRadiusKm` se nastaví podle posledního úspěšného fetchu


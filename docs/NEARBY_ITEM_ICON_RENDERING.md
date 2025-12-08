# Jak funguje zobrazování ikon v nearby items

## Přehled

Nearby items (blízké body) se zobrazují v mobile sheetu a používají specifický způsob renderování ikon s dvěma barvami:
1. **Background barva** (`style="background: #049FE8;"`) - barva pozadí ikony
2. **SVG fill barva** (`fill="#FFACC4"`) - barva výplně SVG ikony

## Workflow renderování

### 1. Generování HTML (`loadNearbyForMobileSheet`)

**Umístění:** `assets/map/core.js` řádek ~6030-6100

```javascript
const renderNearbyItems = (items) => {
  const nearbyItems = items.slice(0, 3).map(item => {
    // ...
    const getItemIcon = (props) => {
      // Vrací SVG obsah nebo fallback
      if (props.svg_content && props.svg_content.trim() !== '') {
        return props.svg_content; // SVG přímo z API
      }
      // ... další fallbacky
    };
    
    const getNearbySquareColor = (props) => {
      // Vrací barvu pozadí podle typu bodu
      if (props.post_type === 'charging_location') {
        const mode = getChargerMode(props);
        const acColor = '#049FE8'; // Modrá pro AC
        const dcColor = '#FFACC4'; // Růžová pro DC
        return mode === 'dc' ? dcColor : acColor;
      } else if (props.post_type === 'poi') {
        return item.icon_color || '#FCE67D'; // Žlutá pro POI
      }
      return '#049FE8'; // Default modrá
    };
    
    return `
      <div class="nearby-item-icon" style="background: ${getNearbySquareColor(item)};">
        ${getItemIcon(item)}
      </div>
    `;
  });
};
```

### 2. Získání SVG ikony (`getItemIcon`)

**Hierarchie:**
1. **`props.svg_content`** - SVG přímo z API (priorita 1)
2. **`props.icon_slug`** - Načtení ze souboru pomocí `getIconUrl()`
3. **`featureCache`** - Zkusit načíst z cache
4. **Fallback** - Emoji nebo default SVG

**Pro charging_location:**
- Používá `recolorChargerIcon()` pokud je SVG v cache
- SVG už má nastavenou barvu z PHP (server-side)

**Pro POI:**
- Používá SVG přímo z `svg_content`
- Nebo načte podle `icon_slug`

### 3. Barva pozadí (`getNearbySquareColor`)

**Logika:**
- **Charging location:**
  - AC: `#049FE8` (modrá)
  - DC: `#FFACC4` (růžová)
  - Hybrid: gradient
- **POI:** `icon_color` nebo `#FCE67D` (žlutá)
- **RV spot:** `#FCE67D` (žlutá)
- **Default:** `#049FE8` (modrá)

### 4. SVG fill barva

**Zdroj:**
- SVG přichází z API s již nastavenou `fill` barvou
- Server (`Icon_Registry`) nastavuje `fill` podle `icon_color` option
- Pro charging locations: podle `db_charger_icon_color` option
- Pro POI: podle `db_poi_icon_color` option
- Pro RV: podle `db_rv_icon_color` option

**Příklad:**
```html
<svg fill="#FFACC4" ...>
  <!-- SVG obsah -->
</svg>
```

## Problém s aktuálním stavem

### Co se děje:
1. **Background barva** (`#049FE8`) se nastavuje podle typu bodu
2. **SVG fill barva** (`#FFACC4`) přichází z API (server-side)
3. **Konflikt:** Background a fill mohou mít různé barvy

### Příklad z vašeho HTML:
```html
<div class="nearby-item-icon" style="background: #049FE8;">
  <svg fill="#FFACC4" ...>
    <!-- SVG obsah -->
  </svg>
</div>
```

**Výsledek:**
- Pozadí: Modrá (`#049FE8`)
- SVG výplň: Růžová (`#FFACC4`)
- **Vizuální efekt:** Růžová ikona na modrém pozadí

## Možná řešení

### Řešení 1: Synchronizovat barvy
**Koncept:** Background barva by měla odpovídat SVG fill barvě

**Implementace:**
```javascript
const getNearbySquareColor = (props) => {
  // Pro charging_location použít stejnou logiku jako pro SVG fill
  if (props.post_type === 'charging_location') {
    const mode = getChargerMode(props);
    const acColor = '#049FE8';
    const dcColor = '#FFACC4';
    return mode === 'dc' ? dcColor : acColor;
  }
  // Pro POI použít icon_color z props
  return props.icon_color || '#FCE67D';
};
```

### Řešení 2: Odstranit background barvu
**Koncept:** Použít pouze SVG fill barvu, bez background

**Implementace:**
```javascript
<div class="nearby-item-icon">
  ${getItemIcon(item)}
</div>
```

### Řešení 3: Přebarvit SVG podle background barvy
**Koncept:** Dynamicky změnit SVG fill barvu podle background barvy

**Implementace:**
```javascript
const getItemIconWithColor = (item, bgColor) => {
  let icon = getItemIcon(item);
  // Pokud je SVG, změnit fill barvu
  if (icon.includes('<svg')) {
    icon = icon.replace(/fill="[^"]*"/g, `fill="${bgColor}"`);
  }
  return icon;
};
```

## Doporučení

**Doporučuji Řešení 1:** Synchronizovat background barvu s SVG fill barvou podle typu bodu.

**Důvody:**
- Konzistentní vzhled
- Background barva má význam (AC/DC pro charging locations)
- SVG fill barva je správně nastavená z serveru

## Aktuální implementace

### `getItemIcon()` funkce:
- Vrací SVG obsah z `props.svg_content`
- SVG má již nastavenou `fill` barvu z serveru
- Pro charging locations může použít `recolorChargerIcon()` (ale aktuálně jen vrací SVG jak je)

### `getNearbySquareColor()` funkce:
- Vrací barvu pozadí podle typu bodu
- Pro charging locations: AC = modrá, DC = růžová
- Pro POI: `icon_color` nebo žlutá

### Výsledek:
- Background a SVG fill mohou mít různé barvy
- To může být záměrné (kontrast) nebo nechtěné (nesoulad)

## Závěr

Ikony v nearby items fungují takto:
1. **Background barva** se nastavuje podle typu bodu (`getNearbySquareColor`)
2. **SVG obsah** přichází z API s již nastavenou `fill` barvou
3. **Vizuální efekt:** Ikona má dvě barvy - background a fill

Pokud chcete synchronizovat barvy, použijte Řešení 1.


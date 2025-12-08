# Oprava: Chybějící ikony v minimal payload

## Problém

Z HAR souboru analýza ukázala:
- ✅ **Výkon se zrychlil:** Map API volání trvá **3048ms** místo 9+ sekund (70% rychlejší!)
- ❌ **Ikony se nezobrazují:** V response není `svg_content` pro žádné features v minimal payload

### Analýza HAR souboru

```
Map API volání: 1
Čas: 3048ms (místo 9198ms - 70% rychlejší!)
Status: 200
Počet features: 300
Response size: 104388 bytes (101.9 KB)

Statistiky ikon v response:
- S svg_content: 0
- S icon_slug: 5
- Bez obou: 45
```

### Příčina

V `includes/REST_Map.php` řádek 620-647:
- Když je `fields_mode === 'minimal'` (což je default), server **NEVRACÍ** `svg_content`
- Vrací se pouze `icon_slug` a `icon_color`
- Frontend potřebuje `svg_content` pro renderování markerů

### Proč nearby body mají správné ikony?

Nearby body se načítají z jiného endpointu (`/wp-json/db/v1/nearby`), který vrací `svg_content` správně.

## Řešení

Přidán `svg_content` do minimal payload v `REST_Map.php`:

```php
// Před:
$properties['icon_slug'] = $icon_data['slug'] ?: get_post_meta($post->ID, '_icon_slug', true);
$properties['icon_color'] = $icon_data['color'] ?: get_post_meta($post->ID, '_icon_color', true);
// svg_content chybí!

// Po:
$properties['icon_slug'] = $icon_data['slug'] ?: get_post_meta($post->ID, '_icon_slug', true);
$properties['icon_color'] = $icon_data['color'] ?: get_post_meta($post->ID, '_icon_color', true);
$properties['svg_content'] = $icon_data['svg_content'] ?? '';
```

## Očekávaný výsledek

Po opravě:
- ✅ Server bude vracet `svg_content` v minimal payload
- ✅ Frontend bude moci renderovat ikony na pinech
- ✅ Ikony budou zobrazeny správně pro všechny typy bodů (POI, charging_location, rv_spot)

## Testování

1. Ověřit, že API vrací `svg_content` v response
2. Ověřit, že ikony se zobrazují na pinech na mapě
3. Ověřit, že výkon zůstává rychlý (3048ms)

## Změněné soubory

- `includes/REST_Map.php` - přidán `svg_content` do minimal payload


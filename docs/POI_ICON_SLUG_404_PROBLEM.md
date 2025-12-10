# Problém: 404 chyba při načítání POI ikon

## Problém

V network kartě se objevuje 404 chyba při načítání ikon:
```
GET https://staging-f576-dobitybaterky.wpcomstaging.com/wp-content/plugins/dobity-baterky/assets/icons/poi_type-768414503.svg
Status: 404 Not Found
```

## Analýza

### Příčina:
V `includes/Icon_Admin.php` na řádku 265 se při ukládání barvy automaticky nastavuje `icon_slug` jako `poi_type-{term_id}`:

```php
// includes/Icon_Admin.php:261-266
if ( preg_match('/^(poi_type|rv_type):(\d+)$/', $type, $m) ) {
    $term_id = intval($m[2]);
    if ( $term_id ) {
        update_term_meta($term_id, 'color_hex', $color);
        update_term_meta($term_id, 'icon_slug', $m[1] . '-' . $term_id); // ❌ PROBLÉM
    }
}
```

Toto vytváří `icon_slug` jako `poi_type-768414503` místo správného názvu souboru ikony (např. `restaurant-google.svg`).

### Důsledek:
1. Frontend dostane `icon_slug` = `poi_type-768414503`
2. `getIconUrl()` vytvoří URL: `/assets/icons/poi_type-768414503.svg`
3. Soubor neexistuje → 404 chyba

### Z HAR souboru:
- 13 POI má `icon_slug` = `poi_type-768414503`
- Všechny mají `svg_content: NO` (protože není `icon_slug`, takže se nevrací `svg_content`)

## Řešení

### Řešení 1: Ignorovat špatný `icon_slug` v `Icon_Registry.php` (doporučeno)

**Koncept:** V `Icon_Registry.php` ignorovat `icon_slug`, který vypadá jako `poi_type-{id}` nebo `rv_type-{id}` (to jsou fallback hodnoty, ne skutečné názvy souborů).

**Implementace:**
```php
// includes/Icon_Registry.php:181
$icon_slug = get_term_meta( $term->term_id, 'icon_slug', true );

// Ignorovat špatný icon_slug (poi_type-{id} nebo rv_type-{id} jsou fallback hodnoty)
if (preg_match('/^(poi_type|rv_type)-\d+$/', $icon_slug)) {
    $icon_slug = '';
}
```

**Výhody:**
- ✅ Nezmění existující data
- ✅ Automaticky použije `svg_content` fallback
- ✅ Funguje okamžitě

---

### Řešení 2: Opravit `Icon_Admin.php` - neukládat `icon_slug` při ukládání barvy

**Koncept:** Při ukládání barvy neukládat `icon_slug` automaticky.

**Implementace:**
```php
// includes/Icon_Admin.php:261-266
if ( preg_match('/^(poi_type|rv_type):(\d+)$/', $type, $m) ) {
    $term_id = intval($m[2]);
    if ( $term_id ) {
        update_term_meta($term_id, 'color_hex', $color);
        // ❌ ODSTRANIT: update_term_meta($term_id, 'icon_slug', $m[1] . '-' . $term_id);
    }
}
```

**Výhody:**
- ✅ Opraví příčinu problému
- ✅ Zabrání budoucím problémům

**Nevýhody:**
- ❌ Neopraví existující špatná data v databázi

---

### Řešení 3: Kombinace - opravit `Icon_Admin.php` + validovat v `Icon_Registry.php`

**Koncept:** Opravit `Icon_Admin.php` (zabránit budoucím problémům) + přidat validaci v `Icon_Registry.php` (opravit existující data).

**Implementace:**
1. Odstranit automatické nastavení `icon_slug` v `Icon_Admin.php`
2. Přidat validaci v `Icon_Registry.php` (jako v Řešení 1)

**Výhody:**
- ✅ Opraví příčinu problému
- ✅ Opraví existující data
- ✅ Nejkompletnější řešení

---

## Doporučení

**Doporučuji Řešení 3:** Kombinace opravy `Icon_Admin.php` + validace v `Icon_Registry.php`.

**Důvody:**
- ✅ Opraví příčinu problému
- ✅ Opraví existující špatná data
- ✅ Zabrání budoucím problémům
- ✅ Kompletní řešení


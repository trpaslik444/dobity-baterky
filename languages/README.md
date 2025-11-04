# Systém překladů pro Dobitý Baterky

Tento adresář obsahuje systém pro správu překladů frontendu aplikace.

## Struktura

- `cs.json` - České překlady
- `en.json` - Anglické překlady

## Použití

### Automatická detekce jazyka

Systém automaticky detekuje jazyk podle:
1. WordPress locale (pokud je nastaven)
2. HTTP_ACCEPT_LANGUAGE header (jazyk prohlížeče)
3. Fallback na češtinu

### Přidávání nových překladů

1. **Přidejte klíč do JSON souborů** ve všech podporovaných jazycích:

```json
{
  "category": {
    "new_key": "Překlad textu"
  }
}
```

2. **V JavaScriptu použijte funkci `t()`**:

```javascript
const text = t('category.new_key');
```

3. **V PHP použijte Translation_Manager**:

```php
$translation_manager = \DB\Translation_Manager::get_instance();
$text = $translation_manager->get('category.new_key', 'Výchozí text');
```

## Struktura kategorii

- `common` - Společné texty (tlačítka, zprávy)
- `map` - Mapa a její ovládání
- `filters` - Filtry a jejich možnosti
- `cards` - Karty záznamů
- `menu` - Menu a nastavení
- `navigation` - Navigační aplikace
- `feedback` - Zpětná vazba
- `legends` - Legendy
- `login` - Přihlášení a přístup
- `templates` - Texty v šablonách

## Přidání nového jazyka

1. Vytvořte soubor `XX.json` s překlady (např. `de.json` pro němčinu)
2. Systém automaticky detekuje a použije překlady

## API Reference

### Translation_Manager

- `get($key, $default = '')` - Získat překlad podle klíče
- `get_all()` - Získat všechny překlady
- `get_current_lang()` - Získat aktuální jazyk
- `set_language($lang)` - Změnit jazyk ručně

### JavaScript funkce t()

```javascript
// Jednoduchý překlad
const text = t('common.close');

// Překlad s výchozí hodnotou
const text = t('common.close', 'Close');

// Nested překlad
const text = t('feedback.types.bug');
```

## Poznámky

- Všechny překlady jsou přístupné jak v JavaScriptu, tak v PHP
- Výchozí jazyk je čeština (cs)
- Systém podporuje automatickou detekci podle prohlížeče
- Překlady se načítají automaticky při inicializaci


# Simple API Selector Implementation

## Přehled

Simple API Selector je jednoduchý systém pro výběr mezi Mapy.com, Google a Tripadvisor API s pevným workflow: Mapy.com → Google → Tripadvisor (pouze pro Českou republiku). Systém automaticky přepíná mezi službami při vyčerpání tokenů.

## Architektura

### Hlavní komponenty

1. **Simple_API_Selector** - Jednoduchý výběr služby s pevným workflow
2. **Mapy_Discovery** - Integrace s Mapy.com API
3. **Simple_API_Test_Admin** - Administrační rozhraní pro testování
4. **Icon_Admin** - Integrace do existující správy API klíčů

### Workflow

```
Request → Mapy.com API → Success? → Return Data
                ↓ No
         Google API → Success? → Return Data
                ↓ No
         Tripadvisor API → Success? → Return Data
                ↓ No
         Return null
```

## Konfigurace

### API Klíče

Konfigurace probíhá v **WordPress Admin > Tools > Správa ikon > API nastavení**:

```php
// Mapy.com API (primární pro ČR)
update_option('db_mapy_api_key', 'your-mapy-api-key');

// Google API (sekundární)
update_option('db_google_api_key', 'your-google-api-key');

// Tripadvisor API (terciární)
update_option('db_tripadvisor_api_key', 'your-tripadvisor-api-key');
```

### Cache TTL

- **Mapy.com**: 30 dní (stejně jako Google)
- **Google**: 30 dní
- **Tripadvisor**: 24 hodin

## Použití

### PHP API

```php
use DB\Simple_API_Selector;

// Automatický výběr služby s fallback
$selector = new Simple_API_Selector();
$result = $selector->enrichPOIData($postId, 'search');

if ($result) {
    echo "Data enriched using: " . $result['service'];
    echo "Cache TTL: " . $result['cache_ttl'] . " seconds";
}
```

### Administrační rozhraní

**WordPress Admin > Tools > API Test**
- Testování dostupnosti služeb
- Zobrazení statistik cache
- Vyčištění cache
- Rychlé testy všech služeb

## Testy spolehlivosti

### Test dostupnosti služby
```php
$selector = new Simple_API_Selector();
$result = $selector->testServiceAvailability('mapy');
```

### Testovací skript
```bash
php test-simple-api-selector.php
```

### Administrační testování

**WordPress Admin > Tools > API Test**
- Individuální testy služeb
- Rychlé testy všech služeb
- Zobrazení statistik
- Správa cache

## Strategie výběru služby

### Pevné pořadí (pouze pro Českou republiku)

1. **Mapy.com API** (primární)
   - Nejlepší lokální data pro ČR
   - 30 dní cache TTL
   - Výhodné cenové podmínky

2. **Google API** (sekundární)
   - Fallback při vyčerpání Mapy.com tokenů
   - 30 dní cache TTL
   - Globální pokrytí

3. **Tripadvisor API** (terciární)
   - Poslední možnost
   - 24 hodin cache TTL
   - Recenze a hodnocení

## Monitoring a statistiky

### Sledované metriky
- Počet cachovaných položek
- Dostupnost API klíčů
- Cache TTL pro každou službu
- Doba odezvy při testování

### Cache management
- Automatické cachování podle TTL
- Možnost vyčištění cache
- Sledování využití cache
- Fallback mechanismy

## Mapy.com API specifika

### Limity a kvóty
- **Basic Tariff**: 250,000 kreditů/měsíc zdarma
- **Extended Tariff**: 10,000,000 kreditů/měsíc zdarma
- **Cena**: 1.6 CZK za 1,000 kreditů při překročení

### Podporované operace
- Vyhledávání míst
- Geokódování
- Reverzní geokódování
- Detaily míst
- Fotky a recenze

### Atributování
Povinné uvádění loga a copyright podle [podmínek používání](https://developer.mapy.com/rest-api/atributovani/).

## Doporučení pro implementaci

### 1. Postupné nasazení
1. Nakonfigurovat API klíče v **Správa ikon > API nastavení**
2. Spustit testy v **API Test** stránce
3. Monitorovat cache využití
4. Postupně přepínat provoz

### 2. Optimalizace výkonu
- Respektovat cache TTL (Mapy.com/Google: 30 dní, Tripadvisor: 24 hodin)
- Automatický fallback při selhání
- Sledování využití API tokenů
- Pravidelné testy dostupnosti

### 3. Monitoring
- Pravidelné testy dostupnosti služeb
- Sledování cache statistik
- Kontrola API klíčů
- Vyčištění cache při problémech

## Troubleshooting

### Časté problémy

1. **API klíč není nakonfigurován**
   - Zkontrolujte **Správa ikon > API nastavení**
   - Ověřte platnost klíče

2. **Služba není dostupná**
   - Spusťte testy v **API Test** stránce
   - Zkontrolujte síťové připojení
   - Ověřte API kvóty

3. **Cache problémy**
   - Vyčistěte cache v **API Test** stránce
   - Zkontrolujte TTL nastavení
   - Ověřte post meta data

### Testování

```bash
# Spustit testovací skript
php test-simple-api-selector.php

# Zkontrolovat logy
tail -f /path/to/wordpress/wp-content/debug.log
```

## Shrnutí

Simple API Selector poskytuje:
- **Jednoduchý workflow**: Mapy.com → Google → Tripadvisor
- **Automatický fallback** při selhání služeb
- **Respektování cache TTL** podle API podmínek
- **Integrace do existující správy** API klíčů
- **Testovací rozhraní** pro monitoring

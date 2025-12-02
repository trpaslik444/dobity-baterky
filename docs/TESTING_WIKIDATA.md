# Testování Wikidata Provider na skutečných nabíječkách

## ✅ Test úspěšný!

Wikidata API funguje a vrací data. Test na Praze (50.0755, 14.4378) s radiusem 2 km našel **10 POIs**:
- Galerie Hugo Feigl
- Muchovo muzeum
- Muzeum českého granátu Praha
- Apple Museum
- Muzeum Lega
- a další...

---

## 🧪 Jak otestovat na skutečných nabíječkách

### Možnost 1: WP-CLI příkaz (doporučeno)

```bash
wp db test-wikidata --limit=5 --radius=2000
```

**Parametry:**
- `--limit=5` - počet nabíječek k testování (default: 5)
- `--radius=2000` - radius v metrech (default: 2000)

**Výstup:**
- Seznam testovaných nabíječek
- POIs nalezené pro každou nabíječku
- Statistiky (celkem POIs, kategorie, unikátní POIs)
- Ukázka dat jednoho POI

---

### Možnost 2: Standalone PHP skript

```bash
php scripts/test-wikidata-simple.php
```

**Nebo přes webový prohlížeč:**
```
https://your-site.com/wp-content/plugins/dobity-baterky/scripts/test-wikidata-simple.php?limit=5&radius=2000
```

**Testuje:**
- Praha (50.0755, 14.4378)
- Brno (49.1951, 16.6068)
- Ostrava (49.8209, 18.2625)

---

### Možnost 3: Curl test (nejjednodušší)

```bash
bash scripts/test-wikidata-curl.sh
```

**Testuje:**
- Praha (50.0755, 14.4378)
- Radius: 2 km
- Limit: 10 POIs

---

## 📊 Co test zobrazí

### Pro každou nabíječku:
- 📍 GPS souřadnice
- 🔄 Doba stahování
- ✅ Počet nalezených POIs
- 📋 Seznam POIs s:
  - Název
  - GPS souřadnice
  - Kategorie
  - Wikidata ID

### Shrnutí:
- Celkem testovaných nabíječek
- Celkem nalezených POIs
- Unikátních POIs
- Rozdělení podle kategorií
- Ukázka kompletní struktury POI

---

## 🎯 Očekávané výsledky

### Kategorie POIs z Wikidata:
- **museum** - Muzea
- **gallery** - Galerie
- **tourist_attraction** - Turistické atrakce
- **viewpoint** - Výhledy
- **park** - Parky

### Typy míst:
- Muzea (Q33506)
- Galerie (Q190598)
- Turistické atrakce (Q570116)
- Výhledy (Q1075788)
- Parky (Q22698)
- Památky (Q12280)
- Hrady (Q47513)
- Kostely (Q16970)
- Kulturní dědictví (Q483551)

---

## ⚠️ Důležité poznámky

1. **Rate limiting**: Wikidata má limit 60 requests/min
   - Test automaticky čeká 1 sekundu mezi requesty
   - Při větším počtu nabíječek může trvat déle

2. **Cache**: POIs se cachují na 1 hodinu
   - Při opakovaném testování se použije cache

3. **GPS souřadnice**: Používají se originální hodnoty (nezaokrouhlené)

4. **Deduplikace**: POIs se deduplikují podle:
   - Wikidata ID (source_id)
   - GPS + jméno (50m, 80% podobnost)

---

## 🔍 Co zkontrolovat

1. ✅ **Funguje Wikidata API?**
   - Test by měl vrátit HTTP 200
   - Měly by se najít POIs

2. ✅ **Jsou POIs relevantní?**
   - Měly by být v okruhu radius
   - Měly by být relevantní kategorie

3. ✅ **Jsou data kompletní?**
   - Název
   - GPS souřadnice
   - Kategorie
   - Wikidata ID

4. ✅ **Funguje deduplikace?**
   - Stejné POI by se nemělo vytvořit dvakrát

---

## 📝 Příklady výstupu

### Úspěšný test:
```
🔍 Testování Wikidata Provider
Limit nabíječek: 3
Radius: 2000 metrů

✅ Nalezeno 3 nabíječek

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📍 Nabíječka: #123 - Tesla Supercharger Praha
   GPS: 50.0755, 14.4378

   🔄 Stahování POIs z Wikidata...
   ✅ Nalezeno 10 POIs (trvalo 1234.56ms)

   📋 Seznam POIs:
   1. Muchovo muzeum
      📍 GPS: 50.084361111, 14.427583333 | Kategorie: museum | Wikidata ID: Q959038
   2. Apple Museum
      📍 GPS: 50.0860458, 14.4178661 | Kategorie: museum | Wikidata ID: Q60542064
   ...

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📊 SHRNUTÍ
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Celkem testovaných nabíječek: 3
Celkem nalezených POIs: 28
Unikátních POIs: 25

Kategorie POIs:
  - museum: 15
  - gallery: 8
  - tourist_attraction: 5
```

---

## 🚀 Spuštění testu

**Nejjednodušší způsob:**
```bash
wp db test-wikidata --limit=3 --radius=2000
```

**Nebo přes webový prohlížeč:**
```
https://your-site.com/wp-content/plugins/dobity-baterky/scripts/test-wikidata-standalone.php?limit=3&radius=2000
```

---

## ✅ Závěr

Wikidata Provider funguje a vrací relevantní POIs. Test na Praze našel 10 POIs v okruhu 2 km, což je dobrý výsledek pro testování.

**Další kroky:**
1. Otestovat na skutečných nabíječkách z databáze
2. Ověřit, že se POIs správně vytvářejí v WordPressu
3. Zkontrolovat deduplikaci
4. Ověřit, že cache funguje správně


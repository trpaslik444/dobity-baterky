# Test výsledky - Konkrétní souřadnice

## 📍 Testované souřadnice

1. **Location 1**: 49.9333900N, 14.1843919E
2. **Location 2**: 49.9433411N, 14.6045947E
3. **Location 3**: 49.9230239N, 14.5762439E
4. **Location 4**: 49.8978919N, 14.7136489E
5. **Location 5**: 49.7138500N, 14.9122900E

**Radius**: 2 km

---

## 📊 Výsledky

### ✅ Location 1 (49.9333900, 14.1843919)
**Nalezeno: 1 POI**
- **Karlštejn čp. 173 - bývalý hostinec U Karla IV., muzeum voskových figurin**
  - GPS: 49.9402631, 14.1889956
  - Kategorie: museum
  - Wikidata ID: Q115978601

### ⚠️ Location 2 (49.9433411, 14.6045947)
**Nalezeno: 0 POIs**
- V okruhu 2 km nejsou žádné relevantní POIs z Wikidata

### ✅ Location 3 (49.9230239, 14.5762439)
**Nalezeno: 1 POI**
- **památník shromáždění na Křížkách**
  - GPS: 49.9263911, 14.5705403
  - Kategorie: tourist_attraction (památník)
  - Wikidata ID: Q65769710

### ⚠️ Location 4 (49.8978919, 14.7136489)
**Nalezeno: 0 POIs**
- V okruhu 2 km nejsou žádné relevantní POIs z Wikidata

### ⚠️ Location 5 (49.7138500, 14.9122900)
**Nalezeno: 2 POIs** (duplicita!)
- **paraZOO** (objevil se 2x se stejným ID)
  - GPS: 49.7069444, 14.8969444
  - Kategorie: tourist_attraction (zoo)
  - Wikidata ID: Q12043772

**Poznámka**: Duplicita v Location 5 je problém - stejný POI se vrátil dvakrát. To by mělo být vyřešeno deduplikací v kódu.

---

## 📈 Shrnutí

- **Celkem testovaných lokací**: 5
- **Celkem nalezených POIs**: 4 (ale Location 5 má duplicitu)
- **Unikátních POIs**: 3

### Kategorie:
- **museum**: 1 (Karlštejn)
- **tourist_attraction**: 2 (památník, paraZOO)

---

## 🔍 Pozorování

1. ✅ **Wikidata API funguje** - vrací relevantní POIs
2. ⚠️ **Některé lokace nemají POIs** - Location 2 a 4 nemají žádné relevantní POIs v okruhu 2 km
3. ⚠️ **Duplicita** - Location 5 vrátila stejný POI dvakrát (problém v SPARQL query nebo Wikidata API)
4. ✅ **POIs jsou relevantní** - muzea, památníky, zoo - vhodné pro trávení času u nabíječky

---

## 💡 Doporučení

1. **Zvětšit radius** - pro lokace bez POIs zkusit větší radius (např. 5 km)
2. **Opravit duplicitu** - zkontrolovat, proč se paraZOO vrátil dvakrát
3. **Přidat více kategorií** - možná přidat další typy míst (restaurace, kavárny) pokud budou dostupné z OpenTripMap

---

## 🚀 Další kroky

1. Otestovat na skutečných nabíječkách z databáze
2. Ověřit, že se POIs správně vytvářejí v WordPressu
3. Zkontrolovat deduplikaci (Location 5 duplicita)
4. Otestovat s větším radiusem pro lokace bez POIs


# Jak sledovat import na pozadí

## 📍 Kde najít log soubory

Log soubory jsou v rootu pluginu:
```
/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky/
```

Název souboru: `staging_import_*.log` nebo `staging_import_complete_*.log`

---

## 🔍 Rychlé příkazy

### 1. Najít nejnovější log
```bash
cd "/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky"
ls -t staging_import*.log | head -1
```

### 2. Zobrazit posledních 50 řádků
```bash
tail -50 staging_import_complete_*.log
# nebo konkrétní soubor:
tail -50 staging_import_complete_20251121_214647.log
```

### 3. Sledovat v reálném čase (live)
```bash
tail -f staging_import_complete_*.log
# nebo konkrétní soubor:
tail -f staging_import_complete_20251121_214647.log
```

**Tip**: Stiskněte `Ctrl+C` pro ukončení sledování.

### 4. Hledat konkrétní informace
```bash
# Počet zpracovaných řádků
grep "📊 Řádek" staging_import_complete_*.log | tail -5

# Hledat chyby
grep -i "chyba\|error" staging_import_complete_*.log

# Finální shrnutí
grep -A 20 "✅ BEZPEČNÝ IMPORT DOKONČEN" staging_import_complete_*.log
```

---

## 📊 Co sledovat v logu

### Průběh importu (každých 1000 řádků):
```
📊 Řádek 1000 | nové: 598 | aktualizované: 402 | chyby: 0 | prázdné: 0 | čas: 998.03s
📊 Řádek 2000 | nové: 1200 | aktualizované: 800 | chyby: 0 | prázdné: 0 | čas: 1956.45s
```

### Finální shrnutí:
```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ BEZPEČNÝ IMPORT DOKONČEN
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📊 Statistika:
   • Nově vytvořené POI: 20455
   • Aktualizované POI (podle ID): 0
   • Celkem zpracovaných řádků: 25515
   • Počet chyb: 5060
   • Celkový čas: 625.27s
```

---

## ⚙️ Zkontrolovat, zda import ještě běží

### 1. Zkontrolovat běžící procesy
```bash
ps aux | grep "import-csv-staging"
ps aux | grep "safe-import-csv-staging.php"
```

### 2. Zkontrolovat SSH spojení
```bash
ps aux | grep "ssh.*staging-f576"
```

### 3. Zkontrolovat, zda log roste
```bash
# Zkontrolovat velikost souboru
ls -lh staging_import_complete_*.log

# Zkontrolovat čas poslední změny
stat staging_import_complete_*.log | grep Modify

# Nebo jednoduše:
tail -f staging_import_complete_*.log
# Pokud se nic neobjevuje po 1-2 minutách, import možná dokončil nebo padl
```

---

## 🎯 Praktické příklady

### Sledovat import live (nejlepší způsob):
```bash
cd "/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky"
tail -f $(ls -t staging_import_complete_*.log | head -1)
```

### Rychlá kontrola, zda import běží:
```bash
cd "/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky"
# Zobrazit poslední řádek
tail -1 $(ls -t staging_import_complete_*.log | head -1)

# Nebo posledních 10 řádků
tail -10 $(ls -t staging_import_complete_*.log | head -1)
```

### Zkontrolovat finální výsledek:
```bash
cd "/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky"
grep -A 15 "✅ BEZPEČNÝ IMPORT DOKONČEN" $(ls -t staging_import_complete_*.log | head -1)
```

---

## 💡 Tipy

1. **Import běží na staging serveru** - log soubor se aktualizuje přes SSH/SFTP stream
2. **Logování probíhá každých 1000 řádků** - mezi zprávami může být pauza
3. **Timeout je 60 minut** - pokud import trvá déle, může být timeoutován
4. **Očekávaný čas**: ~10-15 minut pro 24K řádků

---

## 🚨 Co dělat, pokud se nic neděje?

1. **Zkontrolovat, zda proces běží:**
   ```bash
   ps aux | grep "import-csv-staging"
   ```

2. **Zkontrolovat poslední řádek logu:**
   ```bash
   tail -5 $(ls -t staging_import_complete_*.log | head -1)
   ```

3. **Pokud import padl, znovu spustit:**
   ```bash
   cd "/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky"
   ./scripts/import-csv-staging.sh exported_pois_staging_complete.csv
   ```

---

*Dokument vytvořen pro snadné sledování importu na pozadí.*


# Produkční import CSV - Optimalizovaný průvodce

**Verze**: Optimalizovaná pro produkci  
**Optimalizace**: 
- Balíčky po 3000 řádcích (místo 5000) - rychlejší a spolehlivější
- Spuštění v pozadí (nohup) - žádné timeouty
- Automatické rozdělení CSV
- Lepší memory management (1024M)

---

## 🚀 Rychlý start

### Volba prostředí

- `--env=staging` (výchozí) – import běží proti stagingu, používá `STAGING_PASS`
- `--env=production` – jednorázový import přímo na produkci, používá `PROD_PASS`

Pokud spustíš expect skript přímo, nastav proměnné:

```bash
IMPORT_ENV=production PROD_PASS="••••" ./scripts/import-csv-production.expect data.csv
```

> Doporučení: nejprve spusť import na stagingu, zkontroluj výsledek, a poté identický CSV soubor nahraj na produkci s `--env=production`.

### Automatický import (doporučeno)

```bash
cd "/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky"
source scripts/load-env.sh
# Staging (implicitně)
./scripts/import-csv-production.sh exported_pois_staging_complete.csv

# Produkce (jednorázový import)
export PROD_PASS="••••••"
./scripts/import-csv-production.sh --env=production exported_pois_prod.csv
```

**Co to udělá:**
1. Rozdělí CSV na optimální balíčky (3000 řádků)
2. Nahraje každý balíček na staging
3. Spustí import v pozadí (nohup) - bez timeoutů
4. Zobrazí log soubory pro sledování

---

## 📋 Manuální postup (pokud je potřeba více kontroly)

### KROK 1: Rozdělit CSV na optimální balíčky

```bash
php scripts/split-csv-chunks.php exported_pois_staging_complete.csv exported_pois_prod_chunk_ 3000
```

Vytvoří se balíčky po 3000 řádcích:
- `exported_pois_prod_chunk_1.csv` (3000 řádků)
- `exported_pois_prod_chunk_2.csv` (3000 řádků)
- ...

### KROK 2: Importovat každý balíček

```bash
# Nahrát a spustit balíček 1
./scripts/import-csv-production.sh exported_pois_prod_chunk_1.csv

# Počkat na dokončení, pak balíček 2
./scripts/import-csv-production.sh exported_pois_prod_chunk_2.csv

# A tak dále...
```

---

## 🔍 Sledování importu

### Zkontrolovat běžící importy

Na staging serveru:
```bash
ssh -i ~/.ssh/id_ed25519_wpcom staging-f576-dobitybaterky.wordpress.com@ssh.wp.com

# Najít log soubory
ls -lht /tmp/poi_import_*.log | head -5

# Sledovat průběh
tail -f /tmp/poi_import_XXXXXXXX.log
```

### Zkontrolovat běžící procesy

```bash
ps aux | grep "safe-import-csv-staging.php"
```

---

## ⚙️ Optimalizace

### Proč balíčky po 3000 řádcích?

| Velikost balíčku | Čas importu | Memory riziko | Timeout riziko |
|------------------|-------------|---------------|----------------|
| 5000 řádků       | ~5 min      | ⚠️ Vysoké      | ⚠️ Vysoké      |
| 3000 řádků       | ~3 min      | ✅ Nízké       | ✅ Nízké       |
| 2000 řádků       | ~2 min      | ✅ Velmi nízké | ✅ Velmi nízké |

**3000 řádků je optimální** - dobrá rovnováha mezi rychlostí a spolehlivostí.

### Proč nohup (pozadí)?

- ✅ Žádné timeouty - proces běží i po uzavření SSH
- ✅ Nezávislý na SSH session - můžeš se odpojit
- ✅ Výstup do log souboru - snadné sledování

---

## 📊 Produkční příkazy

### Pro staging:

```bash
# Automatický (doporučeno)
./scripts/import-csv-production.sh exported_pois_staging_complete.csv

# Nebo manuálně po balíčcích
./scripts/import-csv-production.expect exported_pois_prod_chunk_1.csv
```

### Pro produkci:

```bash
# Jednorázový import na produkci
export PROD_PASS="produkční_heslo"
./scripts/import-csv-production.sh --env=production exported_pois_production.csv

# Manuálně (pokud vynecháte wrapper)
IMPORT_ENV=production PROD_PASS="produkční_heslo" \
  ./scripts/import-csv-production.expect exported_pois_prod_chunk_1.csv
```

---

## ✅ Kontrola dokončení

### Zkontrolovat všechny logy

```bash
# Na staging serveru
for log in /tmp/poi_import_*.log; do
    echo "=== $(basename $log) ==="
    tail -5 "$log"
    echo ""
done
```

### Vyhledat dokončené importy

```bash
grep -l "✅ BEZPEČNÝ IMPORT DOKONČEN\|Success.*Hotovo" /tmp/poi_import_*.log
```

---

## 🚨 Troubleshooting

### Import se nezastaví

1. Zkontroluj běžící procesy: `ps aux | grep php`
2. Zkontroluj log: `tail -f /tmp/poi_import_*.log`
3. Pokud je zaseknutý, zabít proces: `kill -9 <PID>`

### Memory limit stále padá

Zvyš memory limit na 2GB:
```bash
php -d memory_limit=2048M safe-import-csv-staging.php ...
```

### Import běží příliš dlouho

Zkontroluj, zda import skutečně běží:
```bash
tail -f /tmp/poi_import_*.log
```

Pokud se nic nemění, možná je import zaseknutý.

---

## 📝 Produkční checklist

- [ ] Backup databáze před importem
- [ ] Testovat na staging
- [ ] Ověřit správnost dat po importu
- [ ] Zkontrolovat memory a CPU použití
- [ ] Monitoring běžících procesů
- [ ] Dokumentace importu

---

*Dokument vytvořen pro optimalizovaný produkční import.*


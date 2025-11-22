# Import CSV po balíčcích - Instrukce

**Problém**: Import padl na řádku 6000 kvůli memory limitu  
**Řešení**: Rozdělit CSV na menší balíčky a importovat postupně  

---

## 📊 Situace

- ✅ **Zpracováno**: 6000 řádků (nové: 1023, aktualizované: 4977)
- ⚠️ **Padlo na**: řádku 6000 (memory limit 512MB)
- 📋 **Zbývá**: ~18,223 řádků

---

## 🔧 Postup

### KROK 1: Vytvořit CSV od řádku 6001

```bash
cd "/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky"
php scripts/split-csv-from-row.php exported_pois_staging_complete.csv exported_pois_from_6001.csv 6001
```

Tím se vytvoří nový CSV soubor bez prvních 6000 řádků (ale s hlavičkou).

---

### KROK 2: Rozdělit na balíčky po 5000 řádcích

```bash
# Balíček 1: řádky 1-5000
php scripts/split-csv-from-row.php exported_pois_from_6001.csv exported_pois_part1.csv 1
php scripts/split-csv-from-row.php exported_pois_from_6001.csv exported_pois_part1_5000.csv 5001 --max-rows=5000

# Nebo jednodušeji - vytvořit skript, který to udělá automaticky
```

**Nebo použít jednodušší přístup** - importovat po částech pomocí `--max-rows`:

---

### KROK 3: Importovat každý balíček zvlášť

#### Na staging přes SSH:

```bash
# 1. Připojit se na staging
ssh -i ~/.ssh/id_ed25519_wpcom staging-f576-dobitybaterky.wordpress.com@ssh.wp.com

# 2. Nahrát CSV soubor (pokud ještě není)
# (Použij SFTP nebo wrapper skript)

# 3. Importovat balíček 1 (řádky 6001-11000)
cd /srv/htdocs
php -d memory_limit=1024M wp db-poi import_csv /tmp/exported_pois_from_6001.csv --log-every=1000 --max-rows=5000

# 4. Importovat balíček 2 (řádky 11001-16000)
# (Musíš vytvořit CSV od řádku 11001)
php -d memory_limit=1024M wp db-poi import_csv /tmp/exported_pois_from_11001.csv --log-every=1000 --max-rows=5000

# 5. A tak dále...
```

---

## 💡 Jednodušší řešení: Použít --max-rows parametr

Místo rozdělování CSV můžeme použít parametr `--max-rows` v safe-import-csv-staging.php:

```bash
# Na staging:
cd /srv/htdocs/wp-content/plugins/dobity-baterky
php -d memory_limit=1024M safe-import-csv-staging.php /tmp/poi_import_XXXXXXXX.csv --max-rows=5000 --log-every=1000
```

**Problém**: `--max-rows` začíná od začátku souboru, takže musíme vytvořit nový CSV bez prvních 6000 řádků.

---

## 🚀 Doporučený postup

### 1. Vytvořit CSV od řádku 6001

```bash
php scripts/split-csv-from-row.php exported_pois_staging_complete.csv exported_pois_from_6001.csv 6001
```

### 2. Nahrát na staging

```bash
# Použít wrapper skript nebo SFTP
./scripts/import-csv-staging.sh exported_pois_from_6001.csv
```

### 3. Importovat po částech (5000 řádků najednou)

Na staging serveru:

```bash
cd /srv/htdocs/wp-content/plugins/dobity-baterky

# Balíček 1: řádky 1-5000 (z nového CSV = řádky 6001-11000 z původního)
php -d memory_limit=1024M safe-import-csv-staging.php /tmp/exported_pois_from_6001.csv --max-rows=5000 --log-every=1000

# Balíček 2: řádky 5001-10000 (z nového CSV = řádky 11001-16000 z původního)
# Musíš vytvořit CSV od řádku 5001
php scripts/split-csv-from-row.php exported_pois_from_6001.csv exported_pois_from_11001.csv 5001
# Nahrát na staging a importovat...
```

---

## 📝 Alternativa: Vytvořit skript pro automatické rozdělení

Můžu vytvořit skript, který automaticky rozdělí CSV na balíčky a vytvoří příkazy pro import.

---

*Dokument vytvořen pro řešení problému s memory limitem při importu.*


# Příkazy pro import po balíčcích na staging

**Status**: Prvních 6000 řádků už bylo importováno  
**Zbývá**: ~18,223 řádků rozdělených do 4 balíčků  

---

## 📦 Vytvořené balíčky

1. **exported_pois_part1_6001-11000.csv** - řádky 6001-11000 (5000 řádků)
2. **exported_pois_part2_11001-16000.csv** - řádky 11001-16000 (5000 řádků)
3. **exported_pois_part3_16001-21000.csv** - řádky 16001-21000 (5000 řádků)
4. **exported_pois_part4_21001-end.csv** - řádky 21001-konec (~3,223 řádků)

---

## 🚀 Postup importu

### KROK 1: Připojit se na staging

```bash
ssh -i ~/.ssh/id_ed25519_wpcom staging-f576-dobitybaterky.wordpress.com@ssh.wp.com
```

*(Zadej passphrase)*

---

### KROK 2: Nahrát balíčky na staging

**Z lokálního počítače** (v novém terminálu):

```bash
cd "/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky"

# Nahrát balíček 1
scp -i ~/.ssh/id_ed25519_wpcom exported_pois_part1_6001-11000.csv staging-f576-dobitybaterky.wordpress.com@ssh.wp.com:/tmp/

# Nahrát balíček 2
scp -i ~/.ssh/id_ed25519_wpcom exported_pois_part2_11001-16000.csv staging-f576-dobitybaterky.wordpress.com@ssh.wp.com:/tmp/

# Nahrát balíček 3
scp -i ~/.ssh/id_ed25519_wpcom exported_pois_part3_16001-21000.csv staging-f576-dobitybaterky.wordpress.com@ssh.wp.com:/tmp/

# Nahrát balíček 4
scp -i ~/.ssh/id_ed25519_wpcom exported_pois_part4_21001-end.csv staging-f576-dobitybaterky.wordpress.com@ssh.wp.com:/tmp/
```

---

### KROK 3: Importovat každý balíček

**Na staging serveru** (v SSH session):

```bash
cd /srv/htdocs/wp-content/plugins/dobity-baterky

# Balíček 1: řádky 6001-11000
php -d memory_limit=1024M safe-import-csv-staging.php /tmp/exported_pois_part1_6001-11000.csv --log-every=1000

# Počkat na dokončení, pak balíček 2: řádky 11001-16000
php -d memory_limit=1024M safe-import-csv-staging.php /tmp/exported_pois_part2_11001-16000.csv --log-every=1000

# Počkat na dokončení, pak balíček 3: řádky 16001-21000
php -d memory_limit=1024M safe-import-csv-staging.php /tmp/exported_pois_part3_16001-21000.csv --log-every=1000

# Počkat na dokončení, pak balíček 4: řádky 21001-konec
php -d memory_limit=1024M safe-import-csv-staging.php /tmp/exported_pois_part4_21001-end.csv --log-every=1000
```

---

## 📊 Očekávaný čas

- **Každý balíček**: ~2-3 minuty (5000 řádků)
- **Celkem**: ~8-12 minut pro všechny 4 balíčky

---

## ✅ Kontrola průběhu

Během importu uvidíš:

```
📊 Řádek 1000 | nové: XXX | aktualizované: XXX | chyby: 0 | prázdné: 0
📊 Řádek 2000 | nové: XXX | aktualizované: XXX | chyby: 0 | prázdné: 0
...
✅ BEZPEČNÝ IMPORT DOKONČEN
```

---

## 🚨 Pokud něco nefunguje

1. **SSH připojení nefunguje:**
   - Zkontroluj, zda máš správný SSH klíč
   - Zkontroluj passphrase

2. **SCP nahrávání nefunguje:**
   - Zkus použít SFTP místo SCP
   - Nebo použít wrapper skript: `./scripts/import-csv-staging.sh exported_pois_part1_6001-11000.csv`

3. **Memory limit stále padá:**
   - Zvyš limit na 2048M: `php -d memory_limit=2048M ...`

---

*Dokument vytvořen pro import zbývajících řádků po balíčcích.*


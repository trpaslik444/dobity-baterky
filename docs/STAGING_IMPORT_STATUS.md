# Status importu POI na staging

**Datum**: 2025-11-21  
**Soubor**: `exported_pois_staging_complete.csv` (24,223 POI)  
**Metoda**: Safe Import Script přes SSH  

---

## ✅ Import běží na staging

### Co se stalo:

1. ✅ **CSV soubor nahrán na staging**: `/tmp/poi_import_*.csv`
2. ✅ **Import skript nahrán**: `safe-import-csv-staging.php` → `/srv/htdocs/wp-content/plugins/dobity-baterky/`
3. ✅ **Import spuštěn**: běží na pozadí přes SSH

### Nastavení importu:

- **CSV řádků**: ~24,223 POI
- **Logování**: každých 1000 řádků
- **Timeout**: 60 minut (3600 sekund)
- **Režim**: Safe mode (použít ID pro aktualizaci)

### Odhadovaný čas:

- **Lokálně**: ~10 minut pro 25K řádků
- **Na staging**: pravděpodobně podobně (~10-15 minut)

---

## 📊 Sledování průběhu

### Zkontrolovat log:

```bash
# Nejnovější log
tail -f staging_import_complete_*.log

# Nebo všechny logy
tail -50 staging_import_*.log
```

### Očekávaný výstup:

Import bude vypisovat průběh každých 1000 řádků:
```
📊 Řádek 1000 | nové: XXX | aktualizované: XXX | chyby: X | prázdné: X | čas: XX.XXs
📊 Řádek 2000 | nové: XXX | aktualizované: XXX | chyby: X | prázdné: X | čas: XX.XXs
...
```

### Finální shrnutí:

Po dokončení uvidíte:
```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
✅ BEZPEČNÝ IMPORT DOKONČEN
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📊 Statistika:
   • Nově vytvořené POI: XXX
   • Aktualizované POI (podle ID): XXX
   • Celkem zpracovaných řádků: XXX
   • Počet chyb: XXX
   • Celkový čas: XX.XXs
```

---

## 🚀 Jak znovu spustit import (pokud by bylo potřeba)

```bash
cd /Users/ondraplas/Local\ Sites/dobity-baterky-dev/app/public/wp-content/plugins/dobity-baterky
./scripts/import-csv-staging.sh exported_pois_staging_complete.csv
```

---

## ✅ Úspěšné kroky

1. ✅ Export POI z lokální databáze (24,223 POI)
2. ✅ Vytvoření import skriptu (`import-csv-staging.sh`)
3. ✅ Automatické nahrání CSV na staging přes SFTP
4. ✅ Automatické nahrání import skriptu do plugin directory
5. ✅ Spuštění importu přes SSH s timeout 60 minut
6. ✅ Import běží na pozadí

---

*Dokument vytvořen po spuštění importu na staging.*


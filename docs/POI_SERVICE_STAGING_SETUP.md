# POI Microservice - Nastavení na Staging

## ⚠️ Problém

WordPress se snaží připojit k POI microservice na `staging-f576-dobitybaterky.wpcomstaging.com:3333`, ale microservice tam neběží.

**Chyba**:
```
cURL error 7: Failed to connect to staging-f576-dobitybaterky.wpcomstaging.com port 3333 after 0 ms: Could not connect to server
```

---

## 🔍 Co to znamená?

**POI microservice není nasazený** nebo běží na jiné URL.

**DŮLEŽITÉ**: POI microservice je **VOLITELNÁ samostatná Node.js služba**. WordPress **FUNGUJE NORMÁLNĚ** i bez něj!

- ✅ WordPress funguje bez POI microservice
- ✅ Používá pouze POIs z vlastní databáze (manuálně vytvořené)
- ✅ POI microservice je pouze **bonus** pro automatické získávání POIs z free zdrojů

POI microservice je **samostatná služba**, která NEMUSÍ běžet na WordPress serveru. Může běžet:
- Na jiném serveru
- Nebo vůbec nemusí běžet (WordPress funguje normálně)

---

## ✅ Řešení

### Krok 1: Rozhodnout, jestli POI microservice potřebujete

**POI microservice je VOLITELNÝ!**

- ✅ **Bez POI microservice**: WordPress funguje normálně, používá pouze POIs z vlastní databáze
- ✅ **S POI microservice**: WordPress automaticky získává POIs z free zdrojů (OpenTripMap, Wikidata)

**Pokud POI microservice nepotřebujete**: Nechat URL prázdné - WordPress funguje normálně.

---

### Krok 2: Pokud chcete použít POI microservice, zjistit kde běží

**Možnosti**:
1. POI microservice není nasazený → musí se nasadit (nebo nechat prázdné)
2. POI microservice běží na jiném serveru → použít správnou URL
3. POI microservice běží na stejném serveru, ale na jiném portu/cestě → použít správnou URL

---

### Krok 3: Nastavit správnou URL v WordPress

**V admin rozhraní** (`Tools > POI Microservice`):

#### Možnost A: POI microservice běží na jiném serveru
```
https://poi-api.your-server.com
```
nebo
```
https://poi-service.your-server.com
```

#### Možnost B: POI microservice běží na stejném serveru přes reverse proxy
```
https://staging-f576-dobitybaterky.wpcomstaging.com/api/pois
```

#### Možnost C: POI microservice není nasazený (doporučeno, pokud ho nepotřebujete)
**Nechat prázdné** - WordPress funguje normálně bez POI microservice, používá pouze POIs z vlastní databáze

---

## 🚀 Jak nasadit POI microservice na staging

Pokud POI microservice ještě není nasazený, je potřeba ho nasadit:

### 1. Připravit POI microservice

```bash
# Na staging serveru (nebo lokálně a pak nahrát)
cd poi-service
npm install
npm run build
```

### 2. Spustit POI microservice

**Možnost A: PM2 (doporučeno)**
```bash
pm2 start poi-service/dist/index.js --name poi-service
pm2 save
pm2 startup
```

**Možnost B: systemd**
```ini
[Unit]
Description=POI Microservice
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/poi-service
ExecStart=/usr/bin/node dist/index.js
Restart=always

[Install]
WantedBy=multi-user.target
```

**Možnost C: Docker (pokud používáte Docker)**
```yaml
# docker-compose.yml
services:
  poi-service:
    build: ./poi-service
    ports:
      - "3333:3333"
    environment:
      - DATABASE_URL=postgresql://...
      - PORT=3333
```

### 3. Nastavit reverse proxy (pokud chcete použít stejnou doménu)

**Nginx**:
```nginx
location /api/pois {
    proxy_pass http://localhost:3333;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
}
```

**Pak použít URL**: `https://staging-f576-dobitybaterky.wpcomstaging.com/api/pois`

---

## 📝 Co vyplnit v admin rozhraní

### Pokud POI microservice NENÍ nasazený (doporučeno, pokud ho nepotřebujete):
**Nechat prázdné** - WordPress funguje normálně, používá pouze POIs z vlastní databáze

### Pokud POI microservice běží na jiném serveru:
```
https://poi-api.your-server.com
```

### Pokud POI microservice běží na stejném serveru přes reverse proxy:
```
https://staging-f576-dobitybaterky.wpcomstaging.com/api/pois
```

---

## ⚠️ DŮLEŽITÉ

**Na staging/produkci NEPOUŽÍVEJTE port 3333 přímo!**

- ❌ `https://staging-f576-dobitybaterky.wpcomstaging.com:3333` - NEPOUŽÍVAT
- ✅ `https://staging-f576-dobitybaterky.wpcomstaging.com/api/pois` - použít přes reverse proxy
- ✅ `https://poi-api.staging-server.com` - použít subdoménu

Port 3333 je pouze pro lokální vývoj!

---

## 🔧 Ověření

### 1. Zkontrolovat, že POI microservice běží

```bash
# Na serveru, kde běží POI microservice
curl http://localhost:3333/api/pois/nearby?lat=50.0755&lon=14.4378&radius=2000
```

Mělo by vrátit JSON s POIs.

### 2. Testovat z WordPress admin

1. Přejít na `Tools > POI Microservice`
2. Nastavit správnou URL
3. Kliknout **"Testovat připojení"**
4. ✅ Mělo by se zobrazit: "Úspěšně připojeno! Nalezeno X POIs."

---

## 💡 Doporučení

**POI microservice je VOLITELNÝ!**

**Pro staging** (WordPress.com hosting):
- POI microservice pravděpodobně **není nasazený** na WordPress.com serveru
- **Doporučení**: **Nechat URL prázdné** - WordPress funguje normálně bez POI microservice
- Pokud chcete použít POI microservice: nasadit na samostatný server

**Pro produkci**:
- **Možnost 1**: Nechat URL prázdné - WordPress funguje normálně
- **Možnost 2**: Nasadit POI microservice na samostatný server nebo VPS
  - Použít subdoménu: `https://poi-api.dobitybaterky.cz`
  - Nebo přes reverse proxy: `https://dobitybaterky.cz/api/pois`

**Shrnutí**: POI microservice je **bonus funkcionalita**. WordPress **FUNGUJE NORMÁLNĚ** i bez něj!

---

## 📚 Související dokumentace

- `docs/POI_SERVICE_DEPLOYMENT.md` - Kompletní nasazení
- `docs/TESTING_QUICK_START.md` - Rychlý start
- `poi-service/README.md` - POI microservice dokumentace

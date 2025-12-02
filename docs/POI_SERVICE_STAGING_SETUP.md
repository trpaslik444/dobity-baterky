# POI Microservice - Nastavení na Staging

## ⚠️ Problém

WordPress se snaží připojit k POI microservice na `staging-f576-dobitybaterky.wpcomstaging.com:3333`, ale microservice tam neběží.

**Chyba**:
```
cURL error 7: Failed to connect to staging-f576-dobitybaterky.wpcomstaging.com port 3333 after 0 ms: Could not connect to server
```

---

## 🔍 Co to znamená?

**POI microservice není nasazený na staging serveru** nebo běží na jiné URL.

POI microservice je **samostatná Node.js služba**, která musí běžet nezávisle na WordPressu. WordPress se k ní připojuje přes HTTP API.

---

## ✅ Řešení

### Krok 1: Zjistit, kde POI microservice běží (nebo jestli vůbec běží)

**Možnosti**:
1. POI microservice není nasazený → musí se nasadit
2. POI microservice běží na jiném serveru → použít správnou URL
3. POI microservice běží na stejném serveru, ale na jiném portu/cestě → použít správnou URL

---

### Krok 2: Nastavit správnou URL v WordPress

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

#### Možnost C: POI microservice není nasazený (dočasně zakázat)
**Nechat prázdné** - WordPress přeskočí synchronizaci POIs z microservice

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

### Pokud POI microservice běží na jiném serveru:
```
https://poi-api.your-server.com
```

### Pokud POI microservice běží na stejném serveru přes reverse proxy:
```
https://staging-f576-dobitybaterky.wpcomstaging.com/api/pois
```

### Pokud POI microservice není nasazený (dočasně):
**Nechat prázdné** - WordPress bude fungovat, ale nebude synchronizovat POIs z microservice

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

**Pro staging** (WordPress.com hosting):
- POI microservice pravděpodobně **není nasazený** na WordPress.com serveru
- **Možnosti**:
  1. Nasadit POI microservice na samostatný server
  2. Nebo dočasně nechat URL prázdné (WordPress bude fungovat bez POI synchronizace)

**Pro produkci**:
- Nasadit POI microservice na samostatný server nebo VPS
- Použít subdoménu: `https://poi-api.dobitybaterky.cz`
- Nebo přes reverse proxy: `https://dobitybaterky.cz/api/pois`

---

## 📚 Související dokumentace

- `docs/POI_SERVICE_DEPLOYMENT.md` - Kompletní nasazení
- `docs/TESTING_QUICK_START.md` - Rychlý start
- `poi-service/README.md` - POI microservice dokumentace

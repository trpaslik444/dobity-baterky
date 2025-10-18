# On-Demand Processing Workflow

## 🎯 Cíl
Optimalizace zpracování dat založená na uživatelské interakci místo neustále běžících workerů.

## 📊 Problém s původními workery
- **Neustále běžící workery** každých 60 sekund
- **Zbytečné zpracování** již zpracovaných dat
- **Vysoká spotřeba zdrojů** na produkci
- **Pomalé API odpovědi** kvůli přetížení

## ✅ Nové optimalizované workflow

### 1. **Cache-First Approach**
```php
// Zkontrolovat cache (30 dní TTL)
$cache_status = On_Demand_Processor::check_cache_status($point_id, $point_type);

if ($cache_status['is_fresh']) {
    return $cache_status['data']; // Okamžitá odpověď
}
```

### 2. **On-Demand Processing**
```javascript
// Spustit zpracování pouze při uživatelské interakci
onDemandProcessor.processPoint(pointId, pointType, 'high');
```

### 3. **Progressive UI Feedback**
```javascript
// Zobrazit loading UI s kroky
🔍 Hledám nearby body...
📏 Vypočítávám vzdálenosti...
🗺️ Generuji isochrony...
💾 Ukládám data...
✅ Hotovo!
```

## 🔄 Workflow sekvence

### **Krok 1: Uživatel klikne na bod**
```javascript
// Event listener na kliknutí
document.addEventListener('click', (e) => {
    const pointElement = e.target.closest('[data-point-id]');
    if (pointElement) {
        const pointId = pointElement.dataset.pointId;
        const pointType = pointElement.dataset.pointType;
        
        onDemandProcessor.processPoint(pointId, pointType);
    }
});
```

### **Krok 2: Kontrola cache**
```php
// Zkontrolovat 30denní cache
$cache_keys = [
    '_db_nearby_cache_poi_foot',
    '_db_nearby_cache_charger_foot',
    '_db_isochrones_v1_foot-walking'
];

foreach ($cache_keys as $cache_key) {
    $cache_data = get_post_meta($point_id, $cache_key, true);
    $age_days = (time() - strtotime($payload['computed_at'])) / DAY_IN_SECONDS;
    
    if ($age_days < 30) {
        return $cache_data; // Cache je aktuální
    }
}
```

### **Krok 3: Zobrazení loading UI**
```javascript
// Zobrazit loading overlay
showLoadingUI(pointId, pointType) {
    this.loadingOverlay.innerHTML = `
        <div class="ondemand-loading">
            <div class="loading-spinner"></div>
            <h3>Zpracovávám data pro bod ${pointId}</h3>
            <div class="loading-steps">
                🔍 Hledám nearby body...
                📏 Vypočítávám vzdálenosti...
                🗺️ Generuji isochrony...
                💾 Ukládám data...
                ✅ Hotovo!
            </div>
        </div>
    `;
}
```

### **Krok 4: Asynchronní zpracování**
```php
// Spustit asynchronní worker
$token = wp_generate_password(24, false, false);
set_transient('db_ondemand_token_' . $point_id, $token, 300);

wp_remote_post(rest_url('db/v1/ondemand/process'), [
    'timeout' => 0.01,
    'blocking' => false,
    'body' => [
        'point_id' => $point_id,
        'point_type' => $point_type,
        'token' => $token,
        'priority' => 'high'
    ]
]);
```

### **Krok 5: Sledování stavu**
```javascript
// Polling stavu zpracování
async monitorProcessing(pointId, checkUrl) {
    const checkStatus = async () => {
        const response = await fetch(checkUrl);
        const status = await response.json();
        
        if (status.status === 'completed') {
            this.hideLoadingUI(pointId);
            this.updateUIWithData(pointId, status.data);
            return;
        }
        
        // Pokračovat ve sledování
        setTimeout(checkStatus, 1000);
    };
    
    checkStatus();
}
```

### **Krok 6: Aktualizace UI**
```javascript
// Aktualizovat UI s novými daty
updateUIWithData(pointId, data) {
    // Aktualizovat nearby body na mapě
    // Aktualizovat isochrony
    // Aktualizovat seznam nearby bodů
    // Aktualizovat detaily bodu
}
```

## 🚀 Výhody nového workflow

### **Výkonnost**
- **Okamžitá odpověď** pro cached data (< 0.1s)
- **Žádné neustále běžící workery** - úspora zdrojů
- **Zpracování pouze při potřebě** - efektivní využití

### **Uživatelská zkušenost**
- **Progressive loading** s kroky zpracování
- **Real-time feedback** o stavu zpracování
- **Neblokující UI** - uživatel může pokračovat

### **Škálovatelnost**
- **On-demand processing** - škáluje s uživateli
- **Cache-first approach** - minimalizuje API volání
- **Asynchronní zpracování** - neblokuje UI

## 📁 Nové soubory

### **Backend**
- `includes/Jobs/On_Demand_Processor.php` - Hlavní logika
- `includes/Jobs/Optimized_Worker_Manager.php` - Správa workerů
- `includes/REST_On_Demand.php` - REST API endpointy

### **Frontend**
- `assets/ondemand-processor.js` - JavaScript komponenta
- CSS styly pro loading UI

## 🔧 API Endpointy

### **POST /wp-json/db/v1/ondemand/process**
```json
{
    "point_id": 123,
    "point_type": "charging_location",
    "token": "abc123...",
    "priority": "high"
}
```

### **GET /wp-json/db/v1/ondemand/status/{point_id}**
```json
{
    "status": "completed",
    "data": { ... },
    "message": "Zpracování dokončeno"
}
```

### **POST /wp-json/db/v1/ondemand/sync** (Admin)
```json
{
    "point_id": 123,
    "point_type": "charging_location"
}
```

## 📊 Monitoring

### **Statistiky zpracování**
```php
$stats = Optimized_Worker_Manager::get_processing_stats();
// [
//     'total_points' => 1500,
//     'cached_points' => 1200,
//     'uncached_points' => 300
// ]
```

### **Cache status**
```php
$cache_status = On_Demand_Processor::check_cache_status($point_id, $point_type);
// [
//     'is_fresh' => true,
//     'data' => { ... },
//     'age_days' => 5
// ]
```

## 🎯 Implementační plán

### **Fáze 1: Backend (Hotovo)**
- [x] On_Demand_Processor
- [x] Optimized_Worker_Manager
- [x] REST API endpointy

### **Fáze 2: Frontend (Hotovo)**
- [x] JavaScript komponenta
- [x] Loading UI
- [x] Progress tracking

### **Fáze 3: Integrace**
- [ ] Integrace s existující mapou
- [ ] Aktualizace event listenerů
- [ ] Testování na produkci

### **Fáze 4: Optimalizace**
- [ ] Monitoring výkonu
- [ ] Fine-tuning cache TTL
- [ ] Optimalizace UI feedback

## 🔄 Migrace z původních workerů

### **Krok 1: Deaktivace automatických workerů**
```php
// Odstranit cron joby
wp_clear_scheduled_hook('db_nearby_recompute');
wp_clear_scheduled_hook('db_poi_discovery');
```

### **Krok 2: Zachování existujících dat**
```php
// Všechna cached data zůstávají
// Pouze se změní způsob zpracování
```

### **Krok 3: Aktivace on-demand systému**
```php
// Registrace nových endpointů
add_action('rest_api_init', [REST_On_Demand::class, 'register']);
```

## 📈 Očekávané zlepšení

| Metrika | Před | Po |
|---------|------|-----|
| **API odpověď** | 13.2s | <0.1s (cached) |
| **CPU využití** | Vysoké | Minimalizované |
| **Paměť** | 200MB+ | <50MB |
| **Uživatelská zkušenost** | Pomalá | Okamžitá |

## 🎉 Závěr

Nové on-demand workflow poskytuje:
- **Okamžitou odpověď** pro cached data
- **Efektivní zpracování** pouze při potřebě
- **Vynikající UX** s progressive loading
- **Škálovatelnost** s růstem uživatelů
- **Úsporu zdrojů** na produkci

Systém je připraven k nasazení a testování! 🚀

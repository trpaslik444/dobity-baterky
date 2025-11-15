/**
 * On-Demand Processor - JavaScript komponenta pro UI feedback
 * @package DobityBaterky
 */

class OnDemandProcessor {
    constructor() {
        this.processingPoints = new Map();
        this.loadingOverlay = null;
        this.init();
    }
    
    init() {
        this.createLoadingOverlay();
        this.bindEvents();
    }
    
    /**
     * Zpracovat bod na požádání
     */
    async processPoint(pointId, pointType, priority = 'normal') {
        // Zkontrolovat, zda už se zpracovává
        if (this.processingPoints.has(pointId)) {
            console.log(`Bod ${pointId} se už zpracovává`);
            return;
        }
        
        try {
            // Zobrazit loading UI
            this.showLoadingUI(pointId, pointType);
            
            // Spustit zpracování
            const result = await this.startProcessing(pointId, pointType, priority);
            
            // Zpracovat výsledek podle statusu
            if (result.status === 'cached' || result.status === 'completed') {
                // Zpracování dokončeno (buď z cache nebo nově zpracováno)
                console.log(`Bod ${pointId} zpracován: ${result.status}`);
                this.hideLoadingUI(pointId);
                this.processingPoints.delete(pointId);
                
                // Aktualizovat UI s daty
                if (result.items || result.nearby || result.isochrones) {
                    this.updateUIWithData(pointId, result);
                }
                
                return result;
            } else if (result.status === 'processing') {
                // Asynchronní zpracování (pokud by bylo implementováno)
                this.monitorProcessing(pointId, result.check_url);
                return result;
            } else {
                // Neznámý status
                console.warn(`Neznámý status: ${result.status}`, result);
                this.hideLoadingUI(pointId);
                this.processingPoints.delete(pointId);
                return result;
            }
            
        } catch (error) {
            console.error('Chyba při zpracování bodu:', error);
            this.hideLoadingUI(pointId);
            this.processingPoints.delete(pointId);
            throw error;
        }
    }
    
    /**
     * Zkontrolovat cache status
     */
    async checkCacheStatus(pointId, pointType) {
        const response = await fetch(`/wp-json/db/v1/ondemand/status/${pointId}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    }
    
    /**
     * Spustit zpracování
     */
    async startProcessing(pointId, pointType, priority) {
        // Nejdříve získat token
        const tokenResponse = await fetch('/wp-json/db/v1/ondemand/token', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce
            },
            body: JSON.stringify({
                point_id: pointId
            })
        });
        
        if (!tokenResponse.ok) {
            throw new Error(`Token generation failed: ${tokenResponse.status}`);
        }
        
        const tokenData = await tokenResponse.json();
        
        // Nyní spustit zpracování s platným tokenem
        const response = await fetch('/wp-json/db/v1/ondemand/process', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce
            },
            body: JSON.stringify({
                point_id: pointId,
                point_type: pointType,
                priority: priority,
                token: tokenData.token
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    }
    
    /**
     * Sledovat stav zpracování
     */
    async monitorProcessing(pointId, checkUrl) {
        const maxAttempts = 30; // 30 sekund
        let attempts = 0;
        
        const checkStatus = async () => {
            attempts++;
            
            try {
                const response = await fetch(checkUrl, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': wpApiSettings.nonce
                    }
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const status = await response.json();
                
                if (status.status === 'completed') {
                    // Zpracování dokončeno
                    this.hideLoadingUI(pointId);
                    this.processingPoints.delete(pointId);
                    
                    // Aktualizovat UI s novými daty
                    this.updateUIWithData(pointId, status.data);
                    
                    return;
                }
                
                if (status.status === 'error') {
                    // Chyba při zpracování
                    this.hideLoadingUI(pointId);
                    this.processingPoints.delete(pointId);
                    this.showError(pointId, status.message);
                    
                    return;
                }
                
                // Aktualizovat progress
                this.updateProgress(pointId, attempts, maxAttempts);
                
                // Pokračovat ve sledování
                if (attempts < maxAttempts) {
                    setTimeout(checkStatus, 1000);
                } else {
                    // Timeout
                    this.hideLoadingUI(pointId);
                    this.processingPoints.delete(pointId);
                    this.showError(pointId, 'Timeout - zpracování trvalo příliš dlouho');
                }
                
            } catch (error) {
                console.error('Chyba při kontrole stavu:', error);
                this.hideLoadingUI(pointId);
                this.processingPoints.delete(pointId);
                this.showError(pointId, 'Chyba při kontrole stavu zpracování');
            }
        };
        
        // Spustit sledování
        checkStatus();
    }
    
    /**
     * Zobrazit loading UI
     */
    showLoadingUI(pointId, pointType) {
        this.processingPoints.set(pointId, {
            pointType: pointType,
            startTime: Date.now()
        });
        
        const loadingSteps = [
            '🔍 Hledám nearby body...',
            '📏 Vypočítávám vzdálenosti...',
            '🗺️ Generuji isochrony...',
            '💾 Ukládám data...',
            '✅ Hotovo!'
        ];
        
        this.loadingOverlay.innerHTML = `
            <div class="ondemand-loading" data-point-id="${pointId}">
                <div class="loading-content">
                    <div class="loading-spinner"></div>
                    <h3>Zpracovávám data pro bod ${pointId}</h3>
                    <div class="loading-steps">
                        ${loadingSteps.map((step, index) => 
                            `<div class="loading-step ${index === 0 ? 'active' : ''}" data-step="${index}">
                                ${step}
                            </div>`
                        ).join('')}
                    </div>
                    <div class="loading-progress">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: 0%"></div>
                        </div>
                        <div class="progress-text">0%</div>
                    </div>
                    <div class="loading-time">Odhadovaný čas: 10-30 sekund</div>
                </div>
            </div>
        `;
        
        this.loadingOverlay.style.display = 'flex';
    }
    
    /**
     * Skrýt loading UI
     */
    hideLoadingUI(pointId) {
        const loadingElement = this.loadingOverlay.querySelector(`[data-point-id="${pointId}"]`);
        if (loadingElement) {
            loadingElement.remove();
        }
        
        if (this.loadingOverlay.children.length === 0) {
            this.loadingOverlay.style.display = 'none';
        }
    }
    
    /**
     * Aktualizovat progress
     */
    updateProgress(pointId, current, max) {
        const loadingElement = this.loadingOverlay.querySelector(`[data-point-id="${pointId}"]`);
        if (!loadingElement) return;
        
        const progress = Math.min((current / max) * 100, 95); // Max 95% dokud není hotovo
        const progressFill = loadingElement.querySelector('.progress-fill');
        const progressText = loadingElement.querySelector('.progress-text');
        
        if (progressFill) {
            progressFill.style.width = `${progress}%`;
        }
        
        if (progressText) {
            progressText.textContent = `${Math.round(progress)}%`;
        }
        
        // Aktualizovat kroky
        const steps = loadingElement.querySelectorAll('.loading-step');
        const currentStep = Math.floor((current / max) * steps.length);
        
        steps.forEach((step, index) => {
            step.classList.toggle('active', index <= currentStep);
            step.classList.toggle('completed', index < currentStep);
        });
    }
    
    /**
     * Aktualizovat UI s novými daty
     */
    updateUIWithData(pointId, data) {
        console.log(`Aktualizuji UI pro bod ${pointId} s daty:`, data);
        
        // Zobrazit toast notifikaci
        this.showToast(`Bod ${pointId} byl úspěšně zpracován`, 'success');
        
        // Aktualizovat nearby data na mapě (pokud existuje dbMap instance)
        if (window.dbMap && data.items) {
            console.log('Aktualizuji nearby data na mapě:', data.items);
            // Zde by se měla volat funkce pro aktualizaci mapy
            // window.dbMap.updateNearbyData(pointId, data.items);
        }
        
        // Aktualizovat isochrony (pokud existují)
        if (data.isochrones && window.dbMap) {
            console.log('Aktualizuji isochrony:', data.isochrones);
            // window.dbMap.updateIsochrones(pointId, data.isochrones);
        }
        
        // Aktualizovat seznam nearby bodů
        if (data.items && data.items.length > 0) {
            console.log(`Našeno ${data.items.length} nearby bodů`);
            // Zde by se měla aktualizovat seznam nearby bodů
        }
        
        // Dispatch custom event pro ostatní komponenty
        const event = new CustomEvent('ondemand-completed', {
            detail: {
                pointId: pointId,
                data: data,
                status: data.status
            }
        });
        document.dispatchEvent(event);
    }
    
    /**
     * Zobrazit chybu
     */
    showError(pointId, message) {
        console.error(`Chyba pro bod ${pointId}:`, message);
        
        // Zobrazit error toast nebo modal
        this.showToast(`Chyba při zpracování bodu ${pointId}: ${message}`, 'error');
    }
    
    /**
     * Zobrazit toast zprávu
     */
    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `ondemand-toast ${type}`;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        // Animace
        setTimeout(() => toast.classList.add('show'), 100);
        
        // Skrýt po 5 sekundách
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }
    
    /**
     * Vytvořit loading overlay
     */
    createLoadingOverlay() {
        this.loadingOverlay = document.createElement('div');
        this.loadingOverlay.className = 'ondemand-loading-overlay';
        this.loadingOverlay.style.display = 'none';
        
        document.body.appendChild(this.loadingOverlay);
    }
    
    /**
     * Bind events
     */
    bindEvents() {
        // Bind na kliknutí na body na mapě
        document.addEventListener('click', (e) => {
            const pointElement = e.target.closest('[data-point-id]');
            if (pointElement) {
                const pointId = pointElement.dataset.pointId;
                const pointType = pointElement.dataset.pointType;
                
                if (pointId && pointType) {
                    this.processPoint(pointId, pointType);
                }
            }
        });
    }
}

// Inicializace
document.addEventListener('DOMContentLoaded', () => {
function dbInitOnDemandProcessor() {
    window.onDemandProcessor = new OnDemandProcessor();
}

if (window.dbMapLoader && window.dbMapLoader.readyPromise) {
    window.dbMapLoader.readyPromise.then(dbInitOnDemandProcessor).catch((error) => {
        console.error('[DB OnDemand] Map loader selhal, inicializuji fallbackem.', error);
        dbInitOnDemandProcessor();
    });
} else {
    window.addEventListener('db-map-ready', function handleDbMapReady() {
        window.removeEventListener('db-map-ready', handleDbMapReady);
        dbInitOnDemandProcessor();
    });
}
});

// CSS styly
const styles = `
.ondemand-loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
}

.ondemand-loading {
    background: white;
    border-radius: 12px;
    padding: 30px;
    max-width: 400px;
    width: 90%;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}

.loading-content {
    text-align: center;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #007cba;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.loading-steps {
    margin: 20px 0;
}

.loading-step {
    padding: 8px 0;
    opacity: 0.5;
    transition: opacity 0.3s ease;
}

.loading-step.active {
    opacity: 1;
    font-weight: bold;
    color: #007cba;
}

.loading-step.completed {
    opacity: 0.7;
    color: #28a745;
}

.loading-progress {
    margin: 20px 0;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background: #f3f3f3;
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: #007cba;
    transition: width 0.3s ease;
}

.progress-text {
    margin-top: 8px;
    font-size: 14px;
    color: #666;
}

.loading-time {
    font-size: 12px;
    color: #999;
    margin-top: 10px;
}

.ondemand-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    color: white;
    font-weight: 500;
    z-index: 10001;
    transform: translateX(100%);
    transition: transform 0.3s ease;
}

.ondemand-toast.show {
    transform: translateX(0);
}

.ondemand-toast.info {
    background: #007cba;
}

.ondemand-toast.error {
    background: #dc3545;
}

.ondemand-toast.success {
    background: #28a745;
}
`;

// Přidat styly do head
const styleSheet = document.createElement('style');
styleSheet.textContent = styles;
document.head.appendChild(styleSheet);

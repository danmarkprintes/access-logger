/**
 * Access Logger - Sistema de Log de Acessos
 * 
 * Coleta informações detalhadas sobre o comportamento do usuário
 * e envia para o backend para análise e estatísticas.
 */
class AccessLogger {
    constructor(options = {}) {
        this.options = {
            endpoint: '/api/access-log',
            updateEndpoint: '/api/access-log/update',
            eventsEndpoint: '/api/access-log/events',
            debug: false,
            autoStart: true,
            trackScroll: true,
            trackTime: true,
            scrollThreshold: 10, // pixels
            timeUpdateInterval: 30000, // 30 seconds
            eventsFlushInterval: 5000, // 5 seconds
            eventsBatchSize: 20, // flush ao atingir 20 eventos
            ...options
        };

        // Toggle de debug via flag global ou localStorage (temporário para ver funcionamento)
        try {
            if (typeof window !== 'undefined') {
                if (window.accessLoggerDebug === true || localStorage.getItem('access_logger_debug') === '1') {
                    this.options.debug = true;
                }
            }
        } catch (e) {
            // silencioso
        }
        
        this.sessionId = this.getOrCreateSessionId();
        this.currentLogId = null;
        this.startTime = Date.now();
        this.lastScrollDepth = 0;
        this.maxScrollDepth = 0;
        this.isPageVisible = true;
        this.timeOnPageStart = Date.now();
        this.fingerprint = null;
        this.navigationOrder = this.getNavigationOrder();
        this.previousLogId = this.getPreviousLogId();
        this.eventBuffer = [];
        this.isFlushing = false;
        
        if (this.options.autoStart) {
            this.init();
        }
    }
    
    /**
     * Inicializa o logger
     */
    async init() {
        try {
            // Gera o fingerprint do dispositivo
            this.fingerprint = await this.generateFingerprint();
            
            // Registra o acesso inicial
            await this.logInitialAccess();
            
            // Configura os event listeners
            this.setupEventListeners();
            
            // Inicia o tracking de scroll e tempo
            if (this.options.trackScroll) {
                this.startScrollTracking();
            }
            
            if (this.options.trackTime) {
                this.startTimeTracking();
            }

            // Inicia flush periódico de eventos
            this.startEventsFlushTimer();
            
            this.log('AccessLogger initialized successfully');
            
        } catch (error) {
            this.error('Failed to initialize AccessLogger:', error);
        }
    }
    
    /**
     * Gera fingerprint único do dispositivo/navegador
     */
    async generateFingerprint() {
        const fingerprint = {
            screen_resolution: `${screen.width}x${screen.height}`,
            user_agent: navigator.userAgent,
            language: navigator.language,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            color_depth: screen.colorDepth,
            pixel_ratio: window.devicePixelRatio || 1,
            touch_support: 'ontouchstart' in window || navigator.maxTouchPoints > 0,
            operating_system: this.getOperatingSystem(),
            browser_name: this.getBrowserName(),
            browser_version: this.getBrowserVersion(),
            device_type: this.getDeviceType(),
            webgl_vendor: this.getWebGLVendor(),
            webgl_renderer: this.getWebGLRenderer(),
            canvas_fingerprint: await this.getCanvasFingerprint(),
            audio_fingerprint: null, // geração adiada para user gesture (política autoplay)
            plugins_list: this.getPluginsList(),
            fonts_list: await this.getFontsList()
        };

        this.scheduleAudioFingerprintOnInteraction();
        return fingerprint;
    }

    /**
     * Agenda geração de audio_fingerprint na primeira interação do usuário
     * (requer user gesture para AudioContext - política de autoplay do Chrome).
     * Importante: criar o AudioContext de forma síncrona no handler, antes de
     * qualquer await, pois o await "consome" o contexto de user gesture.
     */
    scheduleAudioFingerprintOnInteraction() {
        /* Audio fingerprint desativado: causa avisos no console (Chrome autoplay).
         * O fingerprint permanece com audio_fingerprint: null. */
    }
    
    /**
     * Registra o acesso inicial
     */
    async logInitialAccess() {
        const accessData = {
            url: window.location.href,
            referer: document.referrer || null,
            session_id: this.sessionId,
            page_load_time: this.getPageLoadTime(),
            navigation_order: this.navigationOrder,
            previous_log_id: this.previousLogId,
            fingerprint: this.fingerprint,
            ...this.extractUtmParams()
        };
        
        try {
            const response = await this.sendRequest(this.options.endpoint, accessData);
            
            if (response.success) {
                this.currentLogId = response.log_id;
                this.sessionId = response.session_id;
                this.saveSessionData();
                this.log('Initial access logged successfully', response);
            } else {
                this.error('Failed to log initial access:', response.message);
            }
            
        } catch (error) {
            this.error('Error logging initial access:', error);
        }
    }
    
    /**
     * Atualiza o log de acesso com dados adicionais
     */
    async updateAccessLog(data = {}) {
        if (!this.currentLogId) {
            this.error('No current log ID to update');
            return;
        }
        
        const updateData = {
            log_id: this.currentLogId,
            scroll_depth: this.maxScrollDepth,
            time_on_page: this.getTimeOnPage(),
            ...data
        };
        
        try {
            const response = await this.sendRequest(this.options.updateEndpoint, updateData);
            
            if (response.success) {
                this.log('Access log updated successfully');
            } else {
                this.error('Failed to update access log:', response.message);
            }
            
        } catch (error) {
            this.error('Error updating access log:', error);
        }
    }

    /**
     * Retorna o access_log_id atual para integrações (ex.: auth-gate).
     * Não dispara side effects.
     */
    getCurrentLogId() {
        return this.currentLogId;
    }
    
    /**
     * Configura os event listeners
     */
    setupEventListeners() {
        // Tracking de visibilidade da página
        document.addEventListener('visibilitychange', () => {
            this.isPageVisible = !document.hidden;
            if (this.isPageVisible) {
                this.timeOnPageStart = Date.now();
            }
        });
        
        // Tracking de saída da página (apenas pagehide – unload/beforeunload foram descontinuados)
        window.addEventListener('pagehide', (event) => {
            this.flushEvents({ immediate: true, useBeacon: true });
            const exitType = event.persisted ? 'refresh' : 'navigation';
            this.updateAccessLog({ exit_type: exitType });
        });

        // Tracking de cliques compatível com GA4
        document.addEventListener('click', (e) => {
            const el = e.target.closest('[data-ga4-event]');
            if (!el) return;
            const eventName = 'button_click';
            const elementType = this.detectElementType(el);
            const baseLabel = el.getAttribute('data-ga4-event') || (el.textContent || '').trim();
            const imageName = this.getBannerImageName(el, e.target);
            let elementLabel = baseLabel;
            if (imageName) {
                elementLabel = `${baseLabel}__${imageName}`;
            }
            elementLabel = (elementLabel || '').substring(0, 128);
            const targetHref = el.getAttribute('href') || null;
            const timeOffsetMs = Date.now() - this.startTime;
            this.log('Captured click event', { eventName, elementType, elementLabel, targetHref, timeOffsetMs });
            this.enqueueEvent({
                event_name: eventName,
                element_type: elementType,
                element_label: elementLabel,
                target_href: targetHref,
                numeric_value: 1,
                time_offset_ms: timeOffsetMs
            });
        }, { passive: true });
    }

    /**
     * Detecta tipo de elemento para eventos
     */
    detectElementType(el) {
        if (!el) return 'custom';
        const tag = (el.tagName || '').toUpperCase();
        if (tag === 'A') return 'link';
        if (tag === 'BUTTON') return 'button';
        if (el.getAttribute && el.getAttribute('role') === 'button') return 'button';
        if (el.classList && el.classList.contains('btn')) return 'button';
        return 'custom';
    }

    /**
     * Obtém nome do arquivo de imagem do banner
     */
    getBannerImageName(el, target) {
        let imgEl = null;
        if (target && target.tagName && target.tagName.toUpperCase() === 'IMG') {
            imgEl = target;
        } else if (el.tagName && el.tagName.toUpperCase() === 'IMG') {
            imgEl = el;
        } else if (el.querySelector) {
            imgEl = el.querySelector('img');
        }
        if (!imgEl) return null;
        const src = imgEl.getAttribute('src') || imgEl.src || '';
        return this.normalizeBannerImageName(src);
    }

    /**
     * Normaliza nome de arquivo para slug
     */
    normalizeBannerImageName(src) {
        if (!src) return null;
        let cleanPath = src;
        try {
            const url = new URL(src, window.location.href);
            cleanPath = url.pathname;
        } catch (e) {
            // fallback para paths relativos
        }
        cleanPath = cleanPath.split('?')[0].split('#')[0];
        const filename = cleanPath.split('/').pop();
        if (!filename) return null;
        return filename
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    /**
     * Enfileira evento para envio em lote
     */
    enqueueEvent(ev) {
        this.log('enqueueEvent', ev);
        this.eventBuffer.push(ev);
        if (this.eventBuffer.length >= this.options.eventsBatchSize) {
            this.flushEvents();
        }
    }

    /**
     * Timer periódico de flush
     */
    startEventsFlushTimer() {
        setInterval(() => this.flushEvents(), this.options.eventsFlushInterval);
    }

    /**
     * Envia eventos em lote para o backend
     */
    async flushEvents(options = {}) {
        try {
            if (this.isFlushing) return;
            if (!this.currentLogId) return; // aguarda log inicial
            if (!this.eventBuffer.length) return;

            this.isFlushing = true;
            const batch = this.eventBuffer.splice(0, this.options.eventsBatchSize);
            this.log('Flushing events', { count: batch.length, pending: this.eventBuffer.length });
            const payload = {
                access_log_id: this.currentLogId,
                events: batch
            };

            if (options.useBeacon && navigator.sendBeacon) {
                const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
                const ok = navigator.sendBeacon(this.options.eventsEndpoint, blob);
                this.log('Beacon flush result', { ok });
                this.isFlushing = false;
                return ok;
            }

            await fetch(this.options.eventsEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload),
                keepalive: !!options.immediate
            });
            this.log('Fetch flush sent', { count: batch.length });
        } catch (e) {
            this.error('Error flushing events:', e);
        } finally {
            this.isFlushing = false;
        }
    }
    
    /**
     * Inicia o tracking de scroll
     */
    startScrollTracking() {
        let scrollTimeout;
        
        window.addEventListener('scroll', () => {
            clearTimeout(scrollTimeout);
            
            scrollTimeout = setTimeout(() => {
                const scrollDepth = this.calculateScrollDepth();
                
                if (Math.abs(scrollDepth - this.lastScrollDepth) >= this.options.scrollThreshold) {
                    this.lastScrollDepth = scrollDepth;
                    this.maxScrollDepth = Math.max(this.maxScrollDepth, scrollDepth);
                }
            }, 100);
        });
    }
    
    /**
     * Inicia o tracking de tempo
     */
    startTimeTracking() {
        setInterval(() => {
            if (this.isPageVisible) {
                this.updateAccessLog();
            }
        }, this.options.timeUpdateInterval);
    }
    
    /**
     * Calcula a profundidade de scroll em porcentagem
     */
    calculateScrollDepth() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const documentHeight = Math.max(
            document.body.scrollHeight,
            document.body.offsetHeight,
            document.documentElement.clientHeight,
            document.documentElement.scrollHeight,
            document.documentElement.offsetHeight
        );
        const windowHeight = window.innerHeight;
        
        if (documentHeight <= windowHeight) {
            return 100;
        }
        
        return Math.round((scrollTop / (documentHeight - windowHeight)) * 100);
    }
    
    /**
     * Calcula o tempo na página em segundos
     */
    getTimeOnPage() {
        return Math.round((Date.now() - this.timeOnPageStart) / 1000);
    }
    
    /**
     * Obtém o tempo de carregamento da página
     */
    getPageLoadTime() {
        if (window.performance && window.performance.timing) {
            const timing = window.performance.timing;
            return (timing.loadEventEnd - timing.navigationStart) / 1000;
        }
        return null;
    }
    
    /**
     * Extrai parâmetros UTM da URL
     */
    extractUtmParams() {
        const urlParams = new URLSearchParams(window.location.search);
        
        return {
            utm_source: urlParams.get('utm_source'),
            utm_medium: urlParams.get('utm_medium'),
            utm_campaign: urlParams.get('utm_campaign'),
            utm_term: urlParams.get('utm_term'),
            utm_content: urlParams.get('utm_content')
        };
    }
    
    /**
     * Obtém ou cria um session ID
     */
    getOrCreateSessionId() {
        let sessionId = sessionStorage.getItem('access_logger_session_id');
        
        if (!sessionId) {
            sessionId = this.generateUUID();
            sessionStorage.setItem('access_logger_session_id', sessionId);
        }
        
        return sessionId;
    }
    
    /**
     * Obtém a ordem de navegação na sessão
     */
    getNavigationOrder() {
        const order = parseInt(sessionStorage.getItem('access_logger_nav_order') || '0') + 1;
        sessionStorage.setItem('access_logger_nav_order', order.toString());
        return order;
    }
    
    /**
     * Obtém o ID do log anterior
     */
    getPreviousLogId() {
        return sessionStorage.getItem('access_logger_previous_log_id');
    }
    
    /**
     * Salva dados da sessão
     */
    saveSessionData() {
        if (this.currentLogId) {
            sessionStorage.setItem('access_logger_previous_log_id', this.currentLogId.toString());
        }
    }
    
    /**
     * Detecta o sistema operacional
     */
    getOperatingSystem() {
        const userAgent = navigator.userAgent;
        
        if (userAgent.includes('Windows')) return 'Windows';
        if (userAgent.includes('Mac OS')) return 'macOS';
        if (userAgent.includes('Linux')) return 'Linux';
        if (userAgent.includes('Android')) return 'Android';
        if (userAgent.includes('iPhone') || userAgent.includes('iPad') || userAgent.includes('iPod') || userAgent.includes('CPU iPhone OS')) return 'iOS';
        
        return 'Unknown';
    }
    
    /**
     * Detecta o nome do navegador
     */
    getBrowserName() {
        const userAgent = navigator.userAgent;
        
        if (userAgent.includes('Chrome') && !userAgent.includes('Edg')) return 'Chrome';
        if (userAgent.includes('Firefox')) return 'Firefox';
        if (userAgent.includes('Safari') && !userAgent.includes('Chrome')) return 'Safari';
        if (userAgent.includes('Edg')) return 'Edge';
        if (userAgent.includes('Opera')) return 'Opera';
        
        return 'Unknown';
    }
    
    /**
     * Detecta a versão do navegador
     */
    getBrowserVersion() {
        const userAgent = navigator.userAgent;
        const browserName = this.getBrowserName();
        
        let version = 'Unknown';
        
        switch (browserName) {
            case 'Chrome':
                const chromeMatch = userAgent.match(/Chrome\/(\d+\.\d+)/);
                version = chromeMatch ? chromeMatch[1] : 'Unknown';
                break;
            case 'Firefox':
                const firefoxMatch = userAgent.match(/Firefox\/(\d+\.\d+)/);
                version = firefoxMatch ? firefoxMatch[1] : 'Unknown';
                break;
            case 'Safari':
                const safariMatch = userAgent.match(/Version\/(\d+\.\d+)/);
                version = safariMatch ? safariMatch[1] : 'Unknown';
                break;
            case 'Edge':
                const edgeMatch = userAgent.match(/Edg\/(\d+\.\d+)/);
                version = edgeMatch ? edgeMatch[1] : 'Unknown';
                break;
            case 'Opera':
                const operaMatch = userAgent.match(/Opera\/(\d+\.\d+)/);
                version = operaMatch ? operaMatch[1] : 'Unknown';
                break;
        }
        
        return version;
    }
    
    /**
     * Detecta o tipo de dispositivo
     */
    getDeviceType() {
        const userAgent = navigator.userAgent;
        
        if (/tablet|ipad|playbook|silk/i.test(userAgent)) {
            return 'tablet';
        }
        
        if (/mobile|iphone|ipod|android|blackberry|opera|mini|windows\sce|palm|smartphone|iemobile/i.test(userAgent)) {
            return 'mobile';
        }
        
        return 'desktop';
    }
    
    /**
     * Obtém informações do WebGL vendor
     */
    getWebGLVendor() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');

            if (gl) {
                // Parâmetros padrão WebGL (Firefox deprecou WEBGL_debug_renderer_info)
                return gl.getParameter(gl.VENDOR) || null;
            }
        } catch (e) {
            // Ignore errors
        }

        return null;
    }
    
    /**
     * Obtém informações do WebGL renderer
     */
    getWebGLRenderer() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');

            if (gl) {
                return gl.getParameter(gl.RENDERER) || null;
            }
        } catch (e) {
            // Ignore errors
        }

        return null;
    }
    
    /**
     * Gera fingerprint do canvas
     */
    async getCanvasFingerprint() {
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillText('Canvas fingerprint test 🔒', 2, 2);
            
            const dataURL = canvas.toDataURL();
            return await this.hashString(dataURL);
        } catch (e) {
            return null;
        }
    }
    
    /**
     * Gera fingerprint do áudio.
     * Deve ser chamado apenas após user gesture (Chrome autoplay policy).
     */
    async getAudioFingerprint() {
        try {
            const Ctor = window.AudioContext || window.webkitAudioContext;
            if (!Ctor) return null;
            const audioContext = new Ctor();
            const oscillator = audioContext.createOscillator();
            const analyser = audioContext.createAnalyser();
            const gainNode = audioContext.createGain();
            
            oscillator.type = 'triangle';
            oscillator.frequency.setValueAtTime(10000, audioContext.currentTime);
            
            gainNode.gain.setValueAtTime(0, audioContext.currentTime);
            
            oscillator.connect(analyser);
            analyser.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.start(0);
            
            const frequencyData = new Uint8Array(analyser.frequencyBinCount);
            analyser.getByteFrequencyData(frequencyData);
            
            oscillator.stop();
            audioContext.close();
            
            return await this.hashString(Array.from(frequencyData).join(','));
        } catch (e) {
            return null;
        }
    }
    
    /**
     * Obtém lista de plugins
     */
    getPluginsList() {
        const plugins = [];
        
        for (let i = 0; i < navigator.plugins.length; i++) {
            plugins.push(navigator.plugins[i].name);
        }
        
        return JSON.stringify(plugins.sort());
    }
    
    /**
     * Obtém lista de fontes disponíveis
     */
    async getFontsList() {
        const baseFonts = ['monospace', 'sans-serif', 'serif'];
        const testFonts = [
            'Arial', 'Helvetica', 'Times New Roman', 'Courier New', 'Verdana',
            'Georgia', 'Palatino', 'Garamond', 'Bookman', 'Comic Sans MS',
            'Trebuchet MS', 'Arial Black', 'Impact', 'Tahoma', 'Lucida Console'
        ];
        
        const availableFonts = [];
        
        for (const font of testFonts) {
            if (await this.isFontAvailable(font, baseFonts)) {
                availableFonts.push(font);
            }
        }
        
        return JSON.stringify(availableFonts.sort());
    }
    
    /**
     * Verifica se uma fonte está disponível
     */
    async isFontAvailable(font, baseFonts) {
        const testString = 'mmmmmmmmmmlli';
        const testSize = '72px';
        
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        
        context.font = testSize + ' ' + baseFonts[0];
        const baselineWidth = context.measureText(testString).width;
        
        context.font = testSize + ' ' + font + ', ' + baseFonts[0];
        const fontWidth = context.measureText(testString).width;
        
        return fontWidth !== baselineWidth;
    }
    
    /**
     * Gera hash de uma string
     */
    async hashString(str) {
        if (window.crypto && window.crypto.subtle) {
            const encoder = new TextEncoder();
            const data = encoder.encode(str);
            const hashBuffer = await window.crypto.subtle.digest('SHA-256', data);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        } else {
            // Fallback para navegadores sem crypto.subtle
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash; // Convert to 32-bit integer
            }
            return Math.abs(hash).toString(16);
        }
    }
    
    /**
     * Gera UUID v4
     */
    generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
    
    /**
     * Envia requisição para o backend
     */
    async sendRequest(url, data) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(data)
        });
        
        const text = await response.text();

        if (!response.ok) {
            const textPreview = text ? text.slice(0, 200) : '';
            this.error('HTTP error', response.status, textPreview);
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        try {
            return JSON.parse(text);
        } catch (e) {
            const textPreview = text ? text.slice(0, 200) : '';
            this.error('Invalid JSON from server', { url, preview: textPreview });
            throw e;
        }
    }
    
    /**
     * Log de debug
     */
    log(...args) {
        if (this.options.debug) {
            console.log('[AccessLogger]', ...args);
        }
    }
    
    /**
     * Log de erro
     */
    error(...args) {
        if (this.options.debug) {
            console.error('[AccessLogger]', ...args);
        }
    }
}



/**
 * Meelion - Classe para tracking de usuários e investimentos
 * Herda de AccessLogger para reutilizar todos os métodos de fingerprinting
 * 
 * Uso:
 * const meelion = new Meelion(123); // distributor_id obrigatório
 * const meelion = new Meelion(123, 'https://homolog.meelion.com/'); // com BASE_URL customizada
 * 
 * await meelion.newRegistration({name: 'João', email: 'joao@email.com', phone: '11999999999'}, {source: 'landing'});
 * await meelion.newInvestment({name: 'João', email: 'joao@email.com', phone: '11999999999'}, 'INV-123', {amount: 10000});
 */
class MeelionPixel extends AccessLogger {
    constructor(distributorId, baseUrl = 'https://www.meelion.com/') {
        // Inicializar AccessLogger com configurações específicas do Meelion
        super({
            debug: false,
            endpoint: null, // Não usar o endpoint padrão do AccessLogger
            autoStart: false // Não inicializar automaticamente
        });
        
        if (!distributorId) {
            throw new Error('distributor_id é obrigatório');
        }
        
        this.distributorId = distributorId;
        this.baseUrl = baseUrl.endsWith('/') ? baseUrl : baseUrl + '/';
        this.apiEndpoint = this.baseUrl + 'api/access-log/pixel';
        this.fingerprint = null;
        this.fingerprintPromise = null;
        
        // Valores válidos para activity_type (internos, não expostos)
        this.validActivityTypes = ['new_account', 'new_investment', 'returning_investment', 'other'];
        
        // Inicializar fingerprint automaticamente
        this._initializeFingerprint();
    }

    /**
     * Inicializa a geração do fingerprint de forma assíncrona
     * @private
     */
    _initializeFingerprint() {
        this.fingerprintPromise = this.generateFingerprint();
    }

    /**
     * Aguarda o fingerprint estar pronto
     * @private
     */
    async _ensureFingerprint() {
        if (!this.fingerprint) {
            this.fingerprint = await this.fingerprintPromise;
        }
        return this.fingerprint;
    }

    /**
     * Envia dados para o pixel do Meelion
     * @private
     */
    async _send(payload) {
        try {
            const response = await this.sendRequest(this.apiEndpoint, payload);
            return response;
        } catch (error) {
            console.error('Erro ao enviar dados para Meelion:', error);
            throw error;
        }
    }

    /**
     * Prepara payload base com fingerprint e metadados
     * @private
     */
    async _prepareBasePayload(activityType, combinedMetadata) {
        const fingerprint = await this._ensureFingerprint();
        
        return {
            distributor_id: this.distributorId,
            activity_type: activityType,
            fingerprint: fingerprint,
            metadata: combinedMetadata,
            timestamp: new Date().toISOString()
        };
    }

    /**
     * Combina userData com metadata adicional
     * @private
     */
    _combineMetadata(userData, metadata) {
        return { ...userData, ...metadata };
    }

    /**
     * Registra um novo usuário
     */
    async newRegistration(userData = {}, metadata = {}) {
        const combinedMetadata = this._combineMetadata(userData, metadata);
        const payload = await this._prepareBasePayload('new_account', combinedMetadata);
        return await this._send(payload);
    }

    /**
     * Registra um novo investimento
     */
    async newInvestment(userData = {}, investmentIdentifier='', metadata = {}) {
        
        const combinedMetadata = this._combineMetadata(userData, { ...metadata, investment_identifier: investmentIdentifier });
        const payload = await this._prepareBasePayload('new_investment', combinedMetadata);
        return await this._send(payload);
    }
}

// Auto-inicializaÃ§Ã£o se nÃ£o estiver em modo de desenvolvimento
if (typeof window !== 'undefined' && !window.accessLoggerManual) {
    document.addEventListener('DOMContentLoaded', () => {
        window.accessLogger = new AccessLogger({
            debug: window.location.hostname === 'localhost' || window.location.hostname.includes('dev')
        });
    });
}

// Exporta para uso manual
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AccessLogger;
} else if (typeof window !== 'undefined') {
    window.AccessLogger = AccessLogger;
    window.MeelionPixel = MeelionPixel;
}

/**
 * HOTFIX TEMPORARIO (isolado) - Widget de feedback sem depender de site.php/meelion_site.php.
 * - Nao altera classes/funcoes do AccessLogger.
 * - Nao depende de Vue.
 * - Injeta CSS + HTML + submit AJAX do widget.
 */
(function meelionFeedbackWidgetBootstrap() {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    if (window.__mwFbWidgetHotfixEnabled !== true) return;
    if (window.__meelionFeedbackWidgetBootstrapped) return;
    window.__meelionFeedbackWidgetBootstrapped = true;

    var CSS_URL = '/newds/css/components/feedback-widget.css';
    var FEEDBACK_URL = '/feedback/';

    function mwFbWidget_ensureCss() {
        if (document.querySelector('link[data-meelion-feedback-widget-css="1"]')) return;
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = CSS_URL;
        link.setAttribute('data-meelion-feedback-widget-css', '1');
        document.head.appendChild(link);
    }

    function mwFbWidget_ensureHtml() {
        if (document.getElementById('feedbackWidgetForm')) return;

        var root = document.createElement('div');
        root.className = 'feedback-widget';
        root.setAttribute('role', 'complementary');
        root.setAttribute('aria-label', 'Feedback do site');
        root.innerHTML = ''
            + '<button type="button" class="feedback-widget__launcher" id="feedbackWidgetOpenBtn" aria-label="Abrir Dúvidas e Sugestões" aria-expanded="false" aria-controls="feedbackWidgetPanel">'
            + '  <svg class="feedback-widget__launcher-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 8.5-8.5 8.38 8.38 0 0 1 3.8.9 8.5 8.5 0 0 1 4.7 7.6z"/>'
            + '  </svg>'
            + '</button>'
            + '<div class="feedback-widget__overlay" id="feedbackWidgetOverlay" aria-hidden="true">'
            + '  <div class="feedback-widget__backdrop" data-feedback-widget-close tabindex="-1" aria-hidden="true"></div>'
            + '  <div class="feedback-widget__panel" id="feedbackWidgetPanel" role="dialog" aria-modal="true" aria-labelledby="feedbackWidgetTitle">'
            + '    <div class="feedback-widget__panel-inner">'
            + '      <header class="feedback-widget__header">'
            + '        <div class="feedback-widget__head-text">'
            + '          <h2 class="feedback-widget__title" id="feedbackWidgetTitle">Quer nos ajudar?</h2>'
            + '          <p class="feedback-widget__subtitle">Envie sua dúvida ou sugestão sobre o site.</p>'
            + '        </div>'
            + '        <button type="button" class="feedback-widget__close" data-feedback-widget-close aria-label="Fechar"></button>'
            + '      </header>'
            + '      <div class="feedback-widget__body">'
            + '        <div id="feedbackWidgetMessage" class="feedback-widget__message" role="status" hidden></div>'
            + '        <form method="post" action="/feedback/" id="feedbackWidgetForm" novalidate data-no-loading="true">'
            + '          <div class="feedback-widget__field">'
            + '            <label for="feedbackNome" class="feedback-widget__label">Nome</label>'
            + '            <input id="feedbackNome" name="nome" type="text" class="feedback-widget__input" maxlength="120" required>'
            + '          </div>'
            + '          <div class="feedback-widget__field">'
            + '            <label for="feedbackEmail" class="feedback-widget__label">Email</label>'
            + '            <input id="feedbackEmail" name="email" type="email" class="feedback-widget__input" maxlength="180" required>'
            + '          </div>'
            + '          <div class="feedback-widget__field">'
            + '            <fieldset class="feedback-widget__fieldset">'
            + '              <legend class="feedback-widget__legend">O que você quer enviar?</legend>'
            + '              <div class="feedback-widget__radios">'
            + '                <label class="feedback-widget__radio-label">'
            + '                  <input type="radio" name="tipo" value="duvida" id="feedbackTipoDuvida" class="feedback-widget__radio" checked required>'
            + '                  <span>Dúvida</span>'
            + '                </label>'
            + '                <label class="feedback-widget__radio-label">'
            + '                  <input type="radio" name="tipo" value="sugestao" id="feedbackTipoSugestao" class="feedback-widget__radio">'
            + '                  <span>Sugestão</span>'
            + '                </label>'
            + '              </div>'
            + '            </fieldset>'
            + '          </div>'
            + '          <div class="feedback-widget__field">'
            + '            <label for="feedbackMensagem" class="feedback-widget__label">Mensagem</label>'
            + '            <textarea id="feedbackMensagem" name="mensagem" class="feedback-widget__textarea" rows="4" minlength="10" maxlength="5000" required></textarea>'
            + '          </div>'
            + '          <div class="feedback-widget__actions">'
            + '            <button type="submit" class="feedback-widget__submit">Enviar</button>'
            + '          </div>'
            + '        </form>'
            + '      </div>'
            + '    </div>'
            + '  </div>'
            + '</div>';

        document.body.appendChild(root);
    }

    function mwFbWidget_bindUi() {
        if (window.__meelionFeedbackWidgetUIBound) return;
        window.__meelionFeedbackWidgetUIBound = true;

        var overlay = document.getElementById('feedbackWidgetOverlay');
        var launcher = document.getElementById('feedbackWidgetOpenBtn');
        if (!overlay || !launcher) return;

        var mwFbPrevBodyOverflow = '';

        function setOpen(open) {
            if (open) {
                overlay.classList.add('is-active');
                overlay.setAttribute('aria-hidden', 'false');
                launcher.setAttribute('aria-expanded', 'true');
                document.documentElement.classList.add('feedback-widget-open');
                mwFbPrevBodyOverflow = document.body.style.overflow;
                document.body.style.overflow = 'hidden';
                var nome = document.getElementById('feedbackNome');
                if (nome) {
                    window.setTimeout(function () {
                        try { nome.focus(); } catch (e) {}
                    }, 10);
                }
            } else {
                overlay.classList.remove('is-active');
                overlay.setAttribute('aria-hidden', 'true');
                launcher.setAttribute('aria-expanded', 'false');
                document.documentElement.classList.remove('feedback-widget-open');
                document.body.style.overflow = mwFbPrevBodyOverflow;
                try { launcher.focus(); } catch (e2) {}
            }
        }

        launcher.addEventListener('click', function () {
            setOpen(!overlay.classList.contains('is-active'));
        });

        overlay.addEventListener('click', function (e) {
            var t = e.target;
            if (t && t.closest && t.closest('[data-feedback-widget-close]')) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-active')) {
                e.preventDefault();
                setOpen(false);
            }
        });
    }

    function mwFbWidget_isValidEmail(value) {
        var email = (value || '').trim();
        if (!email) return false;
        return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
    }

    function mwFbWidget_setMessage(form, type, text) {
        var box = document.getElementById('feedbackWidgetMessage')
            || (form && form.closest('.feedback-widget') && form.closest('.feedback-widget').querySelector('#feedbackWidgetMessage'));
        if (!box) return;
        box.classList.remove('feedback-widget__message--success', 'feedback-widget__message--error');
        box.classList.add(type === 'success' ? 'feedback-widget__message--success' : 'feedback-widget__message--error');
        box.textContent = text || '';
        box.hidden = !text;
    }

    function mwFbWidget_getCsrfToken(form) {
        var input = form ? form.querySelector('input[name="_csrfToken"]') : null;
        if (input && input.value) return input.value;
        var meta = document.querySelector('meta[name="csrfToken"]');
        return meta && meta.content ? meta.content : '';
    }

    function mwFbWidget_bindSubmitHandler() {
        if (window.__meelionFeedbackWidgetSubmitBound) return;
        window.__meelionFeedbackWidgetSubmitBound = true;

        document.addEventListener('submit', async function (e) {
            var form = e.target;
            if (!form || form.id !== 'feedbackWidgetForm') return;
            e.preventDefault();

            var submitBtn = form.querySelector('button[type="submit"]');
            var nome = (form.querySelector('#feedbackNome') || {}).value || '';
            var email = (form.querySelector('#feedbackEmail') || {}).value || '';
            var tipoRadio = form.querySelector('input[name="tipo"]:checked');
            var tipo = (tipoRadio && tipoRadio.value) ? tipoRadio.value : '';
            var mensagem = (form.querySelector('#feedbackMensagem') || {}).value || '';

            nome = nome.trim();
            email = email.trim();
            mensagem = mensagem.trim();

            if (!nome) { mwFbWidget_setMessage(form, 'error', 'Por favor, informe seu nome.'); return; }
            if (!email) { mwFbWidget_setMessage(form, 'error', 'Por favor, informe seu email.'); return; }
            if (!mwFbWidget_isValidEmail(email)) { mwFbWidget_setMessage(form, 'error', 'Por favor, insira um email válido.'); return; }
            if (!tipo || (tipo !== 'duvida' && tipo !== 'sugestao')) {
                mwFbWidget_setMessage(form, 'error', 'Por favor, selecione se é Dúvida ou Sugestão.');
                var tr = form.querySelector('#feedbackTipoDuvida');
                if (tr) tr.focus();
                return;
            }
            if (!mensagem) { mwFbWidget_setMessage(form, 'error', 'Por favor, escreva sua mensagem.'); return; }
            if (mensagem.length < 10) { mwFbWidget_setMessage(form, 'error', 'Escreva um pouco mais (mínimo de 10 caracteres).'); return; }

            var csrfToken = mwFbWidget_getCsrfToken(form);
            var payload = new URLSearchParams({
                nome: nome,
                email: email,
                tipo: tipo,
                mensagem: mensagem
            });
            if (csrfToken) payload.append('_csrfToken', csrfToken);

            try {
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Enviando...';
                }

                var headers = {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                };
                if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

                var response = await fetch(FEEDBACK_URL, {
                    method: 'POST',
                    headers: headers,
                    credentials: 'same-origin',
                    body: payload.toString()
                });

                var data = null;
                try { data = await response.json(); } catch (parseErr) { data = null; }
                if (!response.ok || !data || data.success !== true) {
                    mwFbWidget_setMessage(form, 'error', (data && data.message) || 'Não foi possível enviar seu feedback. Tente novamente.');
                    return;
                }
                mwFbWidget_setMessage(form, 'success', data.message || 'Obrigado!');
            } catch (err) {
                mwFbWidget_setMessage(form, 'error', 'Erro de conexão. Tente novamente.');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Enviar';
                }
            }
        }, true);
    }

    function mwFbWidget_init() {
        mwFbWidget_ensureCss();
        mwFbWidget_ensureHtml();
        mwFbWidget_bindUi();
        mwFbWidget_bindSubmitHandler();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mwFbWidget_init, { once: true });
    } else {
        mwFbWidget_init();
    }
})();




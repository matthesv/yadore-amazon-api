/**
 * Yadore Interaktive Produktsuche
 * Mit Initial-Produkten, Reset-Funktion und erweiterten Features
 * 
 * @package Yadore_Amazon_API
 * @since 1.6.3
 * 
 * Features:
 * - Live-Suche mit Debouncing
 * - Initial-Produkte
 * - Reset-Funktion
 * - Bild-Fehlerbehandlung mit Fallback-Kette
 * - Lazy-Loading für Bilder
 * - Offline-/Netzwerkfehler-Behandlung
 * - Pagination (Load More)
 * - Touch-Events für Mobile
 * - Keyboard-Navigation
 */

(function($) {
    'use strict';

    class YadoreProductSearch {
        constructor(container) {
            this.$container = $(container);
            this.$form = this.$container.find('.yadore-search-form');
            this.$input = this.$container.find('.yadore-search-input');
            this.$button = this.$container.find('.yadore-search-button');
            this.$buttonText = this.$container.find('.yadore-search-button-text');
            this.$spinner = this.$container.find('.yadore-search-spinner');
            this.$status = this.$container.find('.yadore-search-status');
            
            // Initial-Produkte Container
            this.$initialProducts = this.$container.find('.yadore-initial-products');
            
            // Such-Ergebnisse Container
            this.$results = this.$container.find('.yadore-search-results');
            this.$resultsGrid = this.$results.find('.yadore-search-results-grid');
            this.$resultsCount = this.$container.find('.yadore-search-results-count');
            this.$resultsQuery = this.$container.find('.yadore-search-results-query');
            this.$resetButton = this.$container.find('.yadore-reset-button');

            // Einstellungen aus data-Attributen
            this.settings = {
                network: this.$container.data('network') || '',
                maxResults: this.$container.data('max-results') || 12,
                columns: this.$container.data('columns') || 3,
                minChars: this.$container.data('min-chars') || 3,
                showPrice: this.$container.data('show-price') === 1,
                showMerchant: this.$container.data('show-merchant') === 1,
                newTab: this.$container.data('new-tab') === 1,
                debounce: this.$container.data('debounce') || 500,
                liveSearch: this.$container.data('live-search') === 1,
                sort: this.$container.data('sort') || 'rel_desc',
                merchantFilter: this.$container.data('merchant-filter') || '',
                showInitial: this.$container.data('show-initial') === 1,
                showReset: this.$container.data('show-reset') === 1,
                hasInitial: this.$container.data('has-initial') === 1,
                // NEU: Pagination
                resultsPerPage: this.$container.data('results-per-page') || 12,
                enablePagination: this.$container.data('enable-pagination') !== 0,
            };

            // State
            this.debounceTimer = null;
            this.currentRequest = null;
            this.lastQuery = '';
            this.isShowingResults = false;
            this.isOnline = navigator.onLine;
            this.retryCount = 0;
            this.maxRetries = 3;
            
            // Pagination State
            this.currentPage = 1;
            this.totalResults = 0;
            this.allProducts = [];
            this.displayedProducts = [];
            
            // Touch State
            this.touchStartX = 0;
            this.touchStartY = 0;
            this.isSwiping = false;

            // Lazy Loading Observer
            this.imageObserver = null;

            this.init();
        }

        init() {
            // Form Submit
            this.$form.on('submit', (e) => {
                e.preventDefault();
                this.search();
            });

            // Live-Suche
            if (this.settings.liveSearch) {
                this.$input.on('input', () => {
                    clearTimeout(this.debounceTimer);
                    
                    const query = this.$input.val().trim();
                    
                    // Bei leerem Input zurück zu Initial-Produkten
                    if (query.length === 0 && this.isShowingResults) {
                        this.resetToInitial();
                        return;
                    }
                    
                    this.debounceTimer = setTimeout(() => {
                        this.search();
                    }, this.settings.debounce);
                });
            }

            // Enter-Taste
            this.$input.on('keypress', (e) => {
                if (e.which === 13) {
                    e.preventDefault();
                    clearTimeout(this.debounceTimer);
                    this.search();
                }
            });

            // Reset-Button
            this.$resetButton.on('click', () => {
                this.resetToInitial();
            });

            // ESC-Taste zum Zurücksetzen
            this.$input.on('keydown', (e) => {
                if (e.which === 27 && this.isShowingResults) { // ESC
                    this.resetToInitial();
                }
            });

            // Keyboard Navigation für Ergebnisse
            this.initKeyboardNavigation();

            // Online/Offline Events
            this.initNetworkHandling();

            // Lazy Loading initialisieren
            this.initLazyLoading();

            // Touch Events initialisieren
            this.initTouchEvents();

            // Bild-Fehlerbehandlung für Initial-Produkte
            this.initImageErrorHandling(this.$initialProducts);
        }

        // =========================================
        // NETZWERK-FEHLERBEHANDLUNG
        // =========================================

        initNetworkHandling() {
            // Online/Offline Status überwachen
            window.addEventListener('online', () => {
                this.isOnline = true;
                this.hideOfflineMessage();
                
                // Automatisch letzte Suche wiederholen wenn offline war
                if (this.lastQuery && this.retryCount > 0) {
                    this.search();
                }
            });

            window.addEventListener('offline', () => {
                this.isOnline = false;
                this.showOfflineMessage();
            });

            // Initial Status prüfen
            if (!navigator.onLine) {
                this.isOnline = false;
                this.showOfflineMessage();
            }
        }

        showOfflineMessage() {
            const offlineHtml = `
                <div class="yadore-offline-message">
                    <span class="yadore-offline-icon">📡</span>
                    <span>${yadoreSearch.i18n.offline || 'Keine Internetverbindung'}</span>
                </div>
            `;
            
            if (!this.$container.find('.yadore-offline-message').length) {
                this.$container.prepend(offlineHtml);
            }
        }

        hideOfflineMessage() {
            this.$container.find('.yadore-offline-message').fadeOut(300, function() {
                $(this).remove();
            });
        }

        handleNetworkError(xhr, status) {
            this.retryCount++;
            
            if (status === 'timeout' || status === 'error') {
                if (this.retryCount <= this.maxRetries && this.isOnline) {
                    // Automatischer Retry mit exponential backoff
                    const delay = Math.min(1000 * Math.pow(2, this.retryCount - 1), 10000);
                    
                    this.showStatus(
                        (yadoreSearch.i18n.retrying || 'Verbindungsfehler. Neuer Versuch in {s} Sekunden...').replace('{s}', Math.ceil(delay / 1000)),
                        'info'
                    );
                    
                    setTimeout(() => {
                        this.search(true); // true = isRetry
                    }, delay);
                    
                    return true; // Retry wird durchgeführt
                }
            }
            
            // Max Retries erreicht oder anderer Fehler
            this.retryCount = 0;
            return false;
        }

        // =========================================
        // SUCHE
        // =========================================

        search(isRetry = false) {
            const query = this.$input.val().trim();

            // Offline-Check
            if (!this.isOnline) {
                this.showStatus(
                    yadoreSearch.i18n.offline || 'Keine Internetverbindung. Bitte später erneut versuchen.',
                    'error'
                );
                return;
            }

            // Validierung
            if (query.length < this.settings.minChars) {
                if (query.length > 0) {
                    this.showStatus(
                        yadoreSearch.i18n.min_chars.replace('%d', this.settings.minChars),
                        'info'
                    );
                } else if (this.isShowingResults) {
                    this.resetToInitial();
                }
                return;
            }

            // Gleiche Suche nicht wiederholen (außer bei Retry)
            if (query === this.lastQuery && this.isShowingResults && !isRetry) {
                return;
            }
            
            if (!isRetry) {
                this.lastQuery = query;
                this.retryCount = 0;
                this.currentPage = 1;
                this.allProducts = [];
            }

            // Vorherige Anfrage abbrechen
            if (this.currentRequest) {
                this.currentRequest.abort();
            }

            this.setLoading(true);
            this.showStatus(yadoreSearch.i18n.searching, 'loading');

            this.currentRequest = $.ajax({
                url: yadoreSearch.ajaxurl,
                type: 'POST',
                timeout: 30000, // 30 Sekunden Timeout
                data: {
                    action: 'yadore_product_search',
                    nonce: yadoreSearch.nonce,
                    query: query,
                    network: this.settings.network,
                    max_results: this.settings.maxResults,
                    sort: this.settings.sort,
                    merchant_filter: this.settings.merchantFilter,
                    show_price: this.settings.showPrice ? '1' : '0',
                    show_merchant: this.settings.showMerchant ? '1' : '0',
                    new_tab: this.settings.newTab ? '1' : '0',
                },
                success: (response) => {
                    this.setLoading(false);
                    this.retryCount = 0; // Reset bei Erfolg
                    
                    if (response.success && response.data.products.length > 0) {
                        this.allProducts = response.data.products;
                        this.totalResults = response.data.total;
                        this.renderResults(response.data);
                    } else {
                        this.showStatus(yadoreSearch.i18n.no_results, 'empty');
                        // Initial-Produkte ausblenden, leere Ergebnisse zeigen
                        this.$initialProducts.slideUp(200);
                        this.$results.hide();
                        this.isShowingResults = true;
                    }
                },
                error: (xhr, status) => {
                    this.setLoading(false);
                    
                    if (status !== 'abort') {
                        // Retry-Logik
                        if (!this.handleNetworkError(xhr, status)) {
                            this.showStatus(yadoreSearch.i18n.error, 'error');
                        }
                    }
                }
            });
        }

        setLoading(isLoading) {
            this.$button.prop('disabled', isLoading);
            this.$buttonText.toggle(!isLoading);
            this.$spinner.toggle(isLoading);
            this.$container.toggleClass('yadore-loading', isLoading);
        }

        showStatus(message, type) {
            this.$status
                .removeClass('yadore-status-loading yadore-status-error yadore-status-empty yadore-status-info')
                .addClass('yadore-status-' + type)
                .html(message)
                .show();
        }

        // =========================================
        // ERGEBNISSE RENDERN
        // =========================================

        renderResults(data) {
            this.$status.hide();
            this.isShowingResults = true;
            
            // Initial-Produkte ausblenden
            this.$initialProducts.slideUp(200);
            
            // Header aktualisieren
            this.$resultsCount.text(data.total + ' Ergebnis' + (data.total !== 1 ? 'se' : ''));
            this.$resultsQuery.text(yadoreSearch.i18n.results_for + ' "' + data.query + '"');

            // Grid leeren und neu befüllen
            this.$resultsGrid.empty();

            // Pagination: Nur erste Seite anzeigen
            const productsToShow = this.settings.enablePagination 
                ? data.products.slice(0, this.settings.resultsPerPage)
                : data.products;
            
            this.displayedProducts = productsToShow;

            productsToShow.forEach((product, index) => {
                const $card = $(this.createProductCard(product));
                $card.css('animation-delay', (index * 0.05) + 's');
                this.$resultsGrid.append($card);
            });

            // Pagination Button hinzufügen wenn nötig
            if (this.settings.enablePagination && data.products.length > this.settings.resultsPerPage) {
                this.renderLoadMoreButton();
            }

            // Reset-Button nur anzeigen wenn Initial-Produkte vorhanden
            if (this.settings.hasInitial && this.settings.showReset) {
                this.$resetButton.show();
            }

            this.$results.slideDown(300, () => {
                // Nach dem Einblenden: Bild-Fehlerbehandlung und Lazy Loading
                this.initImageErrorHandling(this.$results);
                this.observeImages(this.$resultsGrid);
            });

            // Scroll zu Ergebnissen
            const containerTop = this.$container.offset().top;
            if (containerTop < $(window).scrollTop() || containerTop > $(window).scrollTop() + $(window).height()) {
                $('html, body').animate({
                    scrollTop: containerTop - 20
                }, 300);
            }
        }

        // =========================================
        // PAGINATION
        // =========================================

        renderLoadMoreButton() {
            // Bestehenden Button entfernen
            this.$container.find('.yadore-load-more-wrapper').remove();
            
            const remaining = this.allProducts.length - this.displayedProducts.length;
            
            if (remaining <= 0) {
                return;
            }

            const buttonHtml = `
                <div class="yadore-load-more-wrapper">
                    <button type="button" class="yadore-load-more-button">
                        <span class="yadore-load-more-text">
                            ${(yadoreSearch.i18n.load_more || 'Mehr laden')} (${remaining})
                        </span>
                        <span class="yadore-load-more-spinner" style="display: none;">
                            <svg class="yadore-spinner-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round" stroke-dasharray="31.4 31.4">
                                    <animateTransform attributeName="transform" type="rotate" dur="1s" from="0 12 12" to="360 12 12" repeatCount="indefinite"/>
                                </circle>
                            </svg>
                        </span>
                    </button>
                    <div class="yadore-pagination-info">
                        ${this.displayedProducts.length} / ${this.allProducts.length} ${yadoreSearch.i18n.products || 'Produkte'}
                    </div>
                </div>
            `;

            this.$results.append(buttonHtml);

            // Event Handler für Load More
            this.$container.find('.yadore-load-more-button').on('click', () => {
                this.loadMoreProducts();
            });
        }

        loadMoreProducts() {
            const $button = this.$container.find('.yadore-load-more-button');
            const $text = $button.find('.yadore-load-more-text');
            const $spinner = $button.find('.yadore-load-more-spinner');
            
            $button.prop('disabled', true);
            $text.hide();
            $spinner.show();

            // Nächste Seite laden
            const startIndex = this.displayedProducts.length;
            const endIndex = Math.min(startIndex + this.settings.resultsPerPage, this.allProducts.length);
            const newProducts = this.allProducts.slice(startIndex, endIndex);

            // Kleine Verzögerung für bessere UX
            setTimeout(() => {
                newProducts.forEach((product, index) => {
                    const $card = $(this.createProductCard(product));
                    $card.css('animation-delay', (index * 0.05) + 's');
                    this.$resultsGrid.append($card);
                    this.displayedProducts.push(product);
                });

                // Bild-Fehlerbehandlung für neue Karten
                this.initImageErrorHandling(this.$resultsGrid);
                this.observeImages(this.$resultsGrid);

                // Button aktualisieren oder entfernen
                $button.prop('disabled', false);
                $text.show();
                $spinner.hide();

                if (this.displayedProducts.length >= this.allProducts.length) {
                    this.$container.find('.yadore-load-more-wrapper').fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    this.renderLoadMoreButton();
                }
            }, 300);
        }

        // =========================================
        // RESET
        // =========================================

        resetToInitial() {
            this.lastQuery = '';
            this.isShowingResults = false;
            this.currentPage = 1;
            this.allProducts = [];
            this.displayedProducts = [];
            this.$input.val('');
            this.$status.hide();
            
            // Pagination-Elemente entfernen
            this.$container.find('.yadore-load-more-wrapper').remove();
            
            // Such-Ergebnisse ausblenden
            this.$results.slideUp(200, () => {
                this.$resultsGrid.empty();
            });
            
            // Initial-Produkte wieder einblenden
            if (this.settings.hasInitial) {
                this.$initialProducts.slideDown(300);
            }

            // Focus zurück auf Input
            this.$input.focus();
        }

        // =========================================
        // PRODUKT-KARTE ERSTELLEN
        // =========================================

        createProductCard(product) {
            const target = product.new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
            
            let imageHtml = '';
            if (product.image) {
                // Lazy Loading Attribute für späteres Laden
                imageHtml = `
                    <div class="yadore-product-image">
                        <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                             data-src="${this.escapeHtml(product.image)}" 
                             alt="${this.escapeHtml(product.title)}" 
                             class="yadore-lazy-image"
                             data-fallback-attempted="false"
                             data-product-id="${this.escapeHtml(product.id || '')}">
                        <div class="yadore-image-loader"></div>
                    </div>
                `;
            } else {
                imageHtml = `
                    <div class="yadore-product-image yadore-no-image">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                        </svg>
                    </div>
                `;
            }

            let merchantHtml = '';
            if (product.merchant) {
                merchantHtml = `<div class="yadore-product-merchant">${this.escapeHtml(product.merchant)}</div>`;
            }

            let priceHtml = '';
            if (product.price) {
                priceHtml = `<div class="yadore-product-price">${this.escapeHtml(product.price)}</div>`;
            }

            // Source Badge
            let sourceBadge = '';
            if (product.source) {
                sourceBadge = `<span class="yadore-product-source yadore-source-${this.escapeHtml(product.source)}"></span>`;
            }

            return `
                <div class="yadore-product-card" data-product-id="${this.escapeHtml(product.id)}" tabindex="0">
                    <a href="${this.escapeHtml(product.url)}"${target} class="yadore-product-link">
                        ${sourceBadge}
                        ${imageHtml}
                        <div class="yadore-product-content">
                            <h4 class="yadore-product-title">${this.escapeHtml(product.title)}</h4>
                            ${merchantHtml}
                            ${priceHtml}
                            <span class="yadore-product-cta">${yadoreSearch.i18n.view_offer} →</span>
                        </div>
                    </a>
                </div>
            `;
        }

        // =========================================
        // LAZY LOADING
        // =========================================

        initLazyLoading() {
            // IntersectionObserver für Lazy Loading
            if ('IntersectionObserver' in window) {
                this.imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            this.loadImage(img);
                            observer.unobserve(img);
                        }
                    });
                }, {
                    rootMargin: '100px 0px', // 100px vor dem Viewport laden
                    threshold: 0.01
                });
            }

            // Initial-Bilder observieren
            this.observeImages(this.$initialProducts);
        }

        observeImages($container) {
            if (!this.imageObserver) {
                // Fallback: Alle Bilder sofort laden
                $container.find('.yadore-lazy-image').each((i, img) => {
                    this.loadImage(img);
                });
                return;
            }

            $container.find('.yadore-lazy-image').each((i, img) => {
                if (!img.dataset.observed) {
                    img.dataset.observed = 'true';
                    this.imageObserver.observe(img);
                }
            });
        }

        loadImage(img) {
            const src = img.dataset.src;
            if (!src) return;

            const $img = $(img);
            const $wrapper = $img.closest('.yadore-product-image');

            // Loading-Indikator anzeigen
            $wrapper.addClass('yadore-image-loading');

            // Bild laden
            const tempImg = new Image();
            
            tempImg.onload = () => {
                $img.attr('src', src);
                $img.addClass('yadore-img-loaded');
                $wrapper.removeClass('yadore-image-loading');
                $wrapper.find('.yadore-image-loader').remove();
            };

            tempImg.onerror = () => {
                // Fallback-Kette starten
                this.handleImageError(img);
            };

            tempImg.src = src;
        }

        // =========================================
        // BILD-FEHLERBEHANDLUNG
        // =========================================

        initImageErrorHandling($container) {
            $container.find('.yadore-product-image img').each((i, img) => {
                // Nur wenn noch nicht behandelt
                if (img.dataset.errorHandlerAttached) return;
                img.dataset.errorHandlerAttached = 'true';

                $(img).on('error', () => {
                    this.handleImageError(img);
                });

                // Prüfen ob Bild bereits geladen und fehlgeschlagen
                if (img.complete && img.naturalWidth === 0 && img.src && !img.src.startsWith('data:')) {
                    this.handleImageError(img);
                }
            });
        }

        handleImageError(img) {
            const $img = $(img);
            const $wrapper = $img.closest('.yadore-product-image');
            const fallbackAttempted = img.dataset.fallbackAttempted || 'false';

            // Fallback-Kette
            if (fallbackAttempted === 'false') {
                // Versuch 1: Proxy-URL wenn verfügbar
                const originalSrc = img.dataset.src || img.src;
                
                if (typeof window.yaaProxy !== 'undefined' && window.yaaProxy.enabled) {
                    img.dataset.fallbackAttempted = 'proxy';
                    const proxyUrl = window.yaaProxy.endpoint + '?action=' + window.yaaProxy.action + '&url=' + encodeURIComponent(originalSrc);
                    $img.attr('src', proxyUrl);
                    return;
                }
                
                img.dataset.fallbackAttempted = 'placeholder';
            }

            if (fallbackAttempted === 'proxy') {
                img.dataset.fallbackAttempted = 'placeholder';
            }

            // Endgültiger Fallback: Placeholder anzeigen
            if (img.dataset.fallbackAttempted === 'placeholder' || fallbackAttempted === 'placeholder') {
                img.dataset.fallbackAttempted = 'complete';
                $wrapper.addClass('yadore-image-error');
                $wrapper.removeClass('yadore-image-loading');
                
                $img.hide();
                
                // Placeholder einfügen wenn nicht vorhanden
                if (!$wrapper.find('.yadore-placeholder-icon').length) {
                    $wrapper.append(`
                        <div class="yadore-placeholder-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                            </svg>
                        </div>
                    `);
                }
            }
        }

        // =========================================
        // TOUCH EVENTS
        // =========================================

        initTouchEvents() {
            // Touch-Feedback für Produkt-Karten
            this.$container.on('touchstart', '.yadore-product-card', (e) => {
                const $card = $(e.currentTarget);
                $card.addClass('yadore-touch-active');
                
                // Touch-Position speichern für Swipe-Detection
                const touch = e.originalEvent.touches[0];
                this.touchStartX = touch.clientX;
                this.touchStartY = touch.clientY;
                this.isSwiping = false;
            });

            this.$container.on('touchmove', '.yadore-product-card', (e) => {
                if (!this.touchStartX) return;
                
                const touch = e.originalEvent.touches[0];
                const diffX = Math.abs(touch.clientX - this.touchStartX);
                const diffY = Math.abs(touch.clientY - this.touchStartY);
                
                // Wenn mehr horizontal als vertikal bewegt wird, ist es ein Swipe
                if (diffX > 10 || diffY > 10) {
                    this.isSwiping = true;
                    $(e.currentTarget).removeClass('yadore-touch-active');
                }
            });

            this.$container.on('touchend touchcancel', '.yadore-product-card', (e) => {
                const $card = $(e.currentTarget);
                
                // Verzögerte Entfernung für visuelles Feedback
                setTimeout(() => {
                    $card.removeClass('yadore-touch-active');
                }, 150);
                
                this.touchStartX = 0;
                this.touchStartY = 0;
            });

            // Pull-to-Refresh für Such-Ergebnisse (optional)
            let pullStartY = 0;
            let isPulling = false;

            this.$resultsGrid.on('touchstart', (e) => {
                if (this.$resultsGrid.scrollTop() === 0) {
                    pullStartY = e.originalEvent.touches[0].clientY;
                    isPulling = true;
                }
            });

            this.$resultsGrid.on('touchmove', (e) => {
                if (!isPulling) return;
                
                const currentY = e.originalEvent.touches[0].clientY;
                const diff = currentY - pullStartY;
                
                if (diff > 80 && this.$resultsGrid.scrollTop() === 0) {
                    // Pull-to-Refresh Indikator anzeigen
                    if (!this.$container.find('.yadore-pull-refresh').length) {
                        this.$results.prepend('<div class="yadore-pull-refresh">↻ Loslassen zum Aktualisieren</div>');
                    }
                }
            });

            this.$resultsGrid.on('touchend', (e) => {
                if (isPulling && this.$container.find('.yadore-pull-refresh').length) {
                    this.$container.find('.yadore-pull-refresh').remove();
                    // Suche erneut ausführen
                    this.search(true);
                }
                isPulling = false;
                pullStartY = 0;
            });

            // Swipe zum Zurücksetzen (links wischen)
            let swipeStartX = 0;
            
            this.$results.on('touchstart', (e) => {
                swipeStartX = e.originalEvent.touches[0].clientX;
            });

            this.$results.on('touchend', (e) => {
                if (!swipeStartX) return;
                
                const swipeEndX = e.originalEvent.changedTouches[0].clientX;
                const diff = swipeStartX - swipeEndX;
                
                // Rechts-nach-Links Swipe (min. 100px)
                if (diff > 100 && this.isShowingResults && this.settings.hasInitial) {
                    // Optional: Zurück zu Initial-Produkten
                    // this.resetToInitial();
                }
                
                swipeStartX = 0;
            });
        }

        // =========================================
        // KEYBOARD NAVIGATION
        // =========================================

        initKeyboardNavigation() {
            // Tab-Navigation durch Produkt-Karten
            this.$container.on('keydown', '.yadore-product-card', (e) => {
                const $card = $(e.currentTarget);
                const $cards = this.$container.find('.yadore-product-card:visible');
                const currentIndex = $cards.index($card);

                switch (e.which) {
                    case 13: // Enter
                    case 32: // Space
                        e.preventDefault();
                        $card.find('.yadore-product-link')[0].click();
                        break;
                        
                    case 37: // Left Arrow
                        e.preventDefault();
                        if (currentIndex > 0) {
                            $cards.eq(currentIndex - 1).focus();
                        }
                        break;
                        
                    case 38: // Up Arrow
                        e.preventDefault();
                        const colsUp = this.settings.columns;
                        if (currentIndex >= colsUp) {
                            $cards.eq(currentIndex - colsUp).focus();
                        } else {
                            this.$input.focus();
                        }
                        break;
                        
                    case 39: // Right Arrow
                        e.preventDefault();
                        if (currentIndex < $cards.length - 1) {
                            $cards.eq(currentIndex + 1).focus();
                        }
                        break;
                        
                    case 40: // Down Arrow
                        e.preventDefault();
                        const colsDown = this.settings.columns;
                        if (currentIndex + colsDown < $cards.length) {
                            $cards.eq(currentIndex + colsDown).focus();
                        }
                        break;
                }
            });

            // Vom Input zu den Ergebnissen navigieren
            this.$input.on('keydown', (e) => {
                if (e.which === 40) { // Down Arrow
                    e.preventDefault();
                    const $firstCard = this.$container.find('.yadore-product-card:visible').first();
                    if ($firstCard.length) {
                        $firstCard.focus();
                    }
                }
            });
        }

        // =========================================
        // HILFSFUNKTIONEN
        // =========================================

        escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Öffentliche API
        destroy() {
            // Event-Listener entfernen
            this.$form.off();
            this.$input.off();
            this.$resetButton.off();
            this.$container.off();
            
            // Observer stoppen
            if (this.imageObserver) {
                this.imageObserver.disconnect();
            }
            
            // Timer löschen
            clearTimeout(this.debounceTimer);
            
            // Request abbrechen
            if (this.currentRequest) {
                this.currentRequest.abort();
            }
        }

        refresh() {
            // Bilder neu laden
            this.observeImages(this.$container);
            this.initImageErrorHandling(this.$container);
        }

        getState() {
            return {
                query: this.lastQuery,
                isShowingResults: this.isShowingResults,
                totalResults: this.totalResults,
                displayedProducts: this.displayedProducts.length,
                isOnline: this.isOnline,
                currentPage: this.currentPage
            };
        }
    }

    // =========================================
    // ZUSÄTZLICHES CSS (inline eingefügt)
    // =========================================

    const additionalStyles = `
        <style>
            /* Offline-Nachricht */
            .yadore-offline-message {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 16px;
                background: #fef2f2;
                border: 1px solid #fecaca;
                border-radius: 8px;
                color: #991b1b;
                margin-bottom: 15px;
                animation: yadore-fade-in 0.3s ease;
            }
            
            .yadore-offline-icon {
                font-size: 1.2rem;
            }
            
            /* Image Loading */
            .yadore-product-image.yadore-image-loading .yadore-image-loader {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 30px;
                height: 30px;
                border: 3px solid #e5e7eb;
                border-top-color: var(--yadore-primary, #2563eb);
                border-radius: 50%;
                animation: yadore-spin 0.8s linear infinite;
            }
            
            @keyframes yadore-spin {
                to { transform: translate(-50%, -50%) rotate(360deg); }
            }
            
            /* Lazy Image Fade-In */
            .yadore-lazy-image {
                opacity: 0;
                transition: opacity 0.3s ease;
            }
            
            .yadore-lazy-image.yadore-img-loaded {
                opacity: 1;
            }
            
            /* Image Error Placeholder */
            .yadore-product-image.yadore-image-error {
                background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            }
            
            .yadore-placeholder-icon {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 48px;
                height: 48px;
                color: #9ca3af;
            }
            
            .yadore-placeholder-icon svg {
                width: 100%;
                height: 100%;
            }
            
            /* Touch Active State */
            .yadore-product-card.yadore-touch-active {
                transform: scale(0.98);
                opacity: 0.9;
            }
            
            /* Load More Button */
            .yadore-load-more-wrapper {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 10px;
                margin-top: 25px;
                padding: 20px;
            }
            
            .yadore-load-more-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 12px 24px;
                font-size: 1rem;
                font-weight: 600;
                color: var(--yadore-primary, #2563eb);
                background: transparent;
                border: 2px solid var(--yadore-primary, #2563eb);
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.2s ease;
                min-width: 180px;
            }
            
            .yadore-load-more-button:hover:not(:disabled) {
                background: var(--yadore-primary, #2563eb);
                color: #fff;
            }
            
            .yadore-load-more-button:disabled {
                opacity: 0.7;
                cursor: not-allowed;
            }
            
            .yadore-load-more-spinner .yadore-spinner-icon {
                width: 20px;
                height: 20px;
            }
            
            .yadore-pagination-info {
                font-size: 0.85rem;
                color: var(--yadore-text-muted, #6b7280);
            }
            
            /* Pull to Refresh */
            .yadore-pull-refresh {
                text-align: center;
                padding: 15px;
                color: var(--yadore-primary, #2563eb);
                font-weight: 600;
                animation: yadore-pulse 1s ease infinite;
            }
            
            @keyframes yadore-pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
            
            /* Focus States für Keyboard Navigation */
            .yadore-product-card:focus {
                outline: 3px solid var(--yadore-primary, #2563eb);
                outline-offset: 2px;
            }
            
            .yadore-product-card:focus-visible {
                outline: 3px solid var(--yadore-primary, #2563eb);
                outline-offset: 2px;
            }
            
            /* Dark Mode Anpassungen */
            @media (prefers-color-scheme: dark) {
                .yadore-offline-message {
                    background: #450a0a;
                    border-color: #991b1b;
                    color: #fca5a5;
                }
                
                .yadore-product-image.yadore-image-error {
                    background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
                }
                
                .yadore-placeholder-icon {
                    color: #4b5563;
                }
            }
        </style>
    `;

    // CSS einmalig zum DOM hinzufügen
    if (!document.getElementById('yadore-search-extra-styles')) {
        const styleContainer = document.createElement('div');
        styleContainer.id = 'yadore-search-extra-styles';
        styleContainer.innerHTML = additionalStyles;
        document.head.appendChild(styleContainer.querySelector('style'));
    }

    // =========================================
    // I18N DEFAULTS (falls nicht vom Server geladen)
    // =========================================

    if (typeof yadoreSearch === 'undefined') {
        window.yadoreSearch = {
            ajaxurl: '/wp-admin/admin-ajax.php',
            nonce: '',
            i18n: {}
        };
    }

    // Default-Übersetzungen
    yadoreSearch.i18n = Object.assign({
        searching: 'Suche läuft...',
        no_results: 'Keine Produkte gefunden.',
        error: 'Fehler bei der Suche. Bitte erneut versuchen.',
        min_chars: 'Bitte mindestens %d Zeichen eingeben.',
        view_offer: 'Zum Angebot',
        reset: '← Zurück zu Empfehlungen',
        results_for: 'Ergebnisse für',
        load_more: 'Mehr laden',
        products: 'Produkte',
        offline: 'Keine Internetverbindung',
        retrying: 'Verbindungsfehler. Neuer Versuch in {s} Sekunden...'
    }, yadoreSearch.i18n || {});

    // =========================================
    // INITIALISIERUNG
    // =========================================

    $(document).ready(function() {
        $('.yadore-search-container').each(function() {
            const instance = new YadoreProductSearch(this);
            
            // Instanz am Element speichern für externe Zugriffe
            $(this).data('yadoreSearch', instance);
        });
    });

    // Globale API exponieren
    window.YadoreProductSearch = YadoreProductSearch;

})(jQuery);

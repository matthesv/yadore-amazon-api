/**
 * Yadore Interaktive Produktsuche
 * Mit Initial-Produkten und Reset-Funktion
 * 
 * @package Yadore_Amazon_API
 * @since 1.6.0
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
                sort: this.$container.data('sort') || 'cpc_desc',
                merchantFilter: this.$container.data('merchant-filter') || '',
                showInitial: this.$container.data('show-initial') === 1,
                showReset: this.$container.data('show-reset') === 1,
                hasInitial: this.$container.data('has-initial') === 1,
            };

            this.debounceTimer = null;
            this.currentRequest = null;
            this.lastQuery = '';
            this.isShowingResults = false;

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
        }

        search() {
            const query = this.$input.val().trim();

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

            // Gleiche Suche nicht wiederholen
            if (query === this.lastQuery && this.isShowingResults) {
                return;
            }
            this.lastQuery = query;

            // Vorherige Anfrage abbrechen
            if (this.currentRequest) {
                this.currentRequest.abort();
            }

            this.setLoading(true);
            this.showStatus(yadoreSearch.i18n.searching, 'loading');

            this.currentRequest = $.ajax({
                url: yadoreSearch.ajaxurl,
                type: 'POST',
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
                    
                    if (response.success && response.data.products.length > 0) {
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
                        this.showStatus(yadoreSearch.i18n.error, 'error');
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

            data.products.forEach((product) => {
                this.$resultsGrid.append(this.createProductCard(product));
            });

            // Reset-Button nur anzeigen wenn Initial-Produkte vorhanden
            if (this.settings.hasInitial && this.settings.showReset) {
                this.$resetButton.show();
            }

            this.$results.slideDown(300);

            // Scroll zu Ergebnissen
            const containerTop = this.$container.offset().top;
            if (containerTop < $(window).scrollTop() || containerTop > $(window).scrollTop() + $(window).height()) {
                $('html, body').animate({
                    scrollTop: containerTop - 20
                }, 300);
            }
        }

        resetToInitial() {
            this.lastQuery = '';
            this.isShowingResults = false;
            this.$input.val('');
            this.$status.hide();
            
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

        createProductCard(product) {
            const target = product.new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
            
            let imageHtml = '';
            if (product.image) {
                imageHtml = `
                    <div class="yadore-product-image">
                        <img src="${this.escapeHtml(product.image)}" 
                             alt="${this.escapeHtml(product.title)}" 
                             loading="lazy"
                             onerror="this.parentElement.classList.add('yadore-image-error')">
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
                <div class="yadore-product-card" data-product-id="${this.escapeHtml(product.id)}">
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

        escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Initialisierung
    $(document).ready(function() {
        $('.yadore-search-container').each(function() {
            new YadoreProductSearch(this);
        });
    });

})(jQuery);

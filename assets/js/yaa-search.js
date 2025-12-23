/**
 * Yadore Amazon API - Product Search - UNIFIED VERSION
 * 
 * @package Yadore_Amazon_API
 * @since 1.0.0
 * @version 1.8.0 - Unified with PHP, consistent naming
 * 
 * IMPORTANT: This file must stay synchronized with class-search-shortcode.php
 * CSS classes use both 'yaa-' and 'yadore-' prefixes for compatibility.
 */

(function($) {
    'use strict';

    // =========================================
    // CONFIGURATION - MUST MATCH PHP
    // =========================================

    const CONFIG = {
        // CSS Selectors - dual prefixes for compatibility
        selectors: {
            container: '.yaa-search-wrapper, .yadore-search-container',
            form: '.yaa-search-form, .yadore-search-form',
            input: '.yaa-input, .yadore-input',
            submitBtn: '.yaa-submit-btn, .yadore-submit-btn',
            btnText: '.yaa-btn-text, .yadore-btn-text',
            btnSpinner: '.yaa-btn-spinner, .yadore-btn-spinner',
            clearBtn: '.yaa-clear-btn, .yadore-clear-btn',
            suggestions: '.yaa-suggestions, .yadore-suggestions',
            filters: '.yaa-filters, .yadore-filters',
            sortSelect: '.yaa-sort-select, .yadore-sort-select',
            sourceSelect: '.yaa-source-select, .yadore-source-select',
            primeCheckbox: '.yaa-prime-checkbox, .yadore-prime-checkbox',
            initialProducts: '.yaa-initial-products, .yadore-initial-products',
            searchResults: '.yaa-search-results, .yadore-search-results',
            productsGrid: '.yaa-products-grid, .yadore-products-grid',
            resultsCount: '.yaa-results-count, .yadore-results-count',
            resultsQuery: '.yaa-results-query, .yadore-results-query',
            resultsInfo: '.yaa-results-info, .yadore-results-info',
            resetBtn: '.yaa-reset-btn, .yadore-reset-btn',
            loadMore: '.yaa-load-more, .yadore-load-more',
            pagination: '.yaa-pagination, .yadore-pagination',
            paginationInfo: '.yaa-pagination-info, .yadore-pagination-info',
            loading: '.yaa-loading, .yadore-loading',
            status: '.yaa-status, .yadore-status',
            productCard: '.yaa-product-card, .yadore-product-card',
            productLink: '.yaa-product-link, .yadore-product-link',
            productBtn: '.yaa-product-btn, .yadore-product-btn',
            lazyImg: '.yaa-lazy-img, .yadore-lazy-img',
        },
        
        // Sort options - MUST MATCH PHP SORT_OPTIONS
        sortOptions: {
            'relevance': 'Relevanz',
            'price_asc': 'Preis aufsteigend',
            'price_desc': 'Preis absteigend',
            'title_asc': 'Name A-Z',
            'title_desc': 'Name Z-A',
            'rating_desc': 'Beste Bewertung',
            'newest': 'Neueste zuerst',
        },
        
        // Default values - MUST MATCH PHP DEFAULT_ATTS
        defaults: {
            minChars: 3,
            debounce: 500,
            perPage: 12,
            maxProducts: 100,
            target: '_blank',
            showPrice: true,
            showRating: true,
            showPrime: true,
            showAvailability: true,
            showDescription: false,
            showMerchant: true,
            descriptionLength: 150,
            nofollow: true,
            sponsored: true,
            lazyLoad: true,
            liveSearch: true,
            analytics: true,
        }
    };

    // =========================================
    // MAIN CLASS
    // =========================================

    class YAAProductSearch {
        constructor(container) {
            this.$container = $(container);
            this.cacheElements();
            this.loadSettings();
            this.initState();
            this.init();
        }

        // =========================================
        // INITIALIZATION
        // =========================================

        cacheElements() {
            const S = CONFIG.selectors;
            
            this.$form = this.$container.find(S.form);
            this.$input = this.$container.find(S.input);
            this.$submitBtn = this.$container.find(S.submitBtn);
            this.$btnText = this.$container.find(S.btnText);
            this.$btnSpinner = this.$container.find(S.btnSpinner);
            this.$clearBtn = this.$container.find(S.clearBtn);
            this.$suggestions = this.$container.find(S.suggestions);
            this.$sortSelect = this.$container.find(S.sortSelect);
            this.$sourceSelect = this.$container.find(S.sourceSelect);
            this.$primeCheckbox = this.$container.find(S.primeCheckbox);
            this.$initialProducts = this.$container.find(S.initialProducts);
            this.$searchResults = this.$container.find(S.searchResults);
            this.$productsGrid = this.$searchResults.find(S.productsGrid);
            this.$resultsCount = this.$container.find(S.resultsCount);
            this.$resultsQuery = this.$container.find(S.resultsQuery);
            this.$resultsInfo = this.$container.find(S.resultsInfo);
            this.$resetBtn = this.$container.find(S.resetBtn);
            this.$loadMore = this.$container.find(S.loadMore);
            this.$pagination = this.$container.find(S.pagination);
            this.$paginationInfo = this.$container.find(S.paginationInfo);
            this.$loading = this.$container.find(S.loading);
            this.$status = this.$container.find(S.status);
        }

        /**
         * Load settings from data attributes
         * IMPORTANT: Parse integers properly for boolean comparison
         */
        loadSettings() {
            const data = this.$container.data();
            const D = CONFIG.defaults;
            
            this.settings = {
                // Layout
                layout: data.layout || 'grid',
                columns: data.columns || 4,
                
                // Pagination
                perPage: data.perPage || D.perPage,
                maxProducts: data.maxProducts || D.maxProducts,
                enablePagination: this.parseBool(data.enablePagination, true),
                
                // Sorting
                sort: data.sort || data.defaultSort || 'relevance',
                defaultSort: data.defaultSort || 'relevance',
                
                // API
                apiSource: data.apiSource || '',
                category: data.category || '',
                
                // Display Options - parse as booleans properly
                showPrice: this.parseBool(data.showPrice, D.showPrice),
                showRating: this.parseBool(data.showRating, D.showRating),
                showPrime: this.parseBool(data.showPrime, D.showPrime),
                showAvailability: this.parseBool(data.showAvailability, D.showAvailability),
                showDescription: this.parseBool(data.showDescription, D.showDescription),
                showMerchant: this.parseBool(data.showMerchant, D.showMerchant),
                descriptionLength: data.descriptionLength || D.descriptionLength,
                
                // Links
                target: data.target || D.target,
                nofollow: this.parseBool(data.nofollow, D.nofollow),
                sponsored: this.parseBool(data.sponsored, D.sponsored),
                
                // Filters
                minPrice: data.minPrice || '',
                maxPrice: data.maxPrice || '',
                primeOnly: this.parseBool(data.primeOnly, false),
                minRating: data.minRating || '',
                
                // Features
                analytics: this.parseBool(data.analytics, D.analytics),
                lazyLoad: this.parseBool(data.lazyLoad, D.lazyLoad),
                liveSearch: this.parseBool(data.liveSearch, D.liveSearch),
                minChars: data.minChars || D.minChars,
                debounce: data.debounce || D.debounce,
                
                // Initial Products
                hasInitial: this.parseBool(data.hasInitial, false),
                showInitial: this.parseBool(data.showInitial, false),
                showReset: this.parseBool(data.showReset, true),
            };
        }

        /**
         * Parse boolean from data attribute
         * Handles: true, false, 1, 0, "1", "0", "true", "false"
         */
        parseBool(value, defaultValue = false) {
            if (value === undefined || value === null || value === '') {
                return defaultValue;
            }
            if (typeof value === 'boolean') {
                return value;
            }
            if (typeof value === 'number') {
                return value === 1;
            }
            if (typeof value === 'string') {
                return value === '1' || value.toLowerCase() === 'true';
            }
            return defaultValue;
        }

        initState() {
            this.debounceTimer = null;
            this.currentRequest = null;
            this.lastQuery = '';
            this.isShowingResults = false;
            this.isOnline = navigator.onLine;
            this.retryCount = 0;
            this.maxRetries = 3;
            
            this.currentPage = 1;
            this.totalResults = 0;
            this.allProducts = [];
            this.displayedProducts = [];
            
            this.currentSort = this.settings.sort;
            
            this.activeFilters = {
                minPrice: this.settings.minPrice,
                maxPrice: this.settings.maxPrice,
                primeOnly: this.settings.primeOnly,
                minRating: this.settings.minRating,
                category: this.settings.category,
            };
            
            this.imageObserver = null;
            this.instanceId = this.$container.attr('id') || 'yaa-search-' + Math.random().toString(36).substr(2, 9);
        }

        init() {
            this.bindEvents();
            this.initSortHandler();
            this.initFilterHandlers();
            this.initClearButton();
            this.initSuggestions();
            this.initNetworkHandling();
            this.initLazyLoading();
            this.initKeyboardNavigation();
            this.initTouchEvents();
            this.initImageErrorHandling(this.$initialProducts);
            
            this.log('Initialized', this.settings);
        }

        // =========================================
        // EVENT BINDING
        // =========================================

        bindEvents() {
            // Form submit
            this.$form.on('submit', (e) => {
                e.preventDefault();
                this.search();
            });

            // Live search
            if (this.settings.liveSearch) {
                this.$input.on('input', () => {
                    clearTimeout(this.debounceTimer);
                    
                    const query = this.$input.val().trim();
                    
                    if (query.length === 0 && this.isShowingResults) {
                        this.resetToInitial();
                        return;
                    }
                    
                    this.debounceTimer = setTimeout(() => {
                        this.search();
                    }, this.settings.debounce);
                });
            }

            // Enter key
            this.$input.on('keypress', (e) => {
                if (e.which === 13) {
                    e.preventDefault();
                    clearTimeout(this.debounceTimer);
                    this.search();
                }
            });

            // Reset button
            this.$resetBtn.on('click', () => {
                this.resetToInitial();
            });

            // ESC key
            this.$input.on('keydown', (e) => {
                if (e.which === 27 && this.isShowingResults) {
                    this.resetToInitial();
                }
            });
        }

        // =========================================
        // SORTING
        // =========================================

        initSortHandler() {
            this.$sortSelect.on('change', (e) => {
                const newSort = $(e.target).val();
                this.handleSortChange(newSort);
            });

            if (this.$sortSelect.length) {
                this.$sortSelect.val(this.currentSort);
            }
        }

        handleSortChange(newSort) {
            const validSorts = Object.keys(this.getSortOptions());
            if (!validSorts.includes(newSort)) {
                newSort = this.settings.defaultSort;
            }

            this.currentSort = newSort;
            this.log('Sort changed to:', newSort);

            if (this.isShowingResults && this.lastQuery) {
                this.currentPage = 1;
                this.allProducts = [];
                this.displayedProducts = [];
                this.search();
            }
        }

        getSortOptions() {
            return yadoreSearch?.sortOptions || CONFIG.sortOptions;
        }

        syncSortFromServer(serverSort, sortLabel) {
            if (serverSort && serverSort !== this.currentSort) {
                this.log('Syncing sort from server:', serverSort);
                this.currentSort = serverSort;
                
                if (this.$sortSelect.length) {
                    this.$sortSelect.val(serverSort);
                }
            }
        }

        // =========================================
        // FILTERS
        // =========================================

        initFilterHandlers() {
            // Prime filter
            this.$primeCheckbox.on('change', (e) => {
                this.activeFilters.primeOnly = $(e.target).is(':checked');
                this.triggerFilteredSearch();
            });

            // Source filter
            this.$sourceSelect.on('change', (e) => {
                this.settings.apiSource = $(e.target).val();
                this.triggerFilteredSearch();
            });
        }

        triggerFilteredSearch() {
            if (this.isShowingResults && this.lastQuery) {
                this.currentPage = 1;
                this.allProducts = [];
                this.displayedProducts = [];
                this.search();
            }
        }

        // =========================================
        // CLEAR BUTTON
        // =========================================

        initClearButton() {
            this.$input.on('input', () => {
                const hasValue = this.$input.val().trim().length > 0;
                this.$clearBtn.toggle(hasValue);
            });

            this.$clearBtn.on('click', () => {
                this.$input.val('').trigger('input');
                this.$input.focus();
                
                if (this.isShowingResults) {
                    this.resetToInitial();
                }
            });
        }

        // =========================================
        // SUGGESTIONS
        // =========================================

        initSuggestions() {
            if (!yadoreSearch?.enableSuggestions || !this.$suggestions.length) {
                return;
            }

            let suggestionTimer = null;

            this.$input.on('input', () => {
                clearTimeout(suggestionTimer);
                
                const query = this.$input.val().trim();
                
                if (query.length < 2) {
                    this.$suggestions.hide().empty();
                    return;
                }

                suggestionTimer = setTimeout(() => {
                    this.fetchSuggestions(query);
                }, 200);
            });

            this.$input.on('focus', () => {
                if (this.$suggestions.children().length > 0) {
                    this.$suggestions.show();
                }
            });

            this.$input.on('blur', () => {
                setTimeout(() => this.$suggestions.hide(), 200);
            });

            this.initSuggestionKeyboard();
        }

        initSuggestionKeyboard() {
            this.$input.on('keydown', (e) => {
                if (!this.$suggestions.is(':visible')) return;

                const $items = this.$suggestions.find('.yaa-suggestion-item');
                const $active = $items.filter('.active');
                let index = $items.index($active);

                switch (e.which) {
                    case 40: // Down
                        e.preventDefault();
                        index = Math.min(index + 1, $items.length - 1);
                        $items.removeClass('active').eq(index).addClass('active');
                        break;
                    case 38: // Up
                        e.preventDefault();
                        index = Math.max(index - 1, 0);
                        $items.removeClass('active').eq(index).addClass('active');
                        break;
                    case 13: // Enter
                        if ($active.length) {
                            e.preventDefault();
                            this.$input.val($active.data('term'));
                            this.$suggestions.hide();
                            this.search();
                        }
                        break;
                    case 27: // Escape
                        this.$suggestions.hide();
                        break;
                }
            });
        }

        fetchSuggestions(query) {
            $.ajax({
                url: yadoreSearch.ajaxurl,
                type: 'POST',
                data: {
                    action: 'yaa_search_suggestions',
                    nonce: yadoreSearch.nonce,
                    keyword: query,
                },
                success: (response) => {
                    if (response.success && response.data.suggestions.length > 0) {
                        this.renderSuggestions(response.data.suggestions);
                    } else {
                        this.$suggestions.hide().empty();
                    }
                },
                error: () => this.$suggestions.hide().empty()
            });
        }

        renderSuggestions(suggestions) {
            let html = '';
            
            suggestions.forEach((item, index) => {
                const icon = item.type === 'recent' ? '🕐' : '🔍';
                html += `
                    <div class="yaa-suggestion-item yadore-suggestion-item ${index === 0 ? 'active' : ''}" 
                         data-term="${this.escapeHtml(item.term)}"
                         role="option">
                        <span class="yaa-suggestion-icon">${icon}</span>
                        <span class="yaa-suggestion-text">${this.escapeHtml(item.term)}</span>
                    </div>
                `;
            });

            this.$suggestions.html(html).show();

            this.$suggestions.find('.yaa-suggestion-item').on('click', (e) => {
                this.$input.val($(e.currentTarget).data('term'));
                this.$suggestions.hide();
                this.search();
            });
        }

        // =========================================
        // NETWORK HANDLING
        // =========================================

        initNetworkHandling() {
            window.addEventListener('online', () => {
                this.isOnline = true;
                this.hideOfflineMessage();
                if (this.lastQuery && this.retryCount > 0) {
                    this.search();
                }
            });

            window.addEventListener('offline', () => {
                this.isOnline = false;
                this.showOfflineMessage();
            });

            if (!navigator.onLine) {
                this.isOnline = false;
                this.showOfflineMessage();
            }
        }

        showOfflineMessage() {
            const html = `
                <div class="yaa-offline-msg yadore-offline-msg" role="alert">
                    <span class="yaa-offline-icon" aria-hidden="true">📡</span>
                    <span>${this.i18n('offline')}</span>
                </div>
            `;
            
            if (!this.$container.find('.yaa-offline-msg').length) {
                this.$container.prepend(html);
            }
        }

        hideOfflineMessage() {
            this.$container.find('.yaa-offline-msg').fadeOut(300, function() {
                $(this).remove();
            });
        }

        handleNetworkError(xhr, status) {
            this.retryCount++;
            
            if ((status === 'timeout' || status === 'error') && 
                this.retryCount <= this.maxRetries && this.isOnline) {
                
                const delay = Math.min(1000 * Math.pow(2, this.retryCount - 1), 10000);
                
                this.showStatus(
                    this.i18n('retrying').replace('{s}', Math.ceil(delay / 1000)),
                    'info'
                );
                
                setTimeout(() => this.search(true), delay);
                return true;
            }
            
            this.retryCount = 0;
            return false;
        }

        // =========================================
        // SEARCH
        // =========================================

        search(isRetry = false) {
            const query = this.$input.val().trim();

            if (!this.isOnline) {
                this.showStatus(this.i18n('offline'), 'error');
                return;
            }

            if (query.length < this.settings.minChars) {
                if (query.length > 0) {
                    this.showStatus(
                        this.i18n('min_chars').replace('%d', this.settings.minChars),
                        'info'
                    );
                } else if (this.isShowingResults) {
                    this.resetToInitial();
                }
                return;
            }

            if (query === this.lastQuery && this.isShowingResults && !isRetry && this.allProducts.length > 0) {
                return;
            }
            
            if (!isRetry) {
                this.lastQuery = query;
                this.retryCount = 0;
                this.currentPage = 1;
                this.allProducts = [];
            }

            if (this.currentRequest) {
                this.currentRequest.abort();
            }

            this.setLoading(true);
            this.showStatus(this.i18n('searching'), 'loading');

            this.currentRequest = $.ajax({
                url: yadoreSearch.ajaxurl,
                type: 'POST',
                timeout: 30000,
                data: {
                    action: 'yaa_product_search',
                    nonce: yadoreSearch.nonce,
                    keyword: query,
                    page: this.currentPage,
                    per_page: this.settings.perPage,
                    sort: this.currentSort,
                    api_source: this.settings.apiSource,
                    min_price: this.activeFilters.minPrice,
                    max_price: this.activeFilters.maxPrice,
                    prime_only: this.activeFilters.primeOnly ? '1' : '0',
                    min_rating: this.activeFilters.minRating,
                    category: this.activeFilters.category,
                },
                success: (response) => {
                    this.setLoading(false);
                    this.retryCount = 0;
                    
                    if (response.success) {
                        const data = response.data;
                        
                        if (data.current_sort) {
                            this.syncSortFromServer(data.current_sort, data.sort_label);
                        }
                        
                        if (data.products && data.products.length > 0) {
                            this.allProducts = data.products;
                            this.totalResults = data.total;
                            this.renderResults(data);
                            
                            if (this.settings.analytics) {
                                this.trackSearch(query, data.products.length, data.total);
                            }
                        } else {
                            this.showNoResults(query);
                        }
                    } else {
                        this.showStatus(response.data?.message || this.i18n('error'), 'error');
                    }
                },
                error: (xhr, status) => {
                    this.setLoading(false);
                    
                    if (status !== 'abort') {
                        if (!this.handleNetworkError(xhr, status)) {
                            this.showStatus(this.i18n('error'), 'error');
                        }
                    }
                }
            });
        }

        showNoResults(query) {
            this.showStatus(this.i18n('no_results'), 'empty');
            this.$initialProducts.slideUp(200);
            this.$productsGrid.empty();
            
            const html = `
                <div class="yaa-no-results yadore-no-results">
                    <div class="yaa-no-results-icon" aria-hidden="true">🔍</div>
                    <h3>${this.i18n('no_results')}</h3>
                    <p>${this.i18n('try_different')}</p>
                </div>
            `;
            this.$productsGrid.html(html);
            this.$searchResults.slideDown(200);
            this.isShowingResults = true;
        }

        setLoading(isLoading) {
            this.$submitBtn.prop('disabled', isLoading);
            this.$btnText.toggle(!isLoading);
            this.$btnSpinner.toggle(isLoading);
            this.$container.toggleClass('yaa-is-loading yadore-is-loading', isLoading);
            this.$container.attr('aria-busy', isLoading ? 'true' : 'false');
        }

        showStatus(message, type) {
            this.$status
                .removeClass('yaa-status-loading yaa-status-error yaa-status-empty yaa-status-info yaa-status-success')
                .addClass('yaa-status-' + type)
                .html(message)
                .attr('role', type === 'error' ? 'alert' : 'status')
                .show();
        }

        // =========================================
        // RENDER RESULTS
        // =========================================

        renderResults(data) {
            this.$status.hide();
            this.isShowingResults = true;
            
            this.$initialProducts.slideUp(200);
            
            const resultText = data.total === 1 
                ? this.i18n('one_result')
                : this.i18n('multiple_results').replace('%d', data.total);
            
            this.$resultsCount.text(resultText);
            this.$resultsQuery.text(`${this.i18n('results_for')} "${this.escapeHtml(data.keyword || this.lastQuery)}"`);
            this.$resultsInfo.show();

            this.$productsGrid.empty();

            const productsToShow = this.settings.enablePagination 
                ? data.products.slice(0, this.settings.perPage)
                : data.products;
            
            this.displayedProducts = productsToShow;

            productsToShow.forEach((product, index) => {
                const $card = $(this.createProductCard(product));
                $card.css('animation-delay', (index * 0.05) + 's');
                this.$productsGrid.append($card);
            });

            if (this.settings.enablePagination && data.total > this.settings.perPage) {
                this.renderLoadMoreButton(data);
            }

            if (this.settings.hasInitial && this.settings.showReset) {
                this.$resetBtn.show();
            }

            this.$searchResults.slideDown(300, () => {
                this.initImageErrorHandling(this.$searchResults);
                this.observeImages(this.$productsGrid);
            });

            this.scrollToResults();
        }

        scrollToResults() {
            const containerTop = this.$container.offset().top;
            const windowTop = $(window).scrollTop();
            const windowHeight = $(window).height();
            
            if (containerTop < windowTop || containerTop > windowTop + windowHeight) {
                $('html, body').animate({ scrollTop: containerTop - 20 }, 300);
            }
        }

        // =========================================
        // PRODUCT CARD - MUST MATCH PHP render_product_card()
        // =========================================

        createProductCard(product) {
            const target = this.settings.target;
            const rel = this.buildRelAttribute(product);
            const sourceClass = 'yaa-source-' + (product.source || 'yadore');
            
            return `
                <article class="yaa-product-card yadore-product-card ${sourceClass}" 
                         data-product-id="${this.escapeHtml(product.id || product.asin || '')}"
                         data-asin="${this.escapeHtml(product.asin || '')}"
                         tabindex="0">
                    
                    <a href="${this.escapeHtml(product.url || '#')}" 
                       target="${target}" 
                       rel="${rel}"
                       class="yaa-product-link yadore-product-link">
                        
                        ${this.createBadgesHtml(product)}
                        ${this.createImageHtml(product)}
                        ${this.createContentHtml(product)}
                    </a>
                    
                    ${this.createActionsHtml(product, target, rel)}
                </article>
            `;
        }

        buildRelAttribute(product) {
            const rel = [];
            if (this.settings.nofollow) rel.push('nofollow');
            if (this.settings.sponsored || product.sponsored) rel.push('sponsored');
            if (this.settings.target === '_blank') {
                rel.push('noopener', 'noreferrer');
            }
            return rel.join(' ');
        }

        createBadgesHtml(product) {
            let html = '';
            
            if (this.settings.showPrime && product.is_prime) {
                html += '<span class="yaa-badge-prime yadore-badge-prime" title="Amazon Prime">Prime</span>';
            }
            
            if (product.discount_percent) {
                html += `<span class="yaa-badge-discount yadore-badge-discount">-${product.discount_percent}%</span>`;
            }
            
            if (product.source) {
                html += `<span class="yaa-badge-source yadore-badge-source">${this.escapeHtml(product.source)}</span>`;
            }
            
            return html;
        }

        createImageHtml(product) {
            if (product.image_url) {
                if (this.settings.lazyLoad) {
                    return `
                        <div class="yaa-product-image yadore-product-image">
                            <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                                 data-src="${this.escapeHtml(product.image_url)}" 
                                 alt="${this.escapeHtml(product.title || '')}" 
                                 class="yaa-lazy-img yadore-lazy-img"
                                 data-fallback-attempted="false"
                                 loading="lazy">
                            <div class="yaa-img-loader yadore-img-loader"></div>
                        </div>
                    `;
                }
                return `
                    <div class="yaa-product-image yadore-product-image">
                        <img src="${this.escapeHtml(product.image_url)}" 
                             alt="${this.escapeHtml(product.title || '')}"
                             loading="lazy">
                    </div>
                `;
            }
            
            return `
                <div class="yaa-product-image yadore-product-image yaa-no-image yadore-no-image">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                    </svg>
                </div>
            `;
        }

        createContentHtml(product) {
            let html = '<div class="yaa-product-content yadore-product-content">';
            
            // Title
            html += `<h3 class="yaa-product-title yadore-product-title">${this.escapeHtml(product.title || '')}</h3>`;
            
            // Rating
            if (this.settings.showRating && product.rating) {
                html += `
                    <div class="yaa-product-rating yadore-product-rating" 
                         aria-label="Bewertung: ${product.rating} von 5 Sternen">
                        <span class="yaa-stars yadore-stars" style="--rating: ${product.rating};"></span>
                        ${product.reviews_count ? `<span class="yaa-reviews-count yadore-reviews-count">(${product.reviews_count})</span>` : ''}
                    </div>
                `;
            }
            
            // Description
            if (this.settings.showDescription && product.description) {
                const truncated = this.truncateText(product.description, this.settings.descriptionLength);
                html += `<p class="yaa-product-desc yadore-product-desc">${this.escapeHtml(truncated)}</p>`;
            }
            
            // Merchant
            if (this.settings.showMerchant && product.merchant) {
                html += `<div class="yaa-product-merchant yadore-product-merchant">${this.escapeHtml(product.merchant)}</div>`;
            }
            
            // Price
            if (this.settings.showPrice && product.price) {
                html += '<div class="yaa-price-wrapper yadore-price-wrapper">';
                html += `<span class="yaa-price yadore-price">${this.escapeHtml(product.price)}</span>`;
                if (product.price_old) {
                    html += `<span class="yaa-price-old yadore-price-old">${this.escapeHtml(product.price_old)}</span>`;
                }
                html += '</div>';
            }
            
            // Availability Dot (ohne Text)
            if (this.settings.showAvailability && product.availability) {
                const isAvailable = (product.availability_status === 'available') || 
                                    (product.availability.toLowerCase() === 'available') ||
                                    (product.availability.toLowerCase() === 'in stock');
                const dotClass = isAvailable ? 'yaa-dot-available' : 'yaa-dot-unknown';
                const dotTitle = isAvailable ? 'Verfügbar' : 'Unbekannt';
                html += `<span class="yaa-availability-dot ${dotClass}" title="${dotTitle}" aria-label="${dotTitle}"></span>`;
            }

            
            // Sponsored
            if (this.settings.sponsored && product.sponsored) {
                html += `<span class="yaa-sponsored yadore-sponsored">${this.i18n('sponsored')}</span>`;
            }
            
            html += '</div>';
            return html;
        }

        createActionsHtml(product, target, rel) {
            const buttonText = yadoreSearch?.buttonText || this.i18n('view_offer');
            
            return `
                <div class="yaa-product-actions yadore-product-actions">
                    <a href="${this.escapeHtml(product.url || '#')}" 
                       target="${target}" 
                       rel="${rel}"
                       class="yaa-product-btn yadore-product-btn"
                       data-product-id="${this.escapeHtml(product.id || product.asin || '')}">
                        ${buttonText}
                        <span class="yaa-icon-external yadore-icon-external" aria-hidden="true">↗</span>
                    </a>
                </div>
            `;
        }

        truncateText(text, maxLength) {
            if (!text || text.length <= maxLength) {
                return text || '';
            }
            return text.substring(0, maxLength).trim() + '…';
        }

        // =========================================
        // PAGINATION
        // =========================================

        renderLoadMoreButton(data) {
            this.$container.find('.yaa-load-more-wrapper').remove();
            
            const remaining = (data?.total || this.allProducts.length) - this.displayedProducts.length;
            
            if (remaining <= 0) return;

            const html = `
                <div class="yaa-load-more-wrapper yadore-load-more-wrapper">
                    <button type="button" class="yaa-load-more yadore-load-more">
                        <span class="yaa-btn-text yadore-btn-text">
                            ${this.i18n('load_more')} (${remaining})
                        </span>
                        <span class="yaa-btn-spinner yadore-btn-spinner" style="display: none;"></span>
                    </button>
                    <div class="yaa-pagination-info yadore-pagination-info">
                        ${this.displayedProducts.length} / ${data?.total || this.allProducts.length} ${this.i18n('products')}
                    </div>
                </div>
            `;

            this.$searchResults.append(html);

            this.$container.find('.yaa-load-more').on('click', () => {
                this.loadMoreProducts();
            });
        }

        loadMoreProducts() {
            const $button = this.$container.find('.yaa-load-more');
            const $text = $button.find('.yaa-btn-text');
            const $spinner = $button.find('.yaa-btn-spinner');
            
            $button.prop('disabled', true);
            $text.hide();
            $spinner.show();

            if (this.displayedProducts.length >= this.allProducts.length && this.totalResults > this.allProducts.length) {
                this.currentPage++;
                this.loadNextPage($button, $text, $spinner);
                return;
            }

            const startIndex = this.displayedProducts.length;
            const endIndex = Math.min(startIndex + this.settings.perPage, this.allProducts.length);
            const newProducts = this.allProducts.slice(startIndex, endIndex);

            setTimeout(() => {
                newProducts.forEach((product, index) => {
                    const $card = $(this.createProductCard(product));
                    $card.css('animation-delay', (index * 0.05) + 's');
                    this.$productsGrid.append($card);
                    this.displayedProducts.push(product);
                });

                this.initImageErrorHandling(this.$productsGrid);
                this.observeImages(this.$productsGrid);

                $button.prop('disabled', false);
                $text.show();
                $spinner.hide();

                if (this.displayedProducts.length >= this.totalResults) {
                    this.$container.find('.yaa-load-more-wrapper').fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    this.renderLoadMoreButton({ total: this.totalResults });
                }
            }, 300);
        }

        loadNextPage($button, $text, $spinner) {
            $.ajax({
                url: yadoreSearch.ajaxurl,
                type: 'POST',
                data: {
                    action: 'yaa_product_search',
                    nonce: yadoreSearch.nonce,
                    keyword: this.lastQuery,
                    page: this.currentPage,
                    per_page: this.settings.perPage,
                    sort: this.currentSort,
                    api_source: this.settings.apiSource,
                    min_price: this.activeFilters.minPrice,
                    max_price: this.activeFilters.maxPrice,
                    prime_only: this.activeFilters.primeOnly ? '1' : '0',
                    min_rating: this.activeFilters.minRating,
                },
                success: (response) => {
                    if (response.success && response.data.products.length > 0) {
                        if (response.data.current_sort) {
                            this.syncSortFromServer(response.data.current_sort, response.data.sort_label);
                        }

                        response.data.products.forEach((product, index) => {
                            const $card = $(this.createProductCard(product));
                            $card.css('animation-delay', (index * 0.05) + 's');
                            this.$productsGrid.append($card);
                            this.allProducts.push(product);
                            this.displayedProducts.push(product);
                        });

                        this.initImageErrorHandling(this.$productsGrid);
                        this.observeImages(this.$productsGrid);
                    }

                    $button.prop('disabled', false);
                    $text.show();
                    $spinner.hide();

                    if (!response.data.has_more) {
                        this.$container.find('.yaa-load-more-wrapper').fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        this.renderLoadMoreButton({ total: this.totalResults });
                    }
                },
                error: () => {
                    $button.prop('disabled', false);
                    $text.show();
                    $spinner.hide();
                    this.showStatus(this.i18n('error'), 'error');
                }
            });
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
            this.$resultsInfo.hide();
            
            this.currentSort = this.settings.defaultSort;
            if (this.$sortSelect.length) {
                this.$sortSelect.val(this.currentSort);
            }
            
            this.resetFilters();
            
            this.$container.find('.yaa-load-more-wrapper').remove();
            
            this.$searchResults.slideUp(200, () => {
                this.$productsGrid.empty();
            });
            
            this.$resetBtn.hide();
            
            if (this.settings.hasInitial) {
                this.$initialProducts.slideDown(300);
            }

            this.$clearBtn.hide();
            this.$input.focus();
            
            this.log('Reset to initial state');
        }

        resetFilters() {
            this.activeFilters = {
                minPrice: this.settings.minPrice,
                maxPrice: this.settings.maxPrice,
                primeOnly: this.settings.primeOnly,
                minRating: this.settings.minRating,
                category: this.settings.category,
            };

            this.$primeCheckbox.prop('checked', this.settings.primeOnly);
        }

        // =========================================
        // LAZY LOADING
        // =========================================

        initLazyLoading() {
            if (!this.settings.lazyLoad) return;

            if ('IntersectionObserver' in window) {
                this.imageObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            this.loadImage(entry.target);
                            observer.unobserve(entry.target);
                        }
                    });
                }, {
                    rootMargin: '100px 0px',
                    threshold: 0.01
                });
            }

            this.observeImages(this.$initialProducts);
        }

        observeImages($container) {
            if (!this.imageObserver) {
                $container.find(CONFIG.selectors.lazyImg).each((i, img) => {
                    this.loadImage(img);
                });
                return;
            }

            $container.find(CONFIG.selectors.lazyImg).each((i, img) => {
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
            const $wrapper = $img.closest('.yaa-product-image, .yadore-product-image');

            $wrapper.addClass('yaa-img-loading yadore-img-loading');

            const tempImg = new Image();
            
            tempImg.onload = () => {
                $img.attr('src', src);
                $img.addClass('yaa-img-loaded yadore-img-loaded');
                $wrapper.removeClass('yaa-img-loading yadore-img-loading');
                $wrapper.find('.yaa-img-loader, .yadore-img-loader').remove();
            };

            tempImg.onerror = () => {
                this.handleImageError(img);
            };

            tempImg.src = src;
        }

        // =========================================
        // IMAGE ERROR HANDLING
        // =========================================

        initImageErrorHandling($container) {
            $container.find('.yaa-product-image img, .yadore-product-image img').each((i, img) => {
                if (img.dataset.errorHandlerAttached) return;
                img.dataset.errorHandlerAttached = 'true';

                $(img).on('error', () => {
                    this.handleImageError(img);
                });

                if (img.complete && img.naturalWidth === 0 && img.src && !img.src.startsWith('data:')) {
                    this.handleImageError(img);
                }
            });
        }

        handleImageError(img) {
            const $img = $(img);
            const $wrapper = $img.closest('.yaa-product-image, .yadore-product-image');
            
            img.dataset.fallbackAttempted = 'complete';
            $wrapper.addClass('yaa-img-error yadore-img-error');
            $wrapper.removeClass('yaa-img-loading yadore-img-loading');
            
            $img.hide();
            
            if (!$wrapper.find('.yaa-placeholder-icon').length) {
                $wrapper.append(`
                    <div class="yaa-placeholder-icon yadore-placeholder-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                        </svg>
                    </div>
                `);
            }
        }

        // =========================================
        // TOUCH EVENTS
        // =========================================

        initTouchEvents() {
            let touchStartX = 0;
            let touchStartY = 0;

            this.$container.on('touchstart', CONFIG.selectors.productCard, (e) => {
                $(e.currentTarget).addClass('yaa-touch-active yadore-touch-active');
                const touch = e.originalEvent.touches[0];
                touchStartX = touch.clientX;
                touchStartY = touch.clientY;
            });

            this.$container.on('touchmove', CONFIG.selectors.productCard, (e) => {
                const touch = e.originalEvent.touches[0];
                const diffX = Math.abs(touch.clientX - touchStartX);
                const diffY = Math.abs(touch.clientY - touchStartY);
                
                if (diffX > 10 || diffY > 10) {
                    $(e.currentTarget).removeClass('yaa-touch-active yadore-touch-active');
                }
            });

            this.$container.on('touchend touchcancel', CONFIG.selectors.productCard, (e) => {
                setTimeout(() => {
                    $(e.currentTarget).removeClass('yaa-touch-active yadore-touch-active');
                }, 150);
            });
        }

        // =========================================
        // KEYBOARD NAVIGATION
        // =========================================

        initKeyboardNavigation() {
            this.$container.on('keydown', CONFIG.selectors.productCard, (e) => {
                const $card = $(e.currentTarget);
                const $cards = this.$container.find(`${CONFIG.selectors.productCard}:visible`);
                const currentIndex = $cards.index($card);

                switch (e.which) {
                    case 13: // Enter
                    case 32: // Space
                        e.preventDefault();
                        $card.find(CONFIG.selectors.productLink)[0]?.click();
                        break;
                    case 37: // Left
                        e.preventDefault();
                        if (currentIndex > 0) $cards.eq(currentIndex - 1).focus();
                        break;
                    case 38: // Up
                        e.preventDefault();
                        if (currentIndex >= this.settings.columns) {
                            $cards.eq(currentIndex - this.settings.columns).focus();
                        } else {
                            this.$input.focus();
                        }
                        break;
                    case 39: // Right
                        e.preventDefault();
                        if (currentIndex < $cards.length - 1) $cards.eq(currentIndex + 1).focus();
                        break;
                    case 40: // Down
                        e.preventDefault();
                        if (currentIndex + this.settings.columns < $cards.length) {
                            $cards.eq(currentIndex + this.settings.columns).focus();
                        }
                        break;
                }
            });

            this.$input.on('keydown', (e) => {
                if (e.which === 40) {
                    e.preventDefault();
                    const $firstCard = this.$container.find(`${CONFIG.selectors.productCard}:visible`).first();
                    if ($firstCard.length) $firstCard.focus();
                }
            });
        }

        // =========================================
        // ANALYTICS
        // =========================================

        trackSearch(keyword, resultsShown, totalResults) {
            if (!this.settings.analytics || !yadoreSearch?.enableAnalytics) return;

            this.$productsGrid.off('click.analytics').on('click.analytics', `${CONFIG.selectors.productBtn}, ${CONFIG.selectors.productLink}`, (e) => {
                const $target = $(e.currentTarget);
                const productId = $target.data('product-id') || $target.closest(CONFIG.selectors.productCard).data('product-id');
                
                if (productId) {
                    this.trackClick(productId);
                }
            });
        }

        trackClick(productId) {
            if (!productId) return;

            $.ajax({
                url: yadoreSearch.ajaxurl,
                type: 'POST',
                data: {
                    action: 'yaa_track_click',
                    nonce: yadoreSearch.nonce,
                    product_id: productId,
                    keyword: this.lastQuery,
                }
            });
        }

        // =========================================
        // UTILITIES
        // =========================================

        i18n(key) {
            return yadoreSearch?.i18n?.[key] || CONFIG.defaults[key] || key;
        }

        escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        log(...args) {
            if (yadoreSearch?.debug) {
                console.log('[YAA Search]', ...args);
            }
        }

        // =========================================
        // PUBLIC API
        // =========================================

        destroy() {
            this.$form.off();
            this.$input.off();
            this.$resetBtn.off();
            this.$sortSelect.off();
            this.$container.off();
            
            if (this.imageObserver) {
                this.imageObserver.disconnect();
            }
            
            clearTimeout(this.debounceTimer);
            
            if (this.currentRequest) {
                this.currentRequest.abort();
            }
        }

        refresh() {
            this.observeImages(this.$container);
            this.initImageErrorHandling(this.$container);
        }

        setSort(sort) {
            this.handleSortChange(sort);
        }

        getState() {
            return {
                query: this.lastQuery,
                isShowingResults: this.isShowingResults,
                totalResults: this.totalResults,
                displayedProducts: this.displayedProducts.length,
                isOnline: this.isOnline,
                currentPage: this.currentPage,
                currentSort: this.currentSort,
                activeFilters: { ...this.activeFilters },
            };
        }
    }

    // =========================================
    // INITIALIZATION
    // =========================================

    $(document).ready(function() {
        $(CONFIG.selectors.container).each(function() {
            const instance = new YAAProductSearch(this);
            $(this).data('yaaSearch', instance);
        });
    });

    // Export
    window.YAAProductSearch = YAAProductSearch;

})(jQuery);

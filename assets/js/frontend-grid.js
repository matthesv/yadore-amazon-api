/**
 * Yadore-Amazon-API Frontend JavaScript
 * Version 1.5.4 - Mit Image Proxy Support
 * PHP 8.3+ compatible
 * 
 * Features:
 * - Read More/Less Toggle
 * - Lazy Load Fallback
 * - Accessibility Enhancements
 * - Image 404 Error Handling with Thumbnail Fallback
 * - NEU: Server-seitiger Image Proxy als letzter Fallback
 * - Public API
 */

(function() {
    'use strict';

    /**
     * Initialize when DOM is ready
     */
    document.addEventListener('DOMContentLoaded', function() {
        initReadMoreButtons();
        initLazyLoadImages();
        initAccessibilityEnhancements();
        initImageErrorHandling();
    });

    /**
     * Initialize read more/less toggle buttons
     */
    function initReadMoreButtons() {
        const buttons = document.querySelectorAll('.yaa-read-more');
        
        buttons.forEach(function(button) {
            button.addEventListener('click', handleReadMoreClick);
            button.addEventListener('keydown', handleReadMoreKeydown);
        });
    }

    /**
     * Handle read more button click
     * @param {Event} event
     */
    function handleReadMoreClick(event) {
        event.preventDefault();
        
        const button = event.currentTarget;
        const targetId = button.getAttribute('data-target');
        const description = document.getElementById('desc-' + targetId);
        
        if (!description) {
            return;
        }
        
        toggleDescription(description, button);
    }

    /**
     * Handle keyboard navigation for read more
     * @param {KeyboardEvent} event
     */
    function handleReadMoreKeydown(event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            handleReadMoreClick(event);
        }
    }

    /**
     * Toggle description expanded state
     * @param {HTMLElement} description
     * @param {HTMLElement} button
     */
    function toggleDescription(description, button) {
        const isExpanded = description.classList.contains('expanded');
        const expandText = button.getAttribute('data-expand-text') || 'mehr lesen';
        const collapseText = button.getAttribute('data-collapse-text') || 'weniger';
        
        if (isExpanded) {
            description.classList.remove('expanded');
            button.classList.remove('expanded');
            button.textContent = expandText;
            button.setAttribute('aria-expanded', 'false');
        } else {
            description.classList.add('expanded');
            button.classList.add('expanded');
            button.textContent = collapseText;
            button.setAttribute('aria-expanded', 'true');
        }
    }

    /**
     * Initialize lazy loading for images
     * Fallback for browsers without native lazy loading
     */
    function initLazyLoadImages() {
        // Check if native lazy loading is supported
        if ('loading' in HTMLImageElement.prototype) {
            return; // Native support, no need for JS
        }

        // Fallback for older browsers
        const images = document.querySelectorAll('.yaa-image-wrapper img[loading="lazy"]');
        
        if (images.length === 0) {
            return;
        }

        // Use IntersectionObserver if available
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver(function(entries, observer) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        const image = entry.target;
                        if (image.dataset.src) {
                            image.src = image.dataset.src;
                            image.removeAttribute('data-src');
                        }
                        observer.unobserve(image);
                    }
                });
            }, {
                rootMargin: '50px 0px',
                threshold: 0.01
            });

            images.forEach(function(image) {
                imageObserver.observe(image);
            });
        }
    }

    /**
     * Initialize image error handling for 404/broken images
     * NEU: Erweiterte Fallback-Kette mit Image Proxy
     * 
     * Fallback-Reihenfolge:
     * 1. Original Image → Error
     * 2. Thumbnail (data-thumbnail) → Error
     * 3. Server-seitiger Proxy (yaaProxy.endpoint) → Error
     * 4. CSS Placeholder
     */
    /**
 * Initialize image error handling for 404/broken images
 */
function initImageErrorHandling() {
    var images = document.querySelectorAll('.yaa-image-wrapper img');
    
    images.forEach(function(img) {
        // Mark image as not yet processed for fallback
        if (!img.hasAttribute('data-fallback-attempted')) {
            img.setAttribute('data-fallback-attempted', 'false');
        }
        
        // NEU: Prüfe auf leeres src mit vorhandener Alternative
        var currentSrc = img.getAttribute('src') || '';
        var proxySrc = img.getAttribute('data-proxy-src') || '';
        var originalSrc = img.getAttribute('data-original-src') || '';
        
        // Wenn src leer ist aber Alternativen existieren, sofort laden
        if (currentSrc === '' || currentSrc === window.location.href) {
            if (proxySrc !== '') {
                if (window.console) {
                    console.log('YAA: Empty src detected, using proxy:', proxySrc);
                }
                img.setAttribute('data-fallback-attempted', 'proxy');
                img.src = proxySrc;
                return;
            } else if (originalSrc !== '') {
                if (window.console) {
                    console.log('YAA: Empty src detected, using original:', originalSrc);
                }
                img.setAttribute('data-fallback-attempted', 'original');
                img.src = originalSrc;
                return;
            }
        }
        
        // Handle images that are already broken (cached or immediate 404)
        if (img.complete) {
            if (img.naturalWidth === 0 || img.naturalHeight === 0) {
                attemptImageFallback(img);
            } else {
                // Image loaded successfully
                img.classList.add('yaa-img-loaded');
            }
        }
        
        // Handle future load events
        img.addEventListener('load', function() {
            img.classList.add('yaa-img-loaded');
            img.classList.remove('yaa-img-loading');
        });
        
        // Handle future errors (async loading)
        img.addEventListener('error', function() {
            attemptImageFallback(img);
        });
    });
}


    /**
     * NEU: Attempt image fallback chain
     * 
     * Fallback-Reihenfolge:
     * 1. Original image (already failed)
     * 2. Try thumbnail if available (data-thumbnail attribute)
     * 3. Try server-side proxy (if enabled)
     * 4. Fall back to CSS placeholder
     * 
     * @param {HTMLImageElement} img
     */
    function attemptImageFallback(img) {
        const wrapper = img.closest('.yaa-image-wrapper');
        
        if (!wrapper) {
            return;
        }
        
        // ========================================
        // Referrer-Policy setzen um Hotlink-Protection zu umgehen
        // ========================================
        if (!img.hasAttribute('referrerpolicy')) {
            img.setAttribute('referrerpolicy', 'no-referrer');
        }
        
        // Get fallback state
        const fallbackAttempted = img.getAttribute('data-fallback-attempted');
        const thumbnailUrl = img.getAttribute('data-thumbnail');
        const originalSrc = img.getAttribute('data-original-src') || img.src;
        
        // Store original src on first attempt
        if (!img.hasAttribute('data-original-src')) {
            img.setAttribute('data-original-src', img.src);
        }
        
        // ========================================
        // FALLBACK CHAIN
        // ========================================
        
        // Step 1: If we haven't tried thumbnail yet and it exists, try it
        if (fallbackAttempted === 'false' && thumbnailUrl && thumbnailUrl !== '' && thumbnailUrl !== originalSrc) {
            if (window.console) {
                console.log('YAA: Original image failed, trying thumbnail:', thumbnailUrl);
            }
            
            img.setAttribute('data-fallback-attempted', 'thumbnail');
            img.classList.add('yaa-img-loading');
            
            // Try loading the thumbnail
            img.src = thumbnailUrl;
            
            // Don't proceed to next fallback yet - wait for thumbnail load/error
            return;
        }
        
        // Step 2: If thumbnail failed or doesn't exist, try proxy (NEU)
        if (fallbackAttempted === 'thumbnail' || (fallbackAttempted === 'false' && !thumbnailUrl)) {
            // Check if proxy is enabled
            if (isProxyEnabled() && originalSrc && originalSrc !== '') {
                if (window.console) {
                    console.log('YAA: Trying server-side proxy for:', originalSrc);
                }
                
                img.setAttribute('data-fallback-attempted', 'proxy');
                img.classList.add('yaa-img-loading');
                
                // Generate proxy URL
                const proxyUrl = getProxyUrl(originalSrc);
                
                if (proxyUrl) {
                    img.src = proxyUrl;
                    return;
                }
            }
        }
        
        // Step 3: Proxy also failed or not available, show CSS placeholder
        if (fallbackAttempted === 'false' || fallbackAttempted === 'thumbnail' || fallbackAttempted === 'proxy') {
            if (window.console) {
                if (fallbackAttempted === 'proxy') {
                    console.warn('YAA: Proxy also failed, showing placeholder for:', originalSrc);
                } else if (fallbackAttempted === 'thumbnail') {
                    console.warn('YAA: Thumbnail failed, showing placeholder:', originalSrc);
                } else {
                    console.warn('YAA: Image failed (no fallbacks), showing placeholder:', originalSrc);
                }
            }
            
            img.setAttribute('data-fallback-attempted', 'complete');
            showCSSPlaceholder(img, wrapper);
        }
    }

    /**
     * NEU: Check if image proxy is enabled
     * @returns {boolean}
     */
    function isProxyEnabled() {
        // Check global config from wp_localize_script
        if (typeof window.yaaProxy !== 'undefined' && window.yaaProxy.enabled) {
            return true;
        }
        return false;
    }

    /**
     * NEU: Generate proxy URL for an image
     * @param {string} originalUrl - The original image URL
     * @returns {string|null} - Proxy URL or null if not available
     */
    function getProxyUrl(originalUrl) {
        if (!originalUrl || originalUrl === '') {
            return null;
        }
        
        // Don't proxy local URLs
        if (originalUrl.indexOf(window.location.origin) === 0) {
            return null;
        }
        
        // Don't proxy data URLs
        if (originalUrl.indexOf('data:') === 0) {
            return null;
        }
        
        // Don't proxy already proxied URLs
        if (originalUrl.indexOf('action=yaa_proxy_image') !== -1) {
            return null;
        }
        
        // Build proxy URL
        if (typeof window.yaaProxy !== 'undefined' && window.yaaProxy.endpoint) {
            var params = new URLSearchParams();
            params.append('action', window.yaaProxy.action || 'yaa_proxy_image');
            params.append('url', originalUrl);
            
            return window.yaaProxy.endpoint + '?' + params.toString();
        }
        
        return null;
    }

    /**
     * Show CSS placeholder after all fallbacks failed
     * 
     * @param {HTMLImageElement} img
     * @param {HTMLElement} wrapper
     */
    function showCSSPlaceholder(img, wrapper) {
        // Prevent multiple executions
        if (wrapper.classList.contains('yaa-image-error')) {
            return;
        }
        
        // Mark wrapper as having an error
        wrapper.classList.add('yaa-image-error');
        
        // Hide the broken image
        img.style.display = 'none';
        img.style.visibility = 'hidden';
        img.classList.remove('yaa-img-loading');
        
        // Check if placeholder already exists
        if (wrapper.querySelector('.yaa-placeholder')) {
            return;
        }
        
        // Create CSS placeholder element
        var placeholder = document.createElement('div');
        placeholder.className = 'yaa-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');
        placeholder.setAttribute('role', 'img');
        placeholder.setAttribute('aria-label', 'Bild nicht verfügbar');
        
        // Determine source type for colored placeholder
        var item = wrapper.closest('.yaa-item');
        if (item) {
            if (item.classList.contains('yaa-amazon')) {
                placeholder.classList.add('yaa-placeholder-amazon');
            } else if (item.classList.contains('yaa-custom')) {
                placeholder.classList.add('yaa-placeholder-custom');
            } else if (item.classList.contains('yaa-yadore')) {
                placeholder.classList.add('yaa-placeholder-yadore');
            }
        }
        
        // Insert placeholder (inside the link if exists, otherwise directly)
        var link = wrapper.querySelector('a');
        if (link) {
            link.appendChild(placeholder);
        } else {
            wrapper.appendChild(placeholder);
        }
    }

    /**
     * Legacy function for backwards compatibility
     * @deprecated Use attemptImageFallback instead
     * @param {HTMLImageElement} img
     */
    function handleBrokenImage(img) {
        attemptImageFallback(img);
    }

    /**
     * Initialize accessibility enhancements
     */
    function initAccessibilityEnhancements() {
        // Add ARIA labels to read more buttons
        var buttons = document.querySelectorAll('.yaa-read-more');
        buttons.forEach(function(button) {
            if (!button.hasAttribute('aria-expanded')) {
                button.setAttribute('aria-expanded', 'false');
            }
            if (!button.hasAttribute('role')) {
                button.setAttribute('role', 'button');
            }
            if (!button.hasAttribute('tabindex')) {
                button.setAttribute('tabindex', '0');
            }
        });

        // Add ARIA labels to product links
        var productLinks = document.querySelectorAll('.yaa-item .yaa-title a, .yaa-item .yaa-button');
        productLinks.forEach(function(link) {
            if (link.hasAttribute('target') && link.getAttribute('target') === '_blank') {
                if (!link.querySelector('.screen-reader-text')) {
                    var srText = document.createElement('span');
                    srText.className = 'screen-reader-text';
                    srText.textContent = ' (öffnet in neuem Tab)';
                    srText.style.cssText = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;';
                    link.appendChild(srText);
                }
            }
        });
    }

    /**
     * Public API for external access
     */
    window.YAA = window.YAA || {};
    
    /**
     * Toggle description by ID
     * @param {string} id - The description ID (without 'desc-' prefix)
     */
    window.YAA.toggleDescription = function(id) {
        var description = document.getElementById('desc-' + id);
        var button = description ? description.parentElement.querySelector('.yaa-read-more') : null;
        
        if (description && button) {
            toggleDescription(description, button);
        }
    };

    /**
     * Refresh all components (useful after AJAX content load)
     */
    window.YAA.refresh = function() {
        initReadMoreButtons();
        initLazyLoadImages();
        initAccessibilityEnhancements();
        initImageErrorHandling();
    };

    /**
     * Manually check all images for errors
     * Useful after dynamically loading content
     */
    window.YAA.checkImages = function() {
        initImageErrorHandling();
    };

    /**
     * Force re-check of a specific image
     * @param {HTMLImageElement} img - The image element to check
     */
    window.YAA.recheckImage = function(img) {
        if (img && img.tagName === 'IMG') {
            // Reset fallback state
            img.setAttribute('data-fallback-attempted', 'false');
            
            if (img.complete && (img.naturalWidth === 0 || img.naturalHeight === 0)) {
                attemptImageFallback(img);
            }
        }
    };

    /**
     * Manually trigger thumbnail fallback for an image
     * @param {HTMLImageElement} img - The image element
     * @returns {boolean} True if thumbnail was attempted
     */
    window.YAA.tryThumbnail = function(img) {
        if (!img || img.tagName !== 'IMG') {
            return false;
        }
        
        var thumbnailUrl = img.getAttribute('data-thumbnail');
        if (thumbnailUrl && thumbnailUrl !== '') {
            img.setAttribute('data-fallback-attempted', 'false');
            attemptImageFallback(img);
            return true;
        }
        
        return false;
    };

    /**
     * NEU: Manually trigger proxy fallback for an image
     * @param {HTMLImageElement} img - The image element
     * @returns {boolean} True if proxy was attempted
     */
    window.YAA.tryProxy = function(img) {
        if (!img || img.tagName !== 'IMG') {
            return false;
        }
        
        if (!isProxyEnabled()) {
            if (window.console) {
                console.warn('YAA: Image proxy is not enabled');
            }
            return false;
        }
        
        var originalSrc = img.getAttribute('data-original-src') || img.src;
        var proxyUrl = getProxyUrl(originalSrc);
        
        if (proxyUrl) {
            img.setAttribute('data-fallback-attempted', 'proxy');
            img.src = proxyUrl;
            return true;
        }
        
        return false;
    };

    /**
     * NEU: Generate proxy URL for external use
     * @param {string} imageUrl - Original image URL
     * @returns {string|null} Proxy URL or null
     */
    window.YAA.getProxyUrl = function(imageUrl) {
        return getProxyUrl(imageUrl);
    };

    /**
     * NEU: Check if proxy is enabled
     * @returns {boolean}
     */
    window.YAA.isProxyEnabled = function() {
        return isProxyEnabled();
    };

    /**
     * Get product data from a grid item
     * @param {HTMLElement} item - The .yaa-item element
     * @returns {Object|null} Product data object
     */
    window.YAA.getProductData = function(item) {
        if (!item || !item.classList.contains('yaa-item')) {
            return null;
        }

        var titleElement = item.querySelector('.yaa-title a');
        var priceElement = item.querySelector('.yaa-price');
        var merchantElement = item.querySelector('.yaa-merchant');
        var imageElement = item.querySelector('.yaa-image-wrapper img');
        var wrapper = item.querySelector('.yaa-image-wrapper');

        return {
            title: titleElement ? titleElement.textContent.trim() : '',
            url: titleElement ? titleElement.href : '',
            price: priceElement ? priceElement.textContent.trim() : '',
            merchant: merchantElement ? merchantElement.textContent.replace('via ', '').trim() : '',
            image: imageElement ? imageElement.src : '',
            originalImage: imageElement ? imageElement.getAttribute('data-original-src') : '',
            thumbnail: imageElement ? imageElement.getAttribute('data-thumbnail') : '',
            proxyUrl: imageElement ? getProxyUrl(imageElement.getAttribute('data-original-src') || imageElement.src) : '',
            isAmazon: item.classList.contains('yaa-amazon'),
            isCustom: item.classList.contains('yaa-custom'),
            isYadore: item.classList.contains('yaa-yadore'),
            isPrime: item.querySelector('.yaa-prime-badge') !== null,
            hasImageError: wrapper ? wrapper.classList.contains('yaa-image-error') : false,
            imageLoaded: imageElement ? imageElement.classList.contains('yaa-img-loaded') : false,
            fallbackAttempted: imageElement ? imageElement.getAttribute('data-fallback-attempted') : null
        };
    };

    /**
     * Get all products in a container
     * @param {string|HTMLElement} container - Container selector or element
     * @returns {Array} Array of product data objects
     */
    window.YAA.getAllProducts = function(container) {
        var containerEl = container;
        
        if (typeof container === 'string') {
            containerEl = document.querySelector(container);
        }
        
        if (!containerEl) {
            containerEl = document;
        }
        
        var items = containerEl.querySelectorAll('.yaa-item');
        var products = [];
        
        items.forEach(function(item) {
            var data = window.YAA.getProductData(item);
            if (data) {
                products.push(data);
            }
        });
        
        return products;
    };

    /**
     * Get count of broken images
     * @returns {number}
     */
    window.YAA.getBrokenImageCount = function() {
        return document.querySelectorAll('.yaa-image-wrapper.yaa-image-error').length;
    };

    /**
     * Get count of images currently loading thumbnails
     * @returns {number}
     */
    window.YAA.getLoadingThumbnailCount = function() {
        return document.querySelectorAll('.yaa-image-wrapper img[data-fallback-attempted="thumbnail"]').length;
    };

    /**
     * NEU: Get count of images currently using proxy
     * @returns {number}
     */
    window.YAA.getProxyImageCount = function() {
        return document.querySelectorAll('.yaa-image-wrapper img[data-fallback-attempted="proxy"]').length;
    };

    /**
     * Get detailed image status for debugging
     * @returns {Object}
     */
    window.YAA.getImageStats = function() {
        var images = document.querySelectorAll('.yaa-image-wrapper img');
        var loaded = 0;
        var broken = 0;
        var loadingThumbnail = 0;
        var loadingProxy = 0;
        var hasThumbnail = 0;
        var hasProxy = 0;
        
        images.forEach(function(img) {
            if (img.classList.contains('yaa-img-loaded')) {
                loaded++;
            }
            
            var fallbackState = img.getAttribute('data-fallback-attempted');
            if (fallbackState === 'complete') {
                broken++;
            } else if (fallbackState === 'thumbnail') {
                loadingThumbnail++;
            } else if (fallbackState === 'proxy') {
                loadingProxy++;
            }
            
            if (img.getAttribute('data-thumbnail')) {
                hasThumbnail++;
            }
            
            var originalSrc = img.getAttribute('data-original-src') || img.src;
            if (getProxyUrl(originalSrc)) {
                hasProxy++;
            }
        });
        
        return {
            total: images.length,
            loaded: loaded,
            broken: broken,
            loadingThumbnail: loadingThumbnail,
            loadingProxy: loadingProxy,
            withThumbnailFallback: hasThumbnail,
            withProxyAvailable: hasProxy,
            proxyEnabled: isProxyEnabled()
        };
    };

})();

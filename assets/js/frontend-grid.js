/**
 * Yadore-Amazon-API Frontend JavaScript
 * Version 1.0.0 - PHP 8.3+ compatible
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
     * Initialize accessibility enhancements
     */
    function initAccessibilityEnhancements() {
        // Add ARIA labels to read more buttons
        const buttons = document.querySelectorAll('.yaa-read-more');
        buttons.forEach(function(button) {
            if (!button.hasAttribute('aria-expanded')) {
                button.setAttribute('aria-expanded', 'false');
            }
            if (!button.hasAttribute('role')) {
                button.setAttribute('role', 'button');
            }
        });

        // Add ARIA labels to product links
        const productLinks = document.querySelectorAll('.yaa-item .yaa-title a, .yaa-item .yaa-button');
        productLinks.forEach(function(link) {
            if (link.hasAttribute('target') && link.getAttribute('target') === '_blank') {
                if (!link.querySelector('.screen-reader-text')) {
                    const srText = document.createElement('span');
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
        const description = document.getElementById('desc-' + id);
        const button = description ? description.parentElement.querySelector('.yaa-read-more') : null;
        
        if (description && button) {
            toggleDescription(description, button);
        }
    };

    /**
     * Refresh all read more buttons (useful after AJAX content load)
     */
    window.YAA.refresh = function() {
        initReadMoreButtons();
        initLazyLoadImages();
        initAccessibilityEnhancements();
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

        const titleElement = item.querySelector('.yaa-title a');
        const priceElement = item.querySelector('.yaa-price');
        const merchantElement = item.querySelector('.yaa-merchant');
        const imageElement = item.querySelector('.yaa-image-wrapper img');

        return {
            title: titleElement ? titleElement.textContent.trim() : '',
            url: titleElement ? titleElement.href : '',
            price: priceElement ? priceElement.textContent.trim() : '',
            merchant: merchantElement ? merchantElement.textContent.replace('via ', '').trim() : '',
            image: imageElement ? imageElement.src : '',
            isAmazon: item.classList.contains('yaa-amazon'),
            isPrime: item.querySelector('.yaa-prime-badge') !== null
        };
    };

})();

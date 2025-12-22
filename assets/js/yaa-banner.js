/**
 * YAA Banner Shortcode JavaScript
 * 
 * Handles scroll behavior and indicators
 * @since 1.7.0
 */

(function() {
    'use strict';

    /**
     * Initialize all banner containers
     */
    function initBanners() {
        const containers = document.querySelectorAll('.yaa-banner-container');
        containers.forEach(initBanner);
    }

    /**
     * Initialize single banner
     */
    function initBanner(container) {
        const wrapper = container.querySelector('.yaa-banner-scroll-wrapper');
        const track = container.querySelector('.yaa-banner-track');
        const arrowLeft = container.querySelector('.yaa-banner-arrow-left');
        const arrowRight = container.querySelector('.yaa-banner-arrow-right');

        if (!wrapper || !track) return;

        // Update scroll indicators
        function updateScrollIndicators() {
            const scrollLeft = wrapper.scrollLeft;
            const maxScroll = wrapper.scrollWidth - wrapper.clientWidth;
            const threshold = 10;

            // Toggle classes based on scroll position
            container.classList.toggle('has-scroll-left', scrollLeft > threshold);
            container.classList.toggle('has-scroll-right', scrollLeft < maxScroll - threshold);
        }

        // Scroll by amount
        function scrollBy(direction) {
            const scrollAmount = wrapper.clientWidth * 0.8;
            wrapper.scrollBy({
                left: direction * scrollAmount,
                behavior: 'smooth'
            });
        }

        // Event listeners
        wrapper.addEventListener('scroll', updateScrollIndicators, { passive: true });

        if (arrowLeft) {
            arrowLeft.addEventListener('click', function(e) {
                e.preventDefault();
                scrollBy(-1);
            });
        }

        if (arrowRight) {
            arrowRight.addEventListener('click', function(e) {
                e.preventDefault();
                scrollBy(1);
            });
        }

        // Handle resize
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(updateScrollIndicators, 100);
        }, { passive: true });

        // Initial check
        updateScrollIndicators();

        // Re-check after images load
        const images = track.querySelectorAll('img');
        images.forEach(function(img) {
            if (img.complete) {
                updateScrollIndicators();
            } else {
                img.addEventListener('load', updateScrollIndicators);
            }
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBanners);
    } else {
        initBanners();
    }

    // Re-initialize on AJAX content load (for dynamic loading)
    document.addEventListener('yaa:content-loaded', initBanners);

})();

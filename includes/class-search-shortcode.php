<?php
/**
 * Search Shortcode Handler
 *
 * @package Yadore_Amazon_API
 * @since 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class YAA_Search_Shortcode
 * 
 * Handles the [yaa_search] shortcode for product search functionality.
 */
final class YAA_Search_Shortcode {

    /**
     * Singleton instance
     */
    private static ?self $instance = null;

    /**
     * Image handler instance
     */
    private ?YAA_Image_Handler $image_handler = null;

    /**
     * Cache handler instance
     */
    private ?YAA_Cache_Handler $cache_handler = null;

    /**
     * Valid sort options
     */
    private const VALID_SORT_OPTIONS = [
        'relevance'    => 'Relevanz',
        'price_asc'    => 'Preis aufsteigend',
        'price_desc'   => 'Preis absteigend',
        'title_asc'    => 'Name A-Z',
        'title_desc'   => 'Name Z-A',
        'rating_desc'  => 'Beste Bewertung',
        'newest'       => 'Neueste zuerst',
    ];

    /**
     * Valid layout options
     */
    private const VALID_LAYOUTS = ['grid', 'list', 'compact', 'table'];

    /**
     * Default shortcode attributes
     */
    private const DEFAULT_ATTS = [
        'placeholder'       => 'Produkt suchen...',
        'button_text'       => 'Suchen',
        'show_filters'      => 'true',
        'show_source_filter'=> 'false',
        'default_sort'      => 'relevance',
        'products_per_page' => 12,
        'max_products'      => 100,
        'show_pagination'   => 'true',
        'layout'            => 'grid',
        'columns'           => 4,
        'columns_tablet'    => 3,
        'columns_mobile'    => 1,
        'show_rating'       => 'true',
        'show_prime'        => 'true',
        'show_availability' => 'true',
        'show_description'  => 'false',
        'description_length'=> 150,
        'target'            => '_blank',
        'nofollow'          => 'true',
        'sponsored'         => 'true',
        'class'             => '',
        'id'                => '',
        'api_source'        => '',
        'category'          => '',
        'min_price'         => '',
        'max_price'         => '',
        'prime_only'        => 'false',
        'min_rating'        => '',
        'button_style'      => 'primary',
        'image_size'        => 'medium',
        'lazy_load'         => 'true',
        'analytics'         => 'true',
        'cache_duration'    => '',
    ];

    /**
     * Current shortcode instance ID
     */
    private int $instance_id = 0;

    /**
     * Get singleton instance
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup(): void {
        throw new \Exception('Cannot unserialize singleton');
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        add_shortcode('yaa_search', [$this, 'render_shortcode']);
        add_shortcode('yaa_product_search', [$this, 'render_shortcode']); // Alias
        
        // AJAX handlers
        add_action('wp_ajax_yaa_product_search', [$this, 'ajax_search']);
        add_action('wp_ajax_nopriv_yaa_product_search', [$this, 'ajax_search']);
        add_action('wp_ajax_yaa_search_suggestions', [$this, 'ajax_suggestions']);
        add_action('wp_ajax_nopriv_yaa_search_suggestions', [$this, 'ajax_suggestions']);
        add_action('wp_ajax_yaa_track_click', [$this, 'ajax_track_click']);
        add_action('wp_ajax_nopriv_yaa_track_click', [$this, 'ajax_track_click']);
        
        // Assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_templates'], 100);
        
        // REST API endpoint
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        register_rest_route('yaa/v1', '/search', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_search'],
            'permission_callback' => '__return_true',
            'args'                => [
                'keyword' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'page' => [
                    'default'           => 1,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default'           => 12,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'sort' => [
                    'default'           => 'relevance',
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    /**
     * Handle REST API search
     */
    public function rest_search(\WP_REST_Request $request): \WP_REST_Response {
        $keyword = $request->get_param('keyword');
        $page = $request->get_param('page');
        $per_page = min($request->get_param('per_page'), 50);
        $sort = $this->validate_sort($request->get_param('sort'));

        $results = $this->perform_search($keyword, $page, $per_page, $sort, '');

        if (is_wp_error($results)) {
            return new \WP_REST_Response([
                'error' => $results->get_error_message()
            ], 500);
        }

        return new \WP_REST_Response($results, 200);
    }

    /**
     * Get image handler instance
     */
    private function get_image_handler(): YAA_Image_Handler {
        if ($this->image_handler === null) {
            $this->image_handler = YAA_Image_Handler::get_instance();
        }
        return $this->image_handler;
    }

    /**
     * Get cache handler instance
     */
    private function get_cache_handler(): YAA_Cache_Handler {
        if ($this->cache_handler === null) {
            $this->cache_handler = YAA_Cache_Handler::get_instance();
        }
        return $this->cache_handler;
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets(): void {
        if (!$this->should_load_assets()) {
            return;
        }

        // Styles
        wp_enqueue_style(
            'yaa-search-style',
            YAA_PLUGIN_URL . 'assets/css/search.css',
            [],
            YAA_VERSION
        );

        // Optional: Load layout-specific styles
        $layout = $this->get_current_layout();
        if ($layout !== 'grid' && file_exists(YAA_PLUGIN_DIR . "assets/css/search-{$layout}.css")) {
            wp_enqueue_style(
                "yaa-search-{$layout}-style",
                YAA_PLUGIN_URL . "assets/css/search-{$layout}.css",
                ['yaa-search-style'],
                YAA_VERSION
            );
        }

        // Scripts
        wp_enqueue_script(
            'yaa-search-script',
            YAA_PLUGIN_URL . 'assets/js/yaa-search.js',
            ['jquery'],
            YAA_VERSION,
            true
        );

        // Localize script
        wp_localize_script('yaa-search-script', 'yaaSearch', $this->get_js_config());
    }

    /**
     * Get JavaScript configuration
     */
    private function get_js_config(): array {
        return [
            'ajaxUrl'           => admin_url('admin-ajax.php'),
            'restUrl'           => rest_url('yaa/v1/'),
            'nonce'             => wp_create_nonce('yaa_search_nonce'),
            'restNonce'         => wp_create_nonce('wp_rest'),
            'i18n'              => $this->get_i18n_strings(),
            'sortOptions'       => self::VALID_SORT_OPTIONS,
            'defaultSort'       => $this->get_default_sort(),
            'debounceDelay'     => (int)get_option('yaa_search_debounce', 300),
            'minChars'          => (int)get_option('yaa_search_min_chars', 2),
            'enableSuggestions' => (bool)get_option('yaa_search_suggestions', true),
            'enableAnalytics'   => (bool)get_option('yaa_search_analytics', true),
            'animationSpeed'    => (int)get_option('yaa_animation_speed', 200),
            'lazyLoadOffset'    => (int)get_option('yaa_lazy_load_offset', 100),
            'imageLoadError'    => YAA_PLUGIN_URL . 'assets/images/placeholder.svg',
        ];
    }

    /**
     * Get current layout from shortcode or settings
     */
    private function get_current_layout(): string {
        return get_option('yaa_default_layout', 'grid');
    }

    /**
     * Check if assets should be loaded
     */
    private function should_load_assets(): bool {
        global $post;

        // Always load on specific pages
        $force_load_pages = get_option('yaa_force_load_pages', []);
        if (!empty($force_load_pages) && is_array($force_load_pages)) {
            if (is_page($force_load_pages) || is_single($force_load_pages)) {
                return true;
            }
        }

        // Check for shortcode in current post
        if (is_singular() && $post instanceof WP_Post) {
            if (has_shortcode($post->post_content, 'yaa_search') ||
                has_shortcode($post->post_content, 'yaa_product_search')) {
                return true;
            }
        }

        // Check if globally enabled
        if (get_option('yaa_load_assets_globally', false)) {
            return true;
        }

        // Check widgets
        if (is_active_widget(false, false, 'yaa_search_widget', true)) {
            return true;
        }

        return false;
    }

    /**
     * Get i18n strings for JavaScript
     */
    private function get_i18n_strings(): array {
        return [
            'searching'         => __('Suche läuft...', 'yadore-amazon-api'),
            'noResults'         => __('Keine Produkte gefunden.', 'yadore-amazon-api'),
            'error'             => __('Fehler bei der Suche. Bitte versuchen Sie es erneut.', 'yadore-amazon-api'),
            'networkError'      => __('Netzwerkfehler. Bitte prüfen Sie Ihre Verbindung.', 'yadore-amazon-api'),
            'loadMore'          => __('Mehr laden', 'yadore-amazon-api'),
            'loading'           => __('Laden...', 'yadore-amazon-api'),
            'sortBy'            => __('Sortieren nach:', 'yadore-amazon-api'),
            'filterBy'          => __('Filtern nach:', 'yadore-amazon-api'),
            'showingResults'    => __('Zeige %1$d von %2$d Ergebnissen', 'yadore-amazon-api'),
            'oneResult'         => __('1 Ergebnis gefunden', 'yadore-amazon-api'),
            'multipleResults'   => __('%d Ergebnisse gefunden', 'yadore-amazon-api'),
            'minChars'          => __('Bitte mindestens %d Zeichen eingeben', 'yadore-amazon-api'),
            'primeOnly'         => __('Nur Prime', 'yadore-amazon-api'),
            'allProducts'       => __('Alle Produkte', 'yadore-amazon-api'),
            'viewProduct'       => __('Produkt ansehen', 'yadore-amazon-api'),
            'addToCart'         => __('In den Warenkorb', 'yadore-amazon-api'),
            'outOfStock'        => __('Nicht verfügbar', 'yadore-amazon-api'),
            'inStock'           => __('Auf Lager', 'yadore-amazon-api'),
            'freeShipping'      => __('Kostenloser Versand', 'yadore-amazon-api'),
            'price'             => __('Preis', 'yadore-amazon-api'),
            'rating'            => __('Bewertung', 'yadore-amazon-api'),
            'reviews'           => __('Bewertungen', 'yadore-amazon-api'),
            'source'            => __('Quelle', 'yadore-amazon-api'),
            'clearSearch'       => __('Suche löschen', 'yadore-amazon-api'),
            'searchPlaceholder' => __('Produkt suchen...', 'yadore-amazon-api'),
            'noImage'           => __('Kein Bild verfügbar', 'yadore-amazon-api'),
            'sponsored'         => __('Gesponsert', 'yadore-amazon-api'),
            'advertisement'     => __('Anzeige', 'yadore-amazon-api'),
        ];
    }

    /**
     * Get default sort option from settings
     */
    private function get_default_sort(): string {
        $saved_sort = get_option('yaa_default_sort', 'relevance');
        
        if (array_key_exists($saved_sort, self::VALID_SORT_OPTIONS)) {
            return $saved_sort;
        }
        
        return 'relevance';
    }

    /**
     * Validate sort parameter
     */
    private function validate_sort(string $sort): string {
        $sort = sanitize_key($sort);
        
        if (array_key_exists($sort, self::VALID_SORT_OPTIONS)) {
            return $sort;
        }
        
        return $this->get_default_sort();
    }

    /**
     * Validate layout parameter
     */
    private function validate_layout(string $layout): string {
        if (in_array($layout, self::VALID_LAYOUTS, true)) {
            return $layout;
        }
        
        return 'grid';
    }

    /**
     * Render the shortcode
     */
    public function render_shortcode(array $atts = [], ?string $content = null, string $tag = ''): string {
        $this->instance_id++;
        
        $atts = shortcode_atts(self::DEFAULT_ATTS, $atts, $tag ?: 'yaa_search');
        
        // Validate and sanitize attributes
        $atts = $this->sanitize_attributes($atts);
        
        // Generate unique ID for this instance
        if (empty($atts['id'])) {
            $atts['id'] = 'yaa-search-' . $this->instance_id;
        }

        // Check if any API is configured
        if (!$this->is_any_api_configured()) {
            return $this->render_no_api_message();
        }

        ob_start();
        $this->render_search_form($atts);
        return ob_get_clean() ?: '';
    }

    /**
     * Check if any API is configured
     */
    private function is_any_api_configured(): bool {
        // Check Yadore
        if (class_exists('YAA_Yadore_API')) {
            $yadore = YAA_Yadore_API::get_instance();
            if ($yadore->is_configured()) {
                return true;
            }
        }

        // Check Amazon
        if (class_exists('YAA_Amazon_API')) {
            $amazon = YAA_Amazon_API::get_instance();
            if ($amazon->is_configured()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render message when no API is configured
     */
    private function render_no_api_message(): string {
        if (current_user_can('manage_options')) {
            return sprintf(
                '<div class="yaa-notice yaa-notice-warning">%s <a href="%s">%s</a></div>',
                esc_html__('Keine API konfiguriert.', 'yadore-amazon-api'),
                esc_url(admin_url('admin.php?page=yadore-amazon-api')),
                esc_html__('Jetzt konfigurieren', 'yadore-amazon-api')
            );
        }
        
        return '';
    }

    /**
     * Sanitize shortcode attributes
     */
    private function sanitize_attributes(array $atts): array {
        return [
            'placeholder'        => sanitize_text_field($atts['placeholder']),
            'button_text'        => sanitize_text_field($atts['button_text']),
            'show_filters'       => filter_var($atts['show_filters'], FILTER_VALIDATE_BOOLEAN),
            'show_source_filter' => filter_var($atts['show_source_filter'], FILTER_VALIDATE_BOOLEAN),
            'default_sort'       => $this->validate_sort($atts['default_sort']),
            'products_per_page'  => min(max(absint($atts['products_per_page']), 1), 50),
            'max_products'       => min(max(absint($atts['max_products']), 1), 200),
            'show_pagination'    => filter_var($atts['show_pagination'], FILTER_VALIDATE_BOOLEAN),
            'layout'             => $this->validate_layout($atts['layout']),
            'columns'            => min(max(absint($atts['columns']), 1), 6),
            'columns_tablet'     => min(max(absint($atts['columns_tablet']), 1), 4),
            'columns_mobile'     => min(max(absint($atts['columns_mobile']), 1), 2),
            'show_rating'        => filter_var($atts['show_rating'], FILTER_VALIDATE_BOOLEAN),
            'show_prime'         => filter_var($atts['show_prime'], FILTER_VALIDATE_BOOLEAN),
            'show_availability'  => filter_var($atts['show_availability'], FILTER_VALIDATE_BOOLEAN),
            'show_description'   => filter_var($atts['show_description'], FILTER_VALIDATE_BOOLEAN),
            'description_length' => min(max(absint($atts['description_length']), 50), 500),
            'target'             => in_array($atts['target'], ['_blank', '_self', '_parent', '_top'], true) 
                                    ? $atts['target'] : '_blank',
            'nofollow'           => filter_var($atts['nofollow'], FILTER_VALIDATE_BOOLEAN),
            'sponsored'          => filter_var($atts['sponsored'], FILTER_VALIDATE_BOOLEAN),
            'class'              => sanitize_html_class($atts['class']),
            'id'                 => sanitize_html_class($atts['id']),
            'api_source'         => sanitize_key($atts['api_source']),
            'category'           => sanitize_text_field($atts['category']),
            'min_price'          => $this->sanitize_price($atts['min_price']),
            'max_price'          => $this->sanitize_price($atts['max_price']),
            'prime_only'         => filter_var($atts['prime_only'], FILTER_VALIDATE_BOOLEAN),
            'min_rating'         => $this->sanitize_rating($atts['min_rating']),
            'button_style'       => in_array($atts['button_style'], ['primary', 'secondary', 'outline', 'text'], true)
                                    ? $atts['button_style'] : 'primary',
            'image_size'         => in_array($atts['image_size'], ['small', 'medium', 'large'], true)
                                    ? $atts['image_size'] : 'medium',
            'lazy_load'          => filter_var($atts['lazy_load'], FILTER_VALIDATE_BOOLEAN),
            'analytics'          => filter_var($atts['analytics'], FILTER_VALIDATE_BOOLEAN),
            'cache_duration'     => $this->sanitize_cache_duration($atts['cache_duration']),
        ];
    }

    /**
     * Sanitize price value
     */
    private function sanitize_price(string $price): string {
        if (empty($price)) {
            return '';
        }
        
        $price = preg_replace('/[^\d.,]/', '', $price);
        return $price !== null ? $price : '';
    }

    /**
     * Sanitize rating value
     */
    private function sanitize_rating(string $rating): string {
        if (empty($rating)) {
            return '';
        }
        
        $rating_float = (float)$rating;
        if ($rating_float >= 1 && $rating_float <= 5) {
            return (string)$rating_float;
        }
        
        return '';
    }

    /**
     * Sanitize cache duration
     */
    private function sanitize_cache_duration(string $duration): int {
        if (empty($duration)) {
            return 0; // Use default
        }
        
        $seconds = absint($duration);
        return min($seconds, 86400 * 7); // Max 7 days
    }

    /**
     * Render search form HTML
     */
    private function render_search_form(array $atts): void {
        $wrapper_classes = $this->build_wrapper_classes($atts);
        $data_attributes = $this->build_data_attributes($atts);
        ?>
        <div id="<?php echo esc_attr($atts['id']); ?>" 
             class="<?php echo esc_attr($wrapper_classes); ?>"
             <?php echo $data_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            
            <?php $this->render_search_input($atts); ?>
            
            <?php if ($atts['show_filters']): ?>
                <?php $this->render_filters($atts); ?>
            <?php endif; ?>

            <div class="yaa-search-results-info" aria-live="polite" role="status"></div>
            
            <div class="yaa-search-results"
                 role="region"
                 aria-label="<?php esc_attr_e('Suchergebnisse', 'yadore-amazon-api'); ?>">
                <div class="yaa-search-results-inner"></div>
            </div>

            <?php if ($atts['show_pagination']): ?>
                <?php $this->render_pagination($atts); ?>
            <?php endif; ?>
            
            <div class="yaa-search-loading" aria-hidden="true">
                <div class="yaa-spinner"></div>
                <span class="screen-reader-text"><?php esc_html_e('Laden...', 'yadore-amazon-api'); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Build wrapper CSS classes
     */
    private function build_wrapper_classes(array $atts): string {
        $classes = ['yaa-search-wrapper'];
        
        $classes[] = 'yaa-layout-' . $atts['layout'];
        $classes[] = 'yaa-columns-' . $atts['columns'];
        $classes[] = 'yaa-columns-tablet-' . $atts['columns_tablet'];
        $classes[] = 'yaa-columns-mobile-' . $atts['columns_mobile'];
        $classes[] = 'yaa-image-' . $atts['image_size'];
        
        if ($atts['lazy_load']) {
            $classes[] = 'yaa-lazy-load';
        }
        
        if (!empty($atts['class'])) {
            $classes[] = $atts['class'];
        }
        
        return implode(' ', $classes);
    }

    /**
     * Build data attributes for wrapper
     */
    private function build_data_attributes(array $atts): string {
        $data = [
            'layout'           => $atts['layout'],
            'columns'          => $atts['columns'],
            'per-page'         => $atts['products_per_page'],
            'max-products'     => $atts['max_products'],
            'default-sort'     => $atts['default_sort'],
            'api-source'       => $atts['api_source'],
            'show-rating'      => $atts['show_rating'] ? 'true' : 'false',
            'show-prime'       => $atts['show_prime'] ? 'true' : 'false',
            'show-availability'=> $atts['show_availability'] ? 'true' : 'false',
            'show-description' => $atts['show_description'] ? 'true' : 'false',
            'description-length' => $atts['description_length'],
            'target'           => $atts['target'],
            'nofollow'         => $atts['nofollow'] ? 'true' : 'false',
            'sponsored'        => $atts['sponsored'] ? 'true' : 'false',
            'category'         => $atts['category'],
            'min-price'        => $atts['min_price'],
            'max-price'        => $atts['max_price'],
            'prime-only'       => $atts['prime_only'] ? 'true' : 'false',
            'min-rating'       => $atts['min_rating'],
            'analytics'        => $atts['analytics'] ? 'true' : 'false',
            'cache-duration'   => $atts['cache_duration'],
        ];

        $output = '';
        foreach ($data as $key => $value) {
            if ($value !== '') {
                $output .= sprintf(' data-%s="%s"', esc_attr($key), esc_attr((string)$value));
            }
        }
        
        return $output;
    }

    /**
     * Render search input section
     */
    private function render_search_input(array $atts): void {
        ?>
        <form class="yaa-search-form" role="search" action="" method="get">
            <div class="yaa-search-input-wrapper">
                <label for="<?php echo esc_attr($atts['id']); ?>-input" class="screen-reader-text">
                    <?php esc_html_e('Produktsuche', 'yadore-amazon-api'); ?>
                </label>
                <input type="search" 
                       id="<?php echo esc_attr($atts['id']); ?>-input"
                       class="yaa-search-input" 
                       name="yaa_search"
                       placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                       autocomplete="off"
                       autocapitalize="off"
                       autocorrect="off"
                       spellcheck="false"
                       aria-describedby="<?php echo esc_attr($atts['id']); ?>-description"
                       required>
                
                <button type="button" class="yaa-search-clear" aria-label="<?php esc_attr_e('Suche löschen', 'yadore-amazon-api'); ?>" style="display: none;">
                    <span aria-hidden="true">&times;</span>
                </button>
                
                <button type="submit" class="yaa-search-button yaa-button-<?php echo esc_attr($atts['button_style']); ?>">
                    <span class="yaa-search-button-text"><?php echo esc_html($atts['button_text']); ?></span>
                    <span class="yaa-search-button-spinner" aria-hidden="true"></span>
                </button>
            </div>
            
            <div id="<?php echo esc_attr($atts['id']); ?>-description" class="screen-reader-text">
                <?php esc_html_e('Geben Sie einen Suchbegriff ein und drücken Sie Enter oder klicken Sie auf Suchen.', 'yadore-amazon-api'); ?>
            </div>
            
            <div class="yaa-search-suggestions" role="listbox" aria-label="<?php esc_attr_e('Suchvorschläge', 'yadore-amazon-api'); ?>" style="display: none;"></div>
        </form>
        <?php
    }

    /**
     * Render filter section
     */
    private function render_filters(array $atts): void {
        ?>
        <div class="yaa-search-filters" role="group" aria-label="<?php esc_attr_e('Filteroptionen', 'yadore-amazon-api'); ?>">
            <div class="yaa-filters-row">
                <?php $this->render_sort_dropdown($atts); ?>
                
                <?php if ($atts['show_source_filter'] && $this->has_multiple_sources()): ?>
                    <?php $this->render_source_filter($atts); ?>
                <?php endif; ?>
                
                <?php if ($atts['show_prime']): ?>
                    <?php $this->render_prime_filter($atts); ?>
                <?php endif; ?>
            </div>
            
            <?php $this->render_advanced_filters($atts); ?>
        </div>
        <?php
    }

    /**
     * Render sort dropdown
     */
    private function render_sort_dropdown(array $atts): void {
        ?>
        <div class="yaa-sort-wrapper">
            <label for="<?php echo esc_attr($atts['id']); ?>-sort" class="yaa-filter-label">
                <?php esc_html_e('Sortieren:', 'yadore-amazon-api'); ?>
            </label>
            <select id="<?php echo esc_attr($atts['id']); ?>-sort" class="yaa-sort-select">
                <?php foreach (self::VALID_SORT_OPTIONS as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>" 
                            <?php selected($atts['default_sort'], $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    /**
     * Render source filter
     */
    private function render_source_filter(array $atts): void {
        $sources = $this->get_available_sources();
        ?>
        <div class="yaa-source-wrapper">
            <label for="<?php echo esc_attr($atts['id']); ?>-source" class="yaa-filter-label">
                <?php esc_html_e('Quelle:', 'yadore-amazon-api'); ?>
            </label>
            <select id="<?php echo esc_attr($atts['id']); ?>-source" class="yaa-source-select">
                <option value=""><?php esc_html_e('Alle Quellen', 'yadore-amazon-api'); ?></option>
                <?php foreach ($sources as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>" 
                            <?php selected($atts['api_source'], $value); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    /**
     * Render Prime filter
     */
    private function render_prime_filter(array $atts): void {
        ?>
        <div class="yaa-prime-wrapper">
            <label class="yaa-checkbox-label">
                <input type="checkbox" 
                       class="yaa-prime-checkbox" 
                       <?php checked($atts['prime_only']); ?>>
                <span class="yaa-prime-badge">Prime</span>
                <span class="yaa-checkbox-text"><?php esc_html_e('Nur Prime', 'yadore-amazon-api'); ?></span>
            </label>
        </div>
        <?php
    }

    /**
     * Render advanced filters (collapsible)
     */
    private function render_advanced_filters(array $atts): void {
        if (empty($atts['min_price']) && empty($atts['max_price']) && empty($atts['min_rating'])) {
            return;
        }
        ?>
        <div class="yaa-advanced-filters">
            <button type="button" class="yaa-toggle-filters" aria-expanded="false">
                <?php esc_html_e('Erweiterte Filter', 'yadore-amazon-api'); ?>
                <span class="yaa-toggle-icon" aria-hidden="true"></span>
            </button>
            
            <div class="yaa-advanced-filters-content" hidden>
                <?php if ($atts['min_price'] !== '' || $atts['max_price'] !== ''): ?>
                    <div class="yaa-price-filter">
                        <span class="yaa-filter-label"><?php esc_html_e('Preis:', 'yadore-amazon-api'); ?></span>
                        <input type="number" 
                               class="yaa-min-price" 
                               placeholder="<?php esc_attr_e('Min', 'yadore-amazon-api'); ?>"
                               value="<?php echo esc_attr($atts['min_price']); ?>"
                               min="0"
                               step="0.01">
                        <span class="yaa-price-separator">-</span>
                        <input type="number" 
                               class="yaa-max-price" 
                               placeholder="<?php esc_attr_e('Max', 'yadore-amazon-api'); ?>"
                               value="<?php echo esc_attr($atts['max_price']); ?>"
                               min="0"
                               step="0.01">
                    </div>
                <?php endif; ?>
                
                <?php if ($atts['min_rating'] !== ''): ?>
                    <div class="yaa-rating-filter">
                        <span class="yaa-filter-label"><?php esc_html_e('Mindestbewertung:', 'yadore-amazon-api'); ?></span>
                        <select class="yaa-min-rating-select">
                            <option value=""><?php esc_html_e('Alle', 'yadore-amazon-api'); ?></option>
                            <?php for ($i = 4; $i >= 1; $i--): ?>
                                <option value="<?php echo $i; ?>" <?php selected($atts['min_rating'], (string)$i); ?>>
                                    <?php echo str_repeat('★', $i) . str_repeat('☆', 5 - $i); ?> & mehr
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render pagination section
     */
    private function render_pagination(array $atts): void {
        ?>
        <div class="yaa-search-pagination" role="navigation" aria-label="<?php esc_attr_e('Seitennummerierung', 'yadore-amazon-api'); ?>">
            <button type="button" class="yaa-load-more yaa-button-<?php echo esc_attr($atts['button_style']); ?>" style="display: none;">
                <span class="yaa-load-more-text"><?php esc_html_e('Mehr laden', 'yadore-amazon-api'); ?></span>
                <span class="yaa-load-more-spinner" aria-hidden="true"></span>
            </button>
            
            <div class="yaa-pagination-info" aria-live="polite"></div>
        </div>
        <?php
    }

    /**
     * Check if multiple sources are available
     */
    private function has_multiple_sources(): bool {
        $count = 0;
        
        if (class_exists('YAA_Yadore_API') && YAA_Yadore_API::get_instance()->is_configured()) {
            $count++;
        }
        
        if (class_exists('YAA_Amazon_API') && YAA_Amazon_API::get_instance()->is_configured()) {
            $count++;
        }
        
        return $count > 1;
    }

    /**
     * Get available API sources
     */
    private function get_available_sources(): array {
        $sources = [];
        
        if (class_exists('YAA_Yadore_API') && YAA_Yadore_API::get_instance()->is_configured()) {
            $sources['yadore'] = 'Yadore';
        }
        
        if (class_exists('YAA_Amazon_API') && YAA_Amazon_API::get_instance()->is_configured()) {
            $sources['amazon'] = 'Amazon';
        }
        
        return $sources;
    }

    /**
     * Render JavaScript templates in footer
     */
    public function render_templates(): void {
        if (!$this->should_load_assets()) {
            return;
        }
        ?>
        <script type="text/html" id="tmpl-yaa-product-card">
            <article class="yaa-product-card" data-product-id="{{ data.id }}" data-asin="{{ data.asin }}">
                <div class="yaa-product-image-wrapper">
                    <# if (data.image_url) { #>
                        <img src="{{ data.image_url }}" 
                             alt="{{ data.title }}" 
                             class="yaa-product-image"
                             loading="lazy"
                             onerror="this.src='<?php echo esc_url(YAA_PLUGIN_URL . 'assets/images/placeholder.svg'); ?>'">
                    <# } else { #>
                        <div class="yaa-product-no-image">
                            <span><?php esc_html_e('Kein Bild', 'yadore-amazon-api'); ?></span>
                        </div>
                    <# } #>
                    
                    <# if (data.is_prime) { #>
                        <span class="yaa-prime-badge" title="Amazon Prime">Prime</span>
                    <# } #>
                    
                    <# if (data.discount_percent) { #>
                        <span class="yaa-discount-badge">-{{ data.discount_percent }}%</span>
                    <# } #>
                </div>
                
                <div class="yaa-product-content">
                    <h3 class="yaa-product-title">
                        <a href="{{ data.url }}" 
                           target="{{ data.target }}" 
                           rel="{{ data.rel }}"
                           class="yaa-product-link">
                            {{ data.title }}
                        </a>
                    </h3>
                    
                    <# if (data.show_rating && data.rating) { #>
                        <div class="yaa-product-rating" aria-label="<?php esc_attr_e('Bewertung:', 'yadore-amazon-api'); ?> {{ data.rating }} <?php esc_attr_e('von 5 Sternen', 'yadore-amazon-api'); ?>">
                            <span class="yaa-stars" style="--rating: {{ data.rating }};"></span>
                            <# if (data.reviews_count) { #>
                                <span class="yaa-reviews-count">({{ data.reviews_count }})</span>
                            <# } #>
                        </div>
                    <# } #>
                    
                    <# if (data.show_description && data.description) { #>
                        <p class="yaa-product-description">{{ data.description }}</p>
                    <# } #>
                    
                    <div class="yaa-product-price-wrapper">
                        <# if (data.price) { #>
                            <span class="yaa-product-price">{{ data.price }}</span>
                        <# } #>
                        
                        <# if (data.price_old) { #>
                            <span class="yaa-product-price-old">{{ data.price_old }}</span>
                        <# } #>
                    </div>
                    
                    <# if (data.show_availability) { #>
                        <div class="yaa-product-availability yaa-availability-{{ data.availability_status }}">
                            {{ data.availability_text }}
                        </div>
                    <# } #>
                    
                    <# if (data.sponsored) { #>
                        <span class="yaa-sponsored-label"><?php esc_html_e('Anzeige', 'yadore-amazon-api'); ?></span>
                    <# } #>
                </div>
                
                <div class="yaa-product-actions">
                    <a href="{{ data.url }}" 
                       target="{{ data.target }}" 
                       rel="{{ data.rel }}"
                       class="yaa-product-button">
                        <?php esc_html_e('Zum Angebot', 'yadore-amazon-api'); ?>
                        <span class="yaa-external-icon" aria-hidden="true">↗</span>
                    </a>
                </div>
            </article>
        </script>
        
        <script type="text/html" id="tmpl-yaa-no-results">
            <div class="yaa-no-results">
                <div class="yaa-no-results-icon" aria-hidden="true">🔍</div>
                <h3><?php esc_html_e('Keine Produkte gefunden', 'yadore-amazon-api'); ?></h3>
                <p><?php esc_html_e('Versuchen Sie es mit einem anderen Suchbegriff.', 'yadore-amazon-api'); ?></p>
            </div>
        </script>
        
        <script type="text/html" id="tmpl-yaa-error">
            <div class="yaa-error">
                <div class="yaa-error-icon" aria-hidden="true">⚠️</div>
                <h3><?php esc_html_e('Fehler bei der Suche', 'yadore-amazon-api'); ?></h3>
                <p>{{ data.message }}</p>
                <button type="button" class="yaa-retry-button">
                    <?php esc_html_e('Erneut versuchen', 'yadore-amazon-api'); ?>
                </button>
            </div>
        </script>
        <?php
    }

    /**
     * Handle AJAX search request
     */
    public function ajax_search(): void {
        // Verify nonce
        $nonce = isset($_POST['nonce']) 
            ? sanitize_text_field(wp_unslash($_POST['nonce'])) 
            : '';
            
        if (!wp_verify_nonce($nonce, 'yaa_search_nonce')) {
            wp_send_json_error([
                'message' => __('Sicherheitsüberprüfung fehlgeschlagen.', 'yadore-amazon-api'),
                'code'    => 'invalid_nonce',
            ], 403);
        }

        // Rate limiting
        if ($this->is_rate_limited()) {
            wp_send_json_error([
                'message' => __('Zu viele Anfragen. Bitte warten Sie einen Moment.', 'yadore-amazon-api'),
                'code'    => 'rate_limited',
            ], 429);
        }

        // Get and sanitize parameters
        $keyword = isset($_POST['keyword']) 
            ? sanitize_text_field(wp_unslash($_POST['keyword'])) 
            : '';
            
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? min(absint($_POST['per_page']), 50) : 12;
        
        // FIX: Validate and get sort parameter correctly
        $sort = isset($_POST['sort']) 
            ? $this->validate_sort(sanitize_key(wp_unslash($_POST['sort']))) 
            : $this->get_default_sort();
            
        $api_source = isset($_POST['api_source']) 
            ? sanitize_key(wp_unslash($_POST['api_source'])) 
            : '';
            
        // Additional filters
        $filters = $this->get_search_filters_from_request();

        // Validate keyword
        $min_chars = (int)get_option('yaa_search_min_chars', 2);
        if (empty($keyword) || mb_strlen($keyword) < $min_chars) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Bitte geben Sie mindestens %d Zeichen ein.', 'yadore-amazon-api'),
                    $min_chars
                ),
                'code' => 'keyword_too_short',
            ], 400);
        }

        // Perform search
        $results = $this->perform_search($keyword, $page, $per_page, $sort, $api_source, $filters);

        if (is_wp_error($results)) {
            $this->log_search_error($keyword, $results);
            
            wp_send_json_error([
                'message' => $results->get_error_message(),
                'code'    => $results->get_error_code(),
            ], 500);
        }

        // Track search for analytics
        if (get_option('yaa_search_analytics', true)) {
            $this->track_search($keyword, count($results['products']), $results['total']);
        }

        // FIX: Include current sort in response for JavaScript synchronization
        wp_send_json_success([
            'products'      => $results['products'],
            'total'         => $results['total'],
            'page'          => $page,
            'per_page'      => $per_page,
            'has_more'      => $results['has_more'],
            'current_sort'  => $sort,  // WICHTIG: Sortierung zurückgeben
            'sort_label'    => self::VALID_SORT_OPTIONS[$sort] ?? 'Relevanz',
            'keyword'       => $keyword,
            'source'        => $results['source'] ?? 'mixed',
            'cached'        => $results['cached'] ?? false,
            'filters'       => $filters,
        ]);
    }

    /**
     * Get search filters from request
     */
    private function get_search_filters_from_request(): array {
        $filters = [];
        
        if (isset($_POST['min_price']) && $_POST['min_price'] !== '') {
            $filters['min_price'] = (float)$_POST['min_price'];
        }
        
        if (isset($_POST['max_price']) && $_POST['max_price'] !== '') {
            $filters['max_price'] = (float)$_POST['max_price'];
        }
        
        if (isset($_POST['prime_only']) && filter_var($_POST['prime_only'], FILTER_VALIDATE_BOOLEAN)) {
            $filters['prime_only'] = true;
        }
        
        if (isset($_POST['min_rating']) && $_POST['min_rating'] !== '') {
            $filters['min_rating'] = (float)$_POST['min_rating'];
        }
        
        if (isset($_POST['category']) && !empty($_POST['category'])) {
            $filters['category'] = sanitize_text_field(wp_unslash($_POST['category']));
        }
        
        return $filters;
    }

    /**
     * Handle AJAX suggestions request
     */
    public function ajax_suggestions(): void {
        $nonce = isset($_POST['nonce']) 
            ? sanitize_text_field(wp_unslash($_POST['nonce'])) 
            : '';
            
        if (!wp_verify_nonce($nonce, 'yaa_search_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $keyword = isset($_POST['keyword']) 
            ? sanitize_text_field(wp_unslash($_POST['keyword'])) 
            : '';

        if (mb_strlen($keyword) < 2) {
            wp_send_json_success(['suggestions' => []]);
        }

        $suggestions = $this->get_search_suggestions($keyword);
        
        wp_send_json_success(['suggestions' => $suggestions]);
    }

    /**
     * Get search suggestions
     */
    private function get_search_suggestions(string $keyword): array {
        $cache_key = 'yaa_suggestions_' . md5($keyword);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }

        $suggestions = [];

        // Get from recent searches
        $recent = $this->get_recent_searches($keyword, 5);
        foreach ($recent as $term) {
            $suggestions[] = [
                'term' => $term,
                'type' => 'recent',
            ];
        }

        // Get from popular searches
        $popular = $this->get_popular_searches($keyword, 5);
        foreach ($popular as $term) {
            if (!in_array($term, array_column($suggestions, 'term'), true)) {
                $suggestions[] = [
                    'term' => $term,
                    'type' => 'popular',
                ];
            }
        }

        $suggestions = array_slice($suggestions, 0, 8);
        
        set_transient($cache_key, $suggestions, 5 * MINUTE_IN_SECONDS);
        
        return $suggestions;
    }

    /**
     * Get recent searches matching keyword
     */
    private function get_recent_searches(string $keyword, int $limit): array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yaa_search_log';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT keyword FROM {$table} 
             WHERE keyword LIKE %s 
             ORDER BY searched_at DESC 
             LIMIT %d",
            $wpdb->esc_like($keyword) . '%',
            $limit
        ));
        
        return $results ?: [];
    }

    /**
     * Get popular searches matching keyword
     */
    private function get_popular_searches(string $keyword, int $limit): array {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yaa_search_log';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return [];
        }

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT keyword FROM {$table} 
             WHERE keyword LIKE %s 
             GROUP BY keyword 
             ORDER BY COUNT(*) DESC 
             LIMIT %d",
            $wpdb->esc_like($keyword) . '%',
            $limit
        ));
        
        return $results ?: [];
    }

    /**
     * Handle click tracking AJAX
     */
    public function ajax_track_click(): void {
        $nonce = isset($_POST['nonce']) 
            ? sanitize_text_field(wp_unslash($_POST['nonce'])) 
            : '';
            
        if (!wp_verify_nonce($nonce, 'yaa_search_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $product_id = isset($_POST['product_id']) 
            ? sanitize_text_field(wp_unslash($_POST['product_id'])) 
            : '';
            
        $keyword = isset($_POST['keyword']) 
            ? sanitize_text_field(wp_unslash($_POST['keyword'])) 
            : '';

        if (empty($product_id)) {
            wp_send_json_error(['message' => 'Missing product ID'], 400);
        }

        $this->track_click($product_id, $keyword);
        
        wp_send_json_success(['tracked' => true]);
    }

    /**
     * Check if current request is rate limited
     */
    private function is_rate_limited(): bool {
        $rate_limit = (int)get_option('yaa_search_rate_limit', 30);
        
        if ($rate_limit <= 0) {
            return false;
        }

        $ip = $this->get_client_ip();
        $cache_key = 'yaa_rate_' . md5($ip);
        
        $count = (int)get_transient($cache_key);
        
        if ($count >= $rate_limit) {
            return true;
        }
        
        set_transient($cache_key, $count + 1, MINUTE_IN_SECONDS);
        
        return false;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip(): string {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Perform product search
     */
    private function perform_search(
        string $keyword, 
        int $page, 
        int $per_page, 
        string $sort,
        string $api_source,
        array $filters = []
    ): array|\WP_Error {
        // Determine which API to use
        $api_handler = $this->get_api_handler($api_source);

        if ($api_handler === null) {
            return new \WP_Error(
                'no_api_configured',
                __('Keine API konfiguriert. Bitte konfigurieren Sie Yadore oder Amazon PA-API.', 'yadore-amazon-api')
            );
        }

        // Calculate offset
        $offset = ($page - 1) * $per_page;
        
        // Get cache key including sort and filters
        $cache_key = $this->get_cache_key($keyword, $sort, $api_source, $filters);
        
        // Try to get from cache
        $cached_results = $this->get_cache_handler()->get($cache_key);
        
        if ($cached_results !== false && is_array($cached_results)) {
            $paginated = $this->paginate_results($cached_results, $offset, $per_page);
            $paginated['cached'] = true;
            $paginated['source'] = $api_source ?: 'auto';
            return $paginated;
        }

        // Fetch from API - get more results for sorting
        $max_results = (int)get_option('yaa_max_api_results', 100);
        $api_results = $api_handler->search($keyword, $max_results);

        if (is_wp_error($api_results)) {
            return $api_results;
        }

        if (empty($api_results)) {
            return [
                'products' => [],
                'total'    => 0,
                'has_more' => false,
                'cached'   => false,
                'source'   => $api_source ?: 'auto',
            ];
        }

        // Apply filters first
        $filtered_results = $this->apply_filters($api_results, $filters);

        // Apply sorting AFTER filtering
        $sorted_results = $this->apply_sorting($filtered_results, $sort);

        // Process images
        $processed_results = $this->process_product_images($sorted_results);

        // Cache the processed results
        $cache_duration = (int)get_option('yaa_search_cache_duration', 6 * HOUR_IN_SECONDS);
        $this->get_cache_handler()->set($cache_key, $processed_results, $cache_duration);

        // Return paginated results
        $paginated = $this->paginate_results($processed_results, $offset, $per_page);
        $paginated['cached'] = false;
        $paginated['source'] = $api_source ?: 'auto';
        
        return $paginated;
    }

    /**
     * Get appropriate API handler
     */
    private function get_api_handler(string $preferred_source): ?object {
        $yadore_handler = null;
        $amazon_handler = null;

        // Check Yadore API
        if (class_exists('YAA_Yadore_API')) {
            $yadore = YAA_Yadore_API::get_instance();
            if ($yadore->is_configured()) {
                $yadore_handler = $yadore;
            }
        }

        // Check Amazon API
        if (class_exists('YAA_Amazon_API')) {
            $amazon = YAA_Amazon_API::get_instance();
            if ($amazon->is_configured()) {
                $amazon_handler = $amazon;
            }
        }

        // Return based on preference
        if ($preferred_source === 'yadore' && $yadore_handler !== null) {
            return $yadore_handler;
        }

        if ($preferred_source === 'amazon' && $amazon_handler !== null) {
            return $amazon_handler;
        }

        // Return based on priority setting
        $priority = get_option('yaa_api_priority', 'yadore');
        
        if ($priority === 'yadore') {
            return $yadore_handler ?? $amazon_handler;
        }
        
        return $amazon_handler ?? $yadore_handler;
    }

    /**
     * Generate cache key for search
     */
    private function get_cache_key(string $keyword, string $sort, string $api_source, array $filters = []): string {
        $key_parts = [
            'yaa_search',
            md5(mb_strtolower(trim($keyword))),
            $sort,
            $api_source ?: 'auto',
        ];

        // Include filters in cache key
        if (!empty($filters)) {
            $key_parts[] = md5(serialize($filters));
        }

        return implode('_', $key_parts);
    }

    /**
     * Apply filters to products
     */
    private function apply_filters(array $products, array $filters): array {
        if (empty($filters)) {
            return $products;
        }

        return array_filter($products, function ($product) use ($filters): bool {
            // Price filter
            if (isset($filters['min_price'])) {
                $price = $this->extract_price($product['price'] ?? '');
                if ($price < $filters['min_price']) {
                    return false;
                }
            }
            
            if (isset($filters['max_price'])) {
                $price = $this->extract_price($product['price'] ?? '');
                if ($price !== PHP_FLOAT_MAX && $price > $filters['max_price']) {
                    return false;
                }
            }

            // Prime filter
            if (!empty($filters['prime_only'])) {
                if (empty($product['is_prime'])) {
                    return false;
                }
            }

            // Rating filter
            if (isset($filters['min_rating'])) {
                $rating = (float)($product['rating'] ?? 0);
                if ($rating < $filters['min_rating']) {
                    return false;
                }
            }

            // Category filter
            if (!empty($filters['category'])) {
                $category = $product['category'] ?? '';
                if (stripos($category, $filters['category']) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Apply sorting to products - VERBESSERTE VERSION
     */
    private function apply_sorting(array $products, string $sort): array {
        if (empty($products)) {
            return $products;
        }

        // Ensure we have a valid array with sequential keys
        $products = array_values($products);
        
        // Validate sort parameter
        $sort = $this->validate_sort($sort);

        switch ($sort) {
            case 'price_asc':
                usort($products, function ($a, $b): int {
                    $price_a = $this->extract_price($a['price'] ?? '');
                    $price_b = $this->extract_price($b['price'] ?? '');
                    return $price_a <=> $price_b;
                });
                break;

            case 'price_desc':
                usort($products, function ($a, $b): int {
                    $price_a = $this->extract_price($a['price'] ?? '');
                    $price_b = $this->extract_price($b['price'] ?? '');
                    // Items without price go to the end
                    if ($price_a === PHP_FLOAT_MAX && $price_b === PHP_FLOAT_MAX) {
                        return 0;
                    }
                    if ($price_a === PHP_FLOAT_MAX) {
                        return 1;
                    }
                    if ($price_b === PHP_FLOAT_MAX) {
                        return -1;
                    }
                    return $price_b <=> $price_a;
                });
                break;

            case 'title_asc':
                usort($products, function ($a, $b): int {
                    $title_a = mb_strtolower($a['title'] ?? '');
                    $title_b = mb_strtolower($b['title'] ?? '');
                    return strnatcasecmp($title_a, $title_b);
                });
                break;

            case 'title_desc':
                usort($products, function ($a, $b): int {
                    $title_a = mb_strtolower($a['title'] ?? '');
                    $title_b = mb_strtolower($b['title'] ?? '');
                    return strnatcasecmp($title_b, $title_a);
                });
                break;

            case 'rating_desc':
                usort($products, function ($a, $b): int {
                    $rating_a = (float)($a['rating'] ?? 0);
                    $rating_b = (float)($b['rating'] ?? 0);
                    
                    // Primary sort: rating (descending)
                    if ($rating_a !== $rating_b) {
                        return $rating_b <=> $rating_a;
                    }
                    
                    // Secondary sort: review count (descending)
                    $reviews_a = (int)($a['reviews_count'] ?? 0);
                    $reviews_b = (int)($b['reviews_count'] ?? 0);
                    return $reviews_b <=> $reviews_a;
                });
                break;

            case 'newest':
                usort($products, function ($a, $b): int {
                    // Try multiple date fields
                    $date_a = $this->parse_product_date($a);
                    $date_b = $this->parse_product_date($b);
                    return $date_b <=> $date_a;
                });
                break;

            case 'relevance':
            default:
                // Keep original API order (relevance score)
                break;
        }

        return $products;
    }

    /**
     * Parse product date from various fields
     */
    private function parse_product_date(array $product): int {
        $date_fields = ['release_date', 'created_at', 'publication_date', 'date_added'];
        
        foreach ($date_fields as $field) {
            if (!empty($product[$field])) {
                $timestamp = strtotime($product[$field]);
                if ($timestamp !== false && $timestamp > 0) {
                    return $timestamp;
                }
            }
        }
        
        return 0;
    }

    /**
     * Extract numeric price from price string
     */
    private function extract_price(string $price_string): float {
        if (empty($price_string)) {
            return PHP_FLOAT_MAX; // Push items without price to the end
        }

        // Remove currency symbols, whitespace, and other non-numeric characters except . and ,
        $normalized = preg_replace('/[^\d,.]/', '', $price_string);
        
        if ($normalized === null || $normalized === '') {
            return PHP_FLOAT_MAX;
        }

        // Detect format and normalize
        // German format: 1.234,56 -> 1234.56
        // English format: 1,234.56 -> 1234.56
        // Simple: 1234.56 or 1234,56
        
        $has_dot = strpos($normalized, '.') !== false;
        $has_comma = strpos($normalized, ',') !== false;
        
        if ($has_dot && $has_comma) {
            // Both present - determine which is decimal separator
            $last_dot = strrpos($normalized, '.');
            $last_comma = strrpos($normalized, ',');
            
            if ($last_comma > $last_dot) {
                // German format: 1.234,56
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                // English format: 1,234.56
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($has_comma && !$has_dot) {
            // Only comma - could be decimal or thousands
            // If exactly 2 digits after comma, treat as decimal
            if (preg_match('/,\d{2}$/', $normalized)) {
                $normalized = str_replace(',', '.', $normalized);
            } else {
                // Treat as thousands separator
                $normalized = str_replace(',', '', $normalized);
            }
        }
        // If only dot, keep as is (already English format)

        $price = (float)$normalized;
        
        return $price > 0 ? $price : PHP_FLOAT_MAX;
    }

    /**
     * Process product images
     */
    private function process_product_images(array $products): array {
        $image_handler = $this->get_image_handler();

        foreach ($products as &$product) {
            if (!empty($product['image_url'])) {
                $product_id = $product['asin'] ?? $product['id'] ?? uniqid('prod_', true);
                $shop = $product['shop'] ?? $product['source'] ?? 'amazon';
                
                $processed_image = $image_handler->get_product_image(
                    $product['image_url'],
                    $product_id,
                    $shop
                );
                
                $product['image_url'] = $processed_image;
                $product['image_processed'] = true;
            }
            
            // Ensure required fields exist
            $product['id'] = $product['id'] ?? $product['asin'] ?? uniqid('prod_', true);
            $product['title'] = $product['title'] ?? __('Unbekanntes Produkt', 'yadore-amazon-api');
            $product['url'] = $product['url'] ?? $product['detail_page_url'] ?? '#';
            $product['price'] = $product['price'] ?? '';
            $product['rating'] = $product['rating'] ?? null;
            $product['reviews_count'] = $product['reviews_count'] ?? null;
            $product['is_prime'] = $product['is_prime'] ?? false;
            $product['availability'] = $product['availability'] ?? '';
        }

        return $products;
    }

    /**
     * Paginate results
     */
    private function paginate_results(array $products, int $offset, int $per_page): array {
        $total = count($products);
        $paginated = array_slice($products, $offset, $per_page);

        return [
            'products' => array_values($paginated),
            'total'    => $total,
            'has_more' => ($offset + $per_page) < $total,
        ];
    }

    /**
     * Track search for analytics
     */
    private function track_search(string $keyword, int $results_shown, int $total_results): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yaa_search_log';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }

        $wpdb->insert(
            $table,
            [
                'keyword'        => mb_substr($keyword, 0, 255),
                'results_count'  => $total_results,
                'results_shown'  => $results_shown,
                'user_ip'        => $this->anonymize_ip($this->get_client_ip()),
                'user_agent'     => mb_substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'searched_at'    => current_time('mysql'),
            ],
            ['%s', '%d', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * Track product click
     */
    private function track_click(string $product_id, string $keyword): void {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yaa_click_log';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }

        $wpdb->insert(
            $table,
            [
                'product_id'  => $product_id,
                'keyword'     => mb_substr($keyword, 0, 255),
                'user_ip'     => $this->anonymize_ip($this->get_client_ip()),
                'clicked_at'  => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s']
        );
    }

    /**
     * Anonymize IP address for GDPR compliance
     */
    private function anonymize_ip(string $ip): string {
        if (!get_option('yaa_anonymize_ip', true)) {
            return $ip;
        }

        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip) ?? $ip;
        }
        
        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return preg_replace('/:[^:]+$/', ':0', $ip) ?? $ip;
        }
        
        return '0.0.0.0';
    }

    /**
     * Log search error
     */
    private function log_search_error(string $keyword, \WP_Error $error): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log(sprintf(
            'YAA Search Error: Keyword="%s", Code=%s, Message=%s',
            $keyword,
            $error->get_error_code(),
            $error->get_error_message()
        ));
    }
}

<?php
/**
 * Search Shortcode Handler
 *
 * @package Yadore_Amazon_API
 * @since 1.0.0
 * @version 1.7.4 - Added yadore_search alias, initial_keywords, initial_count support
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class YAA_Search_Shortcode
 * 
 * Handles the [yaa_search] and [yadore_search] shortcode for product search functionality.
 */
final class YAA_Search_Shortcode {

    /**
     * Singleton instance
     */
    private static ?self $instance = null;

    /**
     * Yadore API instance (injected)
     */
    private ?YAA_Yadore_API $yadore_api;

    /**
     * Amazon API instance (injected)
     */
    private ?YAA_Amazon_PAAPI $amazon_api;

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
        'rel_desc'     => 'Relevanz', // Alias für Yadore API Kompatibilität
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
     * UPDATED: Added initial_keywords, initial_count, sort alias
     */
    private const DEFAULT_ATTS = [
        'placeholder'       => 'Produkt suchen...',
        'button_text'       => 'Suchen',
        'show_filters'      => 'true',
        'show_source_filter'=> 'false',
        'default_sort'      => 'relevance',
        'sort'              => '',  // NEW: Alias for default_sort (Yadore compatibility)
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
        // NEW: Initial products support
        'initial_keywords'  => '',  // Keywords for initial product display
        'initial_count'     => 6,   // Number of initial products to show
        'show_reset'        => 'true', // Show reset button after search
    ];

    /**
     * Current shortcode instance ID
     */
    private int $instance_id = 0;

    /**
     * Constructor - PUBLIC für Dependency Injection
     * 
     * @param YAA_Yadore_API|null $yadore_api Yadore API instance
     * @param YAA_Amazon_PAAPI|null $amazon_api Amazon PA-API instance
     */
    public function __construct(?YAA_Yadore_API $yadore_api = null, ?YAA_Amazon_PAAPI $amazon_api = null) {
        $this->yadore_api = $yadore_api;
        $this->amazon_api = $amazon_api;
        
        // Singleton-Instanz setzen für statischen Zugriff
        if (self::$instance === null) {
            self::$instance = $this;
        }
        
        $this->init_hooks();
    }

    /**
     * Get singleton instance (für Rückwärtskompatibilität)
     * 
     * @param YAA_Yadore_API|null $yadore_api
     * @param YAA_Amazon_PAAPI|null $amazon_api
     * @return self
     */
    public static function get_instance(?YAA_Yadore_API $yadore_api = null, ?YAA_Amazon_PAAPI $amazon_api = null): self {
        if (self::$instance === null) {
            self::$instance = new self($yadore_api, $amazon_api);
        }
        return self::$instance;
    }

    /**
     * Initialize hooks
     * UPDATED: Added yadore_search alias
     */
    private function init_hooks(): void {
        // Primary shortcodes
        add_shortcode('yaa_search', [$this, 'render_shortcode']);
        add_shortcode('yaa_product_search', [$this, 'render_shortcode']); // Alias
        add_shortcode('yadore_search', [$this, 'render_shortcode']);      // NEW: Alias for compatibility
        
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
    private function get_image_handler(): ?YAA_Image_Handler {
        if ($this->image_handler === null && class_exists('YAA_Image_Handler')) {
            $this->image_handler = new YAA_Image_Handler();
        }
        return $this->image_handler;
    }

    /**
     * Get cache handler instance
     */
    private function get_cache_handler(): ?YAA_Cache_Handler {
        if ($this->cache_handler === null && class_exists('YAA_Cache_Handler')) {
            $this->cache_handler = new YAA_Cache_Handler();
        }
        return $this->cache_handler;
    }

    /**
     * Enqueue frontend assets
     * UPDATED: Check for yadore_search shortcode as well
     */
    public function enqueue_assets(): void {
        if (!$this->should_load_assets()) {
            return;
        }

        // Styles
        wp_enqueue_style(
            'yaa-search-style',
            YAA_PLUGIN_URL . 'assets/css/yaa-search.css',
            [],
            YAA_VERSION
        );

        // Optional: Load layout-specific styles
        $layout = $this->get_current_layout();
        if ($layout !== 'grid') {
            $layout_file = YAA_PLUGIN_PATH . "assets/css/yaa-search-{$layout}.css";
            
            if (file_exists($layout_file)) {
                wp_enqueue_style(
                    "yaa-search-{$layout}-style",
                    YAA_PLUGIN_URL . "assets/css/yaa-search-{$layout}.css",
                    ['yaa-search-style'],
                    YAA_VERSION
                );
            }
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
        wp_localize_script('yaa-search-script', 'yadoreSearch', $this->get_js_config());
    }

    /**
     * Get JavaScript configuration
     */
    private function get_js_config(): array {
        return [
            'ajaxurl'           => admin_url('admin-ajax.php'),
            'restUrl'           => rest_url('yaa/v1/'),
            'nonce'             => wp_create_nonce('yaa_search_nonce'),
            'restNonce'         => wp_create_nonce('wp_rest'),
            'i18n'              => $this->get_i18n_strings(),
            'sortOptions'       => self::VALID_SORT_OPTIONS,
            'defaultSort'       => $this->get_default_sort(),
            'debounceDelay'     => (int)yaa_get_option('search_debounce', 300),
            'minChars'          => (int)yaa_get_option('search_min_chars', 2),
            'enableSuggestions' => (bool)yaa_get_option('search_suggestions', true),
            'enableAnalytics'   => (bool)yaa_get_option('search_analytics', true),
            'animationSpeed'    => 200,
            'lazyLoadOffset'    => 100,
            'imageLoadError'    => YAA_PLUGIN_URL . 'assets/images/placeholder.svg',
            'debug'             => defined('WP_DEBUG') && WP_DEBUG,
        ];
    }

    /**
     * Get current layout from shortcode or settings
     */
    private function get_current_layout(): string {
        return yaa_get_option('default_layout', 'grid');
    }

    /**
     * Check if assets should be loaded
     * UPDATED: Check for yadore_search shortcode as well
     */
    private function should_load_assets(): bool {
        global $post;

        // Check for shortcode in current post
        if (is_singular() && $post instanceof WP_Post) {
            if (has_shortcode($post->post_content, 'yaa_search') ||
                has_shortcode($post->post_content, 'yaa_product_search') ||
                has_shortcode($post->post_content, 'yadore_search')) { // NEW
                return true;
            }
        }

        // Check if globally enabled
        if (yaa_get_option('load_assets_globally', 'no') === 'yes') {
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
            'no_results'        => __('Keine Produkte gefunden.', 'yadore-amazon-api'),
            'error'             => __('Fehler bei der Suche. Bitte versuchen Sie es erneut.', 'yadore-amazon-api'),
            'networkError'      => __('Netzwerkfehler. Bitte prüfen Sie Ihre Verbindung.', 'yadore-amazon-api'),
            'load_more'         => __('Mehr laden', 'yadore-amazon-api'),
            'loading'           => __('Laden...', 'yadore-amazon-api'),
            'sortBy'            => __('Sortieren nach:', 'yadore-amazon-api'),
            'filterBy'          => __('Filtern nach:', 'yadore-amazon-api'),
            'results_for'       => __('Ergebnisse für', 'yadore-amazon-api'),
            'one_result'        => __('1 Ergebnis', 'yadore-amazon-api'),
            'multiple_results'  => __('%d Ergebnisse', 'yadore-amazon-api'),
            'min_chars'         => __('Bitte mindestens %d Zeichen eingeben', 'yadore-amazon-api'),
            'primeOnly'         => __('Nur Prime', 'yadore-amazon-api'),
            'allProducts'       => __('Alle Produkte', 'yadore-amazon-api'),
            'view_offer'        => __('Zum Angebot', 'yadore-amazon-api'),
            'reset'             => __('← Zurück zu Empfehlungen', 'yadore-amazon-api'),
            'sponsored'         => __('Anzeige', 'yadore-amazon-api'),
            'try_different'     => __('Versuchen Sie es mit einem anderen Suchbegriff.', 'yadore-amazon-api'),
            'products'          => __('Produkte', 'yadore-amazon-api'),
            'offline'           => __('Keine Internetverbindung', 'yadore-amazon-api'),
            'retrying'          => __('Verbindungsfehler. Neuer Versuch in {s} Sekunden...', 'yadore-amazon-api'),
        ];
    }

    /**
     * Get default sort option from settings
     */
    private function get_default_sort(): string {
        // First check search-specific setting, then fallback to yadore default
        $saved_sort = yaa_get_option('search_default_sort', '');
        
        if ($saved_sort === '') {
            $saved_sort = yaa_get_option('yadore_default_sort', 'rel_desc');
        }
        
        // Map rel_desc to relevance for frontend
        if ($saved_sort === 'rel_desc') {
            return 'relevance';
        }
        
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
        
        // Map rel_desc to relevance
        if ($sort === 'rel_desc') {
            return 'relevance';
        }
        
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
     * UPDATED: Added initial_keywords and initial_count support
     */
    public function render_shortcode(array $atts = [], ?string $content = null, string $tag = ''): string {
        $this->instance_id++;
        
        $atts = shortcode_atts(self::DEFAULT_ATTS, $atts, $tag ?: 'yaa_search');
        
        // Handle 'sort' as alias for 'default_sort'
        if (!empty($atts['sort']) && empty($atts['default_sort'])) {
            $atts['default_sort'] = $atts['sort'];
        } elseif (!empty($atts['sort'])) {
            $atts['default_sort'] = $atts['sort']; // sort takes precedence
        }
        
        // Validate and sanitize attributes
        $atts = $this->sanitize_attributes($atts);
        
        // Generate unique ID for this instance
        if (empty($atts['id'])) {
            $atts['id'] = 'yadore-search-' . $this->instance_id;
        }

        // Check if any API is configured
        if (!$this->is_any_api_configured()) {
            return $this->render_no_api_message();
        }

        // Fetch initial products if initial_keywords is set
        $initial_products = [];
        $has_initial = false;
        
        if (!empty($atts['initial_keywords'])) {
            $initial_products = $this->fetch_initial_products($atts);
            $has_initial = !empty($initial_products);
        }

        ob_start();
        $this->render_search_form($atts, $initial_products, $has_initial);
        return ob_get_clean() ?: '';
    }

    /**
     * NEW: Fetch initial products for display
     * 
     * @param array $atts Sanitized attributes
     * @return array Products array
     */
    private function fetch_initial_products(array $atts): array {
        $keyword = trim($atts['initial_keywords']);
        $limit = (int) $atts['initial_count'];
        $sort = $atts['default_sort'];
        
        if ($keyword === '' || $limit <= 0) {
            return [];
        }
        
        // Map frontend sort to API sort
        $api_sort = $sort;
        if ($sort === 'relevance') {
            $api_sort = 'rel_desc';
        }
        
        // Determine which API to use
        $api_source = $atts['api_source'];
        
        // Try Yadore first if configured
        if (($api_source === '' || $api_source === 'yadore') && 
            $this->yadore_api !== null && $this->yadore_api->is_configured()) {
            
            $result = $this->yadore_api->fetch([
                'keyword' => $keyword,
                'limit'   => $limit,
                'sort'    => $api_sort,
                'market'  => yaa_get_option('yadore_market', 'de'),
            ]);
            
            if (!is_wp_error($result) && !empty($result)) {
                return $this->normalize_products_for_display($result, 'yadore');
            }
        }
        
        // Fallback to Amazon if configured
        if (($api_source === '' || $api_source === 'amazon') && 
            $this->amazon_api !== null && $this->amazon_api->is_configured()) {
            
            $category = !empty($atts['category']) ? $atts['category'] : 'All';
            $result = $this->amazon_api->search_items($keyword, $category, min($limit, 10));
            
            if (!is_wp_error($result) && !empty($result)) {
                return $this->normalize_products_for_display($result, 'amazon');
            }
        }
        
        return [];
    }

    /**
     * NEW: Normalize products for display (both initial and search results)
     * 
     * @param array $products Raw products from API
     * @param string $source Source identifier (yadore, amazon)
     * @return array Normalized products
     */
    private function normalize_products_for_display(array $products, string $source): array {
        $normalized = [];
        
        foreach ($products as $product) {
            $item = [
                'id'          => $product['id'] ?? $product['asin'] ?? uniqid('prod_'),
                'asin'        => $product['asin'] ?? '',
                'title'       => $product['title'] ?? '',
                'description' => $product['description'] ?? '',
                'url'         => $product['url'] ?? '#',
                'image_url'   => $product['image']['url'] ?? $product['image_url'] ?? '',
                'price'       => $product['price']['display'] ?? '',
                'price_amount'=> $product['price']['amount'] ?? '',
                'currency'    => $product['price']['currency'] ?? 'EUR',
                'merchant'    => $product['merchant']['name'] ?? '',
                'source'      => $source,
                'is_prime'    => !empty($product['is_prime']),
                'rating'      => $product['rating'] ?? null,
                'reviews_count' => $product['reviews_count'] ?? null,
            ];
            
            // Format price if not already formatted
            if (empty($item['price']) && !empty($item['price_amount'])) {
                $item['price'] = number_format((float)$item['price_amount'], 2, ',', '.') . ' ' . $item['currency'];
            }
            
            $normalized[] = $item;
        }
        
        return $normalized;
    }

    /**
     * Check if any API is configured
     */
    private function is_any_api_configured(): bool {
        // Check injected Yadore API
        if ($this->yadore_api !== null && $this->yadore_api->is_configured()) {
            return true;
        }

        // Check injected Amazon API
        if ($this->amazon_api !== null && $this->amazon_api->is_configured()) {
            return true;
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
                esc_url(admin_url('admin.php?page=yaa-settings')),
                esc_html__('Jetzt konfigurieren', 'yadore-amazon-api')
            );
        }
        
        return '';
    }

    /**
     * Sanitize shortcode attributes
     * UPDATED: Added initial_keywords, initial_count, sort handling
     */
    private function sanitize_attributes(array $atts): array {
        // Handle sort alias - map rel_desc to relevance
        $default_sort = $atts['default_sort'] ?? 'relevance';
        if ($default_sort === 'rel_desc') {
            $default_sort = 'relevance';
        }
        
        return [
            'placeholder'        => sanitize_text_field($atts['placeholder']),
            'button_text'        => sanitize_text_field($atts['button_text']),
            'show_filters'       => filter_var($atts['show_filters'], FILTER_VALIDATE_BOOLEAN),
            'show_source_filter' => filter_var($atts['show_source_filter'], FILTER_VALIDATE_BOOLEAN),
            'default_sort'       => $this->validate_sort($default_sort),
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
            // NEW: Initial products
            'initial_keywords'   => sanitize_text_field($atts['initial_keywords']),
            'initial_count'      => min(max(absint($atts['initial_count']), 1), 24),
            'show_reset'         => filter_var($atts['show_reset'], FILTER_VALIDATE_BOOLEAN),
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
            return 0;
        }
        
        $seconds = absint($duration);
        return min($seconds, 86400 * 7);
    }

    /**
     * Render search form HTML
     * UPDATED: Added initial products rendering
     * 
     * @param array $atts Sanitized attributes
     * @param array $initial_products Initial products to display
     * @param bool $has_initial Whether initial products exist
     */
    private function render_search_form(array $atts, array $initial_products = [], bool $has_initial = false): void {
        $wrapper_classes = $this->build_wrapper_classes($atts);
        $data_attributes = $this->build_data_attributes($atts, $has_initial);
        ?>
        <div id="<?php echo esc_attr($atts['id']); ?>" 
             class="<?php echo esc_attr($wrapper_classes); ?>"
             <?php echo $data_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            
            <?php $this->render_search_input($atts); ?>
            
            <?php if ($atts['show_filters']): ?>
                <?php $this->render_filters($atts); ?>
            <?php endif; ?>

            <div class="yaa-search-results-info" aria-live="polite" role="status" style="display: none;">
                <span class="yaa-search-results-count"></span>
                <span class="yaa-search-results-query"></span>
            </div>
            
            <?php // Initial Products Section ?>
            <?php if ($has_initial): ?>
                <div class="yadore-initial-products">
                    <div class="yaa-search-results-inner">
                        <?php foreach ($initial_products as $product): ?>
                            <?php $this->render_product_card($product, $atts); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php // Search Results Section (initially hidden) ?>
            <div class="yadore-search-results" style="display: none;"
                 role="region"
                 aria-label="<?php esc_attr_e('Suchergebnisse', 'yadore-amazon-api'); ?>">
                <div class="yadore-search-results-grid yaa-search-results-inner"></div>
            </div>

            <?php // Reset Button (shown after search) ?>
            <?php if ($atts['show_reset'] && $has_initial): ?>
                <button type="button" class="yadore-reset-button" style="display: none;">
                    <?php esc_html_e('← Zurück zu Empfehlungen', 'yadore-amazon-api'); ?>
                </button>
            <?php endif; ?>

            <?php if ($atts['show_pagination']): ?>
                <?php $this->render_pagination($atts); ?>
            <?php endif; ?>
            
            <div class="yaa-search-loading yadore-search-loading" aria-hidden="true" style="display: none;">
                <div class="yaa-spinner"></div>
                <span class="screen-reader-text"><?php esc_html_e('Laden...', 'yadore-amazon-api'); ?></span>
            </div>
            
            <div class="yadore-search-status yaa-status" style="display: none;"></div>
        </div>
        <?php
    }

    /**
     * NEW: Render a single product card
     * 
     * @param array $product Product data
     * @param array $atts Shortcode attributes
     */
    private function render_product_card(array $product, array $atts): void {
        $target = esc_attr($atts['target']);
        $rel_parts = [];
        if ($atts['nofollow']) $rel_parts[] = 'nofollow';
        if ($atts['sponsored']) $rel_parts[] = 'sponsored';
        if ($atts['target'] === '_blank') {
            $rel_parts[] = 'noopener';
            $rel_parts[] = 'noreferrer';
        }
        $rel = implode(' ', $rel_parts);
        
        $source_class = 'yaa-source-' . esc_attr($product['source'] ?? 'yadore');
        ?>
        <article class="yaa-product-card yadore-product-card <?php echo $source_class; ?>" 
                 data-product-id="<?php echo esc_attr($product['id']); ?>"
                 data-asin="<?php echo esc_attr($product['asin'] ?? ''); ?>">
            
            <div class="yaa-product-image-wrapper">
                <?php if (!empty($product['image_url'])): ?>
                    <img src="<?php echo esc_url($product['image_url']); ?>" 
                         alt="<?php echo esc_attr($product['title']); ?>" 
                         class="yaa-product-image"
                         loading="lazy">
                <?php else: ?>
                    <div class="yaa-product-no-image">
                        <span><?php esc_html_e('Kein Bild', 'yadore-amazon-api'); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($atts['show_prime'] && !empty($product['is_prime'])): ?>
                    <span class="yaa-prime-badge" title="Amazon Prime">Prime</span>
                <?php endif; ?>
            </div>
            
            <div class="yaa-product-content">
                <h3 class="yaa-product-title">
                    <a href="<?php echo esc_url($product['url']); ?>" 
                       target="<?php echo $target; ?>" 
                       rel="<?php echo esc_attr($rel); ?>">
                        <?php echo esc_html($product['title']); ?>
                    </a>
                </h3>
                
                <?php if ($atts['show_rating'] && !empty($product['rating'])): ?>
                    <div class="yaa-product-rating">
                        <span class="yaa-stars" style="--rating: <?php echo esc_attr($product['rating']); ?>;"></span>
                        <?php if (!empty($product['reviews_count'])): ?>
                            <span class="yaa-reviews-count">(<?php echo esc_html($product['reviews_count']); ?>)</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($atts['show_description'] && !empty($product['description'])): ?>
                    <p class="yaa-product-description">
                        <?php echo esc_html(wp_trim_words($product['description'], 20, '...')); ?>
                    </p>
                <?php endif; ?>
                
                <div class="yaa-product-price-wrapper">
                    <?php if (!empty($product['price'])): ?>
                        <span class="yaa-product-price"><?php echo esc_html($product['price']); ?></span>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($product['merchant'])): ?>
                    <div class="yaa-product-merchant">
                        <?php echo esc_html($product['merchant']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($atts['sponsored']): ?>
                    <span class="yaa-sponsored-label"><?php esc_html_e('Anzeige', 'yadore-amazon-api'); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="yaa-product-actions">
                <a href="<?php echo esc_url($product['url']); ?>" 
                   target="<?php echo $target; ?>" 
                   rel="<?php echo esc_attr($rel); ?>"
                   class="yaa-product-button">
                    <?php echo esc_html(yaa_get_option('button_text_yadore', 'Zum Angebot')); ?>
                    <span class="yaa-external-icon" aria-hidden="true">↗</span>
                </a>
            </div>
        </article>
        <?php
    }

    /**
     * Build wrapper CSS classes
     */
    private function build_wrapper_classes(array $atts): string {
        $classes = ['yaa-search-wrapper', 'yadore-search-container'];
        
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
     * UPDATED: Added has_initial, show_initial, show_reset
     */
    private function build_data_attributes(array $atts, bool $has_initial = false): string {
        $data = [
            'layout'           => $atts['layout'],
            'columns'          => $atts['columns'],
            'per-page'         => $atts['products_per_page'],
            'results-per-page' => $atts['products_per_page'],
            'max-products'     => $atts['max_products'],
            'default-sort'     => $atts['default_sort'],
            'sort'             => $atts['default_sort'],
            'api-source'       => $atts['api_source'],
            'show-rating'      => $atts['show_rating'] ? '1' : '0',
            'show-prime'       => $atts['show_prime'] ? '1' : '0',
            'show-availability'=> $atts['show_availability'] ? '1' : '0',
            'show-description' => $atts['show_description'] ? '1' : '0',
            'description-length' => $atts['description_length'],
            'target'           => $atts['target'],
            'nofollow'         => $atts['nofollow'] ? '1' : '0',
            'sponsored'        => $atts['sponsored'] ? '1' : '0',
            'category'         => $atts['category'],
            'min-price'        => $atts['min_price'],
            'max-price'        => $atts['max_price'],
            'prime-only'       => $atts['prime_only'] ? '1' : '0',
            'min-rating'       => $atts['min_rating'],
            'analytics'        => $atts['analytics'] ? '1' : '0',
            'cache-duration'   => $atts['cache_duration'],
            // NEW: Initial products data
            'has-initial'      => $has_initial ? '1' : '0',
            'show-initial'     => $has_initial ? '1' : '0',
            'show-reset'       => $atts['show_reset'] ? '1' : '0',
            'min-chars'        => 3,
            'live-search'      => '1',
            'enable-pagination'=> $atts['show_pagination'] ? '1' : '0',
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
        <form class="yaa-search-form yadore-search-form" role="search" action="" method="get">
            <div class="yaa-search-input-wrapper">
                <label for="<?php echo esc_attr($atts['id']); ?>-input" class="screen-reader-text">
                    <?php esc_html_e('Produktsuche', 'yadore-amazon-api'); ?>
                </label>
                <input type="search" 
                       id="<?php echo esc_attr($atts['id']); ?>-input"
                       class="yaa-search-input yadore-search-input" 
                       name="yaa_search"
                       placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                       autocomplete="off"
                       autocapitalize="off"
                       autocorrect="off"
                       spellcheck="false"
                       aria-describedby="<?php echo esc_attr($atts['id']); ?>-description">
                
                <button type="button" class="yaa-search-clear yadore-search-clear" 
                        aria-label="<?php esc_attr_e('Suche löschen', 'yadore-amazon-api'); ?>" 
                        style="display: none;">
                    <span aria-hidden="true">&times;</span>
                </button>
                
                <button type="submit" class="yaa-search-button yadore-search-button yaa-button-<?php echo esc_attr($atts['button_style']); ?>">
                    <span class="yaa-search-button-text yadore-search-button-text"><?php echo esc_html($atts['button_text']); ?></span>
                    <span class="yaa-search-button-spinner yadore-search-spinner" aria-hidden="true" style="display: none;"></span>
                </button>
            </div>
            
            <div id="<?php echo esc_attr($atts['id']); ?>-description" class="screen-reader-text">
                <?php esc_html_e('Geben Sie einen Suchbegriff ein und drücken Sie Enter oder klicken Sie auf Suchen.', 'yadore-amazon-api'); ?>
            </div>
            
            <div class="yaa-search-suggestions yadore-search-suggestions" 
                 role="listbox" 
                 aria-label="<?php esc_attr_e('Suchvorschläge', 'yadore-amazon-api'); ?>" 
                 style="display: none;"></div>
        </form>
        <?php
    }

    /**
     * Render filter section
     */
    private function render_filters(array $atts): void {
        ?>
        <div class="yaa-search-filters yadore-filters-wrapper" role="group" aria-label="<?php esc_attr_e('Filteroptionen', 'yadore-amazon-api'); ?>">
            <div class="yaa-filters-row">
                <?php $this->render_sort_dropdown($atts); ?>
                
                <?php if ($atts['show_source_filter'] && $this->has_multiple_sources()): ?>
                    <?php $this->render_source_filter($atts); ?>
                <?php endif; ?>
                
                <?php if ($atts['show_prime']): ?>
                    <?php $this->render_prime_filter($atts); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render sort dropdown
     */
    private function render_sort_dropdown(array $atts): void {
        ?>
        <div class="yaa-sort-wrapper yadore-sort-wrapper">
            <label for="<?php echo esc_attr($atts['id']); ?>-sort" class="yaa-filter-label">
                <?php esc_html_e('Sortieren:', 'yadore-amazon-api'); ?>
            </label>
            <select id="<?php echo esc_attr($atts['id']); ?>-sort" class="yaa-sort-select yadore-sort-select">
                <?php 
                // Filter out rel_desc duplicate (it maps to relevance)
                $sort_options = self::VALID_SORT_OPTIONS;
                unset($sort_options['rel_desc']); // Remove duplicate
                
                foreach ($sort_options as $value => $label): ?>
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
            <select id="<?php echo esc_attr($atts['id']); ?>-source" class="yaa-source-select yadore-source-select">
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
                       class="yaa-prime-checkbox yadore-prime-checkbox" 
                       <?php checked($atts['prime_only']); ?>>
                <span class="yaa-prime-badge">Prime</span>
                <span class="yaa-checkbox-text"><?php esc_html_e('Nur Prime', 'yadore-amazon-api'); ?></span>
            </label>
        </div>
        <?php
    }

    /**
     * Render pagination section
     */
    private function render_pagination(array $atts): void {
        ?>
        <div class="yaa-search-pagination yadore-search-pagination" 
             role="navigation" 
             aria-label="<?php esc_attr_e('Seitennummerierung', 'yadore-amazon-api'); ?>">
            <button type="button" class="yaa-load-more yadore-load-more-button yaa-button-<?php echo esc_attr($atts['button_style']); ?>" style="display: none;">
                <span class="yaa-load-more-text yadore-load-more-text"><?php esc_html_e('Mehr laden', 'yadore-amazon-api'); ?></span>
                <span class="yaa-load-more-spinner yadore-load-more-spinner" aria-hidden="true" style="display: none;"></span>
            </button>
            
            <div class="yaa-pagination-info yadore-pagination-info" aria-live="polite"></div>
        </div>
        <?php
    }

    /**
     * Check if multiple sources are available
     */
    private function has_multiple_sources(): bool {
        $count = 0;
        
        if ($this->yadore_api !== null && $this->yadore_api->is_configured()) {
            $count++;
        }
        
        if ($this->amazon_api !== null && $this->amazon_api->is_configured()) {
            $count++;
        }
        
        return $count > 1;
    }

    /**
     * Get available API sources
     */
    private function get_available_sources(): array {
        $sources = [];
        
        if ($this->yadore_api !== null && $this->yadore_api->is_configured()) {
            $sources['yadore'] = 'Yadore';
        }
        
        if ($this->amazon_api !== null && $this->amazon_api->is_configured()) {
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
        // Templates werden vom JavaScript gerendert
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

        // Get and sanitize parameters
        $keyword = isset($_POST['keyword']) 
            ? sanitize_text_field(wp_unslash($_POST['keyword'])) 
            : '';
            
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? min(absint($_POST['per_page']), 50) : 12;
        
        // Validate sort parameter
        $sort = isset($_POST['sort']) 
            ? $this->validate_sort(sanitize_key(wp_unslash($_POST['sort']))) 
            : $this->get_default_sort();
            
        $api_source = isset($_POST['api_source']) 
            ? sanitize_key(wp_unslash($_POST['api_source'])) 
            : '';

        // Validate keyword
        $min_chars = 2;
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
        $results = $this->perform_search($keyword, $page, $per_page, $sort, $api_source);

        if (is_wp_error($results)) {
            wp_send_json_error([
                'message' => $results->get_error_message(),
                'code'    => $results->get_error_code(),
            ], 500);
        }

        // Return response with sort info
        wp_send_json_success([
            'products'      => $results['products'],
            'total'         => $results['total'],
            'page'          => $page,
            'per_page'      => $per_page,
            'has_more'      => $results['has_more'],
            'current_sort'  => $sort,
            'sort_label'    => self::VALID_SORT_OPTIONS[$sort] ?? 'Relevanz',
            'keyword'       => $keyword,
            'source'        => $results['source'] ?? 'yadore',
            'cached'        => $results['cached'] ?? false,
        ]);
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

        // Simple suggestions based on featured keywords
        $featured = yaa_get_option('yadore_featured_keywords', '');
        $suggestions = [];
        
        if (!empty($featured)) {
            $keywords = array_map('trim', explode(',', $featured));
            $keyword_lower = mb_strtolower($keyword);
            
            foreach ($keywords as $kw) {
                if (mb_strpos(mb_strtolower($kw), $keyword_lower) !== false) {
                    $suggestions[] = [
                        'term' => $kw,
                        'type' => 'popular',
                    ];
                }
            }
        }
        
        wp_send_json_success(['suggestions' => array_slice($suggestions, 0, 5)]);
    }

    /**
     * Handle click tracking AJAX
     */
    public function ajax_track_click(): void {
        // Simple tracking - can be extended
        wp_send_json_success(['tracked' => true]);
    }

    /**
     * Perform product search
     */
    private function perform_search(
        string $keyword, 
        int $page, 
        int $per_page, 
        string $sort,
        string $api_source
    ): array|\WP_Error {
        
        // Map frontend sort to API sort
        $api_sort = $sort;
        if ($sort === 'relevance') {
            $api_sort = 'rel_desc';
        }
        
        $products = [];
        $source = 'yadore';
        
        // Try Yadore first
        if (($api_source === '' || $api_source === 'yadore') && 
            $this->yadore_api !== null && $this->yadore_api->is_configured()) {
            
            $result = $this->yadore_api->fetch([
                'keyword' => $keyword,
                'limit'   => $per_page * 2, // Fetch more for pagination
                'sort'    => $api_sort,
                'market'  => yaa_get_option('yadore_market', 'de'),
            ]);
            
            if (!is_wp_error($result) && !empty($result)) {
                $products = $this->normalize_products_for_display($result, 'yadore');
                $source = 'yadore';
            }
        }
        
        // Fallback to Amazon
        if (empty($products) && ($api_source === '' || $api_source === 'amazon') && 
            $this->amazon_api !== null && $this->amazon_api->is_configured()) {
            
            $result = $this->amazon_api->search_items($keyword, 'All', min($per_page, 10));
            
            if (!is_wp_error($result) && !empty($result)) {
                $products = $this->normalize_products_for_display($result, 'amazon');
                $source = 'amazon';
            }
        }
        
        if (empty($products)) {
            return [
                'products' => [],
                'total'    => 0,
                'has_more' => false,
                'source'   => $source,
                'cached'   => false,
            ];
        }
        
        // Apply sorting if needed
        $products = $this->apply_sorting($products, $sort);
        
        // Paginate
        $total = count($products);
        $offset = ($page - 1) * $per_page;
        $paginated = array_slice($products, $offset, $per_page);
        
        return [
            'products' => $paginated,
            'total'    => $total,
            'has_more' => ($offset + $per_page) < $total,
            'source'   => $source,
            'cached'   => false,
        ];
    }

    /**
     * Apply sorting to products
     */
    private function apply_sorting(array $products, string $sort): array {
        if (empty($products)) {
            return $products;
        }

        switch ($sort) {
            case 'price_asc':
                usort($products, function($a, $b) {
                    $price_a = $this->extract_price($a['price'] ?? '');
                    $price_b = $this->extract_price($b['price'] ?? '');
                    return $price_a <=> $price_b;
                });
                break;

            case 'price_desc':
                usort($products, function($a, $b) {
                    $price_a = $this->extract_price($a['price'] ?? '');
                    $price_b = $this->extract_price($b['price'] ?? '');
                    return $price_b <=> $price_a;
                });
                break;

            case 'title_asc':
                usort($products, fn($a, $b) => strcasecmp($a['title'] ?? '', $b['title'] ?? ''));
                break;

            case 'title_desc':
                usort($products, fn($a, $b) => strcasecmp($b['title'] ?? '', $a['title'] ?? ''));
                break;

            case 'rating_desc':
                usort($products, function($a, $b) {
                    $rating_a = (float)($a['rating'] ?? 0);
                    $rating_b = (float)($b['rating'] ?? 0);
                    return $rating_b <=> $rating_a;
                });
                break;

            default:
                // relevance - keep original order
                break;
        }

        return $products;
    }

    /**
     * Extract numeric price from string
     */
    private function extract_price(string $price_string): float {
        if (empty($price_string)) {
            return PHP_FLOAT_MAX;
        }

        $normalized = preg_replace('/[^\d,.]/', '', $price_string);
        
        if ($normalized === null || $normalized === '') {
            return PHP_FLOAT_MAX;
        }

        // Handle German format (1.234,56) vs English format (1,234.56)
        if (preg_match('/,\d{2}$/', $normalized)) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }

        $price = (float)$normalized;
        return $price > 0 ? $price : PHP_FLOAT_MAX;
    }
}

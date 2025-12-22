<?php
/**
 * Search Shortcode Handler - UNIFIED VERSION
 *
 * @package Yadore_Amazon_API
 * @since 1.0.0
 * @version 1.8.0 - Unified with JavaScript, consistent naming
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class YAA_Search_Shortcode
 * 
 * Handles the [yaa_search], [yaa_product_search] and [yadore_search] shortcodes.
 * 
 * IMPORTANT: This class must stay synchronized with yaa-search.js
 * CSS classes use both 'yaa-' and 'yadore-' prefixes for compatibility.
 */
final class YAA_Search_Shortcode {

    /**
     * Singleton instance
     */
    private static ?self $instance = null;

    /**
     * Yadore API instance
     */
    private ?YAA_Yadore_API $yadore_api;

    /**
     * Amazon API instance
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
     * Valid sort options - MUST MATCH JavaScript sortOptions
     */
    private const SORT_OPTIONS = [
        'relevance'   => 'Relevanz',
        'price_asc'   => 'Preis aufsteigend',
        'price_desc'  => 'Preis absteigend',
        'title_asc'   => 'Name A-Z',
        'title_desc'  => 'Name Z-A',
        'rating_desc' => 'Beste Bewertung',
        'newest'      => 'Neueste zuerst',
    ];

    /**
     * Sort mapping: Frontend -> API
     */
    private const SORT_MAP_TO_API = [
        'relevance'   => 'rel_desc',
        'price_asc'   => 'price_asc',
        'price_desc'  => 'price_desc',
        'title_asc'   => 'title_asc',
        'title_desc'  => 'title_desc',
        'rating_desc' => 'rating_desc',
        'newest'      => 'newest',
    ];

    /**
     * Valid layout options
     */
    private const VALID_LAYOUTS = ['grid', 'list', 'compact', 'table'];

    /**
     * Default shortcode attributes - MUST MATCH JavaScript defaults
     */
    private const DEFAULT_ATTS = [
        // Search UI
        'placeholder'        => 'Produkt suchen...',
        'button_text'        => 'Suchen',
        'show_filters'       => 'true',
        'show_source_filter' => 'false',
        
        // Sorting
        'default_sort'       => 'relevance',
        'sort'               => '',  // Alias
        
        // Pagination
        'products_per_page'  => 12,
        'max_products'       => 100,
        'show_pagination'    => 'true',
        
        // Layout
        'layout'             => 'grid',
        'columns'            => 4,
        'columns_tablet'     => 3,
        'columns_mobile'     => 1,
        
        // Display Options - IMPORTANT: Must match JS settings
        'show_price'         => 'true',
        'show_rating'        => 'true',
        'show_prime'         => 'true',
        'show_availability'  => 'true',
        'show_description'   => 'false',
        'show_merchant'      => 'true',
        'description_length' => 150,
        
        // Links
        'target'             => '_blank',
        'nofollow'           => 'true',
        'sponsored'          => 'true',
        
        // Identifiers
        'class'              => '',
        'id'                 => '',
        
        // API
        'api_source'         => '',
        'category'           => '',
        
        // Filters
        'min_price'          => '',
        'max_price'          => '',
        'prime_only'         => 'false',
        'min_rating'         => '',
        
        // Styling
        'button_style'       => 'primary',
        'image_size'         => 'medium',
        'lazy_load'          => 'true',
        
        // Features
        'analytics'          => 'true',
        'cache_duration'     => '',
        'live_search'        => 'true',
        'min_chars'          => 3,
        'debounce'           => 500,
        
        // Initial Products
        'initial_keywords'   => '',
        'initial_count'      => 6,
        'show_reset'         => 'true',
    ];

    /**
     * Current shortcode instance ID
     */
    private int $instance_id = 0;

    /**
     * Constructor
     */
    public function __construct(?YAA_Yadore_API $yadore_api = null, ?YAA_Amazon_PAAPI $amazon_api = null) {
        $this->yadore_api = $yadore_api;
        $this->amazon_api = $amazon_api;
        
        if (self::$instance === null) {
            self::$instance = $this;
        }
        
        $this->init_hooks();
    }

    /**
     * Get singleton instance
     */
    public static function get_instance(?YAA_Yadore_API $yadore_api = null, ?YAA_Amazon_PAAPI $amazon_api = null): self {
        if (self::$instance === null) {
            self::$instance = new self($yadore_api, $amazon_api);
        }
        return self::$instance;
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Shortcodes
        add_shortcode('yaa_search', [$this, 'render_shortcode']);
        add_shortcode('yaa_product_search', [$this, 'render_shortcode']);
        add_shortcode('yadore_search', [$this, 'render_shortcode']);
        
        // AJAX handlers
        add_action('wp_ajax_yaa_product_search', [$this, 'ajax_search']);
        add_action('wp_ajax_nopriv_yaa_product_search', [$this, 'ajax_search']);
        add_action('wp_ajax_yaa_search_suggestions', [$this, 'ajax_suggestions']);
        add_action('wp_ajax_nopriv_yaa_search_suggestions', [$this, 'ajax_suggestions']);
        add_action('wp_ajax_yaa_track_click', [$this, 'ajax_track_click']);
        add_action('wp_ajax_nopriv_yaa_track_click', [$this, 'ajax_track_click']);
        
        // Assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // REST API
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
                'keyword'  => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'page'     => ['default' => 1, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'per_page' => ['default' => 12, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                'sort'     => ['default' => 'relevance', 'type' => 'string', 'sanitize_callback' => 'sanitize_key'],
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
            return new \WP_REST_Response(['error' => $results->get_error_message()], 500);
        }

        return new \WP_REST_Response($results, 200);
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets(): void {
        if (!$this->should_load_assets()) {
            return;
        }

        // Main Styles
        wp_enqueue_style(
            'yaa-search-style',
            YAA_PLUGIN_URL . 'assets/css/yaa-search.css',
            [],
            YAA_VERSION
        );

        // Main Script
        wp_enqueue_script(
            'yaa-search-script',
            YAA_PLUGIN_URL . 'assets/js/yaa-search.js',
            ['jquery'],
            YAA_VERSION,
            true
        );

        // Localize script - CRITICAL: Must match JS expectations
        wp_localize_script('yaa-search-script', 'yadoreSearch', $this->get_js_config());
    }

    /**
     * Get JavaScript configuration - MUST MATCH JS defaults
     */
    private function get_js_config(): array {
        return [
            'ajaxurl'           => admin_url('admin-ajax.php'),
            'restUrl'           => rest_url('yaa/v1/'),
            'nonce'             => wp_create_nonce('yaa_search_nonce'),
            'restNonce'         => wp_create_nonce('wp_rest'),
            'i18n'              => $this->get_i18n_strings(),
            'sortOptions'       => self::SORT_OPTIONS,  // Use consistent constant
            'defaultSort'       => $this->get_default_sort(),
            'debounceDelay'     => (int)yaa_get_option('search_debounce', 300),
            'minChars'          => (int)yaa_get_option('search_min_chars', 3),
            'enableSuggestions' => (bool)yaa_get_option('search_suggestions', true),
            'enableAnalytics'   => (bool)yaa_get_option('search_analytics', true),
            'animationSpeed'    => 200,
            'lazyLoadOffset'    => 100,
            'imageLoadError'    => YAA_PLUGIN_URL . 'assets/images/placeholder.svg',
            'debug'             => defined('WP_DEBUG') && WP_DEBUG,
            'buttonText'        => yaa_get_option('button_text_yadore', 'Zum Angebot'),
        ];
    }

    /**
     * Get i18n strings for JavaScript
     */
    private function get_i18n_strings(): array {
        return [
            'searching'        => __('Suche läuft...', 'yadore-amazon-api'),
            'no_results'       => __('Keine Produkte gefunden.', 'yadore-amazon-api'),
            'error'            => __('Fehler bei der Suche. Bitte versuchen Sie es erneut.', 'yadore-amazon-api'),
            'networkError'     => __('Netzwerkfehler. Bitte prüfen Sie Ihre Verbindung.', 'yadore-amazon-api'),
            'load_more'        => __('Mehr laden', 'yadore-amazon-api'),
            'loading'          => __('Laden...', 'yadore-amazon-api'),
            'sortBy'           => __('Sortieren nach:', 'yadore-amazon-api'),
            'filterBy'         => __('Filtern nach:', 'yadore-amazon-api'),
            'results_for'      => __('Ergebnisse für', 'yadore-amazon-api'),
            'one_result'       => __('1 Ergebnis', 'yadore-amazon-api'),
            'multiple_results' => __('%d Ergebnisse', 'yadore-amazon-api'),
            'min_chars'        => __('Bitte mindestens %d Zeichen eingeben', 'yadore-amazon-api'),
            'primeOnly'        => __('Nur Prime', 'yadore-amazon-api'),
            'allProducts'      => __('Alle Produkte', 'yadore-amazon-api'),
            'view_offer'       => __('Zum Angebot', 'yadore-amazon-api'),
            'reset'            => __('← Zurück zu Empfehlungen', 'yadore-amazon-api'),
            'sponsored'        => __('Anzeige', 'yadore-amazon-api'),
            'try_different'    => __('Versuchen Sie es mit einem anderen Suchbegriff.', 'yadore-amazon-api'),
            'products'         => __('Produkte', 'yadore-amazon-api'),
            'offline'          => __('Keine Internetverbindung', 'yadore-amazon-api'),
            'retrying'         => __('Verbindungsfehler. Neuer Versuch in {s} Sekunden...', 'yadore-amazon-api'),
            'no_image'         => __('Kein Bild', 'yadore-amazon-api'),
        ];
    }

    /**
     * Check if assets should be loaded
     */
    private function should_load_assets(): bool {
        global $post;

        if (is_singular() && $post instanceof WP_Post) {
            if (has_shortcode($post->post_content, 'yaa_search') ||
                has_shortcode($post->post_content, 'yaa_product_search') ||
                has_shortcode($post->post_content, 'yadore_search')) {
                return true;
            }
        }

        if (yaa_get_option('load_assets_globally', 'no') === 'yes') {
            return true;
        }

        return false;
    }

    /**
     * Get default sort option
     */
    private function get_default_sort(): string {
        $saved_sort = yaa_get_option('search_default_sort', '');
        
        if ($saved_sort === '' || $saved_sort === 'rel_desc') {
            return 'relevance';
        }
        
        return array_key_exists($saved_sort, self::SORT_OPTIONS) ? $saved_sort : 'relevance';
    }

    /**
     * Validate sort parameter
     */
    private function validate_sort(string $sort): string {
        $sort = sanitize_key($sort);
        
        // Map API sort to frontend sort
        if ($sort === 'rel_desc') {
            return 'relevance';
        }
        
        return array_key_exists($sort, self::SORT_OPTIONS) ? $sort : $this->get_default_sort();
    }

    /**
     * Map frontend sort to API sort
     */
    private function map_sort_to_api(string $frontend_sort): string {
        return self::SORT_MAP_TO_API[$frontend_sort] ?? 'rel_desc';
    }

    /**
     * Validate layout parameter
     */
    private function validate_layout(string $layout): string {
        return in_array($layout, self::VALID_LAYOUTS, true) ? $layout : 'grid';
    }

    /**
     * Render the shortcode
     */
    public function render_shortcode(array $atts = [], ?string $content = null, string $tag = ''): string {
        $this->instance_id++;
        
        $atts = shortcode_atts(self::DEFAULT_ATTS, $atts, $tag ?: 'yaa_search');
        
        // Handle 'sort' alias
        if (!empty($atts['sort'])) {
            $atts['default_sort'] = $atts['sort'];
        }
        
        // Sanitize attributes
        $atts = $this->sanitize_attributes($atts);
        
        // Generate unique ID
        if (empty($atts['id'])) {
            $atts['id'] = 'yaa-search-' . $this->instance_id;
        }

        // Check API configuration
        if (!$this->is_any_api_configured()) {
            return $this->render_no_api_message();
        }

        // Fetch initial products
        $initial_products = [];
        $has_initial = false;
        
        if (!empty($atts['initial_keywords'])) {
            $initial_products = $this->fetch_initial_products($atts);
            $has_initial = !empty($initial_products);
        }

        ob_start();
        $this->render_search_container($atts, $initial_products, $has_initial);
        return ob_get_clean() ?: '';
    }

    /**
     * Sanitize shortcode attributes
     */
    private function sanitize_attributes(array $atts): array {
        return [
            'placeholder'        => sanitize_text_field($atts['placeholder']),
            'button_text'        => sanitize_text_field($atts['button_text']),
            'show_filters'       => $this->to_bool($atts['show_filters']),
            'show_source_filter' => $this->to_bool($atts['show_source_filter']),
            'default_sort'       => $this->validate_sort($atts['default_sort']),
            'products_per_page'  => min(max(absint($atts['products_per_page']), 1), 50),
            'max_products'       => min(max(absint($atts['max_products']), 1), 200),
            'show_pagination'    => $this->to_bool($atts['show_pagination']),
            'layout'             => $this->validate_layout($atts['layout']),
            'columns'            => min(max(absint($atts['columns']), 1), 6),
            'columns_tablet'     => min(max(absint($atts['columns_tablet']), 1), 4),
            'columns_mobile'     => min(max(absint($atts['columns_mobile']), 1), 2),
            'show_price'         => $this->to_bool($atts['show_price']),
            'show_rating'        => $this->to_bool($atts['show_rating']),
            'show_prime'         => $this->to_bool($atts['show_prime']),
            'show_availability'  => $this->to_bool($atts['show_availability']),
            'show_description'   => $this->to_bool($atts['show_description']),
            'show_merchant'      => $this->to_bool($atts['show_merchant']),
            'description_length' => min(max(absint($atts['description_length']), 50), 500),
            'target'             => in_array($atts['target'], ['_blank', '_self', '_parent', '_top'], true) 
                                    ? $atts['target'] : '_blank',
            'nofollow'           => $this->to_bool($atts['nofollow']),
            'sponsored'          => $this->to_bool($atts['sponsored']),
            'class'              => sanitize_html_class($atts['class']),
            'id'                 => sanitize_html_class($atts['id']),
            'api_source'         => sanitize_key($atts['api_source']),
            'category'           => sanitize_text_field($atts['category']),
            'min_price'          => $this->sanitize_price($atts['min_price']),
            'max_price'          => $this->sanitize_price($atts['max_price']),
            'prime_only'         => $this->to_bool($atts['prime_only']),
            'min_rating'         => $this->sanitize_rating($atts['min_rating']),
            'button_style'       => in_array($atts['button_style'], ['primary', 'secondary', 'outline', 'text'], true)
                                    ? $atts['button_style'] : 'primary',
            'image_size'         => in_array($atts['image_size'], ['small', 'medium', 'large'], true)
                                    ? $atts['image_size'] : 'medium',
            'lazy_load'          => $this->to_bool($atts['lazy_load']),
            'analytics'          => $this->to_bool($atts['analytics']),
            'live_search'        => $this->to_bool($atts['live_search']),
            'min_chars'          => min(max(absint($atts['min_chars']), 1), 10),
            'debounce'           => min(max(absint($atts['debounce']), 100), 2000),
            'cache_duration'     => $this->sanitize_cache_duration($atts['cache_duration']),
            'initial_keywords'   => sanitize_text_field($atts['initial_keywords']),
            'initial_count'      => min(max(absint($atts['initial_count']), 1), 24),
            'show_reset'         => $this->to_bool($atts['show_reset']),
        ];
    }

    /**
     * Convert to boolean consistently
     */
    private function to_bool($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }
        return (bool) $value;
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
        return ($rating_float >= 1 && $rating_float <= 5) ? (string)$rating_float : '';
    }

    /**
     * Sanitize cache duration
     */
    private function sanitize_cache_duration(string $duration): int {
        if (empty($duration)) {
            return 0;
        }
        return min(absint($duration), 86400 * 7);
    }

    /**
     * Check if any API is configured
     */
    private function is_any_api_configured(): bool {
        if ($this->yadore_api !== null && $this->yadore_api->is_configured()) {
            return true;
        }
        if ($this->amazon_api !== null && $this->amazon_api->is_configured()) {
            return true;
        }
        return false;
    }

    /**
     * Render no API message
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
     * Fetch initial products
     */
    private function fetch_initial_products(array $atts): array {
        $keyword = trim($atts['initial_keywords']);
        $limit = (int) $atts['initial_count'];
        $sort = $atts['default_sort'];
        
        if ($keyword === '' || $limit <= 0) {
            return [];
        }
        
        $api_sort = $this->map_sort_to_api($sort);
        $api_source = $atts['api_source'];
        
        // Try Yadore first
        if (($api_source === '' || $api_source === 'yadore') && 
            $this->yadore_api !== null && $this->yadore_api->is_configured()) {
            
            $result = $this->yadore_api->fetch([
                'keyword' => $keyword,
                'limit'   => $limit,
                'sort'    => $api_sort,
                'market'  => yaa_get_option('yadore_market', 'de'),
            ]);
            
            if (!is_wp_error($result) && !empty($result)) {
                return $this->normalize_products($result, 'yadore');
            }
        }
        
        // Fallback to Amazon
        if (($api_source === '' || $api_source === 'amazon') && 
            $this->amazon_api !== null && $this->amazon_api->is_configured()) {
            
            $category = !empty($atts['category']) ? $atts['category'] : 'All';
            $result = $this->amazon_api->search_items($keyword, $category, min($limit, 10));
            
            if (!is_wp_error($result) && !empty($result)) {
                return $this->normalize_products($result, 'amazon');
            }
        }
        
        return [];
    }

    /**
     * Normalize products - MUST MATCH JavaScript product structure
     */
    private function normalize_products(array $products, string $source): array {
        $normalized = [];
        
        foreach ($products as $product) {
            $normalized[] = [
                // IDs
                'id'              => $product['id'] ?? $product['asin'] ?? uniqid('prod_'),
                'asin'            => $product['asin'] ?? '',
                
                // Content
                'title'           => $product['title'] ?? '',
                'description'     => $product['description'] ?? '',
                'url'             => $product['url'] ?? '#',
                
                // Image
                'image_url'       => $product['image']['url'] ?? $product['image_url'] ?? '',
                
                // Price
                'price'           => $product['price']['display'] ?? $this->format_price($product),
                'price_amount'    => $product['price']['amount'] ?? '',
                'price_old'       => $product['price_old'] ?? '',
                'currency'        => $product['price']['currency'] ?? 'EUR',
                'discount_percent'=> $product['discount_percent'] ?? null,
                
                // Merchant
                'merchant'        => $product['merchant']['name'] ?? $product['merchant'] ?? '',
                
                // Meta
                'source'          => $source,
                'is_prime'        => !empty($product['is_prime']),
                'rating'          => $product['rating'] ?? null,
                'reviews_count'   => $product['reviews_count'] ?? null,
                'availability'    => $product['availability'] ?? null,
                'availability_status' => $product['availability_status'] ?? 'unknown',
                'sponsored'       => $product['sponsored'] ?? false,
            ];
        }
        
        return $normalized;
    }

    /**
     * Format price from product data
     */
    private function format_price(array $product): string {
        $amount = $product['price']['amount'] ?? $product['price_amount'] ?? '';
        if (empty($amount)) {
            return '';
        }
        $currency = $product['price']['currency'] ?? $product['currency'] ?? 'EUR';
        return number_format((float)$amount, 2, ',', '.') . ' ' . $currency;
    }

    /**
     * Render search container
     */
    private function render_search_container(array $atts, array $initial_products, bool $has_initial): void {
        $wrapper_classes = $this->build_wrapper_classes($atts);
        $data_attributes = $this->build_data_attributes($atts, $has_initial);
        ?>
        <div id="<?php echo esc_attr($atts['id']); ?>" 
             class="<?php echo esc_attr($wrapper_classes); ?>"
             <?php echo $data_attributes; // phpcs:ignore ?>>
            
            <?php $this->render_search_form($atts); ?>
            
            <?php if ($atts['show_filters']): ?>
                <?php $this->render_filters($atts); ?>
            <?php endif; ?>

            <div class="yaa-results-info yadore-results-info" aria-live="polite" role="status" style="display: none;">
                <span class="yaa-results-count yadore-results-count"></span>
                <span class="yaa-results-query yadore-results-query"></span>
            </div>
            
            <?php if ($has_initial): ?>
                <div class="yaa-initial-products yadore-initial-products">
                    <div class="yaa-products-grid yadore-products-grid">
                        <?php foreach ($initial_products as $product): ?>
                            <?php $this->render_product_card($product, $atts); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="yaa-search-results yadore-search-results" style="display: none;"
                 role="region"
                 aria-label="<?php esc_attr_e('Suchergebnisse', 'yadore-amazon-api'); ?>">
                <div class="yaa-products-grid yadore-products-grid"></div>
            </div>

            <?php if ($atts['show_reset'] && $has_initial): ?>
                <button type="button" class="yaa-reset-btn yadore-reset-btn" style="display: none;">
                    <?php esc_html_e('← Zurück zu Empfehlungen', 'yadore-amazon-api'); ?>
                </button>
            <?php endif; ?>

            <?php if ($atts['show_pagination']): ?>
                <?php $this->render_pagination($atts); ?>
            <?php endif; ?>
            
            <div class="yaa-loading yadore-loading" aria-hidden="true" style="display: none;">
                <div class="yaa-spinner yadore-spinner"></div>
                <span class="screen-reader-text"><?php esc_html_e('Laden...', 'yadore-amazon-api'); ?></span>
            </div>
            
            <div class="yaa-status yadore-status" style="display: none;"></div>
        </div>
        <?php
    }

    /**
     * Render product card - MUST MATCH JavaScript createProductCard()
     * 
     * IMPORTANT: Keep this HTML structure synchronized with yaa-search.js
     */
    private function render_product_card(array $product, array $atts): void {
        $target = esc_attr($atts['target']);
        $rel = $this->build_rel_attribute($atts, $product);
        $source_class = 'yaa-source-' . esc_attr($product['source'] ?? 'yadore');
        ?>
        <article class="yaa-product-card yadore-product-card <?php echo $source_class; ?>" 
                 data-product-id="<?php echo esc_attr($product['id']); ?>"
                 data-asin="<?php echo esc_attr($product['asin'] ?? ''); ?>"
                 tabindex="0">
            
            <a href="<?php echo esc_url($product['url']); ?>" 
               target="<?php echo $target; ?>" 
               rel="<?php echo esc_attr($rel); ?>"
               class="yaa-product-link yadore-product-link">
                
                <?php // Badges ?>
                <?php if ($atts['show_prime'] && !empty($product['is_prime'])): ?>
                    <span class="yaa-badge-prime yadore-badge-prime" title="Amazon Prime">Prime</span>
                <?php endif; ?>
                
                <?php if (!empty($product['discount_percent'])): ?>
                    <span class="yaa-badge-discount yadore-badge-discount">-<?php echo esc_html($product['discount_percent']); ?>%</span>
                <?php endif; ?>
                
                <?php if (!empty($product['source'])): ?>
                    <span class="yaa-badge-source yadore-badge-source"><?php echo esc_html($product['source']); ?></span>
                <?php endif; ?>
                
                <?php // Image ?>
                <div class="yaa-product-image yadore-product-image">
                    <?php if (!empty($product['image_url'])): ?>
                        <?php if ($atts['lazy_load']): ?>
                            <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                                 data-src="<?php echo esc_url($product['image_url']); ?>" 
                                 alt="<?php echo esc_attr($product['title']); ?>" 
                                 class="yaa-lazy-img yadore-lazy-img"
                                 data-fallback-attempted="false"
                                 loading="lazy">
                        <?php else: ?>
                            <img src="<?php echo esc_url($product['image_url']); ?>" 
                                 alt="<?php echo esc_attr($product['title']); ?>"
                                 loading="lazy">
                        <?php endif; ?>
                        <div class="yaa-img-loader yadore-img-loader"></div>
                    <?php else: ?>
                        <div class="yaa-no-image yadore-no-image">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                            </svg>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php // Content ?>
                <div class="yaa-product-content yadore-product-content">
                    <h3 class="yaa-product-title yadore-product-title"><?php echo esc_html($product['title']); ?></h3>
                    
                    <?php if ($atts['show_rating'] && !empty($product['rating'])): ?>
                        <div class="yaa-product-rating yadore-product-rating" 
                             aria-label="<?php printf(esc_attr__('Bewertung: %s von 5 Sternen', 'yadore-amazon-api'), $product['rating']); ?>">
                            <span class="yaa-stars yadore-stars" style="--rating: <?php echo esc_attr($product['rating']); ?>;"></span>
                            <?php if (!empty($product['reviews_count'])): ?>
                                <span class="yaa-reviews-count yadore-reviews-count">(<?php echo esc_html($product['reviews_count']); ?>)</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($atts['show_description'] && !empty($product['description'])): ?>
                        <p class="yaa-product-desc yadore-product-desc">
                            <?php echo esc_html(wp_trim_words($product['description'], 20, '…')); ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($atts['show_merchant'] && !empty($product['merchant'])): ?>
                        <div class="yaa-product-merchant yadore-product-merchant">
                            <?php echo esc_html($product['merchant']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($atts['show_price'] && !empty($product['price'])): ?>
                        <div class="yaa-price-wrapper yadore-price-wrapper">
                            <span class="yaa-price yadore-price"><?php echo esc_html($product['price']); ?></span>
                            <?php if (!empty($product['price_old'])): ?>
                                <span class="yaa-price-old yadore-price-old"><?php echo esc_html($product['price_old']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($atts['show_availability'] && !empty($product['availability'])): ?>
                        <div class="yaa-availability yadore-availability yaa-availability-<?php echo esc_attr($product['availability_status']); ?>">
                            <?php echo esc_html($product['availability']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($atts['sponsored'] && !empty($product['sponsored'])): ?>
                        <span class="yaa-sponsored yadore-sponsored"><?php esc_html_e('Anzeige', 'yadore-amazon-api'); ?></span>
                    <?php endif; ?>
                </div>
            </a>
            
            <?php // Actions ?>
            <div class="yaa-product-actions yadore-product-actions">
                <a href="<?php echo esc_url($product['url']); ?>" 
                   target="<?php echo $target; ?>" 
                   rel="<?php echo esc_attr($rel); ?>"
                   class="yaa-product-btn yadore-product-btn"
                   data-product-id="<?php echo esc_attr($product['id']); ?>">
                    <?php echo esc_html(yaa_get_option('button_text_yadore', 'Zum Angebot')); ?>
                    <span class="yaa-icon-external yadore-icon-external" aria-hidden="true">↗</span>
                </a>
            </div>
        </article>
        <?php
    }

    /**
     * Build rel attribute for links
     */
    private function build_rel_attribute(array $atts, array $product = []): string {
        $rel = [];
        
        if ($atts['nofollow']) {
            $rel[] = 'nofollow';
        }
        if ($atts['sponsored'] || !empty($product['sponsored'])) {
            $rel[] = 'sponsored';
        }
        if ($atts['target'] === '_blank') {
            $rel[] = 'noopener';
            $rel[] = 'noreferrer';
        }
        
        return implode(' ', $rel);
    }

    /**
     * Build wrapper CSS classes
     */
    private function build_wrapper_classes(array $atts): string {
        $classes = [
            'yaa-search-wrapper',
            'yadore-search-container',
            'yaa-layout-' . $atts['layout'],
            'yaa-columns-' . $atts['columns'],
            'yaa-columns-tablet-' . $atts['columns_tablet'],
            'yaa-columns-mobile-' . $atts['columns_mobile'],
            'yaa-image-' . $atts['image_size'],
        ];
        
        if ($atts['lazy_load']) {
            $classes[] = 'yaa-lazy-load';
        }
        
        if (!empty($atts['class'])) {
            $classes[] = $atts['class'];
        }
        
        return implode(' ', $classes);
    }

    /**
     * Build data attributes - MUST MATCH JavaScript settings parsing
     * 
     * IMPORTANT: Use integers (1/0) for booleans, not strings
     */
    private function build_data_attributes(array $atts, bool $has_initial): string {
        $data = [
            // Layout
            'layout'             => $atts['layout'],
            'columns'            => $atts['columns'],
            
            // Pagination
            'per-page'           => $atts['products_per_page'],
            'max-products'       => $atts['max_products'],
            'enable-pagination'  => $atts['show_pagination'] ? 1 : 0,
            
            // Sorting
            'sort'               => $atts['default_sort'],
            'default-sort'       => $atts['default_sort'],
            
            // API
            'api-source'         => $atts['api_source'],
            'category'           => $atts['category'],
            
            // Display Options (integers for JS parsing)
            'show-price'         => $atts['show_price'] ? 1 : 0,
            'show-rating'        => $atts['show_rating'] ? 1 : 0,
            'show-prime'         => $atts['show_prime'] ? 1 : 0,
            'show-availability'  => $atts['show_availability'] ? 1 : 0,
            'show-description'   => $atts['show_description'] ? 1 : 0,
            'show-merchant'      => $atts['show_merchant'] ? 1 : 0,
            'description-length' => $atts['description_length'],
            
            // Links
            'target'             => $atts['target'],
            'nofollow'           => $atts['nofollow'] ? 1 : 0,
            'sponsored'          => $atts['sponsored'] ? 1 : 0,
            
            // Filters
            'min-price'          => $atts['min_price'],
            'max-price'          => $atts['max_price'],
            'prime-only'         => $atts['prime_only'] ? 1 : 0,
            'min-rating'         => $atts['min_rating'],
            
            // Features
            'analytics'          => $atts['analytics'] ? 1 : 0,
            'lazy-load'          => $atts['lazy_load'] ? 1 : 0,
            'live-search'        => $atts['live_search'] ? 1 : 0,
            'min-chars'          => $atts['min_chars'],
            'debounce'           => $atts['debounce'],
            
            // Initial Products
            'has-initial'        => $has_initial ? 1 : 0,
            'show-initial'       => $has_initial ? 1 : 0,
            'show-reset'         => $atts['show_reset'] ? 1 : 0,
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
     * Render search form
     */
    private function render_search_form(array $atts): void {
        ?>
        <form class="yaa-search-form yadore-search-form" role="search" action="" method="get">
            <div class="yaa-input-wrapper yadore-input-wrapper">
                <label for="<?php echo esc_attr($atts['id']); ?>-input" class="screen-reader-text">
                    <?php esc_html_e('Produktsuche', 'yadore-amazon-api'); ?>
                </label>
                <input type="search" 
                       id="<?php echo esc_attr($atts['id']); ?>-input"
                       class="yaa-input yadore-input" 
                       name="yaa_search"
                       placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                       autocomplete="off"
                       autocapitalize="off"
                       autocorrect="off"
                       spellcheck="false"
                       aria-describedby="<?php echo esc_attr($atts['id']); ?>-desc">
                
                <button type="button" class="yaa-clear-btn yadore-clear-btn" 
                        aria-label="<?php esc_attr_e('Suche löschen', 'yadore-amazon-api'); ?>" 
                        style="display: none;">
                    <span aria-hidden="true">&times;</span>
                </button>
                
                <button type="submit" class="yaa-submit-btn yadore-submit-btn yaa-btn-<?php echo esc_attr($atts['button_style']); ?>">
                    <span class="yaa-btn-text yadore-btn-text"><?php echo esc_html($atts['button_text']); ?></span>
                    <span class="yaa-btn-spinner yadore-btn-spinner" aria-hidden="true" style="display: none;"></span>
                </button>
            </div>
            
            <div id="<?php echo esc_attr($atts['id']); ?>-desc" class="screen-reader-text">
                <?php esc_html_e('Geben Sie einen Suchbegriff ein und drücken Sie Enter oder klicken Sie auf Suchen.', 'yadore-amazon-api'); ?>
            </div>
            
            <div class="yaa-suggestions yadore-suggestions" 
                 role="listbox" 
                 aria-label="<?php esc_attr_e('Suchvorschläge', 'yadore-amazon-api'); ?>" 
                 style="display: none;"></div>
        </form>
        <?php
    }

    /**
     * Render filters
     */
    private function render_filters(array $atts): void {
        ?>
        <div class="yaa-filters yadore-filters" role="group" aria-label="<?php esc_attr_e('Filteroptionen', 'yadore-amazon-api'); ?>">
            <div class="yaa-filters-row yadore-filters-row">
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
            <label for="<?php echo esc_attr($atts['id']); ?>-sort" class="yaa-filter-label yadore-filter-label">
                <?php esc_html_e('Sortieren:', 'yadore-amazon-api'); ?>
            </label>
            <select id="<?php echo esc_attr($atts['id']); ?>-sort" class="yaa-sort-select yadore-sort-select">
                <?php foreach (self::SORT_OPTIONS as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($atts['default_sort'], $value); ?>>
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
        <div class="yaa-source-wrapper yadore-source-wrapper">
            <label for="<?php echo esc_attr($atts['id']); ?>-source" class="yaa-filter-label yadore-filter-label">
                <?php esc_html_e('Quelle:', 'yadore-amazon-api'); ?>
            </label>
            <select id="<?php echo esc_attr($atts['id']); ?>-source" class="yaa-source-select yadore-source-select">
                <option value=""><?php esc_html_e('Alle Quellen', 'yadore-amazon-api'); ?></option>
                <?php foreach ($sources as $value => $label): ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($atts['api_source'], $value); ?>>
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
        <div class="yaa-prime-wrapper yadore-prime-wrapper">
            <label class="yaa-checkbox-label yadore-checkbox-label">
                <input type="checkbox" class="yaa-prime-checkbox yadore-prime-checkbox" <?php checked($atts['prime_only']); ?>>
                <span class="yaa-badge-prime yadore-badge-prime">Prime</span>
                <span class="yaa-checkbox-text yadore-checkbox-text"><?php esc_html_e('Nur Prime', 'yadore-amazon-api'); ?></span>
            </label>
        </div>
        <?php
    }

    /**
     * Render pagination
     */
    private function render_pagination(array $atts): void {
        ?>
        <div class="yaa-pagination yadore-pagination" role="navigation" aria-label="<?php esc_attr_e('Seitennummerierung', 'yadore-amazon-api'); ?>">
            <button type="button" class="yaa-load-more yadore-load-more yaa-btn-<?php echo esc_attr($atts['button_style']); ?>" style="display: none;">
                <span class="yaa-btn-text yadore-btn-text"><?php esc_html_e('Mehr laden', 'yadore-amazon-api'); ?></span>
                <span class="yaa-btn-spinner yadore-btn-spinner" aria-hidden="true" style="display: none;"></span>
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
        if ($this->yadore_api !== null && $this->yadore_api->is_configured()) $count++;
        if ($this->amazon_api !== null && $this->amazon_api->is_configured()) $count++;
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
     * Handle AJAX search
     */
    public function ajax_search(): void {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
            
        if (!wp_verify_nonce($nonce, 'yaa_search_nonce')) {
            wp_send_json_error([
                'message' => __('Sicherheitsüberprüfung fehlgeschlagen.', 'yadore-amazon-api'),
                'code'    => 'invalid_nonce',
            ], 403);
        }

        $keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? min(absint($_POST['per_page']), 50) : 12;
        $sort = isset($_POST['sort']) ? $this->validate_sort(sanitize_key(wp_unslash($_POST['sort']))) : $this->get_default_sort();
        $api_source = isset($_POST['api_source']) ? sanitize_key(wp_unslash($_POST['api_source'])) : '';

        $min_chars = 2;
        if (empty($keyword) || mb_strlen($keyword) < $min_chars) {
            wp_send_json_error([
                'message' => sprintf(__('Bitte geben Sie mindestens %d Zeichen ein.', 'yadore-amazon-api'), $min_chars),
                'code'    => 'keyword_too_short',
            ], 400);
        }

        $results = $this->perform_search($keyword, $page, $per_page, $sort, $api_source);

        if (is_wp_error($results)) {
            wp_send_json_error([
                'message' => $results->get_error_message(),
                'code'    => $results->get_error_code(),
            ], 500);
        }

        wp_send_json_success([
            'products'     => $results['products'],
            'total'        => $results['total'],
            'page'         => $page,
            'per_page'     => $per_page,
            'has_more'     => $results['has_more'],
            'current_sort' => $sort,
            'sort_label'   => self::SORT_OPTIONS[$sort] ?? 'Relevanz',
            'keyword'      => $keyword,
            'source'       => $results['source'] ?? 'yadore',
            'cached'       => $results['cached'] ?? false,
        ]);
    }

    /**
     * Handle AJAX suggestions
     */
    public function ajax_suggestions(): void {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
            
        if (!wp_verify_nonce($nonce, 'yaa_search_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';

        if (mb_strlen($keyword) < 2) {
            wp_send_json_success(['suggestions' => []]);
        }

        $featured = yaa_get_option('yadore_featured_keywords', '');
        $suggestions = [];
        
        if (!empty($featured)) {
            $keywords = array_map('trim', explode(',', $featured));
            $keyword_lower = mb_strtolower($keyword);
            
            foreach ($keywords as $kw) {
                if (mb_strpos(mb_strtolower($kw), $keyword_lower) !== false) {
                    $suggestions[] = ['term' => $kw, 'type' => 'popular'];
                }
            }
        }
        
        wp_send_json_success(['suggestions' => array_slice($suggestions, 0, 5)]);
    }

    /**
     * Handle click tracking
     */
    public function ajax_track_click(): void {
        wp_send_json_success(['tracked' => true]);
    }

    /**
     * Perform product search
     */
    private function perform_search(string $keyword, int $page, int $per_page, string $sort, string $api_source): array|\WP_Error {
        $api_sort = $this->map_sort_to_api($sort);
        $products = [];
        $source = 'yadore';
        
        // Try Yadore
        if (($api_source === '' || $api_source === 'yadore') && 
            $this->yadore_api !== null && $this->yadore_api->is_configured()) {
            
            $result = $this->yadore_api->fetch([
                'keyword' => $keyword,
                'limit'   => $per_page * 2,
                'sort'    => $api_sort,
                'market'  => yaa_get_option('yadore_market', 'de'),
            ]);
            
            if (!is_wp_error($result) && !empty($result)) {
                $products = $this->normalize_products($result, 'yadore');
                $source = 'yadore';
            }
        }
        
        // Fallback to Amazon
        if (empty($products) && ($api_source === '' || $api_source === 'amazon') && 
            $this->amazon_api !== null && $this->amazon_api->is_configured()) {
            
            $result = $this->amazon_api->search_items($keyword, 'All', min($per_page, 10));
            
            if (!is_wp_error($result) && !empty($result)) {
                $products = $this->normalize_products($result, 'amazon');
                $source = 'amazon';
            }
        }
        
        if (empty($products)) {
            return ['products' => [], 'total' => 0, 'has_more' => false, 'source' => $source, 'cached' => false];
        }
        
        $products = $this->apply_sorting($products, $sort);
        
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
                usort($products, fn($a, $b) => $this->extract_price($a['price'] ?? '') <=> $this->extract_price($b['price'] ?? ''));
                break;
            case 'price_desc':
                usort($products, fn($a, $b) => $this->extract_price($b['price'] ?? '') <=> $this->extract_price($a['price'] ?? ''));
                break;
            case 'title_asc':
                usort($products, fn($a, $b) => strcasecmp($a['title'] ?? '', $b['title'] ?? ''));
                break;
            case 'title_desc':
                usort($products, fn($a, $b) => strcasecmp($b['title'] ?? '', $a['title'] ?? ''));
                break;
            case 'rating_desc':
                usort($products, fn($a, $b) => (float)($b['rating'] ?? 0) <=> (float)($a['rating'] ?? 0));
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

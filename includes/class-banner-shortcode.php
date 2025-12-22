<?php
/**
 * Banner Shortcode Class
 * 
 * Responsive Affiliate-Banner als AdSense-Ersatz
 * 
 * @package Yadore_Amazon_API
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class YAA_Banner_Shortcode {

    /**
     * API Dependencies
     */
    private YAA_Yadore_API $yadore_api;
    private YAA_Amazon_PAAPI $amazon_api;
    private YAA_Custom_Products $custom_products;

    /**
     * Default Attribute Values
     */
    private array $defaults = [
        'keyword'    => '',
        'source'     => 'yadore',    // yadore, amazon, custom, all
        'limit'      => 6,
        'cta'        => 'Zum Angebot',
        'style'      => 'horizontal', // horizontal, compact, minimal
        'show_price' => 'yes',
        'aspect'     => '1:1',        // 1:1, 16:9, 4:3
        'market'     => '',
        'category'   => 'All',
    ];

    /**
     * Constructor with Dependency Injection
     */
    public function __construct(
        YAA_Yadore_API $yadore_api,
        YAA_Amazon_PAAPI $amazon_api,
        YAA_Custom_Products $custom_products
    ) {
        $this->yadore_api      = $yadore_api;
        $this->amazon_api      = $amazon_api;
        $this->custom_products = $custom_products;

        $this->register_shortcode();
        $this->register_assets();
    }

    /**
     * Register Shortcode
     */
    private function register_shortcode(): void {
        add_shortcode('yaa_banner', [$this, 'render_banner']);
    }

    /**
     * Register CSS and JS Assets
     */
    private function register_assets(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue CSS and JS (nur wenn Shortcode vorhanden)
     */
    public function enqueue_assets(): void {
        global $post;

        if (!($post instanceof WP_Post) || !has_shortcode($post->post_content, 'yaa_banner')) {
            return;
        }

        wp_enqueue_style(
            'yaa-banner',
            YAA_PLUGIN_URL . 'assets/css/yaa-banner.css',
            ['yaa-frontend-grid'],
            YAA_VERSION
        );

        wp_enqueue_script(
            'yaa-banner',
            YAA_PLUGIN_URL . 'assets/js/yaa-banner.js',
            [],
            YAA_VERSION,
            true
        );
    }

    /**
     * Render Banner Shortcode
     */
    public function render_banner(array $atts): string {
        $atts = shortcode_atts($this->defaults, $atts, 'yaa_banner');

        // Validate keyword
        if (empty($atts['keyword'])) {
            return $this->render_error('Bitte ein Keyword angeben.');
        }

        // Sanitize attributes
        $atts['limit']      = absint($atts['limit']);
        $atts['limit']      = max(1, min(12, $atts['limit']));
        $atts['show_price'] = $atts['show_price'] === 'yes';
        $atts['aspect']     = $this->sanitize_aspect($atts['aspect']);
        $atts['style']      = in_array($atts['style'], ['horizontal', 'compact', 'minimal'], true) 
                              ? $atts['style'] : 'horizontal';

        // Fetch products based on source
        $products = $this->fetch_products($atts);

        if (empty($products)) {
            return $this->render_error('Keine Produkte gefunden.');
        }

        // Render banner HTML
        return $this->render_html($products, $atts);
    }

    /**
     * Fetch Products from Selected Source(s)
     */
    private function fetch_products(array $atts): array {
        $products = [];
        $source   = strtolower($atts['source']);
        $keyword  = sanitize_text_field($atts['keyword']);
        $limit    = $atts['limit'];

        // Determine which sources to query
        $sources = ($source === 'all') 
            ? ['yadore', 'amazon', 'custom'] 
            : [$source];

        foreach ($sources as $src) {
            $remaining = $limit - count($products);
            if ($remaining <= 0) {
                break;
            }

            $fetched = match ($src) {
                'yadore' => $this->fetch_yadore($keyword, $remaining, $atts['market']),
                'amazon' => $this->fetch_amazon($keyword, $remaining, $atts['category']),
                'custom' => $this->fetch_custom($keyword, $remaining),
                default  => [],
            };

            $products = array_merge($products, $fetched);
        }

        return array_slice($products, 0, $limit);
    }

    /**
     * Fetch from Yadore API
     */
    private function fetch_yadore(string $keyword, int $limit, string $market): array {
        if (!$this->yadore_api->is_configured()) {
            return [];
        }

        $market = !empty($market) ? $market : yaa_get_option('yadore_market', 'de');
        
        $response = $this->yadore_api->fetch([
            'keyword' => $keyword,
            'market'  => $market,
            'limit'   => $limit,
        ]);

        if (empty($response) || !is_array($response)) {
            return [];
        }

        $products = [];
        foreach ($response as $offer) {
            if (!is_array($offer)) {
                continue;
            }

            $products[] = [
                'source'   => 'yadore',
                'title'    => $this->extract_string($offer, ['title', 'name']),
                'price'    => $this->extract_string($offer, ['price', 'displayPrice']),
                'currency' => $this->extract_string($offer, ['currency'], '€'),
                'image'    => $this->extract_string($offer, ['image_url', 'image', 'imageUrl', 'thumbnail']),
                'url'      => $this->extract_string($offer, ['deeplink', 'url', 'link', 'affiliateUrl']),
                'merchant' => $this->extract_string($offer, ['merchant_name', 'merchant', 'shop', 'shopName']),
            ];
        }

        return $products;
    }

    /**
     * Fetch from Amazon PA-API
     */
    private function fetch_amazon(string $keyword, int $limit, string $category): array {
        if (!$this->amazon_api->is_configured()) {
            return [];
        }

        $response = $this->amazon_api->search_items($keyword, $category, $limit);

        if (empty($response) || !is_array($response)) {
            return [];
        }

        $products = [];
        foreach ($response as $item) {
            if (!is_array($item)) {
                continue;
            }

            $products[] = [
                'source'   => 'amazon',
                'title'    => $this->extract_string($item, ['title', 'name']),
                'price'    => $this->extract_string($item, ['price', 'displayPrice', 'priceFormatted']),
                'currency' => '€',
                'image'    => $this->extract_string($item, ['image', 'imageUrl', 'mediumImage', 'largeImage']),
                'url'      => $this->extract_string($item, ['url', 'detailPageURL', 'link']),
                'merchant' => 'Amazon',
            ];
        }

        return $products;
    }

    /**
     * Fetch from Custom Products (CPT)
     */
    private function fetch_custom(string $keyword, int $limit): array {
        $args = [
            'post_type'      => 'yaa_custom_product',
            'posts_per_page' => $limit,
            's'              => $keyword,
            'post_status'    => 'publish',
        ];

        $query = new WP_Query($args);
        $products = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                $price = get_post_meta($post_id, '_yaa_price', true);
                $currency = get_post_meta($post_id, '_yaa_currency', true);
                $url = get_post_meta($post_id, '_yaa_affiliate_url', true);
                $merchant = get_post_meta($post_id, '_yaa_merchant', true);
                $image = get_the_post_thumbnail_url($post_id, 'medium');

                $products[] = [
                    'source'   => 'custom',
                    'title'    => get_the_title(),
                    'price'    => is_string($price) ? $price : '',
                    'currency' => is_string($currency) && !empty($currency) ? $currency : '€',
                    'image'    => is_string($image) ? $image : '',
                    'url'      => is_string($url) ? $url : '',
                    'merchant' => is_string($merchant) ? $merchant : '',
                ];
            }
            wp_reset_postdata();
        }

        return $products;
    }

    /**
     * Extract string value from array with multiple possible keys
     * 
     * @param array $data Source array
     * @param array $keys Possible keys to try (in order of priority)
     * @param string $default Default value if not found
     * @return string
     */
    private function extract_string(array $data, array $keys, string $default = ''): string {
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $value = $data[$key];
                
                // Handle nested arrays (e.g., image might be ['url' => '...'])
                if (is_array($value)) {
                    // Try common nested keys
                    foreach (['url', 'src', 'href', 'value', 'text', 0] as $nestedKey) {
                        if (isset($value[$nestedKey]) && is_string($value[$nestedKey])) {
                            return $value[$nestedKey];
                        }
                    }
                    // Last resort: get first string value
                    foreach ($value as $v) {
                        if (is_string($v) && !empty($v)) {
                            return $v;
                        }
                    }
                    continue;
                }
                
                // Handle scalar values
                if (is_string($value)) {
                    return $value;
                }
                
                if (is_numeric($value)) {
                    return (string) $value;
                }
            }
        }
        
        return $default;
    }

    /**
     * Render Banner HTML
     */
    private function render_html(array $products, array $atts): string {
        $style        = esc_attr($atts['style']);
        $aspect_class = 'yaa-banner-aspect-' . str_replace(':', '-', $atts['aspect']);

        ob_start();
        ?>
        <div class="yaa-banner-container yaa-banner-<?php echo $style; ?>">
            <?php if ($style === 'minimal') : ?>
                <div class="yaa-banner-track">
                    <?php foreach ($products as $product) : ?>
                        <?php echo $this->render_minimal_item($product, $atts); ?>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="yaa-banner-scroll-wrapper">
                    <div class="yaa-banner-track">
                        <?php foreach ($products as $product) : ?>
                            <?php echo $this->render_item($product, $atts, $aspect_class); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Scroll Indicators -->
                <div class="yaa-banner-fade yaa-banner-fade-left"></div>
                <div class="yaa-banner-fade yaa-banner-fade-right"></div>
                <button class="yaa-banner-arrow yaa-banner-arrow-left" aria-label="Zurück" type="button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>
                <button class="yaa-banner-arrow yaa-banner-arrow-right" aria-label="Weiter" type="button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean() ?: '';
    }

    /**
     * Render Single Banner Item (horizontal & compact)
     */
    private function render_item(array $product, array $atts, string $aspect_class): string {
        $style      = $atts['style'];
        $show_price = $atts['show_price'];
        $cta_text   = esc_html($atts['cta']);
        $source     = esc_attr($product['source'] ?? 'unknown');

        // Ensure all values are strings before escaping
        $title    = esc_html($this->truncate_title($this->ensure_string($product['title'] ?? ''), $style));
        $url      = esc_url($this->ensure_string($product['url'] ?? ''));
        $image    = esc_url($this->ensure_string($product['image'] ?? ''));
        $price    = esc_html($this->ensure_string($product['price'] ?? ''));
        $currency = esc_html($this->ensure_string($product['currency'] ?? '€'));

        // Skip items without URL
        if (empty($url)) {
            return '';
        }

        ob_start();
        ?>
        <div class="yaa-banner-item yaa-<?php echo $source; ?> <?php echo esc_attr($aspect_class); ?>">
            <a href="<?php echo $url; ?>" 
               target="_blank" 
               rel="nofollow noopener sponsored"
               class="yaa-banner-link">
                
                <?php if (!empty($image)) : ?>
                <div class="yaa-banner-image <?php echo esc_attr($aspect_class); ?>">
                    <img src="<?php echo $image; ?>" 
                         alt="<?php echo $title; ?>" 
                         loading="lazy">
                </div>
                <?php endif; ?>

                <div class="yaa-banner-content">
                    <span class="yaa-banner-title"><?php echo $title; ?></span>
                    
                    <?php if ($show_price && !empty($price)) : ?>
                    <span class="yaa-banner-price">
                        <?php echo $price; ?> <?php echo $currency; ?>
                    </span>
                    <?php endif; ?>

                    <?php if ($style === 'horizontal') : ?>
                    <span class="yaa-banner-cta"><?php echo $cta_text; ?></span>
                    <?php endif; ?>
                </div>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render Minimal Style Item (Text-Link Only)
     */
    private function render_minimal_item(array $product, array $atts): string {
        $show_price = $atts['show_price'];
        $source     = esc_attr($product['source'] ?? 'unknown');

        // Ensure all values are strings before escaping
        $title    = esc_html($this->truncate_title($this->ensure_string($product['title'] ?? ''), 'minimal'));
        $url      = esc_url($this->ensure_string($product['url'] ?? ''));
        $price    = esc_html($this->ensure_string($product['price'] ?? ''));
        $currency = esc_html($this->ensure_string($product['currency'] ?? '€'));

        // Skip items without URL
        if (empty($url)) {
            return '';
        }

        ob_start();
        ?>
        <span class="yaa-banner-item yaa-banner-minimal-item yaa-<?php echo $source; ?>">
            <a href="<?php echo $url; ?>" 
               target="_blank" 
               rel="nofollow noopener sponsored"
               class="yaa-banner-minimal-link">
                <?php echo $title; ?>
                <?php if ($show_price && !empty($price)) : ?>
                    <span class="yaa-banner-minimal-price">(<?php echo $price; ?> <?php echo $currency; ?>)</span>
                <?php endif; ?>
            </a>
        </span>
        <?php
        return ob_get_clean();
    }

    /**
     * Ensure value is a string
     * 
     * @param mixed $value
     * @return string
     */
    private function ensure_string(mixed $value): string {
        if (is_string($value)) {
            return $value;
        }
        
        if (is_numeric($value)) {
            return (string) $value;
        }
        
        if (is_array($value)) {
            // Try to extract a string from common array structures
            foreach (['url', 'src', 'href', 'value', 'text', 0] as $key) {
                if (isset($value[$key]) && is_string($value[$key])) {
                    return $value[$key];
                }
            }
            // Return first string found
            foreach ($value as $v) {
                if (is_string($v)) {
                    return $v;
                }
            }
        }
        
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        
        return '';
    }

    /**
     * Truncate Title Based on Style
     */
    private function truncate_title(string $title, string $style): string {
        $limits = [
            'horizontal' => 50,
            'compact'    => 30,
            'minimal'    => 40,
        ];

        $limit = $limits[$style] ?? 50;

        if (mb_strlen($title) > $limit) {
            return mb_substr($title, 0, $limit - 3) . '...';
        }

        return $title;
    }

    /**
     * Sanitize Aspect Ratio
     */
    private function sanitize_aspect(string $aspect): string {
        $allowed = ['1:1', '16:9', '4:3'];
        return in_array($aspect, $allowed, true) ? $aspect : '1:1';
    }

    /**
     * Render Error Message (nur für Admins)
     */
    private function render_error(string $message): string {
        if (current_user_can('edit_posts')) {
            return '<div class="yaa-banner-error">' . esc_html($message) . '</div>';
        }
        return '';
    }
}

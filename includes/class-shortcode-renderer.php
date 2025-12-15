<?php
/**
 * Shortcode Renderer
 * PHP 8.3+ compatible
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Shortcode_Renderer {
    
    private YAA_Yadore_API $yadore_api;
    private YAA_Amazon_PAAPI $amazon_api;
    private YAA_Cache_Handler $cache;
    
    public function __construct(
        YAA_Yadore_API $yadore_api, 
        YAA_Amazon_PAAPI $amazon_api, 
        YAA_Cache_Handler $cache
    ) {
        $this->yadore_api = $yadore_api;
        $this->amazon_api = $amazon_api;
        $this->cache = $cache;
        
        // Register shortcodes
        add_shortcode('yaa_products', [$this, 'render_combined']);
        add_shortcode('yadore_products', [$this, 'render_yadore']);
        add_shortcode('amazon_products', [$this, 'render_amazon']);
        add_shortcode('combined_products', [$this, 'render_combined']);
    }
    
    /**
     * Render Yadore products
     * 
     * @param array<string, mixed>|string $atts
     */
    public function render_yadore(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'    => '',
            'limit'      => (int) yaa_get_option('yadore_default_limit', 9),
            'market'     => (string) yaa_get_option('yadore_market', 'de'),
            'precision'  => (string) yaa_get_option('yadore_precision', 'fuzzy'),
            'columns'    => '',
            'class'      => '',
        ], $atts, 'yadore_products');
        
        if (!$this->yadore_api->is_configured()) {
            return $this->render_error(__('Yadore API nicht konfiguriert.', 'yadore-amazon-api'));
        }
        
        $keyword = trim((string) $atts['keyword']);
        if ($keyword === '') {
            return $this->render_error(__('Bitte keyword angeben.', 'yadore-amazon-api'));
        }
        
        $items = $this->yadore_api->fetch([
            'keyword'   => $keyword,
            'limit'     => (int) $atts['limit'],
            'market'    => (string) $atts['market'],
            'precision' => (string) $atts['precision'],
        ]);
        
        if (is_wp_error($items)) {
            return $this->render_error($items->get_error_message());
        }
        
        if (empty($items)) {
            return $this->render_empty();
        }
        
        return $this->render_grid($items, $atts);
    }
    
    /**
     * Render Amazon products
     * 
     * @param array<string, mixed>|string $atts
     */
    public function render_amazon(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'    => '',
            'category'   => (string) yaa_get_option('amazon_default_category', 'All'),
            'limit'      => 10,
            'min_price'  => '',
            'max_price'  => '',
            'brand'      => '',
            'sort'       => '',
            'asins'      => '',
            'columns'    => '',
            'class'      => '',
        ], $atts, 'amazon_products');
        
        if (!$this->amazon_api->is_configured()) {
            return $this->render_error(__('Amazon PA-API nicht konfiguriert.', 'yadore-amazon-api'));
        }
        
        // If ASINs provided, fetch by ASIN
        $asins = trim((string) $atts['asins']);
        if ($asins !== '') {
            $asin_array = array_map('trim', explode(',', $asins));
            $items = $this->amazon_api->get_items($asin_array);
        } else {
            $keyword = trim((string) $atts['keyword']);
            if ($keyword === '') {
                return $this->render_error(__('Bitte keyword oder asins angeben.', 'yadore-amazon-api'));
            }
            
            $additional = [];
            if ((string) $atts['min_price'] !== '') {
                $additional['min_price'] = (float) $atts['min_price'];
            }
            if ((string) $atts['max_price'] !== '') {
                $additional['max_price'] = (float) $atts['max_price'];
            }
            if ((string) $atts['brand'] !== '') {
                $additional['brand'] = (string) $atts['brand'];
            }
            if ((string) $atts['sort'] !== '') {
                $additional['sort_by'] = (string) $atts['sort'];
            }
            
            $items = $this->amazon_api->search_items(
                $keyword,
                (string) $atts['category'],
                (int) $atts['limit'],
                $additional
            );
        }
        
        if (is_wp_error($items)) {
            return $this->render_error($items->get_error_message());
        }
        
        if (empty($items)) {
            return $this->render_empty();
        }
        
        return $this->render_grid($items, $atts);
    }
    
    /**
     * Render combined products (Yadore + Amazon)
     * 
     * @param array<string, mixed>|string $atts
     */
    public function render_combined(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'       => '',
            'yadore_limit'  => 6,
            'amazon_limit'  => 4,
            'market'        => (string) yaa_get_option('yadore_market', 'de'),
            'category'      => (string) yaa_get_option('amazon_default_category', 'All'),
            'shuffle'       => 'yes',
            'columns'       => '',
            'class'         => '',
        ], $atts, 'combined_products');
        
        $keyword = trim((string) $atts['keyword']);
        if ($keyword === '') {
            return $this->render_error(__('Bitte keyword angeben.', 'yadore-amazon-api'));
        }
        
        $combined = [];
        $yadore_limit = (int) $atts['yadore_limit'];
        $amazon_limit = (int) $atts['amazon_limit'];
        
        // Fetch from Yadore
        if ($this->yadore_api->is_configured() && $yadore_limit > 0) {
            $yadore_items = $this->yadore_api->fetch([
                'keyword' => $keyword,
                'limit'   => $yadore_limit,
                'market'  => (string) $atts['market'],
            ]);
            
            if (!is_wp_error($yadore_items) && !empty($yadore_items)) {
                $combined = array_merge($combined, $yadore_items);
            }
        }
        
        // Fetch from Amazon
        if ($this->amazon_api->is_configured() && $amazon_limit > 0) {
            $amazon_items = $this->amazon_api->search_items(
                $keyword,
                (string) $atts['category'],
                $amazon_limit
            );
            
            if (!is_wp_error($amazon_items) && !empty($amazon_items)) {
                $combined = array_merge($combined, $amazon_items);
            }
        }
        
        if (empty($combined)) {
            return $this->render_empty();
        }
        
        // Shuffle if enabled
        if ((string) $atts['shuffle'] === 'yes') {
            shuffle($combined);
        }
        
        return $this->render_grid($combined, $atts);
    }
    
    /**
     * Render the product grid
     * 
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $atts
     */
    private function render_grid(array $items, array $atts): string {
        $widget_id = 'yaa-' . uniqid();
        $extra_class = isset($atts['class']) && (string) $atts['class'] !== '' 
            ? ' ' . esc_attr((string) $atts['class']) 
            : '';
        
        // Column override
        $style = '';
        if (isset($atts['columns']) && (string) $atts['columns'] !== '') {
            $cols = (int) $atts['columns'];
            if ($cols >= 1 && $cols <= 6) {
                $style = ' style="--yaa-columns-desktop: ' . $cols . ';"';
            }
        }
        
        // Settings
        $show_prime = yaa_get_option('show_prime_badge', 'yes') === 'yes';
        $show_merchant = yaa_get_option('show_merchant', 'yes') === 'yes';
        $show_description = yaa_get_option('show_description', 'yes') === 'yes';
        $button_yadore = (string) yaa_get_option('button_text_yadore', 'Zum Angebot');
        $button_amazon = (string) yaa_get_option('button_text_amazon', 'Bei Amazon kaufen');
        
        // Ensure assets are loaded
        $plugin = yaa_init();
        $plugin->load_assets();
        
        ob_start();
        ?>
        <div class="yaa-grid-container<?php echo $extra_class; ?>" id="<?php echo esc_attr($widget_id); ?>"<?php echo $style; ?>>
            <?php foreach ($items as $index => $item): 
                $source = $item['source'] ?? 'yadore';
                $is_amazon = $source === 'amazon';
                $url = $item['url'] ?? '#';
                $image_url = $item['image']['url'] ?? '';
                $title = $item['title'] ?? '';
                $description = $item['description'] ?? '';
                $price_amount = $item['price']['amount'] ?? '';
                $price_currency = $item['price']['currency'] ?? 'EUR';
                $price_display = $item['price']['display'] ?? '';
                $merchant_name = $item['merchant']['name'] ?? '';
                $is_prime = !empty($item['is_prime']);
            ?>
                <div class="yaa-item <?php echo $is_amazon ? 'yaa-amazon' : 'yaa-yadore'; ?>">
                    
                    <?php if ($show_prime && $is_amazon && $is_prime): ?>
                        <span class="yaa-prime-badge">Prime</span>
                    <?php endif; ?>
                    
                    <div class="yaa-image-wrapper">
                        <?php if ($image_url !== ''): ?>
                            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="nofollow sponsored noopener">
                                <img src="<?php echo esc_url($image_url); ?>" 
                                     alt="<?php echo esc_attr($title); ?>" 
                                     loading="lazy"
                                     decoding="async">
                            </a>
                        <?php else: ?>
                            <div class="yaa-no-image">
                                <span>📦</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="yaa-content">
                        <h3 class="yaa-title">
                            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="nofollow sponsored noopener">
                                <?php echo esc_html($title); ?>
                            </a>
                        </h3>

                        <?php if ($show_description && $description !== ''): ?>
                            <div class="yaa-description-wrapper">
                                <div class="yaa-description" id="desc-<?php echo esc_attr($widget_id . '-' . $index); ?>">
                                    <?php echo esc_html($description); ?>
                                </div>
                                <button type="button" class="yaa-read-more" data-target="<?php echo esc_attr($widget_id . '-' . $index); ?>">
                                    <?php esc_html_e('mehr lesen', 'yadore-amazon-api'); ?>
                                </button>
                            </div>
                        <?php elseif ($show_description): ?>
                            <div class="yaa-no-description"><?php esc_html_e('Keine Beschreibung verfügbar', 'yadore-amazon-api'); ?></div>
                        <?php endif; ?>

                        <div class="yaa-meta">
                            <?php if ($price_amount !== '' || $price_display !== ''): ?>
                                <span class="yaa-price">
                                    <?php 
                                    if ($price_display !== '') {
                                        echo esc_html($price_display);
                                    } else {
                                        echo number_format((float) $price_amount, 2, ',', '.') . ' ' . esc_html($price_currency);
                                    }
                                    ?>
                                </span>
                            <?php endif; ?>
                            
                            <?php if ($show_merchant && $merchant_name !== ''): ?>
                                <div class="yaa-merchant">
                                    <?php echo esc_html($merchant_name); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="yaa-button-wrapper">
                            <a class="yaa-button" href="<?php echo esc_url($url); ?>" target="_blank" rel="nofollow sponsored noopener">
                                <?php echo esc_html($is_amazon ? $button_amazon : $button_yadore); ?>
                            </a>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean() ?: '';
    }
    
    /**
     * Render error message
     */
    private function render_error(string $message): string {
        return '<div class="yaa-message yaa-error"><span class="yaa-icon">⚠️</span><p>' . 
               esc_html($message) . '</p></div>';
    }
    
    /**
     * Render empty state
     */
    private function render_empty(): string {
        return '<div class="yaa-message yaa-empty"><span class="yaa-icon">📦</span>' .
               '<p>' . esc_html__('Keine Produkte für diese Suche gefunden.', 'yadore-amazon-api') . '</p></div>';
    }
}

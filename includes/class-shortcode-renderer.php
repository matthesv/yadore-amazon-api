<?php
/**
 * Shortcode Renderer - Erweitert mit eigenen Produkten & Multi-Keyword Support
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
    private YAA_Custom_Products $custom_products;
    
    public function __construct(
        YAA_Yadore_API $yadore_api, 
        YAA_Amazon_PAAPI $amazon_api, 
        YAA_Cache_Handler $cache,
        YAA_Custom_Products $custom_products
    ) {
        $this->yadore_api = $yadore_api;
        $this->amazon_api = $amazon_api;
        $this->cache = $cache;
        $this->custom_products = $custom_products;
        
        // Register shortcodes
        add_shortcode('yaa_products', [$this, 'render_combined']);
        add_shortcode('yadore_products', [$this, 'render_yadore']);
        add_shortcode('amazon_products', [$this, 'render_amazon']);
        add_shortcode('combined_products', [$this, 'render_combined']);
        add_shortcode('custom_products', [$this, 'render_custom']);      // NEU
        add_shortcode('all_products', [$this, 'render_all_sources']);    // NEU
    }
    
    /**
     * Render Yadore products - ERWEITERT mit Multi-Keyword Support
     */
    public function render_yadore(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'    => '',
            'keywords'   => '',  // NEU: Mehrere Keywords komma-separiert
            'limit'      => (int) yaa_get_option('yadore_default_limit', 9),
            'market'     => (string) yaa_get_option('yadore_market', 'de'),
            'precision'  => (string) yaa_get_option('yadore_precision', 'fuzzy'),
            'columns'    => '',
            'class'      => '',
        ], $atts, 'yadore_products');
        
        if (!$this->yadore_api->is_configured()) {
            return $this->render_error(__('Yadore API nicht konfiguriert.', 'yadore-amazon-api'));
        }
        
        // Multi-Keyword Support
        $keywords = [];
        
        if (!empty($atts['keywords'])) {
            // Mehrere Keywords
            $keywords = array_map('trim', explode(',', (string) $atts['keywords']));
            $keywords = array_filter($keywords);
        } elseif (!empty($atts['keyword'])) {
            // Einzelnes Keyword
            $keywords = [trim((string) $atts['keyword'])];
        }
        
        if (empty($keywords)) {
            return $this->render_error(__('Bitte keyword oder keywords angeben.', 'yadore-amazon-api'));
        }
        
        $total_limit = (int) $atts['limit'];
        $items = [];
        
        if (count($keywords) === 1) {
            // Einzelnes Keyword - normales Verhalten
            $result = $this->yadore_api->fetch([
                'keyword'   => $keywords[0],
                'limit'     => $total_limit,
                'market'    => (string) $atts['market'],
                'precision' => (string) $atts['precision'],
            ]);
            
            if (!is_wp_error($result)) {
                $items = $result;
            }
        } else {
            // Mehrere Keywords - Limit aufteilen
            $items = $this->fetch_multi_keywords($keywords, $total_limit, $atts);
        }
        
        if (empty($items)) {
            return $this->render_empty();
        }
        
        return $this->render_grid($items, $atts);
    }
    
    /**
     * Fetch products for multiple keywords with distributed limit
     * 
     * @param array<string> $keywords
     * @param array<string, mixed> $atts
     * @return array<int, array<string, mixed>>
     */
    private function fetch_multi_keywords(array $keywords, int $total_limit, array $atts): array {
        $keyword_count = count($keywords);
        
        // Gleichmäßig aufteilen, Rest dem ersten Keyword
        $base_per_keyword = (int) floor($total_limit / $keyword_count);
        $remainder = $total_limit % $keyword_count;
        
        $all_items = [];
        $items_per_keyword = [];
        
        // Erst alle Anfragen durchführen
        foreach ($keywords as $index => $keyword) {
            $limit_for_this = $base_per_keyword;
            
            // Rest auf die ersten Keywords verteilen
            if ($index < $remainder) {
                $limit_for_this++;
            }
            
            if ($limit_for_this <= 0) {
                continue;
            }
            
            $result = $this->yadore_api->fetch([
                'keyword'   => $keyword,
                'limit'     => $limit_for_this,
                'market'    => (string) $atts['market'],
                'precision' => (string) $atts['precision'],
            ]);
            
            if (!is_wp_error($result) && !empty($result)) {
                $items_per_keyword[$keyword] = $result;
            }
            
            // Kleine Pause um API nicht zu überlasten
            if ($index < $keyword_count - 1) {
                usleep(200000); // 200ms
            }
        }
        
        // Wenn ein Keyword weniger liefert als erwartet, 
        // können andere Keywords mehr bekommen
        $collected = 0;
        
        foreach ($items_per_keyword as $keyword => $items) {
            $remaining_slots = $total_limit - $collected;
            $to_add = min(count($items), $remaining_slots);
            
            for ($i = 0; $i < $to_add; $i++) {
                $all_items[] = $items[$i];
                $collected++;
            }
            
            if ($collected >= $total_limit) {
                break;
            }
        }
        
        return $all_items;
    }
    
    /**
     * Render Amazon products
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
     * Render custom products
     * NEU: Shortcode für eigene Produkte
     */
    public function render_custom(array|string $atts = []): string {
        $atts = shortcode_atts([
            'ids'      => '',      // Komma-separierte Post-IDs
            'category' => '',      // Kategorie-Slug
            'limit'    => 10,
            'columns'  => '',
            'class'    => '',
        ], $atts, 'custom_products');
        
        $items = [];
        
        // Nach IDs laden
        if (!empty($atts['ids'])) {
            $ids = array_map('intval', explode(',', (string) $atts['ids']));
            $ids = array_filter($ids);
            $items = $this->custom_products->get_products_by_ids($ids);
        }
        // Nach Kategorie laden
        elseif (!empty($atts['category'])) {
            $items = $this->custom_products->get_products_by_category(
                (string) $atts['category'],
                (int) $atts['limit']
            );
        }
        // Alle laden
        else {
            $items = $this->custom_products->get_all_products((int) $atts['limit']);
        }
        
        if (empty($items)) {
            return $this->render_empty();
        }
        
        return $this->render_grid($items, $atts);
    }
    
    /**
     * Render combined products (Yadore + Amazon + Custom)
     * ERWEITERT mit eigenen Produkten
     */
    public function render_combined(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'       => '',
            'keywords'      => '',  // NEU: Mehrere Yadore-Keywords
            'yadore_limit'  => 6,
            'amazon_limit'  => 4,
            'custom_ids'    => '',  // NEU: IDs eigener Produkte
            'custom_category' => '', // NEU: Kategorie eigener Produkte
            'custom_limit'  => 0,   // NEU: Anzahl eigener Produkte
            'market'        => (string) yaa_get_option('yadore_market', 'de'),
            'category'      => (string) yaa_get_option('amazon_default_category', 'All'),
            'shuffle'       => 'yes',
            'columns'       => '',
            'class'         => '',
        ], $atts, 'combined_products');
        
        $combined = [];
        $yadore_limit = (int) $atts['yadore_limit'];
        $amazon_limit = (int) $atts['amazon_limit'];
        $custom_limit = (int) $atts['custom_limit'];
        
        // Keyword ermitteln
        $keyword = trim((string) $atts['keyword']);
        $keywords_string = trim((string) $atts['keywords']);
        
        // 1. Eigene Produkte laden (haben Priorität)
        if (!empty($atts['custom_ids'])) {
            $ids = array_map('intval', explode(',', (string) $atts['custom_ids']));
            $ids = array_filter($ids);
            $custom_items = $this->custom_products->get_products_by_ids($ids);
            $combined = array_merge($combined, $custom_items);
        } elseif (!empty($atts['custom_category'])) {
            $custom_items = $this->custom_products->get_products_by_category(
                (string) $atts['custom_category'],
                $custom_limit > 0 ? $custom_limit : 5
            );
            $combined = array_merge($combined, $custom_items);
        } elseif ($custom_limit > 0) {
            $custom_items = $this->custom_products->get_all_products($custom_limit);
            $combined = array_merge($combined, $custom_items);
        }
        
        // 2. Yadore Produkte laden
        if ($this->yadore_api->is_configured() && $yadore_limit > 0 && ($keyword !== '' || $keywords_string !== '')) {
            if ($keywords_string !== '') {
                // Multi-Keyword
                $keywords = array_map('trim', explode(',', $keywords_string));
                $keywords = array_filter($keywords);
                $yadore_items = $this->fetch_multi_keywords($keywords, $yadore_limit, [
                    'market'    => (string) $atts['market'],
                    'precision' => (string) yaa_get_option('yadore_precision', 'fuzzy'),
                ]);
            } else {
                // Single Keyword
                $yadore_items = $this->yadore_api->fetch([
                    'keyword' => $keyword,
                    'limit'   => $yadore_limit,
                    'market'  => (string) $atts['market'],
                ]);
                
                if (is_wp_error($yadore_items)) {
                    $yadore_items = [];
                }
            }
            
            if (!empty($yadore_items)) {
                $combined = array_merge($combined, $yadore_items);
            }
        }
        
        // 3. Amazon Produkte laden
        if ($this->amazon_api->is_configured() && $amazon_limit > 0 && $keyword !== '') {
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
        
        // Shuffle wenn aktiviert
        if ((string) $atts['shuffle'] === 'yes') {
            shuffle($combined);
        }
        
        return $this->render_grid($combined, $atts);
    }
    
    /**
     * Render all sources combined
     * NEU: Shortcode der alle Quellen intelligent kombiniert
     */
    public function render_all_sources(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'       => '',
            'keywords'      => '',
            'total_limit'   => 12,
            'custom_ids'    => '',
            'custom_category' => '',
            'market'        => (string) yaa_get_option('yadore_market', 'de'),
            'category'      => (string) yaa_get_option('amazon_default_category', 'All'),
            'priority'      => 'custom,yadore,amazon', // Reihenfolge der Quellen
            'shuffle'       => 'no',
            'columns'       => '',
            'class'         => '',
        ], $atts, 'all_products');
        
        $total_limit = (int) $atts['total_limit'];
        $combined = [];
        $remaining = $total_limit;
        
        // Priority-Reihenfolge parsen
        $priorities = array_map('trim', explode(',', (string) $atts['priority']));
        
        foreach ($priorities as $source) {
            if ($remaining <= 0) {
                break;
            }
            
            $items = [];
            
            switch ($source) {
                case 'custom':
                    if (!empty($atts['custom_ids'])) {
                        $ids = array_map('intval', explode(',', (string) $atts['custom_ids']));
                        $items = $this->custom_products->get_products_by_ids($ids);
                    } elseif (!empty($atts['custom_category'])) {
                        $items = $this->custom_products->get_products_by_category(
                            (string) $atts['custom_category'],
                            $remaining
                        );
                    } else {
                        $items = $this->custom_products->get_all_products($remaining);
                    }
                    break;
                    
                case 'yadore':
                    if ($this->yadore_api->is_configured()) {
                        $keyword = trim((string) $atts['keyword']);
                        $keywords_string = trim((string) $atts['keywords']);
                        
                        if ($keywords_string !== '') {
                            $keywords = array_map('trim', explode(',', $keywords_string));
                            $items = $this->fetch_multi_keywords($keywords, $remaining, [
                                'market'    => (string) $atts['market'],
                                'precision' => (string) yaa_get_option('yadore_precision', 'fuzzy'),
                            ]);
                        } elseif ($keyword !== '') {
                            $result = $this->yadore_api->fetch([
                                'keyword' => $keyword,
                                'limit'   => $remaining,
                                'market'  => (string) $atts['market'],
                            ]);
                            if (!is_wp_error($result)) {
                                $items = $result;
                            }
                        }
                    }
                    break;
                    
                case 'amazon':
                    if ($this->amazon_api->is_configured()) {
                        $keyword = trim((string) $atts['keyword']);
                        if ($keyword !== '') {
                            $result = $this->amazon_api->search_items(
                                $keyword,
                                (string) $atts['category'],
                                min($remaining, 10)
                            );
                            if (!is_wp_error($result)) {
                                $items = $result;
                            }
                        }
                    }
                    break;
            }
            
            if (!empty($items)) {
                $to_add = array_slice($items, 0, $remaining);
                $combined = array_merge($combined, $to_add);
                $remaining -= count($to_add);
            }
        }
        
        if (empty($combined)) {
            return $this->render_empty();
        }
        
        // Shuffle wenn aktiviert
        if ((string) $atts['shuffle'] === 'yes') {
            shuffle($combined);
        }
        
        return $this->render_grid($combined, $atts);
    }
    
    /**
     * Render the product grid
     */
    private function render_grid(array $items, array $atts): string {
        $widget_id = 'yaa-' . uniqid();
        $extra_class = isset($atts['class']) && (string) $atts['class'] !== '' 
            ? ' ' . esc_attr((string) $atts['class']) 
            : '';
        
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
        $button_custom = (string) yaa_get_option('button_text_custom', 'Zum Angebot');
        
        // Ensure assets are loaded
        $plugin = yaa_init();
        $plugin->load_assets();
        
        ob_start();
        ?>
        <div class="yaa-grid-container<?php echo $extra_class; ?>" id="<?php echo esc_attr($widget_id); ?>"<?php echo $style; ?>>
            <?php foreach ($items as $index => $item): 
                $source = $item['source'] ?? 'yadore';
                $is_amazon = $source === 'amazon';
                $is_custom = $source === 'custom';
                $url = $item['url'] ?? '#';
                $image_url = $item['image']['url'] ?? '';
                $title = $item['title'] ?? '';
                $description = $item['description'] ?? '';
                $price_amount = $item['price']['amount'] ?? '';
                $price_currency = $item['price']['currency'] ?? 'EUR';
                $price_display = $item['price']['display'] ?? '';
                $merchant_name = $item['merchant']['name'] ?? '';
                $is_prime = !empty($item['is_prime']);
                
                // Button-Text ermitteln
                $custom_button = $item['button_text'] ?? '';
                if ($custom_button !== '') {
                    $button_text = $custom_button;
                } elseif ($is_amazon) {
                    $button_text = $button_amazon;
                } elseif ($is_custom) {
                    $button_text = $button_custom;
                } else {
                    $button_text = $button_yadore;
                }
                
                // CSS-Klasse basierend auf Quelle
                $source_class = 'yaa-' . $source;
            ?>
                <div class="yaa-item <?php echo esc_attr($source_class); ?>">
                    
                    <?php if ($show_prime && $is_prime): ?>
                        <span class="yaa-prime-badge">Prime</span>
                    <?php endif; ?>
                    
                    <?php if ($is_custom): ?>
                        <span class="yaa-custom-badge"><?php esc_html_e('Empfohlen', 'yadore-amazon-api'); ?></span>
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
                                <?php echo esc_html($button_text); ?>
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

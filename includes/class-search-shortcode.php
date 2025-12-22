<?php
/**
 * Interaktive Produktsuche für Besucher
 * Mit Initial-Produkten vor der Suche
 * 
 * @package Yadore_Amazon_API
 * @since 1.6.0
 * 
 * KORRIGIERT:
 * - Klassenreferenzen zu YAA_* geändert
 * - Constructor auf public geändert (für Dependency Injection)
 * - YAA_URL zu YAA_PLUGIN_URL korrigiert
 * - Singleton-Initialisierung am Ende entfernt (wird in Hauptdatei instanziiert)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class YAA_Search_Shortcode {

    /**
     * Yadore API instance
     */
    private YAA_Yadore_API $yadore_api;

    /**
     * Amazon PA-API instance
     */
    private YAA_Amazon_PAAPI $amazon_api;

    /**
     * Shortcode wurde bereits gerendert (für Asset-Loading)
     */
    private static bool $shortcode_rendered = false;

    /**
     * Standard-Einstellungen
     * 
     * @var array<string, mixed>
     */
    private array $defaults = [
        // Suchformular
        'placeholder'       => 'Produkt suchen...',
        'button_text'       => 'Suchen',
        'min_chars'         => 3,
        'debounce'          => 500,
        'live_search'       => true,
        
        // Initial-Produkte
        'show_initial'      => true,
        'initial_keyword'   => '',
        'initial_keywords'  => '',
        'initial_count'     => 6,
        'initial_title'     => 'Empfohlene Produkte',
        'initial_source'    => 'yadore',
        
        // Anzeige-Optionen
        'network'           => '',
        'max_results'       => 12,
        'columns'           => 3,
        'show_price'        => true,
        'show_merchant'     => true,
        'new_tab'           => true,
        'sort'              => 'cpc_desc',
        'merchant_filter'   => '',
        
        // UI-Optionen
        'show_reset'        => true,
        'hide_form'         => false,
    ];

    /**
     * Constructor - akzeptiert Dependency Injection
     * 
     * @param YAA_Yadore_API $yadore_api Yadore API instance
     * @param YAA_Amazon_PAAPI $amazon_api Amazon API instance
     */
    public function __construct(YAA_Yadore_API $yadore_api, YAA_Amazon_PAAPI $amazon_api) {
        $this->yadore_api = $yadore_api;
        $this->amazon_api = $amazon_api;
        
        add_shortcode('yadore_search', [$this, 'render_shortcode']);
        add_action('wp_ajax_yadore_product_search', [$this, 'ajax_search']);
        add_action('wp_ajax_nopriv_yadore_product_search', [$this, 'ajax_search']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    /**
     * Assets registrieren
     */
    public function register_assets(): void {
        wp_register_style(
            'yadore-search',
            YAA_PLUGIN_URL . 'assets/css/yaa-search.css',
            [],
            YAA_VERSION
        );

        wp_register_script(
            'yadore-search',
            YAA_PLUGIN_URL . 'assets/js/yaa-search.js',
            ['jquery'],
            YAA_VERSION,
            true
        );

        wp_localize_script('yadore-search', 'yadoreSearch', [
            'ajaxurl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('yadore_search_nonce'),
            'i18n'      => [
                'searching'      => __('Suche läuft...', 'yadore-amazon-api'),
                'no_results'     => __('Keine Produkte gefunden.', 'yadore-amazon-api'),
                'error'          => __('Fehler bei der Suche. Bitte erneut versuchen.', 'yadore-amazon-api'),
                'min_chars'      => __('Bitte mindestens %d Zeichen eingeben.', 'yadore-amazon-api'),
                'view_offer'     => __('Zum Angebot', 'yadore-amazon-api'),
                'reset'          => __('← Zurück zu Empfehlungen', 'yadore-amazon-api'),
                'results_for'    => __('Ergebnisse für', 'yadore-amazon-api'),
            ],
        ]);
    }

    /**
     * Shortcode rendern
     * 
     * @param array<string, mixed>|string $atts Shortcode-Attribute
     * @return string HTML-Output
     */
    public function render_shortcode(array|string $atts = []): string {
        $atts = shortcode_atts($this->defaults, $atts, 'yadore_search');

        // Booleans normalisieren
        $atts['show_initial'] = filter_var($atts['show_initial'], FILTER_VALIDATE_BOOLEAN);
        $atts['live_search'] = filter_var($atts['live_search'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_price'] = filter_var($atts['show_price'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_merchant'] = filter_var($atts['show_merchant'], FILTER_VALIDATE_BOOLEAN);
        $atts['new_tab'] = filter_var($atts['new_tab'], FILTER_VALIDATE_BOOLEAN);
        $atts['show_reset'] = filter_var($atts['show_reset'], FILTER_VALIDATE_BOOLEAN);
        $atts['hide_form'] = filter_var($atts['hide_form'], FILTER_VALIDATE_BOOLEAN);

        // Assets nur einmal laden
        if (!self::$shortcode_rendered) {
            wp_enqueue_style('yadore-search');
            wp_enqueue_script('yadore-search');
            self::$shortcode_rendered = true;
        }

        // Unique ID für mehrere Instanzen auf einer Seite
        $instance_id = 'yadore-search-' . wp_unique_id();

        // Initial-Produkte laden (serverseitig)
        $initial_products = [];
        $initial_html = '';
        
        if ($atts['show_initial']) {
            $initial_products = $this->get_initial_products($atts);
            $initial_html = $this->render_product_grid($initial_products, $atts);
        }

        // Data-Attribute für JS
        $data_attrs = [
            'data-network'         => esc_attr((string) $atts['network']),
            'data-max-results'     => (int) $atts['max_results'],
            'data-columns'         => (int) $atts['columns'],
            'data-min-chars'       => (int) $atts['min_chars'],
            'data-show-price'      => $atts['show_price'] ? '1' : '0',
            'data-show-merchant'   => $atts['show_merchant'] ? '1' : '0',
            'data-new-tab'         => $atts['new_tab'] ? '1' : '0',
            'data-debounce'        => (int) $atts['debounce'],
            'data-live-search'     => $atts['live_search'] ? '1' : '0',
            'data-sort'            => esc_attr((string) $atts['sort']),
            'data-merchant-filter' => esc_attr((string) $atts['merchant_filter']),
            'data-show-initial'    => $atts['show_initial'] ? '1' : '0',
            'data-show-reset'      => $atts['show_reset'] ? '1' : '0',
            'data-has-initial'     => !empty($initial_products) ? '1' : '0',
        ];

        $data_string = '';
        foreach ($data_attrs as $key => $value) {
            $data_string .= sprintf(' %s="%s"', $key, $value);
        }

        ob_start();
        ?>
        <div id="<?php echo esc_attr($instance_id); ?>" class="yadore-search-container"<?php echo $data_string; ?>>
            
            <?php if (!$atts['hide_form']): ?>
            <!-- Suchformular -->
            <form class="yadore-search-form" autocomplete="off">
                <div class="yadore-search-input-wrapper">
                    <input 
                        type="text" 
                        class="yadore-search-input" 
                        placeholder="<?php echo esc_attr((string) $atts['placeholder']); ?>"
                        aria-label="<?php echo esc_attr((string) $atts['placeholder']); ?>"
                    >
                    <button type="submit" class="yadore-search-button">
                        <span class="yadore-search-button-text"><?php echo esc_html((string) $atts['button_text']); ?></span>
                        <span class="yadore-search-spinner" style="display: none;">
                            <svg class="yadore-spinner-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round" stroke-dasharray="31.4 31.4">
                                    <animateTransform attributeName="transform" type="rotate" dur="1s" from="0 12 12" to="360 12 12" repeatCount="indefinite"/>
                                </circle>
                            </svg>
                        </span>
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <!-- Status-Meldungen -->
            <div class="yadore-search-status" style="display: none;"></div>

            <!-- Initial-Produkte (Server-gerendert) -->
            <?php if ($atts['show_initial'] && !empty($initial_products)): ?>
            <div class="yadore-initial-products">
                <div class="yadore-initial-header">
                    <h3 class="yadore-initial-title"><?php echo esc_html((string) $atts['initial_title']); ?></h3>
                </div>
                <div class="yadore-search-results-grid yadore-columns-<?php echo (int) $atts['columns']; ?>">
                    <?php echo $initial_html; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Such-Ergebnisse (wird via JS befüllt) -->
            <div class="yadore-search-results" style="display: none;">
                <div class="yadore-search-results-header">
                    <?php if ($atts['show_reset']): ?>
                    <button type="button" class="yadore-reset-button">
                        <?php echo esc_html__('← Zurück zu Empfehlungen', 'yadore-amazon-api'); ?>
                    </button>
                    <?php endif; ?>
                    <div class="yadore-search-results-info">
                        <span class="yadore-search-results-count"></span>
                        <span class="yadore-search-results-query"></span>
                    </div>
                </div>
                <div class="yadore-search-results-grid yadore-columns-<?php echo (int) $atts['columns']; ?>"></div>
            </div>

        </div>
        <?php
        return ob_get_clean() ?: '';
    }

    /**
     * Initial-Produkte laden
     * 
     * @param array<string, mixed> $atts Shortcode-Attribute
     * @return array<int, array<string, mixed>> Produkte
     */
    private function get_initial_products(array $atts): array {
        $products = [];
        $count = (int) $atts['initial_count'];
        $source = (string) $atts['initial_source'];
        
        // Keywords verarbeiten
        $keywords = [];
        if (!empty($atts['initial_keywords'])) {
            $keywords = array_map('trim', explode(',', (string) $atts['initial_keywords']));
            $keywords = array_filter($keywords);
        } elseif (!empty($atts['initial_keyword'])) {
            $keywords = [trim((string) $atts['initial_keyword'])];
        }

        // Wenn keine Keywords, versuche Featured/Trending zu laden
        if (empty($keywords)) {
            $keywords = $this->get_default_keywords();
        }

        // Produkte von Yadore laden
        if (in_array($source, ['yadore', 'both'], true)) {
            $yadore_products = $this->fetch_yadore_products($keywords, $count, $atts);
            $products = array_merge($products, $yadore_products);
        }

        // Produkte von Amazon laden
        if (in_array($source, ['amazon', 'both'], true)) {
            $remaining = $count - count($products);
            if ($remaining > 0) {
                $amazon_products = $this->fetch_amazon_products($keywords, $remaining, $atts);
                $products = array_merge($products, $amazon_products);
            }
        }

        // Auf gewünschte Anzahl begrenzen
        return array_slice($products, 0, $count);
    }

    /**
     * Default-Keywords für Initial-Produkte
     * 
     * @return array<string> Keywords
     */
    private function get_default_keywords(): array {
        // Option 1: Aus Plugin-Einstellungen
        $saved_keywords = get_option('yadore_featured_keywords', '');
        if (!empty($saved_keywords) && is_string($saved_keywords)) {
            return array_map('trim', explode(',', $saved_keywords));
        }

        // Option 2: Hardcoded Fallbacks (anpassbar)
        return ['Bestseller', 'Angebote', 'Beliebt'];
    }

    /**
     * Produkte von Yadore API laden
     * 
     * @param array<string> $keywords Suchbegriffe
     * @param int $count Anzahl
     * @param array<string, mixed> $atts Shortcode-Attribute
     * @return array<int, array<string, mixed>> Produkte
     */
    private function fetch_yadore_products(array $keywords, int $count, array $atts): array {
        if (!$this->yadore_api->is_configured()) {
            return [];
        }

        $products = [];
        $per_keyword = max(1, (int) ceil($count / count($keywords)));

        foreach ($keywords as $keyword) {
            if (count($products) >= $count) {
                break;
            }

            $api_params = [
                'keyword' => $keyword,
                'limit'   => $per_keyword,
                'sort'    => (string) $atts['sort'],
            ];

            if (!empty($atts['network'])) {
                $api_params['network'] = (string) $atts['network'];
            }

            if (!empty($atts['merchant_filter'])) {
                $api_params['merchant_whitelist'] = (string) $atts['merchant_filter'];
            }

            $results = $this->yadore_api->fetch($api_params);

            if (!is_wp_error($results) && !empty($results)) {
                foreach ($results as $offer) {
                    $products[] = $this->format_yadore_product($offer, $atts);
                    if (count($products) >= $count) {
                        break;
                    }
                }
            }
        }

        return $products;
    }

    /**
     * Produkte von Amazon PA-API laden
     * 
     * @param array<string> $keywords Suchbegriffe
     * @param int $count Anzahl
     * @param array<string, mixed> $atts Shortcode-Attribute
     * @return array<int, array<string, mixed>> Produkte
     */
    private function fetch_amazon_products(array $keywords, int $count, array $atts): array {
        if (!$this->amazon_api->is_configured()) {
            return [];
        }

        $products = [];
        $per_keyword = max(1, (int) ceil($count / count($keywords)));

        foreach ($keywords as $keyword) {
            if (count($products) >= $count) {
                break;
            }

            $results = $this->amazon_api->search_items(
                $keyword,
                'All',
                min($per_keyword, 10) // Amazon max 10 pro Request
            );

            if (!is_wp_error($results) && !empty($results)) {
                foreach ($results as $item) {
                    $products[] = $this->format_amazon_product($item, $atts);
                    if (count($products) >= $count) {
                        break;
                    }
                }
            }
        }

        return $products;
    }

    /**
     * Yadore-Produkt formatieren
     * 
     * @param array<string, mixed> $offer API-Offer
     * @param array<string, mixed> $atts Shortcode-Attribute
     * @return array<string, mixed> Formatiertes Produkt
     */
    private function format_yadore_product(array $offer, array $atts): array {
        // Bild verarbeiten
        $image_url = $offer['image']['url'] ?? '';
        
        // Lokale Bildverarbeitung wenn aktiviert
        if (!empty($image_url) && yaa_get_option('enable_local_images', 'yes') === 'yes') {
            $image_url = YAA_Image_Handler::process(
                $image_url,
                $offer['id'] ?? uniqid('ydr_'),
                $offer['title'] ?? 'product',
                'yadore'
            );
        }

        // Fallback auf Shop-Logo
        if (empty($image_url) && !empty($offer['merchant']['logo'])) {
            $image_url = $offer['merchant']['logo'];
        }

        // Preis formatieren
        $price_display = '';
        if ($atts['show_price'] && !empty($offer['price']['amount'])) {
            $currency = $offer['price']['currency'] ?? 'EUR';
            $amount = (float) $offer['price']['amount'];
            $price_display = number_format($amount, 2, ',', '.') . ' ' . $currency;
        }

        return [
            'id'            => $offer['id'] ?? uniqid(),
            'title'         => $offer['title'] ?? '',
            'description'   => $this->truncate_text($offer['description'] ?? '', 120),
            'price'         => $price_display,
            'price_raw'     => $offer['price']['amount'] ?? 0,
            'image'         => $image_url,
            'url'           => $offer['url'] ?? '#',
            'merchant'      => $atts['show_merchant'] ? ($offer['merchant']['name'] ?? '') : '',
            'merchant_logo' => $offer['merchant']['logo'] ?? '',
            'new_tab'       => (bool) $atts['new_tab'],
            'source'        => 'yadore',
        ];
    }

    /**
     * Amazon-Produkt formatieren
     * 
     * @param array<string, mixed> $item Amazon-Item
     * @param array<string, mixed> $atts Shortcode-Attribute
     * @return array<string, mixed> Formatiertes Produkt
     */
    private function format_amazon_product(array $item, array $atts): array {
        $image_url = $item['image']['url'] ?? '';
        
        // Lokale Bildverarbeitung wenn aktiviert
        if (!empty($image_url) && yaa_get_option('enable_local_images', 'yes') === 'yes') {
            $image_url = YAA_Image_Handler::process(
                $image_url,
                $item['asin'] ?? $item['id'] ?? uniqid('amz_'),
                $item['title'] ?? 'product',
                'amazon'
            );
        }

        $price_display = '';
        if ($atts['show_price'] && !empty($item['price']['display'])) {
            $price_display = $item['price']['display'];
        } elseif ($atts['show_price'] && !empty($item['price']['amount'])) {
            $currency = $item['price']['currency'] ?? 'EUR';
            $amount = (float) $item['price']['amount'];
            $price_display = number_format($amount, 2, ',', '.') . ' ' . $currency;
        }

        return [
            'id'            => $item['asin'] ?? $item['id'] ?? uniqid(),
            'title'         => $item['title'] ?? '',
            'description'   => $this->truncate_text($item['description'] ?? '', 120),
            'price'         => $price_display,
            'price_raw'     => $item['price']['amount'] ?? 0,
            'image'         => $image_url,
            'url'           => $item['url'] ?? '#',
            'merchant'      => $atts['show_merchant'] ? 'Amazon' : '',
            'merchant_logo' => '',
            'new_tab'       => (bool) $atts['new_tab'],
            'source'        => 'amazon',
        ];
    }

    /**
     * Produkt-Grid HTML rendern (für Initial-Produkte)
     * 
     * @param array<int, array<string, mixed>> $products Produkte
     * @param array<string, mixed> $atts Shortcode-Attribute
     * @return string HTML
     */
    private function render_product_grid(array $products, array $atts): string {
        if (empty($products)) {
            return '';
        }

        $html = '';
        foreach ($products as $product) {
            $html .= $this->render_product_card($product);
        }
        return $html;
    }

    /**
     * Einzelne Produkt-Karte rendern
     * 
     * @param array<string, mixed> $product Produkt-Daten
     * @return string HTML
     */
    private function render_product_card(array $product): string {
        $target = !empty($product['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '';
        
        // Bild HTML
        if (!empty($product['image'])) {
            $image_html = sprintf(
                '<div class="yadore-product-image">
                    <img src="%s" alt="%s" loading="lazy" onerror="this.parentElement.classList.add(\'yadore-image-error\')">
                </div>',
                esc_url($product['image']),
                esc_attr($product['title'] ?? '')
            );
        } else {
            $image_html = '<div class="yadore-product-image yadore-no-image">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/>
                </svg>
            </div>';
        }

        // Händler HTML
        $merchant_html = '';
        if (!empty($product['merchant'])) {
            $merchant_html = sprintf(
                '<div class="yadore-product-merchant">%s</div>',
                esc_html($product['merchant'])
            );
        }

        // Preis HTML
        $price_html = '';
        if (!empty($product['price'])) {
            $price_html = sprintf(
                '<div class="yadore-product-price">%s</div>',
                esc_html($product['price'])
            );
        }

        // Source Badge (optional)
        $source_badge = '';
        if (!empty($product['source'])) {
            $source_class = 'yadore-source-' . esc_attr($product['source']);
            $source_badge = sprintf(
                '<span class="yadore-product-source %s"></span>',
                $source_class
            );
        }

        return sprintf(
            '<div class="yadore-product-card" data-product-id="%s">
                <a href="%s"%s class="yadore-product-link">
                    %s
                    %s
                    <div class="yadore-product-content">
                        <h4 class="yadore-product-title">%s</h4>
                        %s
                        %s
                        <span class="yadore-product-cta">%s →</span>
                    </div>
                </a>
            </div>',
            esc_attr($product['id'] ?? ''),
            esc_url($product['url'] ?? '#'),
            $target,
            $source_badge,
            $image_html,
            esc_html($product['title'] ?? ''),
            $merchant_html,
            $price_html,
            esc_html__('Zum Angebot', 'yadore-amazon-api')
        );
    }

    /**
     * AJAX-Handler für Produktsuche
     */
    public function ajax_search(): void {
        // Nonce prüfen
        if (!check_ajax_referer('yadore_search_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Sicherheitsprüfung fehlgeschlagen.', 'yadore-amazon-api')], 403);
        }

        // Parameter validieren
        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        $network = isset($_POST['network']) ? sanitize_text_field(wp_unslash($_POST['network'])) : '';
        $max_results = isset($_POST['max_results']) ? (int) $_POST['max_results'] : 12;
        $sort = isset($_POST['sort']) ? sanitize_text_field(wp_unslash($_POST['sort'])) : 'cpc_desc';
        $merchant_filter = isset($_POST['merchant_filter']) ? sanitize_text_field(wp_unslash($_POST['merchant_filter'])) : '';
        $show_price = isset($_POST['show_price']) && $_POST['show_price'] === '1';
        $show_merchant = isset($_POST['show_merchant']) && $_POST['show_merchant'] === '1';
        $new_tab = isset($_POST['new_tab']) && $_POST['new_tab'] === '1';

        if (empty($query) || strlen($query) < 2) {
            wp_send_json_error(['message' => __('Suchbegriff zu kurz.', 'yadore-amazon-api')], 400);
        }

        // Rate-Limiting
        $rate_limit_key = 'yadore_search_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
        $rate_count = get_transient($rate_limit_key);
        
        if ($rate_count !== false && (int) $rate_count > 30) {
            wp_send_json_error(['message' => __('Zu viele Anfragen. Bitte warten.', 'yadore-amazon-api')], 429);
        }
        
        set_transient($rate_limit_key, ($rate_count ? (int) $rate_count + 1 : 1), 60);

        // Yadore API aufrufen
        $api_params = [
            'keyword' => $query,
            'limit'   => min($max_results, 50),
            'sort'    => $sort,
        ];

        if (!empty($network)) {
            $api_params['network'] = $network;
        }

        if (!empty($merchant_filter)) {
            $api_params['merchant_whitelist'] = $merchant_filter;
        }

        $results = $this->yadore_api->fetch($api_params);

        if (is_wp_error($results)) {
            wp_send_json_error([
                'message' => $results->get_error_message(),
            ], 500);
        }

        // Ergebnisse aufbereiten
        $atts = [
            'show_price'    => $show_price,
            'show_merchant' => $show_merchant,
            'new_tab'       => $new_tab,
        ];

        $products = [];
        if (!empty($results)) {
            foreach ($results as $offer) {
                $products[] = $this->format_yadore_product($offer, $atts);
            }
        }

        wp_send_json_success([
            'products' => $products,
            'total'    => count($products),
            'query'    => $query,
        ]);
    }

    /**
     * Text kürzen
     * 
     * @param string $text Text
     * @param int $length Max Länge
     * @return string Gekürzter Text
     */
    private function truncate_text(string $text, int $length = 120): string {
        $text = wp_strip_all_tags($text);
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }
}

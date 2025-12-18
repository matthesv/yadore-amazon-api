<?php
/**
 * Custom Products - Eigene Produkte verwalten mit Fuzzy-Suche
 * PHP 8.3+ compatible
 * Version: 1.2.6
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Custom_Products {
    
    private const POST_TYPE = 'yaa_product';
    private const TAXONOMY = 'yaa_product_category';
    
    private ?YAA_Fuzzy_Matcher $fuzzy_matcher = null;
    
    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_taxonomy']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_meta_data']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'add_admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_columns'], 10, 2);
    }
    
    /**
     * Get Fuzzy Matcher instance (lazy loading)
     */
    private function get_fuzzy_matcher(): YAA_Fuzzy_Matcher {
        if ($this->fuzzy_matcher === null) {
            $this->fuzzy_matcher = new YAA_Fuzzy_Matcher();
        }
        return $this->fuzzy_matcher;
    }
    
    /**
     * Register Custom Post Type
     */
    public function register_post_type(): void {
        $labels = [
            'name'                  => __('Eigene Produkte', 'yadore-amazon-api'),
            'singular_name'         => __('Produkt', 'yadore-amazon-api'),
            'menu_name'             => __('Eigene Produkte', 'yadore-amazon-api'),
            'add_new'               => __('Neues Produkt', 'yadore-amazon-api'),
            'add_new_item'          => __('Neues Produkt hinzufügen', 'yadore-amazon-api'),
            'edit_item'             => __('Produkt bearbeiten', 'yadore-amazon-api'),
            'new_item'              => __('Neues Produkt', 'yadore-amazon-api'),
            'view_item'             => __('Produkt ansehen', 'yadore-amazon-api'),
            'search_items'          => __('Produkte suchen', 'yadore-amazon-api'),
            'not_found'             => __('Keine Produkte gefunden', 'yadore-amazon-api'),
            'not_found_in_trash'    => __('Keine Produkte im Papierkorb', 'yadore-amazon-api'),
            'all_items'             => __('Alle Produkte', 'yadore-amazon-api'),
            'featured_image'        => __('Produktbild', 'yadore-amazon-api'),
            'set_featured_image'    => __('Produktbild festlegen', 'yadore-amazon-api'),
            'remove_featured_image' => __('Produktbild entfernen', 'yadore-amazon-api'),
            'use_featured_image'    => __('Als Produktbild verwenden', 'yadore-amazon-api'),
        ];
        
        $args = [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => 'yaa-settings',
            'query_var'           => false,
            'rewrite'             => false,
            'capability_type'     => 'post',
            'has_archive'         => false,
            'hierarchical'        => false,
            'menu_position'       => null,
            'menu_icon'           => 'dashicons-products',
            'supports'            => ['title', 'thumbnail'],
            'show_in_rest'        => true,
        ];
        
        register_post_type(self::POST_TYPE, $args);
    }
    
    /**
     * Register Taxonomy for Product Categories
     */
    public function register_taxonomy(): void {
        $labels = [
            'name'              => __('Produkt-Kategorien', 'yadore-amazon-api'),
            'singular_name'     => __('Kategorie', 'yadore-amazon-api'),
            'search_items'      => __('Kategorien suchen', 'yadore-amazon-api'),
            'all_items'         => __('Alle Kategorien', 'yadore-amazon-api'),
            'parent_item'       => __('Übergeordnete Kategorie', 'yadore-amazon-api'),
            'parent_item_colon' => __('Übergeordnete Kategorie:', 'yadore-amazon-api'),
            'edit_item'         => __('Kategorie bearbeiten', 'yadore-amazon-api'),
            'update_item'       => __('Kategorie aktualisieren', 'yadore-amazon-api'),
            'add_new_item'      => __('Neue Kategorie hinzufügen', 'yadore-amazon-api'),
            'new_item_name'     => __('Neuer Kategorie-Name', 'yadore-amazon-api'),
            'menu_name'         => __('Kategorien', 'yadore-amazon-api'),
        ];
        
        $args = [
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => false,
            'rewrite'           => false,
            'show_in_rest'      => true,
        ];
        
        register_taxonomy(self::TAXONOMY, self::POST_TYPE, $args);
    }
    
    /**
     * Add Meta Boxes
     */
    public function add_meta_boxes(): void {
        add_meta_box(
            'yaa_product_details',
            __('Produkt-Details', 'yadore-amazon-api'),
            [$this, 'render_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }
    
    /**
     * Render Meta Box
     */
    public function render_meta_box(\WP_Post $post): void {
        wp_nonce_field('yaa_product_meta', 'yaa_product_meta_nonce');
        
        // Bestehende Werte laden
        $url = get_post_meta($post->ID, '_yaa_product_url', true);
        $price = get_post_meta($post->ID, '_yaa_product_price', true);
        $currency = get_post_meta($post->ID, '_yaa_product_currency', true) ?: 'EUR';
        $merchant = get_post_meta($post->ID, '_yaa_product_merchant', true);
        $description = get_post_meta($post->ID, '_yaa_product_description', true);
        $button_text = get_post_meta($post->ID, '_yaa_product_button_text', true);
        $is_prime = get_post_meta($post->ID, '_yaa_product_is_prime', true);
        $external_image = get_post_meta($post->ID, '_yaa_product_external_image', true);
        $sort_order = get_post_meta($post->ID, '_yaa_product_sort_order', true) ?: 0;
        $fuzzy_keywords = get_post_meta($post->ID, '_yaa_product_fuzzy_keywords', true); // NEU
        
        ?>
        <style>
            .yaa-meta-row { margin-bottom: 15px; }
            .yaa-meta-row label { display: block; font-weight: 600; margin-bottom: 5px; }
            .yaa-meta-row input[type="text"],
            .yaa-meta-row input[type="url"],
            .yaa-meta-row input[type="number"],
            .yaa-meta-row select,
            .yaa-meta-row textarea { width: 100%; max-width: 600px; }
            .yaa-meta-row textarea { min-height: 100px; }
            .yaa-meta-row .description { color: #666; font-size: 12px; margin-top: 4px; }
            .yaa-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
            @media (max-width: 782px) { .yaa-meta-grid { grid-template-columns: 1fr; } }
            .yaa-required { color: #dc3232; }
            .yaa-fuzzy-box { background: #f0f6fc; border: 1px solid #c8d9e8; border-radius: 4px; padding: 15px; margin-top: 20px; }
        </style>
        
        <div class="yaa-meta-grid">
            <div>
                <div class="yaa-meta-row">
                    <label for="yaa_product_url">
                        <?php esc_html_e('Produkt-URL / Affiliate-Link', 'yadore-amazon-api'); ?>
                        <span class="yaa-required">*</span>
                    </label>
                    <input type="url" id="yaa_product_url" name="yaa_product_url" 
                           value="<?php echo esc_attr($url); ?>" required>
                    <p class="description"><?php esc_html_e('Der Link zum Produkt oder Affiliate-Link.', 'yadore-amazon-api'); ?></p>
                </div>
                
                <div class="yaa-meta-row">
                    <label for="yaa_product_price"><?php esc_html_e('Preis', 'yadore-amazon-api'); ?></label>
                    <input type="text" id="yaa_product_price" name="yaa_product_price" 
                           value="<?php echo esc_attr($price); ?>" placeholder="29.99">
                    <p class="description"><?php esc_html_e('Numerischer Preis ohne Währungszeichen.', 'yadore-amazon-api'); ?></p>
                </div>
                
                <div class="yaa-meta-row">
                    <label for="yaa_product_currency"><?php esc_html_e('Währung', 'yadore-amazon-api'); ?></label>
                    <select id="yaa_product_currency" name="yaa_product_currency">
                        <?php
                        $currencies = ['EUR' => '€ Euro', 'USD' => '$ US-Dollar', 'GBP' => '£ Pfund', 'CHF' => 'CHF Franken', 'PLN' => 'zł Zloty'];
                        foreach ($currencies as $code => $label):
                        ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($currency, $code); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="yaa-meta-row">
                    <label for="yaa_product_merchant"><?php esc_html_e('Händler / Shop', 'yadore-amazon-api'); ?></label>
                    <input type="text" id="yaa_product_merchant" name="yaa_product_merchant" 
                           value="<?php echo esc_attr($merchant); ?>" placeholder="Amazon, eBay, ...">
                </div>
                
                <div class="yaa-meta-row">
                    <label for="yaa_product_button_text"><?php esc_html_e('Button-Text (optional)', 'yadore-amazon-api'); ?></label>
                    <input type="text" id="yaa_product_button_text" name="yaa_product_button_text" 
                           value="<?php echo esc_attr($button_text); ?>" placeholder="Zum Angebot">
                    <p class="description"><?php esc_html_e('Überschreibt den Standard-Button-Text.', 'yadore-amazon-api'); ?></p>
                </div>
            </div>
            
            <div>
                <div class="yaa-meta-row">
                    <label for="yaa_product_description"><?php esc_html_e('Beschreibung', 'yadore-amazon-api'); ?></label>
                    <textarea id="yaa_product_description" name="yaa_product_description"><?php echo esc_textarea($description); ?></textarea>
                </div>
                
                <div class="yaa-meta-row">
                    <label for="yaa_product_external_image"><?php esc_html_e('Externes Bild (URL)', 'yadore-amazon-api'); ?></label>
                    <input type="url" id="yaa_product_external_image" name="yaa_product_external_image" 
                           value="<?php echo esc_attr($external_image); ?>">
                    <p class="description"><?php esc_html_e('Optional: Externe Bild-URL. Hat Vorrang vor dem Beitragsbild.', 'yadore-amazon-api'); ?></p>
                </div>
                
                <div class="yaa-meta-row">
                    <label for="yaa_product_sort_order"><?php esc_html_e('Sortierung', 'yadore-amazon-api'); ?></label>
                    <input type="number" id="yaa_product_sort_order" name="yaa_product_sort_order" 
                           value="<?php echo esc_attr($sort_order); ?>" min="0" step="1">
                    <p class="description"><?php esc_html_e('Kleinere Zahlen werden zuerst angezeigt.', 'yadore-amazon-api'); ?></p>
                </div>
                
                <div class="yaa-meta-row">
                    <label>
                        <input type="checkbox" name="yaa_product_is_prime" value="1" <?php checked($is_prime, '1'); ?>>
                        <?php esc_html_e('Prime-Badge anzeigen', 'yadore-amazon-api'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Zeigt ein Prime-Badge wie bei Amazon-Produkten.', 'yadore-amazon-api'); ?></p>
                </div>
            </div>
        </div>
        
        <!-- NEU: Fuzzy-Keywords -->
        <div class="yaa-fuzzy-box">
            <h4 style="margin-top: 0;">🔍 <?php esc_html_e('Fuzzy-Suche Keywords', 'yadore-amazon-api'); ?></h4>
            <div class="yaa-meta-row">
                <label for="yaa_product_fuzzy_keywords"><?php esc_html_e('Zusätzliche Suchbegriffe', 'yadore-amazon-api'); ?></label>
                <input type="text" id="yaa_product_fuzzy_keywords" name="yaa_product_fuzzy_keywords" 
                       value="<?php echo esc_attr($fuzzy_keywords); ?>" 
                       placeholder="Keyword1, Keyword2, Synonym, Alternative">
                <p class="description">
                    <?php esc_html_e('Komma-separierte Keywords, die dieses Produkt bei der Fuzzy-Suche finden. Beispiel: "Kopfhörer, Headphones, Over-Ear, Bluetooth Audio"', 'yadore-amazon-api'); ?>
                </p>
            </div>
        </div>
        
        <hr style="margin: 20px 0;">
        
        <h4><?php esc_html_e('Shortcode-Verwendung', 'yadore-amazon-api'); ?></h4>
        <p>
            <code>[custom_products ids="<?php echo $post->ID; ?>"]</code> – 
            <?php esc_html_e('Dieses Produkt einzeln anzeigen', 'yadore-amazon-api'); ?>
        </p>
        <?php
    }
    
    /**
     * Save Meta Data
     */
    public function save_meta_data(int $post_id): void {
        // Nonce check
        if (!isset($_POST['yaa_product_meta_nonce']) || 
            !wp_verify_nonce($_POST['yaa_product_meta_nonce'], 'yaa_product_meta')) {
            return;
        }
        
        // Auto-save check
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Permission check
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Felder speichern
        $fields = [
            '_yaa_product_url'            => 'esc_url_raw',
            '_yaa_product_price'          => 'sanitize_text_field',
            '_yaa_product_currency'       => 'sanitize_text_field',
            '_yaa_product_merchant'       => 'sanitize_text_field',
            '_yaa_product_description'    => 'sanitize_textarea_field',
            '_yaa_product_button_text'    => 'sanitize_text_field',
            '_yaa_product_external_image' => 'esc_url_raw',
            '_yaa_product_sort_order'     => 'absint',
            '_yaa_product_fuzzy_keywords' => 'sanitize_text_field', // NEU
        ];
        
        foreach ($fields as $meta_key => $sanitize_callback) {
            $field_name = str_replace('_yaa_product_', 'yaa_product_', $meta_key);
            
            if (isset($_POST[$field_name])) {
                $value = call_user_func($sanitize_callback, $_POST[$field_name]);
                update_post_meta($post_id, $meta_key, $value);
            }
        }
        
        // Checkbox
        $is_prime = isset($_POST['yaa_product_is_prime']) ? '1' : '';
        update_post_meta($post_id, '_yaa_product_is_prime', $is_prime);
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts(string $hook): void {
        global $post_type;
        
        if ($post_type !== self::POST_TYPE) {
            return;
        }
        
        wp_enqueue_media();
    }
    
    /**
     * Add admin columns
     * 
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function add_admin_columns(array $columns): array {
        $new_columns = [];
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'title') {
                $new_columns['yaa_image'] = __('Bild', 'yadore-amazon-api');
                $new_columns['yaa_price'] = __('Preis', 'yadore-amazon-api');
                $new_columns['yaa_merchant'] = __('Händler', 'yadore-amazon-api');
            }
        }
        
        $new_columns['yaa_shortcode'] = __('Shortcode', 'yadore-amazon-api');
        
        return $new_columns;
    }
    
    /**
     * Render admin columns
     */
    public function render_admin_columns(string $column, int $post_id): void {
        switch ($column) {
            case 'yaa_image':
                $external_image = get_post_meta($post_id, '_yaa_product_external_image', true);
                if ($external_image) {
                    echo '<img src="' . esc_url($external_image) . '" style="width:50px;height:50px;object-fit:contain;">';
                } elseif (has_post_thumbnail($post_id)) {
                    echo get_the_post_thumbnail($post_id, [50, 50], ['style' => 'object-fit:contain;']);
                } else {
                    echo '—';
                }
                break;
                
            case 'yaa_price':
                $price = get_post_meta($post_id, '_yaa_product_price', true);
                $currency = get_post_meta($post_id, '_yaa_product_currency', true) ?: 'EUR';
                echo $price ? esc_html($price . ' ' . $currency) : '—';
                break;
                
            case 'yaa_merchant':
                $merchant = get_post_meta($post_id, '_yaa_product_merchant', true);
                echo $merchant ? esc_html($merchant) : '—';
                break;
                
            case 'yaa_shortcode':
                echo '<code style="font-size:11px;">[custom_products ids="' . $post_id . '"]</code>';
                break;
        }
    }
    
    /**
     * Get products by IDs
     * 
     * @param array<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function get_products_by_ids(array $ids): array {
        if (empty($ids)) {
            return [];
        }
        
        $args = [
            'post_type'      => self::POST_TYPE,
            'post__in'       => $ids,
            'posts_per_page' => count($ids),
            'orderby'        => 'post__in',
            'post_status'    => 'publish',
        ];
        
        $query = new \WP_Query($args);
        
        return $this->format_products($query->posts);
    }
    
    /**
     * Get products by category
     * 
     * @return array<int, array<string, mixed>>
     */
    public function get_products_by_category(string $category, int $limit = 10): array {
        $args = [
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'meta_key'       => '_yaa_product_sort_order',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
            'tax_query'      => [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => $category,
                ],
            ],
        ];
        
        $query = new \WP_Query($args);
        
        return $this->format_products($query->posts);
    }
    
    /**
     * Get all products
     * 
     * @return array<int, array<string, mixed>>
     */
    public function get_all_products(int $limit = -1): array {
        $args = [
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'meta_key'       => '_yaa_product_sort_order',
            'orderby'        => 'meta_value_num',
            'order'          => 'ASC',
        ];
        
        $query = new \WP_Query($args);
        
        return $this->format_products($query->posts);
    }
    
    /**
     * ===== NEU: Fuzzy Search für eigene Produkte =====
     * Findet ähnliche Produkte basierend auf Keyword-Matching
     * 
     * @param string $keyword Suchbegriff
     * @param int $limit Max. Anzahl Ergebnisse
     * @param int|null $threshold Min. Score (0-100), null = Plugin-Standard
     * @return array<int, array<string, mixed>> Sortiert nach Relevanz
     */
    public function search_products_fuzzy(string $keyword, int $limit = 10, ?int $threshold = null): array {
        $keyword = trim($keyword);
        
        if ($keyword === '') {
            return [];
        }
        
        // Fuzzy Matcher initialisieren
        $matcher = $this->get_fuzzy_matcher();
        
        if ($threshold !== null) {
            $matcher->set_threshold($threshold);
        }
        
        // Alle eigenen Produkte laden
        $all_products = $this->get_all_products(-1);
        
        if (empty($all_products)) {
            return [];
        }
        
        // Score für jedes Produkt berechnen
        $scored_products = [];
        
        foreach ($all_products as $product) {
            // Zusätzliche Fuzzy-Keywords aus Meta laden
            $post_id = $product['post_id'] ?? 0;
            $fuzzy_keywords = '';
            if ($post_id > 0) {
                $fuzzy_keywords = get_post_meta($post_id, '_yaa_product_fuzzy_keywords', true) ?: '';
            }
            
            // Keywords als Array hinzufügen
            if ($fuzzy_keywords !== '') {
                $product['keywords'] = array_map('trim', explode(',', $fuzzy_keywords));
            }
            
            // Kategorien laden
            if ($post_id > 0) {
                $terms = wp_get_post_terms($post_id, self::TAXONOMY, ['fields' => 'names']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $product['categories'] = $terms;
                }
            }
            
            // Score berechnen
            $result = $matcher->calculate_score($keyword, $product);
            
            // Nur Produkte über Threshold behalten
            if ($matcher->meets_threshold($result['score'])) {
                $scored_products[] = [
                    'product' => $product,
                    'score'   => $result['score'],
                    'matches' => $result['matches'],
                ];
            }
        }
        
        // Nach Score sortieren
        $scored_products = $matcher->sort_by_score($scored_products);
        
        // Limit anwenden
        $scored_products = array_slice($scored_products, 0, $limit);
        
        // Nur Produkte zurückgeben (Score als Meta hinzufügen)
        $results = [];
        foreach ($scored_products as $item) {
            $product = $item['product'];
            $product['_fuzzy_score'] = $item['score'];
            $product['_fuzzy_matches'] = $item['matches'];
            $results[] = $product;
        }
        
        return $results;
    }
    
    /**
     * NEU: Suche eigene Produkte die zu externen Produkten passen
     * Nützlich um eigene Alternativen zu Amazon/Yadore Produkten zu finden
     * 
     * @param array<string, mixed> $external_product Ein Produkt von Amazon/Yadore
     * @param int $limit Max. ähnliche eigene Produkte
     * @return array<int, array<string, mixed>>
     */
    public function find_similar_own_products(array $external_product, int $limit = 3): array {
        $title = $external_product['title'] ?? '';
        
        if ($title === '') {
            return [];
        }
        
        // Fuzzy-Suche basierend auf dem Titel
        return $this->search_products_fuzzy($title, $limit, 25); // Etwas niedrigerer Threshold
    }
    
    /**
     * NEU: Prüft ob ein eigenes Produkt zu einem Keyword passt
     * 
     * @param int $product_id Post-ID
     * @param string $keyword Suchbegriff
     * @return array{matches: bool, score: float}
     */
    public function product_matches_keyword(int $product_id, string $keyword): array {
        $products = $this->get_products_by_ids([$product_id]);
        
        if (empty($products)) {
            return ['matches' => false, 'score' => 0.0];
        }
        
        $product = $products[0];
        
        // Fuzzy Keywords laden
        $fuzzy_keywords = get_post_meta($product_id, '_yaa_product_fuzzy_keywords', true) ?: '';
        if ($fuzzy_keywords !== '') {
            $product['keywords'] = array_map('trim', explode(',', $fuzzy_keywords));
        }
        
        // Kategorien laden
        $terms = wp_get_post_terms($product_id, self::TAXONOMY, ['fields' => 'names']);
        if (!is_wp_error($terms) && !empty($terms)) {
            $product['categories'] = $terms;
        }
        
        $matcher = $this->get_fuzzy_matcher();
        $result = $matcher->calculate_score($keyword, $product);
        
        return [
            'matches' => $matcher->meets_threshold($result['score']),
            'score'   => $result['score'],
        ];
    }
    
    /**
     * Format products to standard array format
     * 
     * @param array<\WP_Post> $posts
     * @return array<int, array<string, mixed>>
     */
    private function format_products(array $posts): array {
        $products = [];
        
        foreach ($posts as $post) {
            $external_image = get_post_meta($post->ID, '_yaa_product_external_image', true);
            $image_url = '';
            
            if (!empty($external_image)) {
                $image_url = $external_image;
            } elseif (has_post_thumbnail($post->ID)) {
                $image_url = get_the_post_thumbnail_url($post->ID, 'large');
            }
            
            $products[] = [
                'id'          => 'custom_' . $post->ID,
                'post_id'     => $post->ID,
                'title'       => $post->post_title,
                'description' => get_post_meta($post->ID, '_yaa_product_description', true) ?: '',
                'url'         => get_post_meta($post->ID, '_yaa_product_url', true) ?: '#',
                'image'       => [
                    'url' => $image_url,
                ],
                'price'       => [
                    'amount'   => get_post_meta($post->ID, '_yaa_product_price', true) ?: '',
                    'currency' => get_post_meta($post->ID, '_yaa_product_currency', true) ?: 'EUR',
                    'display'  => '',
                ],
                'merchant'    => [
                    'name' => get_post_meta($post->ID, '_yaa_product_merchant', true) ?: '',
                ],
                'source'      => 'custom',
                'is_prime'    => get_post_meta($post->ID, '_yaa_product_is_prime', true) === '1',
                'button_text' => get_post_meta($post->ID, '_yaa_product_button_text', true) ?: '',
            ];
        }
        
        return $products;
    }
    
    /**
     * Get post type name
     */
    public static function get_post_type(): string {
        return self::POST_TYPE;
    }
    
    /**
     * Get taxonomy name
     */
    public static function get_taxonomy(): string {
        return self::TAXONOMY;
    }
}

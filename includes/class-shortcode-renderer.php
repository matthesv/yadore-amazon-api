<?php
/**
 * Shortcode Renderer - Mit Fuzzy-Suche, SEO-Bildnamen und Bild-Fehlerbehandlung
 * PHP 8.3+ compatible
 * Version: 1.2.9
 * 
 * Features:
 * - Yadore, Amazon, Custom Products Shortcodes
 * - Fuzzy-Suche für eigene Produkte
 * - Automatisches Einmischen eigener Produkte
 * - Lokale Bilderspeicherung mit SEO-Dateinamen (NEU)
 * - hide_no_image Option zum Ausfiltern
 * - Multi-Keyword Support
 * 
 * NEU in 1.2.9:
 * - SEO-optimierte Dateinamen für Bilder (Produktname + Timestamp)
 * - Produktname wird an Image Handler übergeben
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
    
    /** @var bool Ob Bilder lokal gespeichert werden sollen */
    private bool $use_local_images;
    
    /** @var bool Ob Fuzzy-Suche global aktiviert ist */
    private bool $fuzzy_enabled;
    
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
        
        $this->use_local_images = (yaa_get_option('enable_local_images', 'yes') === 'yes');
        $this->fuzzy_enabled = (yaa_get_option('enable_fuzzy_search', 'yes') === 'yes');
        
        // Register shortcodes
        add_shortcode('yaa_products', [$this, 'render_combined']);
        add_shortcode('yadore_products', [$this, 'render_yadore']);
        add_shortcode('amazon_products', [$this, 'render_amazon']);
        add_shortcode('combined_products', [$this, 'render_combined']);
        add_shortcode('custom_products', [$this, 'render_custom']);
        add_shortcode('all_products', [$this, 'render_all_sources']);
        add_shortcode('fuzzy_products', [$this, 'render_fuzzy']);
    }
    
    /**
     * Render fuzzy search results from custom products
     */
    public function render_fuzzy(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'       => '',
            'keywords'      => '',
            'limit'         => 10,
            'threshold'     => '',  // Leer = Plugin-Standard
            'columns'       => '',
            'class'         => '',
            'local_images'  => '',
            'hide_no_image' => 'no',
            'show_score'    => 'no',  // Debug: Score anzeigen
        ], $atts, 'fuzzy_products');
        
        // Keywords ermitteln
        $keywords = [];
        
        if (!empty($atts['keywords'])) {
            $keywords = array_map('trim', explode(',', (string) $atts['keywords']));
            $keywords = array_filter($keywords);
        } elseif (!empty($atts['keyword'])) {
            $keywords = [trim((string) $atts['keyword'])];
        }
        
        if (empty($keywords)) {
            return $this->render_error(__('Bitte keyword oder keywords angeben.', 'yadore-amazon-api'));
        }
        
        $total_limit = (int) $atts['limit'];
        $threshold = (string) $atts['threshold'] !== '' ? (int) $atts['threshold'] : null;
        
        $all_items = [];
        
        // Für jedes Keyword Fuzzy-Suche durchführen
        if (count($keywords) === 1) {
            $all_items = $this->custom_products->search_products_fuzzy(
                $keywords[0],
                $total_limit,
                $threshold
            );
        } else {
            // Multi-Keyword: Limit aufteilen
            $per_keyword = (int) ceil($total_limit / count($keywords));
            $collected = [];
            
            foreach ($keywords as $keyword) {
                $results = $this->custom_products->search_products_fuzzy(
                    $keyword,
                    $per_keyword,
                    $threshold
                );
                
                // Duplikate vermeiden (basierend auf post_id)
                foreach ($results as $item) {
                    $pid = $item['post_id'] ?? 0;
                    if (!isset($collected[$pid])) {
                        $collected[$pid] = $item;
                    } else {
                        // Höheren Score behalten
                        if (($item['_fuzzy_score'] ?? 0) > ($collected[$pid]['_fuzzy_score'] ?? 0)) {
                            $collected[$pid] = $item;
                        }
                    }
                }
            }
            
            // Nach Score sortieren
            $all_items = array_values($collected);
            usort($all_items, fn($a, $b) => ($b['_fuzzy_score'] ?? 0) <=> ($a['_fuzzy_score'] ?? 0));
            
            // Limit anwenden
            $all_items = array_slice($all_items, 0, $total_limit);
        }
        
        // Produkte ohne Bild ausfiltern
        $all_items = $this->filter_items_without_image($all_items, $atts);
        
        if (empty($all_items)) {
            return $this->render_empty();
        }
        
        // Debug: Score in Beschreibung anzeigen
        if ((string) $atts['show_score'] === 'yes') {
            foreach ($all_items as &$item) {
                $score = $item['_fuzzy_score'] ?? 0;
                $item['description'] = sprintf(
                    '[Score: %.1f%%] %s',
                    $score,
                    $item['description'] ?? ''
                );
            }
            unset($item);
        }
        
        return $this->render_grid($all_items, $atts);
    }
    
    /**
     * Render Yadore products - Mit optionalem Fuzzy-Mixing
     */
    public function render_yadore(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'         => '',
            'keywords'        => '',
            'limit'           => (int) yaa_get_option('yadore_default_limit', 9),
            'market'          => (string) yaa_get_option('yadore_market', 'de'),
            'precision'       => (string) yaa_get_option('yadore_precision', 'fuzzy'),
            'columns'         => '',
            'class'           => '',
            'local_images'    => '',
            'hide_no_image'   => 'no',
            'mix_custom'      => '',    // 'yes' = Fuzzy-Produkte einmischen
            'custom_position' => 'start',  // 'start', 'end', 'shuffle', 'alternate'
            'custom_limit'    => 2,
        ], $atts, 'yadore_products');
        
        if (!$this->yadore_api->is_configured()) {
            return $this->render_error(__('Yadore API nicht konfiguriert.', 'yadore-amazon-api'));
        }
        
        // Multi-Keyword Support
        $keywords = [];
        
        if (!empty($atts['keywords'])) {
            $keywords = array_map('trim', explode(',', (string) $atts['keywords']));
            $keywords = array_filter($keywords);
        } elseif (!empty($atts['keyword'])) {
            $keywords = [trim((string) $atts['keyword'])];
        }
        
        if (empty($keywords)) {
            return $this->render_error(__('Bitte keyword oder keywords angeben.', 'yadore-amazon-api'));
        }
        
        $total_limit = (int) $atts['limit'];
        $items = [];
        
        if (count($keywords) === 1) {
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
            $items = $this->fetch_multi_keywords($keywords, $total_limit, $atts);
        }
        
        // Fuzzy Custom Products einmischen
        $items = $this->maybe_mix_fuzzy_products($items, $keywords, $atts);
        
        // Produkte ohne Bild ausfiltern
        $items = $this->filter_items_without_image($items, $atts);
        
        if (empty($items)) {
            return $this->render_empty();
        }
        
        return $this->render_grid($items, $atts);
    }
    
    /**
     * Render Amazon products - Mit optionalem Fuzzy-Mixing
     */
    public function render_amazon(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'         => '',
            'category'        => (string) yaa_get_option('amazon_default_category', 'All'),
            'limit'           => 10,
            'min_price'       => '',
            'max_price'       => '',
            'brand'           => '',
            'sort'            => '',
            'asins'           => '',
            'columns'         => '',
            'class'           => '',
            'local_images'    => '',
            'hide_no_image'   => 'no',
            'mix_custom'      => '',
            'custom_position' => 'start',
            'custom_limit'    => 2,
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
        
        // Fuzzy Custom Products einmischen
        $keyword = trim((string) $atts['keyword']);
        if ($keyword !== '') {
            $items = $this->maybe_mix_fuzzy_products($items, [$keyword], $atts);
        }
        
        // Produkte ohne Bild ausfiltern
        $items = $this->filter_items_without_image($items, $atts);
        
        if (empty($items)) {
            return $this->render_empty();
        }
        
        return $this->render_grid($items, $atts);
    }
    
    /**
     * Render custom products
     */
    public function render_custom(array|string $atts = []): string {
        $atts = shortcode_atts([
            'ids'           => '',
            'category'      => '',
            'keyword'       => '',  // Fuzzy-Suche Keyword
            'limit'         => 10,
            'columns'       => '',
            'class'         => '',
            'local_images'  => '',
            'hide_no_image' => 'no',
            'fuzzy'         => '',    // 'yes' für Fuzzy-Suche
            'threshold'     => '',    // Fuzzy Threshold
        ], $atts, 'custom_products');
        
        $items = [];
        
        // Nach IDs laden
        if (!empty($atts['ids'])) {
            $ids = array_map('intval', explode(',', (string) $atts['ids']));
            $ids = array_filter($ids);
            $items = $this->custom_products->get_products_by_ids($ids);
        }
        // Fuzzy-Suche
        elseif (!empty($atts['keyword']) && ($atts['fuzzy'] === 'yes' || $this->fuzzy_enabled)) {
            $threshold = (string) $atts['threshold'] !== '' ? (int) $atts['threshold'] : null;
            $items = $this->custom_products->search_products_fuzzy(
                (string) $atts['keyword'],
                (int) $atts['limit'],
                $threshold
            );
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
        
        // Produkte ohne Bild ausfiltern
        $items = $this->filter_items_without_image($items, $atts);
        
        if (empty($items)) {
            return $this->render_empty();
        }
        
        return $this->render_grid($items, $atts);
    }
    
    /**
     * Render combined products (Yadore + Amazon + Custom)
     */
    public function render_combined(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'         => '',
            'keywords'        => '',
            'yadore_limit'    => 6,
            'amazon_limit'    => 4,
            'custom_ids'      => '',
            'custom_category' => '',
            'custom_limit'    => 0,
            'custom_fuzzy'    => 'no',  // Fuzzy-Suche für eigene Produkte
            'market'          => (string) yaa_get_option('yadore_market', 'de'),
            'category'        => (string) yaa_get_option('amazon_default_category', 'All'),
            'shuffle'         => 'yes',
            'columns'         => '',
            'class'           => '',
            'local_images'    => '',
            'hide_no_image'   => 'no',
        ], $atts, 'combined_products');
        
        $combined = [];
        $yadore_limit = (int) $atts['yadore_limit'];
        $amazon_limit = (int) $atts['amazon_limit'];
        $custom_limit = (int) $atts['custom_limit'];
        
        $keyword = trim((string) $atts['keyword']);
        $keywords_string = trim((string) $atts['keywords']);
        
        // 1. Eigene Produkte laden
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
        } 
        // Fuzzy-Suche für eigene Produkte
        elseif ($atts['custom_fuzzy'] === 'yes' && ($keyword !== '' || $keywords_string !== '')) {
            $search_term = $keyword !== '' ? $keyword : explode(',', $keywords_string)[0];
            $custom_items = $this->custom_products->search_products_fuzzy(
                trim($search_term),
                $custom_limit > 0 ? $custom_limit : 3
            );
            $combined = array_merge($combined, $custom_items);
        } elseif ($custom_limit > 0) {
            $custom_items = $this->custom_products->get_all_products($custom_limit);
            $combined = array_merge($combined, $custom_items);
        }
        
        // 2. Yadore Produkte laden
        if ($this->yadore_api->is_configured() && $yadore_limit > 0 && ($keyword !== '' || $keywords_string !== '')) {
            if ($keywords_string !== '') {
                $keywords = array_map('trim', explode(',', $keywords_string));
                $keywords = array_filter($keywords);
                $yadore_items = $this->fetch_multi_keywords($keywords, $yadore_limit, [
                    'market'    => (string) $atts['market'],
                    'precision' => (string) yaa_get_option('yadore_precision', 'fuzzy'),
                ]);
            } else {
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
        
        // Produkte ohne Bild ausfiltern
        $combined = $this->filter_items_without_image($combined, $atts);
        
        if (empty($combined)) {
            return $this->render_empty();
        }
        
        if ((string) $atts['shuffle'] === 'yes') {
            shuffle($combined);
        }
        
        return $this->render_grid($combined, $atts);
    }
    
    /**
     * Render all sources combined
     */
    public function render_all_sources(array|string $atts = []): string {
        $atts = shortcode_atts([
            'keyword'         => '',
            'keywords'        => '',
            'total_limit'     => 12,
            'custom_ids'      => '',
            'custom_category' => '',
            'custom_fuzzy'    => 'no',
            'market'          => (string) yaa_get_option('yadore_market', 'de'),
            'category'        => (string) yaa_get_option('amazon_default_category', 'All'),
            'priority'        => 'custom,yadore,amazon',
            'shuffle'         => 'no',
            'columns'         => '',
            'class'           => '',
            'local_images'    => '',
            'hide_no_image'   => 'no',
        ], $atts, 'all_products');
        
        $total_limit = (int) $atts['total_limit'];
        $combined = [];
        $remaining = $total_limit;
        
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
                    } 
                    // Fuzzy
                    elseif ($atts['custom_fuzzy'] === 'yes') {
                        $keyword = trim((string) $atts['keyword']);
                        if ($keyword !== '') {
                            $items = $this->custom_products->search_products_fuzzy($keyword, $remaining);
                        }
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
        
        // Produkte ohne Bild ausfiltern
        $combined = $this->filter_items_without_image($combined, $atts);
        
        if (empty($combined)) {
            return $this->render_empty();
        }
        
        if ((string) $atts['shuffle'] === 'yes') {
            shuffle($combined);
        }
        
        return $this->render_grid($combined, $atts);
    }
    
    /**
     * Produkte ohne Bild ausfiltern
     * 
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $atts
     * @return array<int, array<string, mixed>>
     */
    private function filter_items_without_image(array $items, array $atts): array {
        $hide_no_image = ($atts['hide_no_image'] ?? 'no') === 'yes';
        
        if (!$hide_no_image) {
            return $items;
        }
        
        $filtered = array_filter($items, function($item) {
            $image_url = $item['image']['url'] ?? '';
            return !empty($image_url);
        });
        
        return array_values($filtered);
    }
    
    /**
     * Optionales Einmischen von Fuzzy-Produkten
     * 
     * @param array<int, array<string, mixed>> $items Bestehende Produkte
     * @param array<string> $keywords Suchbegriffe
     * @param array<string, mixed> $atts Shortcode Attribute
     * @return array<int, array<string, mixed>>
     */
    private function maybe_mix_fuzzy_products(array $items, array $keywords, array $atts): array {
        // Prüfen ob Mixing aktiviert ist
        $mix_custom = $atts['mix_custom'] ?? '';
        
        // Global oder per Shortcode aktiviert?
        $should_mix = ($mix_custom === 'yes') || 
                      ($mix_custom === '' && $this->fuzzy_enabled && yaa_get_option('fuzzy_auto_mix', 'no') === 'yes');
        
        if (!$should_mix || empty($keywords)) {
            return $items;
        }
        
        $custom_limit = (int) ($atts['custom_limit'] ?? 2);
        $position = $atts['custom_position'] ?? 'start';
        
        // Fuzzy-Suche für jeden Keyword
        $fuzzy_items = [];
        $per_keyword = (int) ceil($custom_limit / count($keywords));
        
        foreach ($keywords as $keyword) {
            $results = $this->custom_products->search_products_fuzzy($keyword, $per_keyword);
            $fuzzy_items = array_merge($fuzzy_items, $results);
        }
        
        // Duplikate entfernen
        $unique_fuzzy = [];
        $seen_ids = [];
        foreach ($fuzzy_items as $item) {
            $pid = $item['post_id'] ?? 0;
            if (!in_array($pid, $seen_ids, true)) {
                $unique_fuzzy[] = $item;
                $seen_ids[] = $pid;
            }
        }
        
        // Auf Limit beschränken
        $unique_fuzzy = array_slice($unique_fuzzy, 0, $custom_limit);
        
        if (empty($unique_fuzzy)) {
            return $items;
        }
        
        // Position bestimmen
        switch ($position) {
            case 'start':
                $items = array_merge($unique_fuzzy, $items);
                break;
            case 'end':
                $items = array_merge($items, $unique_fuzzy);
                break;
            case 'shuffle':
                $items = array_merge($items, $unique_fuzzy);
                shuffle($items);
                break;
            case 'alternate':
                // Abwechselnd einfügen
                $result = [];
                $fuzzy_index = 0;
                $interval = max(1, (int) floor(count($items) / (count($unique_fuzzy) + 1)));
                
                foreach ($items as $i => $item) {
                    if ($fuzzy_index < count($unique_fuzzy) && $i > 0 && $i % $interval === 0) {
                        $result[] = $unique_fuzzy[$fuzzy_index];
                        $fuzzy_index++;
                    }
                    $result[] = $item;
                }
                
                // Restliche Fuzzy-Items am Ende
                while ($fuzzy_index < count($unique_fuzzy)) {
                    $result[] = $unique_fuzzy[$fuzzy_index];
                    $fuzzy_index++;
                }
                
                $items = $result;
                break;
        }
        
        return $items;
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
        $base_per_keyword = (int) floor($total_limit / $keyword_count);
        $remainder = $total_limit % $keyword_count;
        
        $all_items = [];
        $items_per_keyword = [];
        
        foreach ($keywords as $index => $keyword) {
            $limit_for_this = $base_per_keyword;
            
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
                'precision' => (string) ($atts['precision'] ?? yaa_get_option('yadore_precision', 'fuzzy')),
            ]);
            
            if (!is_wp_error($result) && !empty($result)) {
                $items_per_keyword[$keyword] = $result;
            }
            
            if ($index < $keyword_count - 1) {
                usleep(200000);
            }
        }
        
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
     * Process image URL - Mit SEO-Dateinamen Support (NEU in 1.2.9)
     * 
     * @param string $remote_url Remote image URL
     * @param string $unique_id Unique identifier (ASIN, EAN, post_id)
     * @param string $source Source (amazon, yadore, custom)
     * @param string $product_name Product name for SEO filename (NEU)
     * @param array<string, mixed> $atts Shortcode attributes
     * @return string Local or remote URL
     */
    private function process_image_url(
        string $remote_url, 
        string $unique_id, 
        string $source, 
        string $product_name = '',
        array $atts = []
    ): string {
        if ($remote_url === '') {
            return '';
        }
        
        $local_images_override = $atts['local_images'] ?? '';
        
        $use_local = $this->use_local_images;
        
        if ($local_images_override === 'yes') {
            $use_local = true;
        } elseif ($local_images_override === 'no') {
            $use_local = false;
        }
        
        // Eigene Produkte: Lokale Bilder nicht erneut herunterladen
        if ($source === 'custom') {
            $upload_dir = wp_upload_dir();
            $site_url = get_site_url();
            
            if (str_starts_with($remote_url, $upload_dir['baseurl']) || str_starts_with($remote_url, $site_url)) {
                return $remote_url;
            }
        }
        
        if (!$use_local) {
            return $remote_url;
        }
        
        // Nutze den verbesserten Image Handler mit SEO-Dateinamen
        // Parameter: remote_url, id, product_name, source
        return YAA_Image_Handler::process($remote_url, $unique_id, $product_name, $source);
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
        $show_custom_badge = yaa_get_option('show_custom_badge', 'yes') === 'yes';
        $custom_badge_text = (string) yaa_get_option('custom_badge_text', 'Empfohlen');
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
                $title = $item['title'] ?? '';
                
                // Bild-Verarbeitung mit Produktname für SEO-Dateinamen (NEU)
                $raw_image_url = $item['image']['url'] ?? '';
                
                $image_unique_id = match($source) {
                    'amazon' => $item['asin'] ?? $item['id'] ?? uniqid('amz_'),
                    'custom' => (string) ($item['post_id'] ?? $item['id'] ?? uniqid('cst_')),
                    'yadore' => $item['id'] ?? uniqid('ydr_'),
                    default  => $item['id'] ?? uniqid('img_'),
                };
                
                // NEU: Produktname für SEO-Dateinamen übergeben
                $image_url = $this->process_image_url(
                    $raw_image_url, 
                    $image_unique_id, 
                    $source, 
                    $title,  // Produktname für SEO
                    $atts
                );
                
                $description = $item['description'] ?? '';
                $price_amount = $item['price']['amount'] ?? '';
                $price_currency = $item['price']['currency'] ?? 'EUR';
                $price_display = $item['price']['display'] ?? '';
                $merchant_name = $item['merchant']['name'] ?? '';
                $is_prime = !empty($item['is_prime']);
                
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
                
                $source_class = 'yaa-' . $source;
            ?>
                <div class="yaa-item <?php echo esc_attr($source_class); ?>">
                    
                    <?php if ($show_prime && $is_prime): ?>
                        <span class="yaa-prime-badge">Prime</span>
                    <?php endif; ?>
                    
                    <?php if ($is_custom && $show_custom_badge): ?>
                        <span class="yaa-custom-badge"><?php echo esc_html($custom_badge_text); ?></span>
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
                                <button type="button" class="yaa-read-more" 
                                        data-target="<?php echo esc_attr($widget_id . '-' . $index); ?>"
                                        data-expand-text="<?php esc_attr_e('mehr lesen', 'yadore-amazon-api'); ?>"
                                        data-collapse-text="<?php esc_attr_e('weniger', 'yadore-amazon-api'); ?>"
                                        aria-expanded="false"
                                        role="button"
                                        tabindex="0">
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
                                        echo esc_html(number_format((float) $price_amount, 2, ',', '.') . ' ' . $price_currency);
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

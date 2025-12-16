<?php
/**
 * Yadore API Handler
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class YAA_Yadore_API {

    private YAA_Cache_Handler $cache;
    private const API_ENDPOINT = 'https://api.yadore.com/v1/search';

    public function __construct(YAA_Cache_Handler $cache) {
        $this->cache = $cache;
    }

    public function is_configured(): bool {
        $key = defined('YADORE_API_KEY') ? YADORE_API_KEY : yaa_get_option('yadore_api_key');
        return !empty($key);
    }

    public function get_markets(): array {
        return [
            'de' => 'Deutschland',
            'at' => 'Österreich',
            'ch' => 'Schweiz',
            'uk' => 'Vereinigtes Königreich',
            'us' => 'USA',
            'fr' => 'Frankreich',
            'nl' => 'Niederlande',
            'es' => 'Spanien',
            'it' => 'Italien',
        ];
    }

    /**
     * Fetch products from Yadore
     */
    public function fetch(array $args): array {
        if (!$this->is_configured()) {
            return [];
        }

        $keyword = $args['keyword'] ?? '';
        $limit = $args['limit'] ?? 10;
        $market = $args['market'] ?? 'de';
        $api_key = defined('YADORE_API_KEY') ? YADORE_API_KEY : yaa_get_option('yadore_api_key');
        
        $cache_key = 'yadore_' . md5($keyword . $market . $limit);
        
        // Check cache
        $cached = $this->cache->get($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $params = [
            'api_key' => $api_key,
            'q' => $keyword,
            'limit' => $limit,
            'market' => $market,
            'format' => 'json'
        ];

        // Precision setting
        if (yaa_get_option('yadore_precision') === 'strict') {
            $params['match'] = 'all'; 
        }

        $url = add_query_arg($params, self::API_ENDPOINT);
        
        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $products = [];
        
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                // Determine unique ID
                $ean = $item['ean'] ?? $item['id'] ?? md5($item['url']);
                $remote_image_url = $item['image_url'] ?? null;

                // --- IMAGE PROCESSING ---
                // Download image locally or get placeholder
                $local_image_url = YAA_Image_Handler::process($remote_image_url, (string)$ean);

                $products[] = [
                    'source' => 'yadore',
                    'id' => $ean,
                    'title' => $item['title'] ?? 'Kein Titel',
                    'image' => $local_image_url, // Use local URL
                    'price' => isset($item['price']) ? number_format((float)$item['price'], 2, ',', '.') . ' €' : '',
                    'url' => $item['url'] ?? '#',
                    'merchant' => $item['shop_name'] ?? '',
                    'description' => $item['description'] ?? '',
                    'is_prime' => false
                ];
            }
        }

        // Cache result
        if (!empty($products)) {
            $this->cache->set($cache_key, $products, yaa_get_cache_time());
            $this->cache->track_keyword($keyword, 'yadore', 'All', $limit, $market);
        }

        return $products;
    }

    /**
     * Test connection
     */
    public function test_connection(?string $api_key = null): array {
        $key = $api_key ?: (defined('YADORE_API_KEY') ? YADORE_API_KEY : yaa_get_option('yadore_api_key'));
        
        if (empty($key)) {
            return ['success' => false, 'message' => 'API-Key fehlt.'];
        }

        $url = add_query_arg([
            'api_key' => $key,
            'q' => 'test',
            'limit' => 1,
            'market' => 'de',
            'format' => 'json'
        ], self::API_ENDPOINT);

        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            return ['success' => true, 'message' => 'Verbindung erfolgreich!'];
        }

        return ['success' => false, 'message' => 'API Fehler Code: ' . $code];
    }
}
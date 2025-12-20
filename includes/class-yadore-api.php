<?php
/**
 * Yadore API Handler
 * PHP 8.3+ compatible
 * Version: 1.3.0
 * 
 * NEU: Merchant Filter (Whitelist/Blacklist)
 * NEU: /v2/merchant API Integration
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Yadore_API {
    
    private const API_URL = 'https://api.yadore.com/v2/offer';
    private const MERCHANT_API_URL = 'https://api.yadore.com/v2/merchant';
    
    private YAA_Cache_Handler $cache;
    private string $api_key = '';
    
    /**
     * Verfügbare Märkte
     */
    private const MARKETS = [
        'de' => 'Deutschland',
        'at' => 'Österreich',
        'ch' => 'Schweiz',
        'fr' => 'Frankreich',
        'it' => 'Italien',
        'es' => 'Spanien',
        'nl' => 'Niederlande',
        'be' => 'Belgien',
        'pl' => 'Polen',
        'uk' => 'Großbritannien',
        'us' => 'USA',
        'br' => 'Brasilien',
    ];
    
    public function __construct(YAA_Cache_Handler $cache) {
        $this->cache = $cache;
        $this->load_api_key();
    }
    
    /**
     * Load API key from config or options
     */
    private function load_api_key(): void {
        $this->api_key = defined('YADORE_API_KEY') 
            ? (string) YADORE_API_KEY 
            : (string) yaa_get_option('yadore_api_key', '');
    }
    
    /**
     * Reload API key (nach Einstellungsänderung)
     */
    public function reload_api_key(): void {
        $this->load_api_key();
    }
    
    /**
     * Check if API is configured
     */
    public function is_configured(): bool {
        return $this->api_key !== '' && yaa_get_option('enable_yadore', 'yes') === 'yes';
    }
    
    /**
     * Get API key (für Merchant Filter)
     */
    public function get_api_key(): string {
        return $this->api_key;
    }
    
    /**
     * Get available markets
     * 
     * @return array<string, string>
     */
    public function get_markets(): array {
        return self::MARKETS;
    }
    
    /**
     * Fetch products from Yadore API
     * 
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function fetch(array $params): array|\WP_Error {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', __('Yadore API nicht konfiguriert.', 'yadore-amazon-api'));
        }
        
        $keyword = trim($params['keyword'] ?? '');
        $limit = (int) ($params['limit'] ?? 9);
        $market = $params['market'] ?? yaa_get_option('yadore_market', 'de');
        $precision = $params['precision'] ?? yaa_get_option('yadore_precision', 'fuzzy');
        $ean = $params['ean'] ?? '';
        $merchant_id = $params['merchant_id'] ?? '';
        
        // NEU: Filter-Konfiguration aus Parametern extrahieren
        $filter_config = [
            'whitelist' => $params['merchant_whitelist'] ?? '',
            'blacklist' => $params['merchant_blacklist'] ?? '',
        ];
        
        // Build cache key (inkl. Filter)
        $filter_hash = md5(serialize($filter_config));
        $cache_key = 'yadore_' . md5($keyword . $ean . $market . $precision . $limit . $merchant_id . $filter_hash);
        
        // Check cache
        $cached = $this->cache->get($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        
        // Build query params
        $query_params = [
            'market'    => $market,
            'precision' => $precision,
            'limit'     => min(100, max(1, $limit + 20)), // Extra laden für Filter
        ];
        
        if ($keyword !== '') {
            $query_params['keyword'] = $keyword;
        }
        
        if ($ean !== '') {
            $query_params['ean'] = $ean;
        }
        
        if ($merchant_id !== '') {
            $query_params['merchantId'] = $merchant_id;
        }
        
        // Make API request
        $url = self::API_URL . '?' . http_build_query($query_params);
        
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'API-Key' => $this->api_key,
                'Accept'  => 'application/json',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code !== 200) {
            $error_message = $data['errors']['market'][0] 
                ?? $data['errors']['keyword'][0] 
                ?? 'HTTP Error ' . $status_code;
            return new \WP_Error('api_error', $error_message);
        }
        
        if (!is_array($data)) {
            return new \WP_Error('invalid_response', __('Ungültige API-Antwort.', 'yadore-amazon-api'));
        }
        
        // Normalize products
        $products = $this->normalize_response($data);
        
        // NEU: Händlerfilter anwenden
        $products = $this->apply_merchant_filter($products, $filter_config, $params);
        
        // Auf gewünschtes Limit kürzen
        $products = array_slice($products, 0, $limit);
        
        // Cache results
        $this->cache->set($cache_key, $products, yaa_get_cache_time());
        
        // Track keyword
        $this->track_keyword($keyword, $market, $limit);
        
        return $products;
    }
    
    /**
     * Wendet den Händlerfilter auf Produkte an
     * 
     * @param array<int, array<string, mixed>> $products
     * @param array<string, mixed> $filter_config Shortcode-Filter
     * @param array<string, mixed> $params Original-Parameter
     * @return array<int, array<string, mixed>>
     */
    private function apply_merchant_filter(array $products, array $filter_config, array $params): array {
        // Filter-Konfiguration erstellen (Shortcode hat Vorrang)
        $shortcode_whitelist = $filter_config['whitelist'] ?? '';
        $shortcode_blacklist = $filter_config['blacklist'] ?? '';
        
        // Wenn Shortcode-Parameter leer sind, globale Einstellungen laden
        if ($shortcode_whitelist === '' && $shortcode_blacklist === '') {
            $final_config = [
                'whitelist' => YAA_Merchant_Filter::normalize_merchant_list(
                    yaa_get_option('yadore_merchant_whitelist', '')
                ),
                'blacklist' => YAA_Merchant_Filter::normalize_merchant_list(
                    yaa_get_option('yadore_merchant_blacklist', '')
                ),
            ];
        } else {
            // Shortcode-Parameter verwenden
            $final_config = [
                'whitelist' => YAA_Merchant_Filter::normalize_merchant_list($shortcode_whitelist),
                'blacklist' => YAA_Merchant_Filter::normalize_merchant_list($shortcode_blacklist),
            ];
        }
        
        return YAA_Merchant_Filter::filter_products($products, $final_config);
    }
    
    /**
     * Holt die Händlerliste von der API
     * 
     * @param string $market Markt
     * @param bool $force_refresh Cache ignorieren
     * @return array{success: bool, merchants: array, error?: string}
     */
    public function fetch_merchants(string $market = 'de', bool $force_refresh = false): array {
        return YAA_Merchant_Filter::fetch_merchants($this->api_key, $market, $force_refresh);
    }
    
    /**
     * Normalize API response to standard format
     * 
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function normalize_response(array $data): array {
        $products = [];
        $offers = $data['offers'] ?? [];
        
        foreach ($offers as $offer) {
            $products[] = [
                'id'          => $offer['id'] ?? uniqid('yadore_'),
                'title'       => $offer['title'] ?? '',
                'description' => $offer['description'] ?? '',
                'url'         => $offer['clickUrl'] ?? '',
                'image'       => [
                    'url' => $offer['image']['url'] ?? '',
                ],
                'price'       => [
                    'amount'   => $offer['price']['amount'] ?? '',
                    'currency' => $offer['price']['currency'] ?? 'EUR',
                    'display'  => '',
                ],
                'merchant'    => [
                    'id'   => $offer['merchant']['id'] ?? '',
                    'name' => $offer['merchant']['name'] ?? '',
                    'logo' => $offer['merchant']['logo']['url'] ?? '',
                ],
                'source'       => 'yadore',
                'availability' => $offer['availability'] ?? 'UNKNOWN',
                'is_prime'     => false,
                'ean'          => $offer['ean'] ?? '',
                'brand'        => $offer['brand'] ?? '',
            ];
        }
        
        return $products;
    }
    
    /**
     * Track keyword for cron preload
     */
    private function track_keyword(string $keyword, string $market, int $limit): void {
        if ($keyword === '') {
            return;
        }
        
        $cached_keywords = get_option('yaa_cached_keywords', []);
        
        if (!is_array($cached_keywords)) {
            $cached_keywords = [];
        }
        
        $new_entry = [
            'keyword' => $keyword,
            'market'  => $market,
            'limit'   => $limit,
            'source'  => 'yadore',
        ];
        
        // Check for duplicates
        foreach ($cached_keywords as $entry) {
            if (($entry['keyword'] ?? '') === $keyword 
                && ($entry['source'] ?? '') === 'yadore'
                && ($entry['market'] ?? 'de') === $market) {
                return;
            }
        }
        
        $cached_keywords[] = $new_entry;
        $cached_keywords = array_slice($cached_keywords, -30);
        update_option('yaa_cached_keywords', $cached_keywords);
    }
    
    /**
     * Test API connection
     * 
     * @return array{success: bool, message: string}
     */
    public function test_connection(?string $api_key = null): array {
        $key = $api_key ?? $this->api_key;
        
        if ($key === '') {
            return [
                'success' => false,
                'message' => __('Kein API-Key konfiguriert.', 'yadore-amazon-api'),
            ];
        }
        
        $url = self::API_URL . '?' . http_build_query([
            'market'  => 'de',
            'keyword' => 'test',
            'limit'   => 1,
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'API-Key' => $key,
                'Accept'  => 'application/json',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200) {
            return [
                'success' => true,
                'message' => __('Verbindung erfolgreich!', 'yadore-amazon-api'),
            ];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $error = $data['errors']['market'][0] ?? 'HTTP Error ' . $status_code;
        
        return [
            'success' => false,
            'message' => $error,
        ];
    }
}

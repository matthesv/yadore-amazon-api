<?php
/**
 * Yadore API Handler
 * PHP 8.3+ compatible
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Yadore_API {
    
    private const API_BASE = 'https://api.yadore.com/v2/offer';
    
    private YAA_Cache_Handler $cache;
    
    // Yadore supported markets
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
        'se' => 'Schweden',
        'dk' => 'Dänemark',
        'no' => 'Norwegen',
        'fi' => 'Finnland',
    ];
    
    public function __construct(YAA_Cache_Handler $cache) {
        $this->cache = $cache;
    }
    
    /**
     * Check if API is configured
     */
    public function is_configured(): bool {
        return $this->get_api_key() !== '' && yaa_get_option('enable_yadore', 'yes') === 'yes';
    }
    
    /**
     * Get API Key
     */
    private function get_api_key(): string {
        if (defined('YADORE_API_KEY')) {
            return (string) YADORE_API_KEY;
        }
        return (string) yaa_get_option('yadore_api_key', '');
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
     * Fetch offers from Yadore API
     * 
     * @param array<string, mixed> $atts
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function fetch(array $atts): array|\WP_Error {
        $api_key = $this->get_api_key();
        
        if ($api_key === '') {
            return new \WP_Error('no_api_key', __('Yadore API-Key nicht konfiguriert.', 'yadore-amazon-api'));
        }
        
        // Merge with defaults
        $defaults = [
            'keyword'   => '',
            'limit'     => (int) yaa_get_option('yadore_default_limit', 9),
            'market'    => (string) yaa_get_option('yadore_market', 'de'),
            'precision' => (string) yaa_get_option('yadore_precision', 'fuzzy'),
            'sort'      => 'rel_desc',
        ];
        
        $atts = wp_parse_args($atts, $defaults);
        $atts['limit'] = (int) $atts['limit'];
        
        $keyword = trim((string) $atts['keyword']);
        
        if ($keyword === '') {
            return new \WP_Error('no_keyword', __('Kein Suchbegriff angegeben.', 'yadore-amazon-api'));
        }
        
        $cache_key = $this->generate_cache_key($atts);
        $fallback_key = $cache_key . '_fallback';
        
        // Try cache first
        $cached_data = $this->cache->get($cache_key);
        if ($cached_data !== false && is_array($cached_data)) {
            return $cached_data;
        }
        
        // Fetch from API
        $url = add_query_arg([
            'market'    => $atts['market'],
            'keyword'   => urlencode($keyword),
            'limit'     => $atts['limit'],
            'sort'      => $atts['sort'],
            'precision' => $atts['precision'],
        ], self::API_BASE);

        $response = wp_remote_get($url, [
            'headers'   => ['API-Key' => $api_key],
            'timeout'   => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            error_log('Yadore API Error: ' . $response->get_error_message());
            return $this->get_fallback($fallback_key);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('Yadore API HTTP Error: ' . $status_code);
            return $this->get_fallback($fallback_key);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || !isset($data['offers']) || empty($data['offers'])) {
            return $this->get_fallback($fallback_key);
        }

        // Normalize data
        $offers = $this->normalize_offers($data['offers']);
        
        // Cache results
        $this->cache->set($cache_key, $offers, yaa_get_cache_time());
        $this->cache->set($fallback_key, $offers, yaa_get_fallback_time());
        
        // Track keyword
        $this->track_keyword($atts);
        
        return $offers;
    }
    
    /**
     * Normalize offers to standard format
     * 
     * @param array<int, array<string, mixed>> $offers
     * @return array<int, array<string, mixed>>
     */
    private function normalize_offers(array $offers): array {
        $normalized = [];
        
        foreach ($offers as $offer) {
            $normalized[] = [
                'id'          => $offer['id'] ?? uniqid('yadore_'),
                'title'       => $offer['title'] ?? '',
                'description' => $offer['description'] ?? '',
                'url'         => $offer['clickUrl'] ?? '#',
                'image'       => [
                    'url' => $offer['image']['url'] ?? '',
                ],
                'price'       => [
                    'amount'   => $offer['price']['amount'] ?? '',
                    'currency' => $offer['price']['currency'] ?? 'EUR',
                    'display'  => '',
                ],
                'merchant'    => [
                    'name' => $offer['merchant']['name'] ?? '',
                    'logo' => $offer['merchant']['logo'] ?? '',
                ],
                'source'      => 'yadore',
                'is_prime'    => false,
            ];
        }
        
        return $normalized;
    }
    
    /**
     * Generate cache key
     * 
     * @param array<string, mixed> $atts
     */
    private function generate_cache_key(array $atts): string {
        return 'yadore_' . md5(serialize($atts));
    }
    
    /**
     * Get fallback cache
     * 
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    private function get_fallback(string $fallback_key): array|\WP_Error {
        $fallback = $this->cache->get($fallback_key);
        if ($fallback !== false && is_array($fallback)) {
            return $fallback;
        }
        return new \WP_Error('no_data', __('Keine Daten verfügbar.', 'yadore-amazon-api'));
    }
    
    /**
     * Track keywords for cron preload
     * 
     * @param array<string, mixed> $atts
     */
    private function track_keyword(array $atts): void {
        $cached_keywords = get_option('yaa_cached_keywords', []);
        
        if (!is_array($cached_keywords)) {
            $cached_keywords = [];
        }
        
        $new_entry = [
            'keyword' => $atts['keyword'],
            'limit'   => $atts['limit'],
            'market'  => $atts['market'],
            'source'  => 'yadore',
        ];
        
        foreach ($cached_keywords as $entry) {
            if (($entry['keyword'] ?? '') === $new_entry['keyword'] 
                && ($entry['market'] ?? '') === $new_entry['market']
                && ($entry['source'] ?? 'yadore') === 'yadore') {
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
        $api_key ??= $this->get_api_key();
        
        if ($api_key === '') {
            return [
                'success' => false,
                'message' => __('Kein API-Key angegeben.', 'yadore-amazon-api'),
            ];
        }
        
        $url = add_query_arg([
            'market'  => 'de',
            'keyword' => 'test',
            'limit'   => 1,
        ], self::API_BASE);

        $response = wp_remote_get($url, [
            'headers' => ['API-Key' => $api_key],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        
        return match ($status_code) {
            200 => ['success' => true, 'message' => __('Verbindung erfolgreich!', 'yadore-amazon-api')],
            401 => ['success' => false, 'message' => __('Ungültiger API-Key.', 'yadore-amazon-api')],
            default => ['success' => false, 'message' => sprintf(__('HTTP Fehler: %d', 'yadore-amazon-api'), $status_code)],
        };
    }
}

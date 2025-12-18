<?php
/**
 * Amazon Product Advertising API 5.0 Handler
 * PHP 8.3+ compatible with ALL marketplaces
 * Version: 1.2.7
 * 
 * @see https://webservices.amazon.com/paapi5/documentation/
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Amazon_PAAPI {
    
    private YAA_Cache_Handler $cache;
    
    private string $access_key = '';
    private string $secret_key = '';
    private string $partner_tag = '';
    private string $host = '';
    private string $region = '';
    private string $marketplace = 'de';
    
    /**
     * All Amazon PA-API 5.0 Marketplaces
     * @see https://webservices.amazon.com/paapi5/documentation/locale-reference.html
     */
    private const ENDPOINTS = [
        // Europe
        'de' => [
            'host'        => 'webservices.amazon.de',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.de',
            'name'        => 'Amazon.de (Deutschland)',
            'currency'    => 'EUR',
            'language'    => 'de_DE',
            'languages'   => ['cs_CZ', 'de_DE', 'en_GB', 'nl_NL', 'pl_PL', 'tr_TR'],
        ],
        'fr' => [
            'host'        => 'webservices.amazon.fr',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.fr',
            'name'        => 'Amazon.fr (Frankreich)',
            'currency'    => 'EUR',
            'language'    => 'fr_FR',
            'languages'   => ['de_DE', 'en_GB', 'fr_FR'],
        ],
        'it' => [
            'host'        => 'webservices.amazon.it',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.it',
            'name'        => 'Amazon.it (Italien)',
            'currency'    => 'EUR',
            'language'    => 'it_IT',
            'languages'   => ['de_DE', 'en_GB', 'it_IT'],
        ],
        'es' => [
            'host'        => 'webservices.amazon.es',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.es',
            'name'        => 'Amazon.es (Spanien)',
            'currency'    => 'EUR',
            'language'    => 'es_ES',
            'languages'   => ['de_DE', 'en_GB', 'es_ES'],
        ],
        'co.uk' => [
            'host'        => 'webservices.amazon.co.uk',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.co.uk',
            'name'        => 'Amazon.co.uk (Großbritannien)',
            'currency'    => 'GBP',
            'language'    => 'en_GB',
            'languages'   => ['de_DE', 'en_GB'],
        ],
        'nl' => [
            'host'        => 'webservices.amazon.nl',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.nl',
            'name'        => 'Amazon.nl (Niederlande)',
            'currency'    => 'EUR',
            'language'    => 'nl_NL',
            'languages'   => ['de_DE', 'en_GB', 'nl_NL'],
        ],
        'pl' => [
            'host'        => 'webservices.amazon.pl',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.pl',
            'name'        => 'Amazon.pl (Polen)',
            'currency'    => 'PLN',
            'language'    => 'pl_PL',
            'languages'   => ['de_DE', 'en_GB', 'pl_PL'],
        ],
        'se' => [
            'host'        => 'webservices.amazon.se',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.se',
            'name'        => 'Amazon.se (Schweden)',
            'currency'    => 'SEK',
            'language'    => 'sv_SE',
            'languages'   => ['de_DE', 'en_GB', 'sv_SE'],
        ],
        'be' => [
            'host'        => 'webservices.amazon.com.be',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.com.be',
            'name'        => 'Amazon.com.be (Belgien)',
            'currency'    => 'EUR',
            'language'    => 'fr_BE',
            'languages'   => ['de_DE', 'en_GB', 'fr_BE', 'nl_BE'],
        ],
        'com.tr' => [
            'host'        => 'webservices.amazon.com.tr',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.com.tr',
            'name'        => 'Amazon.com.tr (Türkei)',
            'currency'    => 'TRY',
            'language'    => 'tr_TR',
            'languages'   => ['en_GB', 'tr_TR'],
        ],
        
        // Middle East & Africa
        'ae' => [
            'host'        => 'webservices.amazon.ae',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.ae',
            'name'        => 'Amazon.ae (VAE)',
            'currency'    => 'AED',
            'language'    => 'en_AE',
            'languages'   => ['ar_AE', 'en_AE'],
        ],
        'sa' => [
            'host'        => 'webservices.amazon.sa',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.sa',
            'name'        => 'Amazon.sa (Saudi-Arabien)',
            'currency'    => 'SAR',
            'language'    => 'en_AE',
            'languages'   => ['ar_AE', 'en_AE'],
        ],
        'eg' => [
            'host'        => 'webservices.amazon.eg',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.eg',
            'name'        => 'Amazon.eg (Ägypten)',
            'currency'    => 'EGP',
            'language'    => 'en_AE',
            'languages'   => ['ar_AE', 'en_AE'],
        ],
        
        // North America
        'com' => [
            'host'        => 'webservices.amazon.com',
            'region'      => 'us-east-1',
            'marketplace' => 'www.amazon.com',
            'name'        => 'Amazon.com (USA)',
            'currency'    => 'USD',
            'language'    => 'en_US',
            'languages'   => ['de_DE', 'en_US', 'es_US', 'ko_KR', 'pt_BR', 'zh_CN', 'zh_TW'],
        ],
        'ca' => [
            'host'        => 'webservices.amazon.ca',
            'region'      => 'us-east-1',
            'marketplace' => 'www.amazon.ca',
            'name'        => 'Amazon.ca (Kanada)',
            'currency'    => 'CAD',
            'language'    => 'en_CA',
            'languages'   => ['en_CA', 'fr_CA'],
        ],
        'com.mx' => [
            'host'        => 'webservices.amazon.com.mx',
            'region'      => 'us-east-1',
            'marketplace' => 'www.amazon.com.mx',
            'name'        => 'Amazon.com.mx (Mexiko)',
            'currency'    => 'MXN',
            'language'    => 'es_MX',
            'languages'   => ['en_US', 'es_MX'],
        ],
        'com.br' => [
            'host'        => 'webservices.amazon.com.br',
            'region'      => 'us-east-1',
            'marketplace' => 'www.amazon.com.br',
            'name'        => 'Amazon.com.br (Brasilien)',
            'currency'    => 'BRL',
            'language'    => 'pt_BR',
            'languages'   => ['en_US', 'pt_BR'],
        ],
        
        // Asia Pacific
        'co.jp' => [
            'host'        => 'webservices.amazon.co.jp',
            'region'      => 'us-west-2',
            'marketplace' => 'www.amazon.co.jp',
            'name'        => 'Amazon.co.jp (Japan)',
            'currency'    => 'JPY',
            'language'    => 'ja_JP',
            'languages'   => ['en_US', 'ja_JP', 'zh_CN'],
        ],
        'in' => [
            'host'        => 'webservices.amazon.in',
            'region'      => 'eu-west-1',
            'marketplace' => 'www.amazon.in',
            'name'        => 'Amazon.in (Indien)',
            'currency'    => 'INR',
            'language'    => 'en_IN',
            'languages'   => ['en_IN', 'hi_IN', 'kn_IN', 'ml_IN', 'ta_IN', 'te_IN'],
        ],
        'com.au' => [
            'host'        => 'webservices.amazon.com.au',
            'region'      => 'us-west-2',
            'marketplace' => 'www.amazon.com.au',
            'name'        => 'Amazon.com.au (Australien)',
            'currency'    => 'AUD',
            'language'    => 'en_AU',
            'languages'   => ['en_AU'],
        ],
        'sg' => [
            'host'        => 'webservices.amazon.sg',
            'region'      => 'us-west-2',
            'marketplace' => 'www.amazon.sg',
            'name'        => 'Amazon.sg (Singapur)',
            'currency'    => 'SGD',
            'language'    => 'en_SG',
            'languages'   => ['en_SG', 'zh_CN'],
        ],
    ];
    
    /**
     * Search indexes for DE marketplace
     */
    private const SEARCH_INDEXES_DE = [
        'All'                     => 'Alle Kategorien',
        'AmazonVideo'             => 'Prime Video',
        'Apparel'                 => 'Bekleidung',
        'Appliances'              => 'Elektro-Großgeräte',
        'Automotive'              => 'Auto & Motorrad',
        'Baby'                    => 'Baby',
        'Beauty'                  => 'Beauty',
        'Books'                   => 'Bücher',
        'Classical'               => 'Klassik',
        'Computers'               => 'Computer & Zubehör',
        'DigitalMusic'            => 'Musik-Downloads',
        'Electronics'             => 'Elektronik & Foto',
        'EverythingElse'          => 'Sonstiges',
        'Fashion'                 => 'Fashion',
        'ForeignBooks'            => 'Bücher (Fremdsprachig)',
        'GardenAndOutdoor'        => 'Garten',
        'GiftCards'               => 'Geschenkgutscheine',
        'GroceryAndGourmetFood'   => 'Lebensmittel & Getränke',
        'Handmade'                => 'Handmade',
        'HealthPersonalCare'      => 'Drogerie & Körperpflege',
        'HomeAndKitchen'          => 'Küche, Haushalt & Wohnen',
        'Industrial'              => 'Gewerbe, Industrie & Wissenschaft',
        'Jewelry'                 => 'Schmuck',
        'KindleStore'             => 'Kindle-Shop',
        'Lighting'                => 'Beleuchtung',
        'Luggage'                 => 'Koffer, Rucksäcke & Taschen',
        'LuxuryBeauty'            => 'Luxury Beauty',
        'Magazines'               => 'Zeitschriften',
        'MobileApps'              => 'Apps & Spiele',
        'MoviesAndTV'             => 'DVD & Blu-ray',
        'Music'                   => 'Musik-CDs & Vinyl',
        'MusicalInstruments'      => 'Musikinstrumente & DJ-Equipment',
        'OfficeProducts'          => 'Bürobedarf & Schreibwaren',
        'PetSupplies'             => 'Haustier',
        'Photo'                   => 'Kamera & Foto',
        'Shoes'                   => 'Schuhe & Handtaschen',
        'Software'                => 'Software',
        'SportsAndOutdoors'       => 'Sport & Freizeit',
        'ToolsAndHomeImprovement' => 'Baumarkt',
        'ToysAndGames'            => 'Spielzeug',
        'VHS'                     => 'VHS',
        'VideoGames'              => 'Games',
        'Watches'                 => 'Uhren',
    ];
    
    /**
     * Generic search indexes
     */
    private const SEARCH_INDEXES_GENERIC = [
        'All', 'Apparel', 'Appliances', 'Automotive', 'Baby', 'Beauty', 'Books',
        'Computers', 'Electronics', 'Fashion', 'GardenAndOutdoor', 'GiftCards',
        'GroceryAndGourmetFood', 'HealthPersonalCare', 'HomeAndKitchen', 'Industrial',
        'Jewelry', 'KindleStore', 'Luggage', 'MobileApps', 'MoviesAndTV', 'Music',
        'MusicalInstruments', 'OfficeProducts', 'PetSupplies', 'Shoes', 'Software',
        'SportsAndOutdoors', 'ToolsAndHomeImprovement', 'ToysAndGames', 'VideoGames', 'Watches',
    ];
    
    public function __construct(YAA_Cache_Handler $cache) {
        $this->cache = $cache;
        $this->load_credentials();
    }
    
    /**
     * Load API credentials
     */
    private function load_credentials(): void {
        $this->access_key = defined('AMAZON_PAAPI_ACCESS_KEY') 
            ? (string) AMAZON_PAAPI_ACCESS_KEY 
            : (string) yaa_get_option('amazon_access_key', '');
            
        $this->secret_key = defined('AMAZON_PAAPI_SECRET_KEY') 
            ? (string) AMAZON_PAAPI_SECRET_KEY 
            : (string) yaa_get_option('amazon_secret_key', '');
            
        $this->partner_tag = defined('AMAZON_PAAPI_PARTNER_TAG') 
            ? (string) AMAZON_PAAPI_PARTNER_TAG 
            : (string) yaa_get_option('amazon_partner_tag', '');
            
        $this->marketplace = (string) yaa_get_option('amazon_marketplace', 'de');
        
        $this->set_marketplace($this->marketplace);
    }
    
    /**
     * Set marketplace configuration
     */
    private function set_marketplace(string $marketplace): void {
        if (isset(self::ENDPOINTS[$marketplace])) {
            $this->host = self::ENDPOINTS[$marketplace]['host'];
            $this->region = self::ENDPOINTS[$marketplace]['region'];
            $this->marketplace = $marketplace;
        } else {
            $this->host = self::ENDPOINTS['de']['host'];
            $this->region = self::ENDPOINTS['de']['region'];
            $this->marketplace = 'de';
        }
    }
    
    /**
     * Reload credentials
     */
    public function reload_credentials(): void {
        $this->load_credentials();
    }
    
    /**
     * Check if API is configured
     */
    public function is_configured(): bool {
        return $this->access_key !== '' 
            && $this->secret_key !== '' 
            && $this->partner_tag !== ''
            && yaa_get_option('enable_amazon', 'yes') === 'yes';
    }
    
    /**
     * Get Partner Tag
     */
    public function get_partner_tag(): string {
        return $this->partner_tag;
    }
    
    /**
     * Get current marketplace info
     * 
     * @return array<string, mixed>|null
     */
    public function get_current_marketplace(): ?array {
        return self::ENDPOINTS[$this->marketplace] ?? null;
    }
    
    /**
     * Get all available marketplaces
     * 
     * @return array<string, array<string, mixed>>
     */
    public function get_marketplaces(): array {
        return self::ENDPOINTS;
    }
    
    /**
     * Get marketplaces for select dropdown
     * 
     * @return array<string, string>
     */
    public function get_marketplace_options(): array {
        $options = [];
        foreach (self::ENDPOINTS as $code => $data) {
            $options[$code] = $data['name'];
        }
        return $options;
    }
    
    /**
     * Get languages for current marketplace
     * 
     * @return array<string>
     */
    public function get_available_languages(): array {
        return self::ENDPOINTS[$this->marketplace]['languages'] ?? ['en_US'];
    }
    
    /**
     * Get search indexes for current marketplace
     * 
     * @return array<string, string>
     */
    public function get_search_indexes(): array {
        if ($this->marketplace === 'de') {
            return self::SEARCH_INDEXES_DE;
        }
        
        $indexes = [];
        foreach (self::SEARCH_INDEXES_GENERIC as $index) {
            $indexes[$index] = $index;
        }
        return $indexes;
    }
    
    /**
     * Get search indexes as simple list
     * 
     * @return array<string>
     */
    public function get_search_index_list(): array {
        return array_keys($this->get_search_indexes());
    }
    
    /**
     * Search products
     * 
     * @param array<string, mixed> $additional_params
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function search_items(
        string $keyword, 
        string $category = 'All', 
        int $item_count = 10,
        array $additional_params = []
    ): array|\WP_Error {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', __('Amazon PA-API nicht konfiguriert.', 'yadore-amazon-api'));
        }
        
        $keyword = trim($keyword);
        if ($keyword === '') {
            return new \WP_Error('no_keyword', __('Kein Suchbegriff angegeben.', 'yadore-amazon-api'));
        }
        
        // Check cache
        $cache_key = 'amazon_search_' . md5($keyword . $category . $item_count . serialize($additional_params) . $this->marketplace);
        $cached = $this->cache->get($cache_key);
        
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        
        // Build payload - Request ALL image sizes for fallback
        $payload = [
            'Keywords'    => $keyword,
            'SearchIndex' => $category,
            'ItemCount'   => min(max(1, $item_count), 10),
            'PartnerTag'  => $this->partner_tag,
            'PartnerType' => 'Associates',
            'Resources'   => [
                // Request all image sizes for fallback chain
                'Images.Primary.Large',
                'Images.Primary.Medium',
                'Images.Primary.Small',
                'Images.Variants.Large',
                'Images.Variants.Medium',
                'Images.Variants.Small',
                'ItemInfo.Title',
                'ItemInfo.Features',
                'ItemInfo.ProductInfo',
                'ItemInfo.ByLineInfo',
                'Offers.Listings.Price',
                'Offers.Listings.DeliveryInfo.IsPrimeEligible',
                'Offers.Listings.Condition',
                'Offers.Listings.MerchantInfo',
            ],
        ];
        
        // Add optional language
        $language = (string) yaa_get_option('amazon_language', '');
        if ($language !== '' && in_array($language, $this->get_available_languages(), true)) {
            $payload['LanguagesOfPreference'] = [$language];
        }
        
        // Add optional parameters
        if (!empty($additional_params['min_price'])) {
            $payload['MinPrice'] = (int) ($additional_params['min_price'] * 100);
        }
        if (!empty($additional_params['max_price'])) {
            $payload['MaxPrice'] = (int) ($additional_params['max_price'] * 100);
        }
        if (!empty($additional_params['brand'])) {
            $payload['Brand'] = (string) $additional_params['brand'];
        }
        if (!empty($additional_params['sort_by'])) {
            $payload['SortBy'] = (string) $additional_params['sort_by'];
        }
        
        $response = $this->send_request('SearchItems', $payload);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $items = $this->parse_search_response($response);
        
        // Cache results
        $this->cache->set($cache_key, $items, yaa_get_cache_time());
        
        // Track for preload
        $this->track_keyword($keyword, $category, $item_count);
        
        return $items;
    }
    
    /**
     * Get items by ASIN
     * 
     * @param string|array<string> $asins
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public function get_items(string|array $asins): array|\WP_Error {
        if (!$this->is_configured()) {
            return new \WP_Error('not_configured', __('Amazon PA-API nicht konfiguriert.', 'yadore-amazon-api'));
        }
        
        if (is_string($asins)) {
            $asins = [$asins];
        }
        
        $asins = array_slice(array_filter($asins), 0, 10);
        
        if (empty($asins)) {
            return new \WP_Error('no_asins', __('Keine ASINs angegeben.', 'yadore-amazon-api'));
        }
        
        // Check cache
        $cache_key = 'amazon_items_' . md5(implode(',', $asins) . $this->marketplace);
        $cached = $this->cache->get($cache_key);
        
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        
        $payload = [
            'ItemIds'     => $asins,
            'PartnerTag'  => $this->partner_tag,
            'PartnerType' => 'Associates',
            'Resources'   => [
                'Images.Primary.Large',
                'Images.Primary.Medium',
                'Images.Primary.Small',
                'Images.Variants.Large',
                'Images.Variants.Medium',
                'ItemInfo.Title',
                'ItemInfo.Features',
                'ItemInfo.ProductInfo',
                'ItemInfo.ByLineInfo',
                'Offers.Listings.Price',
                'Offers.Listings.DeliveryInfo.IsPrimeEligible',
                'Offers.Listings.MerchantInfo',
            ],
        ];
        
        $response = $this->send_request('GetItems', $payload);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $items = $this->parse_items_response($response);
        
        // Cache results
        $this->cache->set($cache_key, $items, yaa_get_cache_time());
        
        return $items;
    }
    
    /**
     * Send request to Amazon PA-API
     * 
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|\WP_Error
     */
    private function send_request(string $operation, array $payload): array|\WP_Error {
        $path = '/paapi5/' . strtolower($operation);
        $service = 'ProductAdvertisingAPI';
        
        $payload_json = wp_json_encode($payload);
        
        if ($payload_json === false) {
            return new \WP_Error('json_error', __('JSON Encoding fehlgeschlagen.', 'yadore-amazon-api'));
        }
        
        // Create signature
        $headers = $this->create_signed_headers('POST', $path, $payload_json, $service, $operation);
        
        $url = 'https://' . $this->host . $path;
        
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => $payload_json,
            'timeout' => 15,
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            $data = [];
        }
        
        if ($status_code !== 200) {
            $error_message = $data['Errors'][0]['Message'] ?? 'HTTP Error ' . $status_code;
            $error_code = $data['Errors'][0]['Code'] ?? 'unknown_error';
            
            error_log('Amazon PA-API Error: ' . $error_code . ' - ' . $error_message);
            return new \WP_Error($error_code, $error_message);
        }
        
        return $data;
    }
    
    /**
     * Create AWS Signature v4 headers
     * 
     * @return array<string, string>
     */
    private function create_signed_headers(
        string $method, 
        string $path, 
        string $payload, 
        string $service, 
        string $operation
    ): array {
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        
        $content_type = 'application/json; charset=utf-8';
        $amz_target = 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.' . $operation;
        
        $canonical_headers = 
            'content-encoding:amz-1.0' . "\n" .
            'content-type:' . $content_type . "\n" .
            'host:' . $this->host . "\n" .
            'x-amz-date:' . $timestamp . "\n" .
            'x-amz-target:' . $amz_target . "\n";
        
        $signed_headers = 'content-encoding;content-type;host;x-amz-date;x-amz-target';
        
        $payload_hash = hash('sha256', $payload);
        $canonical_request = implode("\n", [
            $method,
            $path,
            '',
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);
        
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = $date . '/' . $this->region . '/' . $service . '/aws4_request';
        $string_to_sign = implode("\n", [
            $algorithm,
            $timestamp,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);
        
        $signing_key = $this->get_signature_key($date, $service);
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);
        
        $authorization = $algorithm . ' ' .
            'Credential=' . $this->access_key . '/' . $credential_scope . ', ' .
            'SignedHeaders=' . $signed_headers . ', ' .
            'Signature=' . $signature;
        
        return [
            'Content-Type'     => $content_type,
            'Content-Encoding' => 'amz-1.0',
            'Host'             => $this->host,
            'X-Amz-Date'       => $timestamp,
            'X-Amz-Target'     => $amz_target,
            'Authorization'    => $authorization,
        ];
    }
    
    /**
     * Get AWS4 signing key
     */
    private function get_signature_key(string $date, string $service): string {
        $k_date    = hash_hmac('sha256', $date, 'AWS4' . $this->secret_key, true);
        $k_region  = hash_hmac('sha256', $this->region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        
        return $k_signing;
    }
    
    /**
     * Parse search response
     * 
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function parse_search_response(array $response): array {
        $items = [];
        
        $search_items = $response['SearchResult']['Items'] ?? [];
        
        foreach ($search_items as $item) {
            $items[] = $this->normalize_item($item);
        }
        
        return $items;
    }
    
    /**
     * Parse items response
     * 
     * @param array<string, mixed> $response
     * @return array<int, array<string, mixed>>
     */
    private function parse_items_response(array $response): array {
        $items = [];
        
        $result_items = $response['ItemsResult']['Items'] ?? [];
        
        foreach ($result_items as $item) {
            $items[] = $this->normalize_item($item);
        }
        
        return $items;
    }
    
    /**
     * Normalize item to standard format
     * INCLUDES: Fallback image chain (Large → Medium → Small → Variants)
     * 
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalize_item(array $item): array {
        $marketplace_info = self::ENDPOINTS[$this->marketplace] ?? self::ENDPOINTS['de'];
        
        // === FALLBACK-BILDERKETTE ===
        // Versuche verschiedene Bildgrößen in Reihenfolge
        $image_url = '';
        $image_width = 0;
        $image_height = 0;
        
        // 1. Primary Large
        if (!empty($item['Images']['Primary']['Large']['URL'])) {
            $image_url = $item['Images']['Primary']['Large']['URL'];
            $image_width = $item['Images']['Primary']['Large']['Width'] ?? 0;
            $image_height = $item['Images']['Primary']['Large']['Height'] ?? 0;
        }
        // 2. Primary Medium
        elseif (!empty($item['Images']['Primary']['Medium']['URL'])) {
            $image_url = $item['Images']['Primary']['Medium']['URL'];
            $image_width = $item['Images']['Primary']['Medium']['Width'] ?? 0;
            $image_height = $item['Images']['Primary']['Medium']['Height'] ?? 0;
        }
        // 3. Primary Small
        elseif (!empty($item['Images']['Primary']['Small']['URL'])) {
            $image_url = $item['Images']['Primary']['Small']['URL'];
            $image_width = $item['Images']['Primary']['Small']['Width'] ?? 0;
            $image_height = $item['Images']['Primary']['Small']['Height'] ?? 0;
        }
        // 4. First Variant Large
        elseif (!empty($item['Images']['Variants'][0]['Large']['URL'])) {
            $image_url = $item['Images']['Variants'][0]['Large']['URL'];
            $image_width = $item['Images']['Variants'][0]['Large']['Width'] ?? 0;
            $image_height = $item['Images']['Variants'][0]['Large']['Height'] ?? 0;
        }
        // 5. First Variant Medium
        elseif (!empty($item['Images']['Variants'][0]['Medium']['URL'])) {
            $image_url = $item['Images']['Variants'][0]['Medium']['URL'];
            $image_width = $item['Images']['Variants'][0]['Medium']['Width'] ?? 0;
            $image_height = $item['Images']['Variants'][0]['Medium']['Height'] ?? 0;
        }
        // 6. First Variant Small
        elseif (!empty($item['Images']['Variants'][0]['Small']['URL'])) {
            $image_url = $item['Images']['Variants'][0]['Small']['URL'];
            $image_width = $item['Images']['Variants'][0]['Small']['Width'] ?? 0;
            $image_height = $item['Images']['Variants'][0]['Small']['Height'] ?? 0;
        }
        
        $normalized = [
            'id'          => $item['ASIN'] ?? uniqid('amazon_'),
            'asin'        => $item['ASIN'] ?? '',
            'title'       => $item['ItemInfo']['Title']['DisplayValue'] ?? '',
            'description' => '',
            'url'         => $item['DetailPageURL'] ?? '',
            'image'       => [
                'url'    => $image_url,
                'width'  => $image_width,
                'height' => $image_height,
            ],
            'price'       => [
                'amount'   => '',
                'currency' => $marketplace_info['currency'],
                'display'  => '',
            ],
            'merchant'    => [
                'name' => 'Amazon',
                'logo' => '',
            ],
            'source'      => 'amazon',
            'is_prime'    => false,
            'partner_tag' => $this->partner_tag,
            'marketplace' => $this->marketplace,
        ];
        
        // Extract price
        $listing = $item['Offers']['Listings'][0] ?? null;
        if ($listing !== null && isset($listing['Price'])) {
            $normalized['price']['amount'] = (string) ($listing['Price']['Amount'] ?? '');
            $normalized['price']['currency'] = $listing['Price']['Currency'] ?? $marketplace_info['currency'];
            $normalized['price']['display'] = $listing['Price']['DisplayAmount'] ?? '';
        }
        
        // Extract features as description
        $features = $item['ItemInfo']['Features']['DisplayValues'] ?? [];
        if (!empty($features)) {
            $normalized['description'] = implode(' • ', array_slice($features, 0, 3));
        }
        
        // Prime eligibility
        if (isset($listing['DeliveryInfo']['IsPrimeEligible'])) {
            $normalized['is_prime'] = (bool) $listing['DeliveryInfo']['IsPrimeEligible'];
        }
        
        // Merchant info
        if (isset($listing['MerchantInfo']['Name'])) {
            $normalized['merchant']['name'] = $listing['MerchantInfo']['Name'];
        }
        
        // Brand
        if (isset($item['ItemInfo']['ByLineInfo']['Brand']['DisplayValue'])) {
            $normalized['brand'] = $item['ItemInfo']['ByLineInfo']['Brand']['DisplayValue'];
        }
        
        return $normalized;
    }
    
    /**
     * Track keyword for cron preload
     */
    private function track_keyword(string $keyword, string $category, int $limit): void {
        $cached_keywords = get_option('yaa_cached_keywords', []);
        
        if (!is_array($cached_keywords)) {
            $cached_keywords = [];
        }
        
        $new_entry = [
            'keyword'     => $keyword,
            'category'    => $category,
            'limit'       => $limit,
            'source'      => 'amazon',
            'marketplace' => $this->marketplace,
        ];
        
        foreach ($cached_keywords as $entry) {
            if (($entry['keyword'] ?? '') === $keyword 
                && ($entry['source'] ?? '') === 'amazon'
                && ($entry['category'] ?? 'All') === $category
                && ($entry['marketplace'] ?? 'de') === $this->marketplace) {
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
     * @return array{success: bool, message: string, code?: string}
     */
    public function test_connection(
        ?string $access_key = null, 
        ?string $secret_key = null, 
        ?string $partner_tag = null, 
        ?string $marketplace = null
    ): array {
        // Temporarily override credentials for test
        $orig_access = $this->access_key;
        $orig_secret = $this->secret_key;
        $orig_tag = $this->partner_tag;
        $orig_host = $this->host;
        $orig_region = $this->region;
        $orig_marketplace = $this->marketplace;
        
        if ($access_key !== null) {
            $this->access_key = $access_key;
        }
        if ($secret_key !== null) {
            $this->secret_key = $secret_key;
        }
        if ($partner_tag !== null) {
            $this->partner_tag = $partner_tag;
        }
        if ($marketplace !== null) {
            $this->set_marketplace($marketplace);
        }
        
        // Validation
        if ($this->access_key === '' || $this->secret_key === '' || $this->partner_tag === '') {
            // Restore
            $this->access_key = $orig_access;
            $this->secret_key = $orig_secret;
            $this->partner_tag = $orig_tag;
            $this->host = $orig_host;
            $this->region = $orig_region;
            $this->marketplace = $orig_marketplace;
            
            return [
                'success' => false,
                'message' => __('Bitte alle Felder ausfüllen.', 'yadore-amazon-api'),
            ];
        }
        
        // Simple search test
        $payload = [
            'Keywords'    => 'test',
            'SearchIndex' => 'All',
            'ItemCount'   => 1,
            'PartnerTag'  => $this->partner_tag,
            'PartnerType' => 'Associates',
            'Resources'   => ['ItemInfo.Title'],
        ];
        
        $result = $this->send_request('SearchItems', $payload);
        
        // Restore original credentials
        $this->access_key = $orig_access;
        $this->secret_key = $orig_secret;
        $this->partner_tag = $orig_tag;
        $this->host = $orig_host;
        $this->region = $orig_region;
        $this->marketplace = $orig_marketplace;
        
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ];
        }
        
        return [
            'success' => true,
            'message' => __('Verbindung erfolgreich!', 'yadore-amazon-api'),
        ];
    }
}

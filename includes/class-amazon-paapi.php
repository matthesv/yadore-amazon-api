<?php
/**
 * Amazon PA-API 5.0 Handler
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class YAA_Amazon_PAAPI {
    
    private YAA_Cache_Handler $cache;
    
    public function __construct(YAA_Cache_Handler $cache) {
        $this->cache = $cache;
    }
    
    public function is_configured(): bool {
        return !empty($this->get_credentials()['access_key']);
    }

    /**
     * Get credentials from settings or constants
     */
    private function get_credentials(): array {
        return [
            'access_key' => defined('AMAZON_PAAPI_ACCESS_KEY') ? AMAZON_PAAPI_ACCESS_KEY : yaa_get_option('amazon_access_key'),
            'secret_key' => defined('AMAZON_PAAPI_SECRET_KEY') ? AMAZON_PAAPI_SECRET_KEY : yaa_get_option('amazon_secret_key'),
            'partner_tag' => defined('AMAZON_PAAPI_PARTNER_TAG') ? AMAZON_PAAPI_PARTNER_TAG : yaa_get_option('amazon_partner_tag'),
            'marketplace' => yaa_get_option('amazon_marketplace', 'de'),
        ];
    }
    
    /**
     * Search Items via API
     */
    public function search_items(string $keyword, string $category = 'All', int $limit = 10): array {
        if (!$this->is_configured()) {
            return [];
        }

        $creds = $this->get_credentials();
        $cache_key = 'amazon_' . md5($keyword . $category . $limit . $creds['marketplace']);
        
        // Try cache first
        $cached = $this->cache->get($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Prepare API Request
        $host = $this->get_marketplaces()[$creds['marketplace']]['host'] ?? 'webservices.amazon.de';
        $region = $this->get_marketplaces()[$creds['marketplace']]['region'] ?? 'eu-central-1';
        $uri_path = '/paapi5/searchitems';
        
        $payload = [
            'Keywords' => $keyword,
            'Resources' => [
                'ItemInfo.Title',
                'ItemInfo.Features',
                'Images.Primary.Large',
                'Offers.Listings.Price',
                'Offers.Listings.DeliveryInfo.IsPrime',
                'ItemInfo.ByLineInfo', // Merchant/Brand
            ],
            'PartnerTag' => $creds['partner_tag'],
            'PartnerType' => 'Associates',
            'Marketplace' => 'www.' . str_replace('webservices.', '', $host),
            'ItemCount' => min(10, $limit) // API max is 10
        ];

        if ($category !== 'All') {
            $payload['SearchIndex'] = $category;
        }

        $response = $this->make_request($host, $region, 'SearchItems', $payload, $creds);

        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $products = [];

        if (!empty($data['SearchResult']['Items'])) {
            foreach ($data['SearchResult']['Items'] as $item) {
                $asin = $item['ASIN'] ?? '';
                $title = $item['ItemInfo']['Title']['DisplayValue'] ?? '';
                $remote_image_url = $item['Images']['Primary']['Large']['URL'] ?? null;
                
                // --- IMAGE PROCESSING ---
                // Download image locally or get placeholder
                $local_image_url = YAA_Image_Handler::process($remote_image_url, $asin);

                $price = $item['Offers']['Listings'][0]['Price']['DisplayAmount'] ?? 'N/A';
                $url = $item['DetailPageURL'] ?? '#';
                $is_prime = $item['Offers']['Listings'][0]['DeliveryInfo']['IsPrime'] ?? false;
                $merchant = $item['ItemInfo']['ByLineInfo']['Brand']['DisplayValue'] ?? 
                           ($item['ItemInfo']['ByLineInfo']['Manufacturer']['DisplayValue'] ?? '');

                $features = [];
                if (!empty($item['ItemInfo']['Features']['DisplayValues'])) {
                    $features = array_slice($item['ItemInfo']['Features']['DisplayValues'], 0, 3);
                }
                $description = implode(' ', $features);

                $products[] = [
                    'source' => 'amazon',
                    'id' => $asin,
                    'title' => $title,
                    'image' => $local_image_url, // Use local URL
                    'price' => $price,
                    'url' => $url,
                    'is_prime' => $is_prime,
                    'merchant' => $merchant,
                    'description' => $description
                ];
            }
        }

        // Cache result (including local image paths)
        if (!empty($products)) {
            $this->cache->set($cache_key, $products, yaa_get_cache_time());
            
            // Add keyword to tracking for Cron
            $this->cache->track_keyword($keyword, 'amazon', $category, $limit);
        }

        return $products;
    }
    
    /**
     * Test connection for Admin
     */
    public function test_connection(?string $access = null, ?string $secret = null, ?string $tag = null, string $market = 'de'): array {
        $creds = $this->get_credentials();
        // Override if provided (for testing unsaved settings)
        if ($access) $creds['access_key'] = $access;
        if ($secret) $creds['secret_key'] = $secret;
        if ($tag) $creds['partner_tag'] = $tag;
        $creds['marketplace'] = $market;

        if (empty($creds['access_key']) || empty($creds['secret_key']) || empty($creds['partner_tag'])) {
            return ['success' => false, 'message' => 'Credentials unvollständig.'];
        }

        $host = $this->get_marketplaces()[$market]['host'] ?? 'webservices.amazon.de';
        $region = $this->get_marketplaces()[$market]['region'] ?? 'eu-central-1';
        
        $payload = [
            'Keywords' => 'Kindle',
            'Resources' => ['ItemInfo.Title'],
            'PartnerTag' => $creds['partner_tag'],
            'PartnerType' => 'Associates',
            'Marketplace' => 'www.' . str_replace('webservices.', '', $host),
            'ItemCount' => 1
        ];

        $response = $this->make_request($host, $region, 'SearchItems', $payload, $creds);
        
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && isset($body['SearchResult'])) {
            return ['success' => true, 'message' => 'Verbindung erfolgreich!'];
        }

        $error_msg = $body['Errors'][0]['Message'] ?? 'Unbekannter API Fehler (' . $code . ')';
        return ['success' => false, 'message' => $error_msg];
    }

    private function make_request($host, $region, $target, $payload, $creds) {
        $uri_path = '/paapi5/searchitems';
        $service = 'ProductAdvertisingAPI';
        $timestamp = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');

        $payload_json = json_encode($payload);
        $headers = [
            'content-encoding' => 'amz-1.0',
            'content-type' => 'application/json; charset=utf-8',
            'host' => $host,
            'x-amz-date' => $timestamp,
            'x-amz-target' => "com.amazon.paapi5.v1.ProductAdvertisingAPIv1.$target"
        ];

        // AWS Signature V4 Logic
        $kSecret = 'AWS4' . $creds['secret_key'];
        $kDate = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $canonical_headers = "content-encoding:amz-1.0\ncontent-type:application/json; charset=utf-8\nhost:$host\nx-amz-date:$timestamp\nx-amz-target:com.amazon.paapi5.v1.ProductAdvertisingAPIv1.$target\n";
        $signed_headers = 'content-encoding;content-type;host;x-amz-date;x-amz-target';
        $payload_hash = hash('sha256', $payload_json);
        
        $canonical_request = "POST\n$uri_path\n\n$canonical_headers\n$signed_headers\n$payload_hash";
        $string_to_sign = "AWS4-HMAC-SHA256\n$timestamp\n$date/$region/$service/aws4_request\n" . hash('sha256', $canonical_request);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);
        $authorization = "AWS4-HMAC-SHA256 Credential={$creds['access_key']}/$date/$region/$service/aws4_request, SignedHeaders=$signed_headers, Signature=$signature";

        $headers['Authorization'] = $authorization;

        return wp_remote_post("https://$host$uri_path", [
            'headers' => $headers,
            'body' => $payload_json,
            'timeout' => 20
        ]);
    }

    public function get_marketplaces(): array {
        return [
            'de' => ['host' => 'webservices.amazon.de', 'region' => 'eu-central-1', 'currency' => 'EUR', 'language' => 'German'],
            'fr' => ['host' => 'webservices.amazon.fr', 'region' => 'eu-west-1', 'currency' => 'EUR', 'language' => 'French'],
            'it' => ['host' => 'webservices.amazon.it', 'region' => 'eu-south-1', 'currency' => 'EUR', 'language' => 'Italian'],
            'es' => ['host' => 'webservices.amazon.es', 'region' => 'eu-south-1', 'currency' => 'EUR', 'language' => 'Spanish'],
            'nl' => ['host' => 'webservices.amazon.nl', 'region' => 'eu-central-1', 'currency' => 'EUR', 'language' => 'Dutch'],
            'co.uk' => ['host' => 'webservices.amazon.co.uk', 'region' => 'eu-west-1', 'currency' => 'GBP', 'language' => 'English'],
            'se' => ['host' => 'webservices.amazon.se', 'region' => 'eu-north-1', 'currency' => 'SEK', 'language' => 'Swedish'],
            'pl' => ['host' => 'webservices.amazon.pl', 'region' => 'eu-central-1', 'currency' => 'PLN', 'language' => 'Polish'],
            'com' => ['host' => 'webservices.amazon.com', 'region' => 'us-east-1', 'currency' => 'USD', 'language' => 'English'],
        ];
    }

    public function get_marketplace_options(): array {
        $opts = [];
        foreach ($this->get_marketplaces() as $key => $data) {
            $opts[$key] = "Amazon " . strtoupper($key);
        }
        return $opts;
    }

    public function get_search_indexes(): array {
        return [
            'All' => 'Alle Kategorien',
            'Electronics' => 'Elektronik & Foto',
            'Computers' => 'Computer & Zubehör',
            'HomeAndKitchen' => 'Küche, Haushalt & Wohnen',
            'GardenAndOutdoor' => 'Garten',
            'ToysAndGames' => 'Spielzeug',
            'Books' => 'Bücher',
            'Fashion' => 'Bekleidung'
        ];
    }
}
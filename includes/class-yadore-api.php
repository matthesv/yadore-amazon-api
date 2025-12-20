<?php
/**
 * Yadore API Handler
 * PHP 8.3+ compatible
 * Version: 1.4.0
 * 
 * Features:
 * - Offer API (/v2/offer)
 * - Merchant API (/v2/merchant)
 * - NEU: Deeplink Merchant API (/v2/deeplink/merchant) mit CPC-Daten
 * - Merchant Filter (Whitelist/Blacklist)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Yadore_API {
    
    private const API_URL = 'https://api.yadore.com/v2/offer';
    private const MERCHANT_API_URL = 'https://api.yadore.com/v2/merchant';
    private const DEEPLINK_MERCHANT_API_URL = 'https://api.yadore.com/v2/deeplink/merchant';
    
    private YAA_Cache_Handler $cache;
    private string $api_key = '';
    
    /**
     * Verfügbare Märkte
     * @var array<string, string>
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
        'se' => 'Schweden',
        'uk' => 'Großbritannien',
        'us' => 'USA',
        'br' => 'Brasilien',
        'mx' => 'Mexiko',
        'au' => 'Australien',
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
        
        // Filter-Konfiguration aus Parametern extrahieren
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
        
        // Händlerfilter anwenden
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
     * Holt die Händlerliste von der Offer-API (/v2/merchant)
     * Diese Händler haben Produkt-Angebote
     * 
     * @param string $market Markt
     * @param bool $force_refresh Cache ignorieren
     * @return array{success: bool, merchants: array<int, array<string, mixed>>, error?: string, from_cache?: bool}
     */
    public function fetch_merchants(string $market = 'de', bool $force_refresh = false): array {
        return YAA_Merchant_Filter::fetch_merchants($this->api_key, $market, $force_refresh);
    }
    
    /**
     * Holt Deeplink-Händler von der API (/v2/deeplink/merchant)
     * Diese Händler haben CPC-Daten!
     * 
     * @param string $market Markt (z.B. 'de', 'at', 'ch')
     * @param bool $smartlink_only Nur Smartlink-Händler zurückgeben
     * @param bool $force_refresh Cache ignorieren
     * @return array{success: bool, merchants: array<int, array<string, mixed>>, error?: string, total?: int, from_cache?: bool}
     */
    public function fetch_deeplink_merchants(
        string $market = 'de', 
        bool $smartlink_only = false,
        bool $force_refresh = false
    ): array {
        if ($this->api_key === '') {
            return [
                'success' => false,
                'merchants' => [],
                'error' => __('Kein API-Key konfiguriert.', 'yadore-amazon-api'),
            ];
        }
        
        // Cache-Key erstellen
        $cache_key = 'yadore_deeplink_merchants_' . $market . ($smartlink_only ? '_smartlink' : '_all');
        
        // Cache prüfen
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                return [
                    'success' => true,
                    'merchants' => $cached,
                    'total' => count($cached),
                    'from_cache' => true,
                ];
            }
        }
        
        // Query-Parameter aufbauen
        $query_params = ['market' => $market];
        
        if ($smartlink_only) {
            $query_params['isSmartlink'] = '1';
        }
        
        // API Request
        $url = self::DEEPLINK_MERCHANT_API_URL . '?' . http_build_query($query_params);
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'API-Key' => $this->api_key,
                'Accept'  => 'application/json',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'merchants' => [],
                'error' => $response->get_error_message(),
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code !== 200 || !is_array($data)) {
            $error_message = 'HTTP Error ' . $status_code;
            
            if (is_array($data) && isset($data['errors'])) {
                $first_error = reset($data['errors']);
                if (is_array($first_error)) {
                    $error_message = $first_error[0] ?? $error_message;
                }
            }
            
            return [
                'success' => false,
                'merchants' => [],
                'error' => sprintf(__('API Fehler: %s', 'yadore-amazon-api'), $error_message),
            ];
        }
        
        // Händler extrahieren und normalisieren
        $merchants = $data['merchants'] ?? [];
        
        // Händler mit CPC-Daten anreichern
        $normalized_merchants = [];
        
        foreach ($merchants as $merchant) {
            $normalized = [
                'id'                    => $merchant['id'] ?? '',
                'name'                  => $merchant['name'] ?? '',
                'isSmartlink'           => (bool) ($merchant['isSmartlink'] ?? false),
                'hasSmartlinkHomepage'  => (bool) ($merchant['hasSmartlinkHomepage'] ?? false),
                'hasExternalHomepage'   => (bool) ($merchant['hasExternalHomepage'] ?? false),
                'deeplinkCount'         => (int) ($merchant['deeplinkCount'] ?? 0),
                'logo'                  => [
                    'url'    => $merchant['logo']['url'] ?? '',
                    'exists' => (bool) ($merchant['logo']['exists'] ?? false),
                ],
                'trafficTypes'          => $merchant['trafficTypes'] ?? [],
                // CPC-Daten
                'estimatedCpc'          => [
                    'amount'   => $merchant['estimatedCpc']['amount'] ?? null,
                    'currency' => $merchant['estimatedCpc']['currency'] ?? 'EUR',
                ],
            ];
            
            $normalized_merchants[] = $normalized;
        }
        
        // Nach Name sortieren
        usort($normalized_merchants, fn($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
        
        // Cache speichern (4 Stunden)
        set_transient($cache_key, $normalized_merchants, 4 * HOUR_IN_SECONDS);
        
        // Auch in Option speichern für Offline-Nutzung in Admin
        update_option('yaa_yadore_deeplink_merchants_' . $market, $normalized_merchants, false);
        update_option('yaa_yadore_deeplink_merchants_updated_' . $market, time(), false);
        
        return [
            'success' => true,
            'merchants' => $normalized_merchants,
            'total' => $data['total'] ?? count($normalized_merchants),
            'from_cache' => false,
        ];
    }
    
    /**
     * Holt gespeicherte Deeplink-Händler aus der Datenbank
     * 
     * @param string $market Markt
     * @return array<int, array<string, mixed>>
     */
    public function get_stored_deeplink_merchants(string $market = 'de'): array {
        $merchants = get_option('yaa_yadore_deeplink_merchants_' . $market, []);
        return is_array($merchants) ? $merchants : [];
    }
    
    /**
     * Gibt das letzte Update-Datum der Deeplink-Händler zurück
     * 
     * @param string $market Markt
     * @return int|null Unix Timestamp oder null
     */
    public function get_deeplink_merchants_last_update(string $market = 'de'): ?int {
        $timestamp = get_option('yaa_yadore_deeplink_merchants_updated_' . $market, null);
        return $timestamp !== null ? (int) $timestamp : null;
    }
    
    /**
     * Holt CPC für einen bestimmten Händler
     * 
     * @param string $merchant_id Händler-ID (64 Zeichen Hex)
     * @param string $market Markt
     * @return array{amount: float|null, currency: string}
     */
    public function get_merchant_cpc(string $merchant_id, string $market = 'de'): array {
        $merchants = $this->get_stored_deeplink_merchants($market);
        
        foreach ($merchants as $merchant) {
            if (($merchant['id'] ?? '') === $merchant_id) {
                $amount = $merchant['estimatedCpc']['amount'] ?? null;
                
                return [
                    'amount'   => $amount !== null ? (float) $amount : null,
                    'currency' => $merchant['estimatedCpc']['currency'] ?? 'EUR',
                ];
            }
        }
        
        return [
            'amount'   => null,
            'currency' => 'EUR',
        ];
    }
    
    /**
     * Holt CPC für einen Händler nach Namen
     * 
     * @param string $merchant_name Händlername
     * @param string $market Markt
     * @return array{amount: float|null, currency: string, merchant_id: string|null}
     */
    public function get_merchant_cpc_by_name(string $merchant_name, string $market = 'de'): array {
        $merchants = $this->get_stored_deeplink_merchants($market);
        $merchant_name_lower = mb_strtolower($merchant_name, 'UTF-8');
        
        foreach ($merchants as $merchant) {
            $name = mb_strtolower($merchant['name'] ?? '', 'UTF-8');
            
            // Exakter Match oder enthält
            if ($name === $merchant_name_lower || str_contains($name, $merchant_name_lower)) {
                $amount = $merchant['estimatedCpc']['amount'] ?? null;
                
                return [
                    'amount'      => $amount !== null ? (float) $amount : null,
                    'currency'    => $merchant['estimatedCpc']['currency'] ?? 'EUR',
                    'merchant_id' => $merchant['id'] ?? null,
                ];
            }
        }
        
        return [
            'amount'      => null,
            'currency'    => 'EUR',
            'merchant_id' => null,
        ];
    }
    
    /**
     * Holt alle Händler mit CPC sortiert nach CPC (absteigend)
     * 
     * @param string $market Markt
     * @param int $limit Max. Anzahl (0 = alle)
     * @return array<int, array{id: string, name: string, cpc_amount: float, cpc_currency: string, is_smartlink: bool}>
     */
    public function get_merchants_by_cpc(string $market = 'de', int $limit = 0): array {
        $merchants = $this->get_stored_deeplink_merchants($market);
        
        // Nur Händler mit CPC
        $with_cpc = array_filter($merchants, function($m) {
            $amount = $m['estimatedCpc']['amount'] ?? null;
            return $amount !== null && (float) $amount > 0;
        });
        
        // Nach CPC sortieren (höchster zuerst)
        usort($with_cpc, function($a, $b) {
            $cpc_a = (float) ($a['estimatedCpc']['amount'] ?? 0);
            $cpc_b = (float) ($b['estimatedCpc']['amount'] ?? 0);
            return $cpc_b <=> $cpc_a;
        });
        
        // Limit anwenden
        if ($limit > 0) {
            $with_cpc = array_slice($with_cpc, 0, $limit);
        }
        
        // Vereinfachtes Format zurückgeben
        $result = [];
        foreach ($with_cpc as $merchant) {
            $result[] = [
                'id'           => $merchant['id'] ?? '',
                'name'         => $merchant['name'] ?? '',
                'cpc_amount'   => (float) ($merchant['estimatedCpc']['amount'] ?? 0),
                'cpc_currency' => $merchant['estimatedCpc']['currency'] ?? 'EUR',
                'is_smartlink' => (bool) ($merchant['isSmartlink'] ?? false),
            ];
        }
        
        return $result;
    }
    
    /**
     * Berechnet Statistiken über CPC-Daten
     * 
     * @param string $market Markt
     * @return array{total: int, with_cpc: int, avg_cpc: float, min_cpc: float, max_cpc: float, currency: string}
     */
    public function get_cpc_statistics(string $market = 'de'): array {
        $merchants = $this->get_stored_deeplink_merchants($market);
        
        $stats = [
            'total'    => count($merchants),
            'with_cpc' => 0,
            'avg_cpc'  => 0.0,
            'min_cpc'  => 0.0,
            'max_cpc'  => 0.0,
            'currency' => 'EUR',
        ];
        
        $cpc_values = [];
        
        foreach ($merchants as $merchant) {
            $amount = $merchant['estimatedCpc']['amount'] ?? null;
            
            if ($amount !== null && (float) $amount > 0) {
                $cpc_values[] = (float) $amount;
                $stats['currency'] = $merchant['estimatedCpc']['currency'] ?? 'EUR';
            }
        }
        
        if (!empty($cpc_values)) {
            $stats['with_cpc'] = count($cpc_values);
            $stats['avg_cpc'] = round(array_sum($cpc_values) / count($cpc_values), 4);
            $stats['min_cpc'] = min($cpc_values);
            $stats['max_cpc'] = max($cpc_values);
        }
        
        return $stats;
    }
    
    /**
     * Kombiniert Offer-Händler und Deeplink-Händler
     * Gibt eine einheitliche Liste mit allen verfügbaren Daten zurück
     * 
     * @param string $market Markt
     * @return array<int, array<string, mixed>>
     */
    public function get_all_merchants_combined(string $market = 'de'): array {
        // Offer-Händler laden
        $offer_merchants = YAA_Merchant_Filter::get_stored_merchants($market);
        
        // Deeplink-Händler laden
        $deeplink_merchants = $this->get_stored_deeplink_merchants($market);
        
        // Index für Deeplink-Händler erstellen (nach ID)
        $deeplink_index = [];
        foreach ($deeplink_merchants as $dm) {
            $deeplink_index[$dm['id'] ?? ''] = $dm;
        }
        
        // Kombinierte Liste erstellen
        $combined = [];
        $seen_ids = [];
        
        // Zuerst Offer-Händler verarbeiten
        foreach ($offer_merchants as $om) {
            $id = $om['id'] ?? '';
            $seen_ids[$id] = true;
            
            $entry = [
                'id'           => $id,
                'name'         => $om['name'] ?? '',
                'offer_count'  => (int) ($om['offerCount'] ?? 0),
                'logo_url'     => $om['logo'] ?? '',
                'has_logo'     => (bool) ($om['hasLogo'] ?? false),
                'type'         => 'offer',
                'is_deeplink'  => false,
                'is_smartlink' => false,
                'cpc_amount'   => null,
                'cpc_currency' => 'EUR',
            ];
            
            // Mit Deeplink-Daten anreichern falls vorhanden
            if (isset($deeplink_index[$id])) {
                $dm = $deeplink_index[$id];
                $entry['is_deeplink'] = true;
                $entry['is_smartlink'] = (bool) ($dm['isSmartlink'] ?? false);
                $entry['deeplink_count'] = (int) ($dm['deeplinkCount'] ?? 0);
                
                $cpc = $dm['estimatedCpc']['amount'] ?? null;
                if ($cpc !== null) {
                    $entry['cpc_amount'] = (float) $cpc;
                    $entry['cpc_currency'] = $dm['estimatedCpc']['currency'] ?? 'EUR';
                }
            }
            
            $combined[] = $entry;
        }
        
        // Dann Deeplink-only Händler hinzufügen
        foreach ($deeplink_merchants as $dm) {
            $id = $dm['id'] ?? '';
            
            if (isset($seen_ids[$id])) {
                continue; // Bereits verarbeitet
            }
            
            $cpc = $dm['estimatedCpc']['amount'] ?? null;
            
            $combined[] = [
                'id'             => $id,
                'name'           => $dm['name'] ?? '',
                'offer_count'    => 0,
                'deeplink_count' => (int) ($dm['deeplinkCount'] ?? 0),
                'logo_url'       => $dm['logo']['url'] ?? '',
                'has_logo'       => (bool) ($dm['logo']['exists'] ?? false),
                'type'           => 'deeplink',
                'is_deeplink'    => true,
                'is_smartlink'   => (bool) ($dm['isSmartlink'] ?? false),
                'cpc_amount'     => $cpc !== null ? (float) $cpc : null,
                'cpc_currency'   => $dm['estimatedCpc']['currency'] ?? 'EUR',
            ];
        }
        
        // Nach Name sortieren
        usort($combined, fn($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
        
        return $combined;
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
                // CPC aus Offer (falls vorhanden)
                'estimated_cpc' => [
                    'amount'   => $offer['estimatedCpc']['amount'] ?? null,
                    'currency' => $offer['estimatedCpc']['currency'] ?? 'EUR',
                ],
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
    
    /**
     * Test Deeplink API connection
     * 
     * @return array{success: bool, message: string, merchant_count?: int}
     */
    public function test_deeplink_connection(?string $api_key = null): array {
        $key = $api_key ?? $this->api_key;
        
        if ($key === '') {
            return [
                'success' => false,
                'message' => __('Kein API-Key konfiguriert.', 'yadore-amazon-api'),
            ];
        }
        
        $url = self::DEEPLINK_MERCHANT_API_URL . '?' . http_build_query([
            'market' => 'de',
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 15,
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
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if ($status_code === 200 && is_array($data)) {
            $total = $data['total'] ?? count($data['merchants'] ?? []);
            
            return [
                'success' => true,
                'message' => sprintf(
                    __('Deeplink API erfolgreich! %d Händler gefunden.', 'yadore-amazon-api'),
                    $total
                ),
                'merchant_count' => $total,
            ];
        }
        
        $error = 'HTTP Error ' . $status_code;
        if (is_array($data) && isset($data['errors'])) {
            $first_error = reset($data['errors']);
            if (is_array($first_error)) {
                $error = $first_error[0] ?? $error;
            }
        }
        
        return [
            'success' => false,
            'message' => $error,
        ];
    }
    
    /**
     * Löscht alle Merchant-Caches
     * 
     * @param string|null $market Spezifischer Markt oder null für alle
     */
    public function clear_merchant_cache(?string $market = null): void {
        if ($market !== null) {
            // Spezifischen Markt löschen
            delete_transient('yadore_merchants_list_' . $market);
            delete_transient('yadore_deeplink_merchants_' . $market . '_all');
            delete_transient('yadore_deeplink_merchants_' . $market . '_smartlink');
        } else {
            // Alle Märkte löschen
            global $wpdb;
            
            $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yadore_merchants_list_%'"
            );
            $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yadore_deeplink_merchants_%'"
            );
            $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_yadore_merchants_list_%'"
            );
            $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_yadore_deeplink_merchants_%'"
            );
        }
    }
}

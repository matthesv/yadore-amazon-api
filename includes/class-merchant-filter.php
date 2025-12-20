<?php
/**
 * Merchant Filter - Whitelist/Blacklist für Yadore Händler
 * PHP 8.3+ compatible
 * Version: 1.3.0
 * 
 * Features:
 * - Whitelist (hat Vorrang)
 * - Blacklist
 * - Matching auf Händlername (case-insensitive, partial match)
 * - Shortcode Override
 * - Händlerliste via /v2/merchant API
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Merchant_Filter {
    
    /**
     * Cache key für Merchant-Liste
     */
    private const MERCHANT_CACHE_KEY = 'yadore_merchants_list';
    
    /**
     * Cache duration für Merchant-Liste (4 Stunden)
     */
    private const MERCHANT_CACHE_DURATION = 4 * HOUR_IN_SECONDS;
    
    /**
     * Filtert Produkte basierend auf Whitelist/Blacklist
     * Whitelist hat IMMER Vorrang vor Blacklist
     * 
     * @param array<int, array<string, mixed>> $products Produkte von der API
     * @param array<string, mixed> $filter_config Filter-Konfiguration
     * @return array<int, array<string, mixed>> Gefilterte Produkte
     */
    public static function filter_products(array $products, array $filter_config): array {
        $whitelist = $filter_config['whitelist'] ?? [];
        $blacklist = $filter_config['blacklist'] ?? [];
        
        // Normalisieren
        $whitelist = self::normalize_merchant_list($whitelist);
        $blacklist = self::normalize_merchant_list($blacklist);
        
        // Wenn weder Whitelist noch Blacklist, alle zurückgeben
        if (empty($whitelist) && empty($blacklist)) {
            return $products;
        }
        
        return array_values(array_filter($products, function($product) use ($whitelist, $blacklist) {
            $merchant_name = $product['merchant']['name'] ?? '';
            
            if ($merchant_name === '') {
                // Produkte ohne Händlernamen: Nur durchlassen wenn keine Whitelist aktiv
                return empty($whitelist);
            }
            
            // Whitelist hat Vorrang
            if (!empty($whitelist)) {
                return self::matches_any($merchant_name, $whitelist);
            }
            
            // Blacklist prüfen
            if (!empty($blacklist)) {
                return !self::matches_any($merchant_name, $blacklist);
            }
            
            return true;
        }));
    }
    
    /**
     * Prüft ob ein Händlername mit einem der Filter-Einträge übereinstimmt
     * Case-insensitive, partial match
     * 
     * @param string $merchant_name Der zu prüfende Händlername
     * @param array<string> $filter_list Liste der Filter-Einträge
     * @return bool True wenn Match gefunden
     */
    public static function matches_any(string $merchant_name, array $filter_list): bool {
        $merchant_lower = mb_strtolower($merchant_name, 'UTF-8');
        
        foreach ($filter_list as $filter) {
            $filter_lower = mb_strtolower(trim($filter), 'UTF-8');
            
            if ($filter_lower === '') {
                continue;
            }
            
            // Exakter Match
            if ($merchant_lower === $filter_lower) {
                return true;
            }
            
            // Partial Match (Händlername enthält Filter oder umgekehrt)
            if (str_contains($merchant_lower, $filter_lower) || str_contains($filter_lower, $merchant_lower)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Normalisiert eine Händlerliste (String oder Array)
     * 
     * @param string|array<string> $list
     * @return array<string>
     */
    public static function normalize_merchant_list(string|array $list): array {
        if (is_string($list)) {
            if ($list === '') {
                return [];
            }
            $list = explode(',', $list);
        }
        
        // Trimmen und leere Einträge entfernen
        $list = array_map('trim', $list);
        $list = array_filter($list, fn($item) => $item !== '');
        
        return array_values($list);
    }
    
    /**
     * Erstellt Filter-Konfiguration aus Shortcode-Attributen und globalen Einstellungen
     * Shortcode-Attribute haben Vorrang
     * 
     * @param array<string, mixed> $atts Shortcode-Attribute
     * @return array{whitelist: array<string>, blacklist: array<string>}
     */
    public static function build_filter_config(array $atts): array {
        // Shortcode-Attribute prüfen
        $shortcode_whitelist = $atts['merchant_whitelist'] ?? $atts['merchants'] ?? '';
        $shortcode_blacklist = $atts['merchant_blacklist'] ?? $atts['exclude_merchants'] ?? '';
        
        // Wenn Shortcode-Attribute gesetzt sind, haben diese Vorrang
        if ($shortcode_whitelist !== '' || $shortcode_blacklist !== '') {
            return [
                'whitelist' => self::normalize_merchant_list($shortcode_whitelist),
                'blacklist' => self::normalize_merchant_list($shortcode_blacklist),
            ];
        }
        
        // Globale Einstellungen laden
        $global_whitelist = yaa_get_option('yadore_merchant_whitelist', '');
        $global_blacklist = yaa_get_option('yadore_merchant_blacklist', '');
        
        return [
            'whitelist' => self::normalize_merchant_list($global_whitelist),
            'blacklist' => self::normalize_merchant_list($global_blacklist),
        ];
    }
    
    /**
     * Holt die Händlerliste von der Yadore API
     * 
     * @param string $api_key Yadore API Key
     * @param string $market Markt (de, at, ch, etc.)
     * @param bool $force_refresh Cache ignorieren
     * @return array{success: bool, merchants: array<array{id: string, name: string, offerCount: int}>, error?: string}
     */
    public static function fetch_merchants(string $api_key, string $market = 'de', bool $force_refresh = false): array {
        if ($api_key === '') {
            return [
                'success' => false,
                'merchants' => [],
                'error' => __('Kein API-Key konfiguriert.', 'yadore-amazon-api'),
            ];
        }
        
        // Cache prüfen
        $cache_key = self::MERCHANT_CACHE_KEY . '_' . $market;
        
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false && is_array($cached)) {
                return [
                    'success' => true,
                    'merchants' => $cached,
                    'from_cache' => true,
                ];
            }
        }
        
        // API Request
        $url = 'https://api.yadore.com/v2/merchant?' . http_build_query([
            'market' => $market,
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'API-Key' => $api_key,
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
            return [
                'success' => false,
                'merchants' => [],
                'error' => sprintf(__('API Fehler: HTTP %d', 'yadore-amazon-api'), $status_code),
            ];
        }
        
        // Händler extrahieren und normalisieren
        $merchants = [];
        $raw_merchants = $data['merchants'] ?? [];
        
        foreach ($raw_merchants as $merchant) {
            $merchants[] = [
                'id'         => $merchant['id'] ?? '',
                'name'       => $merchant['name'] ?? '',
                'offerCount' => (int) ($merchant['offerCount'] ?? 0),
                'logo'       => $merchant['logo']['url'] ?? '',
                'hasLogo'    => (bool) ($merchant['logo']['exists'] ?? false),
            ];
        }
        
        // Nach Name sortieren
        usort($merchants, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        
        // Cache speichern
        set_transient($cache_key, $merchants, self::MERCHANT_CACHE_DURATION);
        
        // Auch in Option speichern für Offline-Nutzung
        update_option('yaa_yadore_merchants_' . $market, $merchants, false);
        update_option('yaa_yadore_merchants_updated_' . $market, time(), false);
        
        return [
            'success' => true,
            'merchants' => $merchants,
            'total' => $data['total'] ?? count($merchants),
            'from_cache' => false,
        ];
    }
    
    /**
     * Holt die gespeicherte Händlerliste (für Admin-Bereich)
     * 
     * @param string $market Markt
     * @return array<array{id: string, name: string, offerCount: int}>
     */
    public static function get_stored_merchants(string $market = 'de'): array {
        $merchants = get_option('yaa_yadore_merchants_' . $market, []);
        return is_array($merchants) ? $merchants : [];
    }
    
    /**
     * Gibt das letzte Update-Datum zurück
     * 
     * @param string $market Markt
     * @return int|null Unix Timestamp oder null
     */
    public static function get_last_update(string $market = 'de'): ?int {
        $timestamp = get_option('yaa_yadore_merchants_updated_' . $market, null);
        return $timestamp !== null ? (int) $timestamp : null;
    }
    
    /**
     * Löscht den Merchant-Cache
     * 
     * @param string|null $market Markt oder null für alle
     */
    public static function clear_cache(?string $market = null): void {
        if ($market !== null) {
            delete_transient(self::MERCHANT_CACHE_KEY . '_' . $market);
        } else {
            // Alle Märkte löschen
            global $wpdb;
            $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_" . self::MERCHANT_CACHE_KEY . "_%'"
            );
        }
    }
    
    /**
     * Prüft ob ein Händler in der Whitelist ist
     * 
     * @param string $merchant_name Händlername
     * @return bool
     */
    public static function is_whitelisted(string $merchant_name): bool {
        $whitelist = self::normalize_merchant_list(yaa_get_option('yadore_merchant_whitelist', ''));
        
        if (empty($whitelist)) {
            return true; // Keine Whitelist = alle erlaubt
        }
        
        return self::matches_any($merchant_name, $whitelist);
    }
    
    /**
     * Prüft ob ein Händler in der Blacklist ist
     * 
     * @param string $merchant_name Händlername
     * @return bool
     */
    public static function is_blacklisted(string $merchant_name): bool {
        $blacklist = self::normalize_merchant_list(yaa_get_option('yadore_merchant_blacklist', ''));
        
        if (empty($blacklist)) {
            return false; // Keine Blacklist = keiner geblockt
        }
        
        return self::matches_any($merchant_name, $blacklist);
    }
    
    /**
     * Generiert ein HTML-Select für Händlerauswahl (Admin)
     * 
     * @param string $name Input name
     * @param array<string> $selected Ausgewählte Händler
     * @param string $market Markt
     * @param string $placeholder Placeholder-Text
     * @return string HTML
     */
    public static function render_merchant_select(
        string $name, 
        array $selected, 
        string $market = 'de',
        string $placeholder = ''
    ): string {
        $merchants = self::get_stored_merchants($market);
        $selected_lower = array_map(fn($s) => mb_strtolower(trim($s), 'UTF-8'), $selected);
        
        $html = '<select name="' . esc_attr($name) . '[]" multiple class="yaa-merchant-select" ';
        $html .= 'data-placeholder="' . esc_attr($placeholder ?: __('Händler auswählen...', 'yadore-amazon-api')) . '">';
        
        foreach ($merchants as $merchant) {
            $name_lower = mb_strtolower($merchant['name'], 'UTF-8');
            $is_selected = in_array($name_lower, $selected_lower, true) || in_array($merchant['name'], $selected, true);
            
            $html .= '<option value="' . esc_attr($merchant['name']) . '"';
            $html .= $is_selected ? ' selected' : '';
            $html .= '>';
            $html .= esc_html($merchant['name']);
            if ($merchant['offerCount'] > 0) {
                $html .= ' (' . number_format($merchant['offerCount'], 0, ',', '.') . ' Angebote)';
            }
            $html .= '</option>';
        }
        
        $html .= '</select>';
        
        return $html;
    }
    
    /**
     * Validiert und bereinigt eine Händlerliste für die Speicherung
     * 
     * @param string|array<string> $input
     * @return string Komma-separierte Liste
     */
    public static function sanitize_merchant_list(string|array $input): string {
        $list = self::normalize_merchant_list($input);
        return implode(', ', $list);
    }
}

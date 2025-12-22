<?php
/**
 * Image Proxy Handler
 * Server-seitiger Proxy für externe Bilder
 * Umgeht Hotlink-Protection und CORS-Probleme
 * 
 * PHP 8.3+ compatible
 * Version: 1.0.0
 * 
 * @see Anforderung: Option C - Server-seitiger Bild-Proxy
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Image_Proxy {
    
    /**
     * Cache-Dauer für Proxy-Bilder (24 Stunden)
     */
    private const CACHE_DURATION = DAY_IN_SECONDS;
    
    /**
     * Maximale Bildgröße die gecacht wird (5 MB)
     */
    private const MAX_IMAGE_SIZE = 5 * 1024 * 1024;
    
    /**
     * Timeout für Remote-Requests (Sekunden)
     */
    private const REQUEST_TIMEOUT = 15;
    
    /**
     * Erlaubte MIME-Types
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];
    
    /**
     * Erlaubte Hosts (leer = alle erlaubt)
     * Kann über Filter erweitert werden
     * 
     * @var array<string>
     */
    private array $allowed_hosts = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        // AJAX Actions registrieren (für eingeloggte und nicht-eingeloggte User)
        add_action('wp_ajax_yaa_proxy_image', [$this, 'handle_proxy_request']);
        add_action('wp_ajax_nopriv_yaa_proxy_image', [$this, 'handle_proxy_request']);
        
        // REST API Endpoint (Alternative zu AJAX)
        add_action('rest_api_init', [$this, 'register_rest_route']);
        
        // Erlaubte Hosts über Filter laden
        $this->allowed_hosts = apply_filters('yaa_proxy_allowed_hosts', [
            // Standard: Bekannte Produkt-Bild-Hosts
            'images-na.ssl-images-amazon.com',
            'images-eu.ssl-images-amazon.com',
            'm.media-amazon.com',
            'images.yadore.com',
            'cdn.yadore.com',
        ]);
    }
    
    /**
     * Generiert die Proxy-URL für ein Bild
     * 
     * @param string $image_url Original-Bild-URL
     * @param string $source Quelle (amazon, yadore, custom)
     * @return string Proxy-URL
     */
    public static function get_proxy_url(string $image_url, string $source = ''): string {
        if ($image_url === '') {
            return '';
        }
        
        // Bereits lokale URL? Kein Proxy nötig
        $site_url = get_site_url();
        if (str_starts_with($image_url, $site_url)) {
            return $image_url;
        }
        
        // Data-URLs brauchen keinen Proxy
        if (str_starts_with($image_url, 'data:')) {
            return $image_url;
        }
        
        // Proxy-URL generieren
        $params = [
            'action' => 'yaa_proxy_image',
            'url'    => $image_url,
        ];
        
        // Source für Logging/Debugging
        if ($source !== '') {
            $params['source'] = $source;
        }
        
        // Nonce für Sicherheit (optional, da öffentlich zugänglich)
        // $params['_wpnonce'] = wp_create_nonce('yaa_proxy_image');
        
        return admin_url('admin-ajax.php') . '?' . http_build_query($params);
    }
    
    /**
     * Generiert REST API Proxy-URL (Alternative)
     * 
     * @param string $image_url Original-Bild-URL
     * @return string REST API Proxy-URL
     */
    public static function get_rest_proxy_url(string $image_url): string {
        if ($image_url === '') {
            return '';
        }
        
        return rest_url('yaa/v1/image-proxy') . '?' . http_build_query([
            'url' => $image_url,
        ]);
    }
    
    /**
     * REST API Route registrieren
     */
    public function register_rest_route(): void {
        register_rest_route('yaa/v1', '/image-proxy', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_rest_request'],
            'permission_callback' => '__return_true', // Öffentlich zugänglich
            'args'                => [
                'url' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ]);
    }
    
    /**
     * AJAX Handler für Proxy-Requests
     */
    public function handle_proxy_request(): void {
        // URL aus Request holen
        $url = isset($_GET['url']) ? esc_url_raw(wp_unslash($_GET['url'])) : '';
        
        if ($url === '') {
            $this->send_error_response('No URL provided', 400);
            return;
        }
        
        // Bild laden und ausgeben
        $this->proxy_image($url);
    }
    
    /**
     * REST API Handler für Proxy-Requests
     * 
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function handle_rest_request(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $url = $request->get_param('url');
        
        if (empty($url)) {
            return new \WP_Error('no_url', 'No URL provided', ['status' => 400]);
        }
        
        // Bild laden und als Response zurückgeben
        $result = $this->fetch_image($url);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Bei REST API: Bild direkt ausgeben mit korrekten Headern
        $this->output_image($result['body'], $result['type']);
        exit; // Nach Bildausgabe beenden
    }
    
    /**
     * Hauptfunktion: Bild proxyen
     * 
     * @param string $url Externe Bild-URL
     */
    private function proxy_image(string $url): void {
        // URL validieren
        if (!$this->is_valid_url($url)) {
            $this->send_error_response('Invalid URL', 400);
            return;
        }
        
        // Host-Whitelist prüfen (wenn aktiviert)
        if (!$this->is_host_allowed($url)) {
            $this->send_error_response('Host not allowed', 403);
            return;
        }
        
        // Cache prüfen
        $cache_key = 'yaa_proxy_' . md5($url);
        $cached = get_transient($cache_key);
        
        if ($cached !== false && is_array($cached)) {
            // Aus Cache laden
            $this->output_image($cached['body'], $cached['type'], true);
            return;
        }
        
        // Bild von externer URL laden
        $result = $this->fetch_image($url);
        
        if (is_wp_error($result)) {
            $this->send_error_response($result->get_error_message(), 502);
            return;
        }
        
        // In Cache speichern (wenn nicht zu groß)
        if (strlen($result['body']) <= self::MAX_IMAGE_SIZE) {
            set_transient($cache_key, $result, self::CACHE_DURATION);
        }
        
        // Bild ausgeben
        $this->output_image($result['body'], $result['type'], false);
    }
    
    /**
     * Bild von externer URL laden
     * 
     * @param string $url Externe URL
     * @return array{body: string, type: string}|\WP_Error
     */
    private function fetch_image(string $url): array|\WP_Error {
        // HTTP Request mit angepassten Headern
        $response = wp_remote_get($url, [
            'timeout'    => self::REQUEST_TIMEOUT,
            'sslverify'  => false, // Manche CDNs haben ungültige Zertifikate
            'user-agent' => $this->get_user_agent(),
            'headers'    => [
                'Accept'          => 'image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8',
                'Cache-Control'   => 'no-cache',
                'Referer'         => home_url(), // Manche Server prüfen den Referer
            ],
        ]);
        
        if (is_wp_error($response)) {
            error_log('YAA Image Proxy Error: ' . $response->get_error_message() . ' - URL: ' . $url);
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            error_log('YAA Image Proxy HTTP Error: ' . $status_code . ' - URL: ' . $url);
            return new \WP_Error(
                'http_error',
                sprintf('HTTP Error %d', $status_code),
                ['status' => $status_code]
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        
        // Content-Type validieren
        if (!$this->is_valid_content_type($content_type)) {
            // Versuche Content-Type aus Magic Bytes zu ermitteln
            $detected_type = $this->detect_image_type($body);
            
            if ($detected_type === null) {
                error_log('YAA Image Proxy: Invalid content type - URL: ' . $url);
                return new \WP_Error('invalid_type', 'Not a valid image');
            }
            
            $content_type = $detected_type;
        }
        
        // Mindestgröße prüfen (Tracking-Pixel filtern)
        if (strlen($body) < 100) {
            error_log('YAA Image Proxy: Image too small (tracking pixel?) - URL: ' . $url);
            return new \WP_Error('too_small', 'Image too small');
        }
        
        return [
            'body' => $body,
            'type' => $content_type,
        ];
    }
    
    /**
     * Bild mit korrekten Headern ausgeben
     * 
     * @param string $body Bild-Daten
     * @param string $content_type MIME-Type
     * @param bool $from_cache Ob aus Cache geladen
     */
    private function output_image(string $body, string $content_type, bool $from_cache = false): void {
        // Status Code
        http_response_code(200);
        
        // Content-Type Header
        header('Content-Type: ' . $content_type);
        
        // Caching Header
        header('Cache-Control: public, max-age=86400'); // 24 Stunden
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
        
        // ETag für Browser-Caching
        $etag = '"' . md5($body) . '"';
        header('ETag: ' . $etag);
        
        // If-None-Match prüfen (304 Not Modified)
        $if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($if_none_match === $etag) {
            http_response_code(304);
            exit;
        }
        
        // Content-Length
        header('Content-Length: ' . strlen($body));
        
        // Debug-Header (optional)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            header('X-YAA-Proxy: ' . ($from_cache ? 'cache' : 'fetch'));
        }
        
        // CORS Header (erlaubt Zugriff von überall)
        header('Access-Control-Allow-Origin: *');
        
        // Bild ausgeben
        echo $body;
        exit;
    }
    
    /**
     * Fehler-Response senden
     * 
     * @param string $message Fehlermeldung
     * @param int $status_code HTTP Status Code
     */
    private function send_error_response(string $message, int $status_code = 400): void {
        http_response_code($status_code);
        
        // Placeholder-Bild als Fallback
        $placeholder = $this->generate_error_placeholder($message);
        
        header('Content-Type: image/svg+xml');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-YAA-Proxy-Error: ' . $message);
        
        echo $placeholder;
        exit;
    }
    
    /**
     * URL validieren
     */
    private function is_valid_url(string $url): bool {
        if ($url === '') {
            return false;
        }
        
        // Nur HTTP(S) URLs erlauben
        if (!preg_match('/^https?:\/\//i', $url)) {
            return false;
        }
        
        // URL-Validierung
        $parsed = wp_parse_url($url);
        
        if ($parsed === false || empty($parsed['host'])) {
            return false;
        }
        
        // Keine lokalen IPs
        $host = $parsed['host'];
        $ip = gethostbyname($host);
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            // Erlaube dennoch, falls es sich um einen gültigen Hostnamen handelt
            // (manche CDNs resolven zu privaten IPs)
            if ($ip === $host) {
                return false; // Konnte nicht aufgelöst werden
            }
        }
        
        return true;
    }
    
    /**
     * Prüft ob Host in Whitelist ist
     */
    private function is_host_allowed(string $url): bool {
        // Wenn keine Whitelist definiert, alle erlauben
        if (empty($this->allowed_hosts)) {
            return true;
        }
        
        $parsed = wp_parse_url($url);
        $host = $parsed['host'] ?? '';
        
        if ($host === '') {
            return false;
        }
        
        // Exakter Match oder Subdomain-Match
        foreach ($this->allowed_hosts as $allowed) {
            if ($host === $allowed) {
                return true;
            }
            
            // Subdomain-Match (z.B. "*.amazon.com")
            if (str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        
        // Filter für dynamische Erweiterung
        return apply_filters('yaa_proxy_is_host_allowed', false, $host, $url);
    }
    
    /**
     * Content-Type validieren
     */
    private function is_valid_content_type(?string $content_type): bool {
        if ($content_type === null || $content_type === '') {
            return false;
        }
        
        // Nur den MIME-Type extrahieren (charset etc. ignorieren)
        $parts = explode(';', $content_type);
        $mime = strtolower(trim($parts[0]));
        
        return in_array($mime, self::ALLOWED_MIME_TYPES, true);
    }
    
    /**
     * Bildtyp aus Magic Bytes erkennen
     */
    private function detect_image_type(string $data): ?string {
        if (strlen($data) < 12) {
            return null;
        }
        
        // Magic Bytes prüfen
        if (str_starts_with($data, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        
        if (str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        
        if (str_starts_with($data, "GIF87a") || str_starts_with($data, "GIF89a")) {
            return 'image/gif';
        }
        
        if (str_starts_with($data, "RIFF") && substr($data, 8, 4) === "WEBP") {
            return 'image/webp';
        }
        
        // SVG (Text-basiert)
        if (str_contains(substr($data, 0, 500), '<svg')) {
            return 'image/svg+xml';
        }
        
        return null;
    }
    
    /**
     * User-Agent für Requests
     */
    private function get_user_agent(): string {
        // Realistischer Browser User-Agent
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }
    
    /**
     * Error-Placeholder SVG generieren
     */
    private function generate_error_placeholder(string $message): string {
        $escaped_message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        
        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400">
    <defs>
        <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:#2d2d2d"/>
            <stop offset="100%" style="stop-color:#1a1a1a"/>
        </linearGradient>
    </defs>
    <rect width="400" height="400" fill="url(#bg)"/>
    <g transform="translate(200,160)" fill="none" stroke="#666" stroke-width="2">
        <circle cx="0" cy="0" r="50"/>
        <line x1="-20" y1="-20" x2="20" y2="20"/>
        <line x1="20" y1="-20" x2="-20" y2="20"/>
    </g>
    <text x="200" y="260" text-anchor="middle" font-family="system-ui, sans-serif" font-size="14" fill="#888">
        Bild nicht verfügbar
    </text>
    <text x="200" y="285" text-anchor="middle" font-family="system-ui, sans-serif" font-size="11" fill="#555">
        {$escaped_message}
    </text>
</svg>
SVG;
    }
    
    /**
     * Cache für eine URL löschen
     * 
     * @param string $url Original-Bild-URL
     */
    public static function clear_cache(string $url): void {
        $cache_key = 'yaa_proxy_' . md5($url);
        delete_transient($cache_key);
    }
    
    /**
     * Alle Proxy-Caches löschen
     */
    public static function clear_all_cache(): int {
        global $wpdb;
        
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yaa_proxy_%'"
        );
        
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_yaa_proxy_%'"
        );
        
        return (int) $deleted;
    }
    
    /**
     * Statistiken über gecachte Proxy-Bilder
     * 
     * @return array{count: int, estimated_size: string}
     */
    public static function get_cache_stats(): array {
        global $wpdb;
        
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_yaa_proxy_%' AND option_name NOT LIKE '_transient_timeout_%'"
        );
        
        // Geschätzte Größe (können wir nicht exakt bestimmen ohne alle zu laden)
        $estimated_size = $count > 0 ? '~' . size_format($count * 50000) : '0 B';
        
        return [
            'count'          => $count,
            'estimated_size' => $estimated_size,
        ];
    }
}

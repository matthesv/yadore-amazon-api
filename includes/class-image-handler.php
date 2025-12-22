<?php
/**
 * Image Handler Class
 * Version: 1.5.1
 * PHP 8.3+ compatible
 * 
 * FIX 1.5.1: TLS-Fingerprinting Problem behoben
 *            - Keine Browser-spezifischen Headers mehr (Sec-Ch-Ua etc.)
 *            - Einfacher, konsistenter User-Agent
 *            - Funktioniert mit CDNs die JA3-Fingerprinting nutzen
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class YAA_Image_Handler {

    private const MAX_RETRIES = 3;
    private const RETRY_COOLDOWN_HOURS = 1;
    private const MAX_LOG_ENTRIES = 100;
    private const SEO_NAME_MAX_LENGTH = 30;
    
    private const IMAGE_SIZES = [
        'Large'  => 500,
        'Medium' => 160,
        'Small'  => 75,
    ];

    public static function can_resize_images(): bool {
        return self::get_image_library() !== 'None';
    }

    public static function get_image_library(): string {
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            return 'Imagick';
        }
        if (extension_loaded('gd') && function_exists('imagecreatetruecolor')) {
            return 'GD';
        }
        return 'None';
    }

    public static function get_max_dimension(string $size = 'Large'): int {
        return self::IMAGE_SIZES[$size] ?? self::IMAGE_SIZES['Large'];
    }

    /**
     * Process an image: Download if not exists, return local URL.
     */
    public static function process(
        ?string $remote_url, 
        string $id, 
        string $product_name = '',
        string $source = ''
    ): string {
        if (empty($remote_url)) {
            return self::get_placeholder_url();
        }

        if (self::should_skip_retry($remote_url)) {
            self::debug_log('Skipping retry for URL (too many failures): ' . $remote_url);
            return self::get_placeholder_url();
        }

        $upload_dir = wp_upload_dir();
        if (isset($upload_dir['error']) && $upload_dir['error'] !== false) {
            return $remote_url;
        }

        $base_dir = $upload_dir['basedir'] . '/yadore-amazon-api';
        $base_url = $upload_dir['baseurl'] . '/yadore-amazon-api';

        if (!file_exists($base_dir)) {
            if (!wp_mkdir_p($base_dir)) {
                return $remote_url;
            }
            file_put_contents($base_dir . '/index.php', '<?php // Silence is golden');
        }

        $path_parts = pathinfo(parse_url($remote_url, PHP_URL_PATH) ?? '');
        $ext = isset($path_parts['extension']) ? strtolower($path_parts['extension']) : 'jpg';
        
        $ext = match($ext) {
            'jpeg' => 'jpg',
            'webp', 'png', 'gif', 'jpg' => $ext,
            default => 'jpg',
        };
        
        $filename = self::generate_filename($id, $product_name, $source, $ext);
        $file_path = $base_dir . '/' . $filename;
        $file_url = $base_url . '/' . $filename;

        $existing_file = self::find_existing_file($base_dir, $base_url, $id, $source, $ext);
        if ($existing_file !== null) {
            return $existing_file;
        }

        if (file_exists($file_path)) {
            if (filesize($file_path) > 100 && self::is_valid_image_file($file_path)) {
                return $file_url;
            }
            @unlink($file_path);
        }

        // Download mit verbesserter Methode
        self::debug_log('Starting download for: ' . $remote_url);
        
        $download_result = self::download_image_smart($remote_url);
        
        if ($download_result === false || $download_result === '') {
            self::log_failed_image($remote_url, $id, 'Download failed (all methods)');
            self::increment_retry_counter($remote_url);
            return self::get_placeholder_url();
        }

        if (!self::is_valid_image_data($download_result)) {
            self::log_failed_image($remote_url, $id, 'Invalid image data');
            self::increment_retry_counter($remote_url);
            return self::get_placeholder_url();
        }

        if (strlen($download_result) < 500) {
            self::log_failed_image($remote_url, $id, 'Image too small (likely tracking pixel)');
            self::increment_retry_counter($remote_url);
            return self::get_placeholder_url();
        }

        if (file_put_contents($file_path, $download_result) === false) {
            self::log_failed_image($remote_url, $id, 'Could not save file');
            return self::get_placeholder_url();
        }

        $resize_enabled = function_exists('yaa_get_option') 
            ? yaa_get_option('image_resize_enabled', 'yes') === 'yes' 
            : false;
        
        if ($resize_enabled && self::can_resize_images()) {
            $preferred_size = function_exists('yaa_get_option') 
                ? yaa_get_option('preferred_image_size', 'Large') 
                : 'Large';
            
            self::resize_image($file_path, $preferred_size);
        }

        self::clear_retry_counter($remote_url);
        
        self::debug_log('Successfully downloaded: ' . $remote_url . ' -> ' . $file_path);

        return $file_url;
    }

    private static function debug_log(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('YAA Image Handler: ' . $message);
        }
    }

    /**
     * FIX 1.5.1: Smart Download mit mehreren Methoden
     * 
     * Reihenfolge:
     * 1. curl MINIMAL (keine verdächtigen Headers) - BESTE METHODE
     * 2. curl mit einfachem User-Agent
     * 3. wp_remote_get 
     * 4. file_get_contents
     */
    public static function download_image_smart(string $url): string|false {
        // Methode 1: curl MINIMAL - wie auf der Konsole
        $result = self::download_with_curl_minimal($url);
        if ($result !== false && strlen($result) > 500) {
            self::debug_log('curl MINIMAL succeeded for: ' . $url);
            return $result;
        }
        self::debug_log('curl MINIMAL failed for: ' . $url);
        
        // Methode 2: curl mit einfachem User-Agent
        $result = self::download_with_curl_simple($url);
        if ($result !== false && strlen($result) > 500) {
            self::debug_log('curl SIMPLE succeeded for: ' . $url);
            return $result;
        }
        
        // Methode 3: wp_remote_get
        $result = self::download_with_wp_remote($url);
        if ($result !== false && strlen($result) > 500) {
            self::debug_log('wp_remote_get succeeded for: ' . $url);
            return $result;
        }
        
        // Methode 4: file_get_contents
        $result = self::download_with_stream_context($url);
        if ($result !== false && strlen($result) > 500) {
            self::debug_log('stream_context succeeded for: ' . $url);
            return $result;
        }
        
        self::debug_log('All download methods failed for: ' . $url);
        return false;
    }

    /**
     * FIX 1.5.1: MINIMALER curl Request - wie auf der Konsole
     * 
     * Keine zusätzlichen Headers!
     * Der Befehl `curl -I URL` funktioniert - also machen wir genau das.
     */
    private static function download_with_curl_minimal(string $url): string|false {
        if (!function_exists('curl_init')) {
            return false;
        }
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            // KEINE zusätzlichen Headers!
            // KEIN User-Agent!
            // KEIN Referer!
            // Genau wie: curl -o file.jpg URL
            CURLOPT_ENCODING       => '', // Akzeptiert gzip automatisch
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($response === false) {
            self::debug_log('curl MINIMAL error: ' . $error . ' - URL: ' . $url);
            return false;
        }
        
        if ($http_code !== 200) {
            self::debug_log('curl MINIMAL HTTP ' . $http_code . ' - URL: ' . $url);
            return false;
        }
        
        return $response;
    }

    /**
     * FIX 1.5.1: Einfacher curl mit generischem User-Agent
     * 
     * WICHTIG: Keine Browser-spezifischen Headers (Sec-Ch-Ua etc.)
     * Diese verraten, dass wir Chrome nachahmen, aber der TLS-Fingerprint
     * zeigt PHP/OpenSSL - das führt zu Blocking!
     */
    private static function download_with_curl_simple(string $url): string|false {
        if (!function_exists('curl_init')) {
            return false;
        }
        
        $ch = curl_init();
        
        // Generischer, ehrlicher User-Agent
        // NICHT Chrome vortäuschen - das führt zu TLS-Fingerprint Mismatch!
        $user_agent = 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo('version') . ')';
        
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => $user_agent,
            CURLOPT_ENCODING       => '',
            // NUR einfache, nicht-verdächtige Headers
            CURLOPT_HTTPHEADER     => [
                'Accept: image/*,*/*;q=0.8',
                'Accept-Language: de-DE,de;q=0.9,en;q=0.8',
            ],
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($response === false) {
            self::debug_log('curl SIMPLE error: ' . $error . ' - URL: ' . $url);
            return false;
        }
        
        if ($http_code !== 200) {
            self::debug_log('curl SIMPLE HTTP ' . $http_code . ' - URL: ' . $url);
            return false;
        }
        
        return $response;
    }

    /**
     * Download mit wp_remote_get (WordPress native)
     */
    private static function download_with_wp_remote(string $url): string|false {
        $response = wp_remote_get($url, [
            'timeout'    => 30,
            'sslverify'  => false,
            // WordPress Standard User-Agent verwenden
            'headers'    => [
                'Accept' => 'image/*,*/*;q=0.8',
            ],
        ]);

        if (is_wp_error($response)) {
            self::debug_log('wp_remote error: ' . $response->get_error_message() . ' - URL: ' . $url);
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            self::debug_log('wp_remote HTTP ' . $status_code . ' - URL: ' . $url);
            return false;
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Download mit file_get_contents und Stream Context (Fallback)
     */
    private static function download_with_stream_context(string $url): string|false {
        if (!ini_get('allow_url_fopen')) {
            return false;
        }
        
        $opts = [
            'http' => [
                'method'           => 'GET',
                'timeout'          => 30,
                'follow_location'  => true,
                'max_redirects'    => 5,
                'ignore_errors'    => true,
                // Minimale Headers
                'header'           => 'Accept: image/*,*/*',
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ];
        
        $context = stream_context_create($opts);
        
        $result = @file_get_contents($url, false, $context);
        
        if ($result === false) {
            self::debug_log('stream error - URL: ' . $url);
            return false;
        }
        
        return $result;
    }

    // ========================================
    // Ab hier: Unveränderte Methoden
    // ========================================

    public static function resize_image(string $file_path, string $size_setting = 'Large'): bool {
        if (!file_exists($file_path)) {
            return false;
        }

        $max_dimension = self::get_max_dimension($size_setting);
        $library = self::get_image_library();

        if ($library === 'None') {
            return false;
        }

        $image_info = @getimagesize($file_path);
        if ($image_info === false) {
            return false;
        }

        $width = $image_info[0];
        $height = $image_info[1];
        $mime = $image_info['mime'] ?? '';

        if ($width <= $max_dimension && $height <= $max_dimension) {
            return true;
        }

        if ($width > $height) {
            $new_width = $max_dimension;
            $new_height = (int) round(($height / $width) * $max_dimension);
        } else {
            $new_height = $max_dimension;
            $new_width = (int) round(($width / $height) * $max_dimension);
        }

        if ($library === 'Imagick') {
            return self::resize_with_imagick($file_path, $new_width, $new_height);
        } else {
            return self::resize_with_gd($file_path, $new_width, $new_height, $mime);
        }
    }

    private static function resize_with_imagick(string $file_path, int $width, int $height): bool {
        try {
            $imagick = new \Imagick($file_path);
            $imagick->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
            $imagick->setImageCompressionQuality(85);
            $imagick->stripImage();
            $result = $imagick->writeImage($file_path);
            $imagick->destroy();
            return $result;
        } catch (\Exception $e) {
            error_log('YAA Imagick resize error: ' . $e->getMessage());
            return false;
        }
    }

    private static function resize_with_gd(string $file_path, int $width, int $height, string $mime): bool {
        try {
            $source = match($mime) {
                'image/jpeg' => @imagecreatefromjpeg($file_path),
                'image/png'  => @imagecreatefrompng($file_path),
                'image/gif'  => @imagecreatefromgif($file_path),
                'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file_path) : false,
                default      => false,
            };

            if ($source === false) {
                return false;
            }

            $source_width = imagesx($source);
            $source_height = imagesy($source);

            $dest = imagecreatetruecolor($width, $height);
            
            if ($dest === false) {
                imagedestroy($source);
                return false;
            }

            if ($mime === 'image/png' || $mime === 'image/gif') {
                imagealphablending($dest, false);
                imagesavealpha($dest, true);
                $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
                imagefill($dest, 0, 0, $transparent);
            }

            imagecopyresampled(
                $dest, $source,
                0, 0, 0, 0,
                $width, $height,
                $source_width, $source_height
            );

            $result = match($mime) {
                'image/jpeg' => imagejpeg($dest, $file_path, 85),
                'image/png'  => imagepng($dest, $file_path, 6),
                'image/gif'  => imagegif($dest, $file_path),
                'image/webp' => function_exists('imagewebp') ? imagewebp($dest, $file_path, 85) : false,
                default      => false,
            };

            imagedestroy($source);
            imagedestroy($dest);

            return $result;
        } catch (\Exception $e) {
            error_log('YAA GD resize error: ' . $e->getMessage());
            return false;
        }
    }

    public static function generate_filename(
        string $id, 
        string $product_name = '', 
        string $source = '',
        string $ext = 'jpg'
    ): string {
        $format = function_exists('yaa_get_option') 
            ? yaa_get_option('image_filename_format', 'seo') 
            : 'seo';
        
        $ext = preg_replace('/[^a-z0-9]/i', '', strtolower($ext)) ?: 'jpg';
        
        if ($format === 'seo' && $product_name !== '') {
            return self::generate_seo_filename($product_name, $ext);
        }
        
        return self::generate_id_filename($id, $source, $ext);
    }

    private static function generate_seo_filename(string $product_name, string $ext): string {
        $name = self::sanitize_for_filename($product_name);
        
        if (mb_strlen($name, 'UTF-8') > self::SEO_NAME_MAX_LENGTH) {
            $name = mb_substr($name, 0, self::SEO_NAME_MAX_LENGTH, 'UTF-8');
            $name = rtrim($name, '-');
        }
        
        if ($name === '') {
            $name = 'product';
        }
        
        $timestamp = time();
        
        return $name . '_' . $timestamp . '.' . $ext;
    }

    private static function generate_id_filename(string $id, string $source, string $ext): string {
        $prefix = '';
        
        if ($source !== '') {
            $prefix = sanitize_file_name($source) . '_';
        }
        
        $safe_id = sanitize_file_name($id);
        
        if ($safe_id === '') {
            $safe_id = uniqid('img_');
        }
        
        return $prefix . $safe_id . '.' . $ext;
    }

    private static function sanitize_for_filename(string $text): string {
        $text = mb_strtolower($text, 'UTF-8');
        
        $text = str_replace(
            ['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü'],
            ['ae', 'oe', 'ue', 'ss', 'ae', 'oe', 'ue'],
            $text
        );
        
        $text = str_replace(
            ['&', '+', '@', '#', '$', '%', '/', '\\', '|', ':', ';', '"', "'", '<', '>', '?', '*'],
            '-',
            $text
        );
        
        $text = preg_replace('/[^a-z0-9\-]/u', '-', $text) ?? $text;
        $text = preg_replace('/-+/', '-', $text) ?? $text;
        $text = trim($text, '-');
        
        return $text;
    }

    private static function find_existing_file(
        string $base_dir, 
        string $base_url, 
        string $id, 
        string $source,
        string $ext
    ): ?string {
        $id_filename = self::generate_id_filename($id, $source, $ext);
        $id_path = $base_dir . '/' . $id_filename;
        
        if (file_exists($id_path) && filesize($id_path) > 100 && self::is_valid_image_file($id_path)) {
            return $base_url . '/' . $id_filename;
        }
        
        if ($source !== '') {
            $simple_filename = sanitize_file_name($id) . '.' . $ext;
            $simple_path = $base_dir . '/' . $simple_filename;
            
            if (file_exists($simple_path) && filesize($simple_path) > 100 && self::is_valid_image_file($simple_path)) {
                return $base_url . '/' . $simple_filename;
            }
        }
        
        return null;
    }

    public static function process_with_retry(
        ?string $remote_url, 
        string $id, 
        string $product_name = '',
        string $source = ''
    ): string {
        return self::process($remote_url, $id, $product_name, $source);
    }

    public static function is_remote_url_accessible(string $url): bool {
        if (empty($url)) {
            return false;
        }

        // HEAD-Checks bei bekannten Domains überspringen
        $skip_domains = ['proshop.de', 'amazon.', 'ebay.', 'otto.de', 'mediamarkt.', 'saturn.', 'office-partner.de', 'keycdn'];
        
        $url_host = parse_url($url, PHP_URL_HOST) ?? '';
        foreach ($skip_domains as $domain) {
            if (str_contains($url_host, $domain)) {
                return true;
            }
        }

        return true; // Immer versuchen zu laden
    }

    public static function is_valid_image_data(string $data): bool {
        if (strlen($data) < 8) {
            return false;
        }

        $signatures = [
            "\xFF\xD8\xFF" => 'jpeg',
            "\x89PNG" => 'png',
            "GIF87a" => 'gif',
            "GIF89a" => 'gif',
            "RIFF" => 'webp_check',
        ];

        foreach ($signatures as $sig => $type) {
            if (str_starts_with($data, $sig)) {
                if ($type === 'webp_check') {
                    if (strlen($data) >= 12 && substr($data, 8, 4) === 'WEBP') {
                        return true;
                    }
                    continue;
                }
                return true;
            }
        }

        if (str_starts_with($data, "BM")) {
            return true;
        }

        return false;
    }

    public static function is_valid_image_file(string $file_path): bool {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        $handle = fopen($file_path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 12);
        fclose($handle);

        if ($header === false) {
            return false;
        }

        return self::is_valid_image_data($header);
    }

    private static function should_skip_retry(string $url): bool {
        $retry_key = 'yaa_img_retry_' . md5($url);
        $retry_data = get_transient($retry_key);

        if ($retry_data === false) {
            return false;
        }

        if (!is_array($retry_data)) {
            return false;
        }

        return ($retry_data['count'] ?? 0) >= self::MAX_RETRIES;
    }

    private static function increment_retry_counter(string $url): void {
        $retry_key = 'yaa_img_retry_' . md5($url);
        $retry_data = get_transient($retry_key);

        $count = 1;
        if (is_array($retry_data)) {
            $count = ($retry_data['count'] ?? 0) + 1;
        }

        $expiration = $count * self::RETRY_COOLDOWN_HOURS * HOUR_IN_SECONDS;

        set_transient($retry_key, [
            'count'      => $count,
            'last_try'   => time(),
            'url'        => $url,
        ], $expiration);
    }

    private static function clear_retry_counter(string $url): void {
        $retry_key = 'yaa_img_retry_' . md5($url);
        delete_transient($retry_key);
    }

    public static function reset_all_retry_counters(): int {
        global $wpdb;
        
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_yaa_img_retry_%'"
        );
        
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_yaa_img_retry_%'"
        );
        
        return (int) $deleted;
    }

    public static function log_failed_image(string $url, string $product_id, string $reason): void {
        $log = get_option('yaa_failed_images_log', []);

        if (!is_array($log)) {
            $log = [];
        }

        $url_hash = md5($url);
        $one_hour_ago = time() - HOUR_IN_SECONDS;

        foreach ($log as $entry) {
            if (md5($entry['url'] ?? '') === $url_hash && ($entry['timestamp'] ?? 0) > $one_hour_ago) {
                return;
            }
        }

        $log[] = [
            'url'        => $url,
            'product_id' => $product_id,
            'reason'     => $reason,
            'timestamp'  => time(),
            'time'       => current_time('mysql'),
        ];

        $log = array_slice($log, -self::MAX_LOG_ENTRIES);

        update_option('yaa_failed_images_log', $log, false);
    }

    public static function get_failed_images_log(): array {
        $log = get_option('yaa_failed_images_log', []);
        return is_array($log) ? $log : [];
    }

    public static function clear_failed_images_log(): void {
        delete_option('yaa_failed_images_log');
    }

    public static function get_recent_failures_count(): int {
        $log = self::get_failed_images_log();
        $one_day_ago = time() - DAY_IN_SECONDS;

        $count = 0;
        foreach ($log as $entry) {
            if (($entry['timestamp'] ?? 0) > $one_day_ago) {
                $count++;
            }
        }

        return $count;
    }

    public static function get_placeholder_url(): string {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400">
            <defs>
                <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#1a1a1a"/>
                    <stop offset="100%" style="stop-color:#2d2d2d"/>
                </linearGradient>
            </defs>
            <rect width="400" height="400" fill="url(#bg)"/>
            <g transform="translate(200,180)" fill="none" stroke="#444" stroke-width="3">
                <rect x="-60" y="-40" width="120" height="80" rx="8"/>
                <circle cx="-30" cy="-15" r="12"/>
                <path d="M-50 30 L-20 0 L10 20 L40 -20 L60 10 L60 35 L-60 35 Z" fill="#333"/>
                <line x1="0" y1="45" x2="0" y2="65"/>
                <line x1="-20" y1="65" x2="20" y2="65"/>
            </g>
            <text x="200" y="280" text-anchor="middle" font-family="system-ui, sans-serif" font-size="16" fill="#555" letter-spacing="2">BILD NICHT VERFÜGBAR</text>
        </svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function get_placeholder_url_colored(string $source = ''): string {
        $color = match($source) {
            'amazon' => '#ff9900',
            'custom' => '#4CAF50',
            'yadore' => '#ff00cc',
            default  => '#666666',
        };

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400">
            <rect width="400" height="400" fill="#1a1a1a"/>
            <rect x="0" y="395" width="400" height="5" fill="' . $color . '"/>
            <g transform="translate(200,180)" fill="none" stroke="#444" stroke-width="3">
                <rect x="-60" y="-40" width="120" height="80" rx="8"/>
                <circle cx="-30" cy="-15" r="12"/>
                <path d="M-50 30 L-20 0 L10 20 L40 -20 L60 10 L60 35 L-60 35 Z" fill="#333"/>
            </g>
            <text x="200" y="280" text-anchor="middle" font-family="system-ui, sans-serif" font-size="14" fill="#555">Bild nicht verfügbar</text>
        </svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function cleanup_old_images(int $days_old = 90): int {
        $upload_dir = wp_upload_dir();
        $image_dir = $upload_dir['basedir'] . '/yadore-amazon-api';

        if (!is_dir($image_dir)) {
            return 0;
        }

        $deleted = 0;
        $threshold = time() - ($days_old * DAY_IN_SECONDS);

        $files = glob($image_dir . '/*');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $threshold) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    public static function get_statistics(): array {
        $upload_dir = wp_upload_dir();
        $image_dir = $upload_dir['basedir'] . '/yadore-amazon-api';

        $stats = [
            'count'          => 0,
            'size'           => 0,
            'size_formatted' => '0 B',
            'oldest'         => null,
            'newest'         => null,
        ];

        if (!is_dir($image_dir)) {
            return $stats;
        }

        $files = glob($image_dir . '/*');
        if ($files === false || empty($files)) {
            return $stats;
        }

        foreach ($files as $file) {
            if (is_file($file) && basename($file) !== 'index.php') {
                $stats['count']++;
                $stats['size'] += filesize($file);
                
                $mtime = filemtime($file);
                if ($stats['oldest'] === null || $mtime < $stats['oldest']) {
                    $stats['oldest'] = $mtime;
                }
                if ($stats['newest'] === null || $mtime > $stats['newest']) {
                    $stats['newest'] = $mtime;
                }
            }
        }

        $size = $stats['size'];
        if ($size < 1024) {
            $stats['size_formatted'] = $size . ' B';
        } elseif ($size < 1024 * 1024) {
            $stats['size_formatted'] = round($size / 1024, 1) . ' KB';
        } else {
            $stats['size_formatted'] = round($size / (1024 * 1024), 2) . ' MB';
        }

        return $stats;
    }

    public static function preview_seo_filename(string $product_name): string {
        return self::generate_seo_filename($product_name, 'jpg');
    }

    /**
     * Test download methods for debugging
     */
    public static function test_download_methods(string $test_url = ''): array {
        $results = [
            'curl_available'        => function_exists('curl_init'),
            'curl_version'          => function_exists('curl_version') ? curl_version()['version'] ?? 'unknown' : null,
            'allow_url_fopen'       => (bool) ini_get('allow_url_fopen'),
            'openssl_available'     => extension_loaded('openssl'),
            'image_library'         => self::get_image_library(),
        ];
        
        if ($test_url !== '') {
            $results['test_url'] = $test_url;
            
            // Test curl MINIMAL
            $start = microtime(true);
            $curl_minimal = self::download_with_curl_minimal($test_url);
            $results['curl_minimal_test'] = [
                'success'  => $curl_minimal !== false && strlen($curl_minimal) > 500,
                'size'     => $curl_minimal !== false ? strlen($curl_minimal) : 0,
                'time_ms'  => round((microtime(true) - $start) * 1000),
                'is_image' => $curl_minimal !== false ? self::is_valid_image_data($curl_minimal) : false,
            ];
            
            // Test curl SIMPLE
            $start = microtime(true);
            $curl_simple = self::download_with_curl_simple($test_url);
            $results['curl_simple_test'] = [
                'success'  => $curl_simple !== false && strlen($curl_simple) > 500,
                'size'     => $curl_simple !== false ? strlen($curl_simple) : 0,
                'time_ms'  => round((microtime(true) - $start) * 1000),
                'is_image' => $curl_simple !== false ? self::is_valid_image_data($curl_simple) : false,
            ];
            
            // Test wp_remote
            $start = microtime(true);
            $wp_result = self::download_with_wp_remote($test_url);
            $results['wp_remote_test'] = [
                'success'  => $wp_result !== false && strlen($wp_result) > 500,
                'size'     => $wp_result !== false ? strlen($wp_result) : 0,
                'time_ms'  => round((microtime(true) - $start) * 1000),
                'is_image' => $wp_result !== false ? self::is_valid_image_data($wp_result) : false,
            ];
        }
        
        return $results;
    }
}

<?php
/**
 * Image Handler Class
 * Handles downloading remote images and serving local versions.
 * Includes validation, retry mechanism, and admin logging.
 * 
 * Version: 1.2.7
 * PHP 8.3+ compatible
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class YAA_Image_Handler {

    /**
     * Max retry attempts for failed images
     */
    private const MAX_RETRIES = 3;
    
    /**
     * Retry cooldown multiplier (hours)
     */
    private const RETRY_COOLDOWN_HOURS = 1;
    
    /**
     * Max log entries to keep
     */
    private const MAX_LOG_ENTRIES = 100;

    /**
     * Process an image: Download if not exists, return local URL.
     * Includes validation and retry logic.
     * 
     * @param string|null $remote_url The remote image URL.
     * @param string $id Unique identifier (ASIN or EAN) for the filename.
     * @param string $product_id Optional product ID for logging.
     * @return string Local URL or Placeholder URL.
     */
    public static function process(?string $remote_url, string $id, string $product_id = ''): string {
        // 1. Check if remote URL is valid
        if (empty($remote_url)) {
            return self::get_placeholder_url();
        }

        // 2. Check retry status (skip if too many failures)
        if (self::should_skip_retry($remote_url)) {
            return self::get_placeholder_url();
        }

        // 3. Setup Upload Directory
        $upload_dir = wp_upload_dir();
        if (isset($upload_dir['error']) && $upload_dir['error'] !== false) {
            return $remote_url; // Fallback to remote if uploads folder is broken
        }

        $base_dir = $upload_dir['basedir'] . '/yadore-amazon-api';
        $base_url = $upload_dir['baseurl'] . '/yadore-amazon-api';

        // Create directory if not exists
        if (!file_exists($base_dir)) {
            if (!wp_mkdir_p($base_dir)) {
                return $remote_url; // Fallback if cannot create dir
            }
            
            // Add index.php for security
            file_put_contents($base_dir . '/index.php', '<?php // Silence is golden');
        }

        // 4. Define Filename
        $path_parts = pathinfo(parse_url($remote_url, PHP_URL_PATH) ?? '');
        $ext = isset($path_parts['extension']) ? strtolower($path_parts['extension']) : 'jpg';
        
        // Normalize extension
        $ext = match($ext) {
            'jpeg' => 'jpg',
            'webp', 'png', 'gif', 'jpg' => $ext,
            default => 'jpg',
        };
        
        $filename = sanitize_file_name($id) . '.' . $ext;
        $file_path = $base_dir . '/' . $filename;
        $file_url = $base_url . '/' . $filename;

        // 5. Return local URL if already exists and is valid
        if (file_exists($file_path)) {
            // Verify existing file is valid
            if (filesize($file_path) > 100 && self::is_valid_image_file($file_path)) {
                return $file_url;
            }
            // Invalid file - delete and re-download
            @unlink($file_path);
        }

        // 6. Optional: Pre-check if remote URL is accessible (HEAD request)
        if (!self::is_remote_url_accessible($remote_url)) {
            self::log_failed_image($remote_url, $product_id ?: $id, 'URL not accessible (HEAD check)');
            self::increment_retry_counter($remote_url);
            return self::get_placeholder_url();
        }

        // 7. Download Image
        $response = wp_remote_get($remote_url, [
            'timeout'    => 15,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers'    => [
                'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
            ],
        ]);

        if (is_wp_error($response)) {
            self::log_failed_image($remote_url, $product_id ?: $id, 'Download error: ' . $response->get_error_message());
            self::increment_retry_counter($remote_url);
            return self::get_placeholder_url();
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            self::log_failed_image($remote_url, $product_id ?: $id, 'HTTP ' . $status_code);
            self::increment_retry_counter($remote_url);
            return self::get_placeholder_url();
        }

        $body = wp_remote_retrieve_body($response);
        
        // 8. Validate that it's actually an image
        if (empty($body) || !self::is_valid_image_data($body)) {
            self::log_failed_image($remote_url, $product_id ?: $id, 'Invalid image data');
            self::increment_retry_counter($remote_url);
            return self::get_placeholder_url();
        }

        // 9. Check minimum size (avoid 1x1 tracking pixels)
        if (strlen($body) < 500) {
            self::log_failed_image($remote_url, $product_id ?: $id, 'Image too small (likely tracking pixel)');
            self::increment_retry_counter($remote_url);
            return self::get_placeholder_url();
        }

        // 10. Save File
        if (file_put_contents($file_path, $body) === false) {
            self::log_failed_image($remote_url, $product_id ?: $id, 'Could not save file');
            return self::get_placeholder_url();
        }

        // 11. Clear retry counter on success
        self::clear_retry_counter($remote_url);

        return $file_url;
    }

    /**
     * Process with automatic retry for temporary failures
     * 
     * @param string|null $remote_url The remote image URL.
     * @param string $id Unique identifier for the filename.
     * @param string $product_id Optional product ID for logging.
     * @return string Local URL or Placeholder URL.
     */
    public static function process_with_retry(?string $remote_url, string $id, string $product_id = ''): string {
        return self::process($remote_url, $id, $product_id);
    }

    /**
     * Check if a remote URL is accessible via HEAD request
     * Faster than downloading the whole image
     */
    public static function is_remote_url_accessible(string $url): bool {
        if (empty($url)) {
            return false;
        }

        $response = wp_remote_head($url, [
            'timeout'    => 5,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (compatible; ImageCheck/1.0)',
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        return $status >= 200 && $status < 400;
    }

    /**
     * Validate image data using magic bytes
     * 
     * @param string $data Binary image data
     * @return bool True if valid image
     */
    public static function is_valid_image_data(string $data): bool {
        if (strlen($data) < 8) {
            return false;
        }

        // Magic byte signatures for image formats
        $signatures = [
            // JPEG: FFD8FF
            "\xFF\xD8\xFF" => 'jpeg',
            // PNG: 89504E47
            "\x89PNG" => 'png',
            // GIF87a / GIF89a
            "GIF87a" => 'gif',
            "GIF89a" => 'gif',
            // WebP: RIFF....WEBP
            "RIFF" => 'webp_check',
        ];

        foreach ($signatures as $sig => $type) {
            if (str_starts_with($data, $sig)) {
                // Additional WebP check
                if ($type === 'webp_check') {
                    // Check for WEBP marker at position 8
                    if (strlen($data) >= 12 && substr($data, 8, 4) === 'WEBP') {
                        return true;
                    }
                    continue;
                }
                return true;
            }
        }

        // BMP: 424D
        if (str_starts_with($data, "BM")) {
            return true;
        }

        return false;
    }

    /**
     * Validate an existing image file
     * 
     * @param string $file_path Path to file
     * @return bool True if valid
     */
    public static function is_valid_image_file(string $file_path): bool {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        // Read first 12 bytes for magic byte check
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

    /**
     * Check if we should skip retrying this URL
     */
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

    /**
     * Increment retry counter for a URL
     */
    private static function increment_retry_counter(string $url): void {
        $retry_key = 'yaa_img_retry_' . md5($url);
        $retry_data = get_transient($retry_key);

        $count = 1;
        if (is_array($retry_data)) {
            $count = ($retry_data['count'] ?? 0) + 1;
        }

        // Exponential backoff: 1h, 2h, 3h...
        $expiration = $count * self::RETRY_COOLDOWN_HOURS * HOUR_IN_SECONDS;

        set_transient($retry_key, [
            'count'      => $count,
            'last_try'   => time(),
            'url'        => $url,
        ], $expiration);
    }

    /**
     * Clear retry counter on success
     */
    private static function clear_retry_counter(string $url): void {
        $retry_key = 'yaa_img_retry_' . md5($url);
        delete_transient($retry_key);
    }

    /**
     * Log a failed image download for admin review
     */
    public static function log_failed_image(string $url, string $product_id, string $reason): void {
        $log = get_option('yaa_failed_images_log', []);

        if (!is_array($log)) {
            $log = [];
        }

        // Check for duplicate (same URL in last hour)
        $url_hash = md5($url);
        $one_hour_ago = time() - HOUR_IN_SECONDS;

        foreach ($log as $entry) {
            if (md5($entry['url'] ?? '') === $url_hash && ($entry['timestamp'] ?? 0) > $one_hour_ago) {
                return; // Already logged recently
            }
        }

        $log[] = [
            'url'        => $url,
            'product_id' => $product_id,
            'reason'     => $reason,
            'timestamp'  => time(),
            'time'       => current_time('mysql'),
        ];

        // Keep only last MAX_LOG_ENTRIES
        $log = array_slice($log, -self::MAX_LOG_ENTRIES);

        update_option('yaa_failed_images_log', $log, false);
    }

    /**
     * Get failed images log
     * 
     * @return array<int, array<string, mixed>>
     */
    public static function get_failed_images_log(): array {
        $log = get_option('yaa_failed_images_log', []);
        return is_array($log) ? $log : [];
    }

    /**
     * Clear failed images log
     */
    public static function clear_failed_images_log(): void {
        delete_option('yaa_failed_images_log');
    }

    /**
     * Get count of failed images in last 24 hours
     */
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

    /**
     * Returns a Data URI for a placeholder image (SVG).
     * No external request needed.
     */
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

    /**
     * Get a colored placeholder based on product source
     * 
     * @param string $source 'amazon', 'yadore', 'custom'
     * @return string Data URI
     */
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

    /**
     * Clean up old/orphaned images
     * 
     * @param int $days_old Delete images older than X days
     * @return int Number of deleted files
     */
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
}

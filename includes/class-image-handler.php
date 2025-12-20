<?php
/**
 * Image Handler Class
 * Handles downloading remote images and serving local versions.
 * Includes validation, retry mechanism, SEO filenames, and admin logging.
 * 
 * Version: 1.2.9
 * PHP 8.3+ compatible
 * 
 * NEU: SEO-optimierte Dateinamen (erste 30 Zeichen Produktname + Timestamp)
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
     * Max characters for SEO filename (product name part)
     */
    private const SEO_NAME_MAX_LENGTH = 30;

    /**
     * Process an image: Download if not exists, return local URL.
     * Includes validation, retry logic, and SEO filename generation.
     * 
     * @param string|null $remote_url The remote image URL.
     * @param string $id Unique identifier (ASIN or EAN) for the filename.
     * @param string $product_name Product name for SEO filename (NEU).
     * @param string $source Source identifier (amazon, yadore, custom).
     * @return string Local URL or Placeholder URL.
     */
    public static function process(
        ?string $remote_url, 
        string $id, 
        string $product_name = '',
        string $source = ''
    ): string {
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

        // 4. Determine file extension
        $path_parts = pathinfo(parse_url($remote_url, PHP_URL_PATH) ?? '');
        $ext = isset($path_parts['extension']) ? strtolower($path_parts['extension']) : 'jpg';
        
        // Normalize extension
        $ext = match($ext) {
            'jpeg' => 'jpg',
            'webp', 'png', 'gif', 'jpg' => $ext,
            default => 'jpg',
        };
        
        // 5. Generate filename based on settings (SEO or ID)
        $filename = self::generate_filename($id, $product_name, $source, $ext);
        $file_path = $base_dir . '/' . $filename

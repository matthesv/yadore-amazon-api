<?php
/**
 * Image Handler Class
 * Handles downloading remote images and serving local versions.
 * Includes fallback to placeholder if download fails.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class YAA_Image_Handler {

    /**
     * Process an image: Download if not exists, return local URL.
     * * @param string|null $remote_url The remote image URL.
     * @param string $id Unique identifier (ASIN or EAN) for the filename.
     * @return string Local URL or Placeholder URL.
     */
    public static function process(?string $remote_url, string $id): string {
        // 1. Check if remote URL is valid
        if (empty($remote_url)) {
            return self::get_placeholder_url();
        }

        // 2. Setup Upload Directory
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
        }

        // 3. Define Filename
        // Remove query parameters from extension (e.g. image.jpg?size=...)
        $path_parts = pathinfo(parse_url($remote_url, PHP_URL_PATH));
        $ext = isset($path_parts['extension']) ? $path_parts['extension'] : 'jpg';
        if (empty($ext) || strlen($ext) > 4) $ext = 'jpg';
        
        $filename = sanitize_file_name($id) . '.' . $ext;
        $file_path = $base_dir . '/' . $filename;
        $file_url = $base_url . '/' . $filename;

        // 4. Return local URL if already exists
        if (file_exists($file_path)) {
            return $file_url;
        }

        // 5. Download Image
        $response = wp_remote_get($remote_url, [
            'timeout' => 15,
            'sslverify' => false, // Sometimes needed for specific CDNs
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36'
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Download failed
            return self::get_placeholder_url();
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return self::get_placeholder_url();
        }

        // 6. Save File
        if (file_put_contents($file_path, $body) === false) {
            return self::get_placeholder_url();
        }

        return $file_url;
    }

    /**
     * Returns a Data URI for a placeholder image (SVG).
     * No external request needed.
     */
    public static function get_placeholder_url(): string {
        // Simple gray placeholder with a "No Image" icon style
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300" viewBox="0 0 300 300" fill="#f0f0f0">
            <rect width="300" height="300" fill="#e0e0e0"/>
            <path d="M110 130 L150 90 L190 130" stroke="#999" stroke-width="10" fill="none"/>
            <rect x="130" y="110" width="40" height="80" fill="#999"/>
            <text x="50%" y="80%" dominant-baseline="middle" text-anchor="middle" font-family="Arial, sans-serif" font-size="20" fill="#777">Kein Bild</text>
        </svg>';
        
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
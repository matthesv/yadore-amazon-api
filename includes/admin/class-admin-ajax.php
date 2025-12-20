<?php
/**
 * Admin AJAX Handler
 * PHP 8.3+ compatible
 * Version: 1.4.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class YAA_Admin_Ajax {
    
    private YAA_Cache_Handler $cache;
    private YAA_Yadore_API $yadore_api;
    private YAA_Amazon_PAAPI $amazon_api;
    
    public function __construct(
        YAA_Cache_Handler $cache,
        YAA_Yadore_API $yadore_api,
        YAA_Amazon_PAAPI $amazon_api
    ) {
        $this->cache = $cache;
        $this->yadore_api = $yadore_api;
        $this->amazon_api = $amazon_api;
        
        // AJAX Actions registrieren
        add_action('wp_ajax_yaa_test_yadore', [$this, 'test_yadore']);
        add_action('wp_ajax_yaa_test_amazon', [$this, 'test_amazon']);
        add_action('wp_ajax_yaa_test_redis', [$this, 'test_redis']);
        add_action('wp_ajax_yaa_refresh_merchants', [$this, 'refresh_merchants']);
        add_action('wp_ajax_yaa_fetch_deeplink_merchants', [$this, 'fetch_deeplink_merchants']);
    }
    
    /**
     * Test Yadore API connection
     */
    public function test_yadore(): void {
        check_ajax_referer('yaa_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung', 'yadore-amazon-api')]);
        }
        
        $api_key = sanitize_text_field($_POST['yadore_api_key'] ?? '');
        $result = $this->yadore_api->test_connection($api_key !== '' ? $api_key : null);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Test Amazon API connection
     */
    public function test_amazon(): void {
        check_ajax_referer('yaa_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung', 'yadore-amazon-api')]);
        }
        
        $access_key = sanitize_text_field($_POST['amazon_access_key'] ?? '');
        $secret_key = $_POST['amazon_secret_key'] ?? '';
        $partner_tag = sanitize_text_field($_POST['amazon_partner_tag'] ?? '');
        $marketplace = sanitize_text_field($_POST['amazon_marketplace'] ?? 'de');
        
        $result = $this->amazon_api->test_connection(
            $access_key !== '' ? $access_key : null,
            $secret_key !== '' ? $secret_key : null,
            $partner_tag !== '' ? $partner_tag : null,
            $marketplace
        );
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Test Redis connection
     */
    public function test_redis(): void {
        check_ajax_referer('yaa_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung', 'yadore-amazon-api')]);
        }
        
        $host = sanitize_text_field($_POST['redis_host'] ?? '127.0.0.1');
        $port = (int) ($_POST['redis_port'] ?? 6379);
        $password = $_POST['redis_password'] ?? '';
        $database = (int) ($_POST['redis_database'] ?? 0);
        
        $result = $this->cache->test_connection($host, $port, $password, $database);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Refresh merchant list (Offer Merchants)
     */
    public function refresh_merchants(): void {
        check_ajax_referer('yaa_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung', 'yadore-amazon-api')]);
        }
        
        $market = sanitize_text_field($_POST['market'] ?? 'de');
        
        $result = $this->yadore_api->fetch_merchants($market, true);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(
                    __('%d Händler geladen!', 'yadore-amazon-api'),
                    count($result['merchants'])
                ),
                'count' => count($result['merchants']),
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['error'] ?? __('Unbekannter Fehler', 'yadore-amazon-api'),
            ]);
        }
    }
    
    /**
     * NEU: Fetch Deeplink Merchants (mit CPC)
     */
    public function fetch_deeplink_merchants(): void {
        check_ajax_referer('yaa_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung', 'yadore-amazon-api')]);
        }
        
        $market = sanitize_text_field($_POST['market'] ?? 'de');
        $smartlink_only = isset($_POST['smartlink_only']) && $_POST['smartlink_only'] === '1';
        
        $result = $this->yadore_api->fetch_deeplink_merchants($market, $smartlink_only, true);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(
                    __('%d Deeplink-Händler geladen!', 'yadore-amazon-api'),
                    count($result['merchants'])
                ),
                'merchants' => $result['merchants'],
                'count' => count($result['merchants']),
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['error'] ?? __('Unbekannter Fehler', 'yadore-amazon-api'),
            ]);
        }
    }
}

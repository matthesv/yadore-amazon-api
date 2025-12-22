<?php
/**
 * Plugin Name: Yadore-Amazon-API
 * Plugin URI: https://github.com/matthesv/yadore-amazon-api
 * Description: Universelles Affiliate-Plugin für Yadore und Amazon PA-API 5.0 mit Redis-Caching, eigenen Produkten und vollständiger Backend-Konfiguration.
 * Version: 1.5.2
 * Author: Matthes Vogel
 * Author URI: https://example.com
 * Text Domain: yadore-amazon-api
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * License: GPL v2 or later
 * GitHub Plugin URI: https://github.com/matthesv/yadore-amazon-api
 * Primary Branch: main
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('YAA_VERSION', '1.5.2');
define('YAA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('YAA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('YAA_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('YAA_PLUGIN_FILE', __FILE__);

/**
 * Load plugin textdomain
 */
function yaa_load_textdomain(): void {
    load_plugin_textdomain('yadore-amazon-api', false, dirname(YAA_PLUGIN_BASENAME) . '/languages');
}
add_action('plugins_loaded', 'yaa_load_textdomain');

/**
 * Get plugin option with default
 */
function yaa_get_option(string $key, mixed $default = ''): mixed {
    $options = get_option('yaa_settings', []);
    return $options[$key] ?? $default;
}

/**
 * Get cache time settings
 */
function yaa_get_cache_time(): int {
    $hours = (int) yaa_get_option('cache_duration', 6);
    return max(1, $hours) * 3600;
}

function yaa_get_fallback_time(): int {
    $hours = (int) yaa_get_option('fallback_duration', 24);
    return max(1, $hours) * 3600;
}

// =========================================
// AUTOLOADER
// =========================================

// Autoloader zuerst laden (manuell, da er sich nicht selbst laden kann)
require_once YAA_PLUGIN_PATH . 'includes/class-autoloader.php';

// Autoloader registrieren
YAA_Autoloader::register(YAA_PLUGIN_PATH);

// Admin-Verzeichnis zum Autoloader hinzufügen
YAA_Autoloader::add_directory('includes/admin');

// =========================================
// LEGACY REQUIRES (für Klassen die noch nicht migriert wurden)
// Diese können nach und nach entfernt werden, sobald der Autoloader greift
// =========================================

// Core-Klassen die immer benötigt werden
// Der Autoloader lädt diese automatisch, aber wir können sie auch explizit laden
// für bessere Kontrolle über die Ladereihenfolge

// Hinweis: Die folgenden require_once Statements sind optional
// wenn der Autoloader korrekt funktioniert. Sie dienen als Fallback.

/*
 * Liste der Kern-Klassen (werden durch Autoloader geladen):
 * - YAA_Cache_Handler
 * - YAA_Image_Handler
 * - YAA_Fuzzy_Matcher
 * - YAA_Yadore_API
 * - YAA_Amazon_PAAPI
 * - YAA_Custom_Products
 * - YAA_Shortcode_Renderer
 * - YAA_Merchant_Filter
 * - YAA_Admin (Koordinator)
 * - YAA_Admin_Settings
 * - YAA_Admin_Ajax
 * - YAA_Admin_Status
 * - YAA_Admin_Docs
 * - YAA_Admin_Merchants (NEU: Händlerübersicht mit CPC)
 */

// =========================================
// PLUGIN UPDATE CHECKER (GitHub)
// =========================================
if (file_exists(YAA_PLUGIN_PATH . 'includes/plugin-update-checker/plugin-update-checker.php')) {
    require_once YAA_PLUGIN_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';
    
    $yaaUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/matthesv/yadore-amazon-api/',
        YAA_PLUGIN_FILE,
        'yadore-amazon-api'
    );
    
    $yaaUpdateChecker->setBranch('main');
    
    $github_token = defined('YAA_GITHUB_TOKEN') ? YAA_GITHUB_TOKEN : yaa_get_option('github_token', '');
    if (!empty($github_token)) {
        $yaaUpdateChecker->setAuthentication($github_token);
    }
}

/**
 * Main Plugin Class - PHP 8.3+ compatible
 * Version 1.4.0 - Mit Autoloader Support
 */
final class Yadore_Amazon_API_Plugin {
    
    private static ?self $instance = null;
    
    public readonly YAA_Cache_Handler $cache;
    public readonly YAA_Yadore_API $yadore_api;
    public readonly YAA_Amazon_PAAPI $amazon_api;
    public readonly YAA_Custom_Products $custom_products;
    public readonly YAA_Shortcode_Renderer $shortcode;
    public readonly YAA_Admin $admin;
    
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_components();
        $this->register_hooks();
    }
    
    private function init_components(): void {
        // Core Components (werden durch Autoloader geladen)
        $this->cache           = new YAA_Cache_Handler();
        $this->yadore_api      = new YAA_Yadore_API($this->cache);
        $this->amazon_api      = new YAA_Amazon_PAAPI($this->cache);
        $this->custom_products = new YAA_Custom_Products();
        $this->shortcode       = new YAA_Shortcode_Renderer(
            $this->yadore_api, 
            $this->amazon_api, 
            $this->cache,
            $this->custom_products
        );
        
        // Admin Component (koordiniert alle Admin-Submodule)
        $this->admin = new YAA_Admin($this->cache, $this->yadore_api, $this->amazon_api);
    }
    
    private function register_hooks(): void {
        register_activation_hook(YAA_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(YAA_PLUGIN_FILE, [$this, 'deactivate']);
        
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('yaa_cache_refresh_event', [$this, 'preload_cache']);
    }
    
    public function activate(): void {
        // Default settings
        $defaults = [
            // Yadore Settings
            'enable_yadore'          => 'yes',
            'yadore_api_key'         => '',
            'yadore_market'          => 'de',
            'yadore_precision'       => 'fuzzy',
            'yadore_default_limit'   => 9,
            
            // Amazon Settings
            'enable_amazon'          => 'yes',
            'amazon_access_key'      => '',
            'amazon_secret_key'      => '',
            'amazon_partner_tag'     => '',
            'amazon_marketplace'     => 'de',
            'amazon_default_category'=> 'All',
            'amazon_language'        => '',
            'amazon_image_size'      => 'Large',
            
            // Cache Settings
            'cache_duration'         => 6,
            'fallback_duration'      => 24,
            'enable_redis'           => 'auto',
            'redis_host'             => '127.0.0.1',
            'redis_port'             => 6379,
            'redis_password'         => '',
            'redis_database'         => 0,
            
            // Local Image Storage
            'enable_local_images'    => 'yes',
            'image_filename_format'  => 'seo',
            
            // Fuzzy Search settings
            'enable_fuzzy_search'    => 'yes',
            'fuzzy_auto_mix'         => 'no',
            'fuzzy_threshold'        => 30,
            'fuzzy_weight_title'     => 0.40,
            'fuzzy_weight_description' => 0.25,
            'fuzzy_weight_category'  => 0.20,
            'fuzzy_weight_merchant'  => 0.10,
            'fuzzy_weight_keywords'  => 0.05,
            
            // Display Settings
            'disable_default_css'    => 'no',
            'grid_columns_desktop'   => 3,
            'grid_columns_tablet'    => 2,
            'grid_columns_mobile'    => 1,
            'button_text_yadore'     => 'Zum Angebot',
            'button_text_amazon'     => 'Bei Amazon kaufen',
            'button_text_custom'     => 'Zum Angebot',
            'show_prime_badge'       => 'yes',
            'show_merchant'          => 'yes',
            'show_description'       => 'yes',
            'show_custom_badge'      => 'yes',
            'custom_badge_text'      => 'Empfohlen',
            'color_primary'          => '#ff00cc',
            'color_secondary'        => '#00ffff',
            'color_amazon'           => '#ff9900',
            'color_custom'           => '#4CAF50',
            
            // Update Settings
            'github_token'           => '',
        ];
        
        $existing = get_option('yaa_settings', []);
        $merged = array_merge($defaults, $existing);
        update_option('yaa_settings', $merged);
        
        // Admin-Verzeichnis erstellen
        $admin_dir = YAA_PLUGIN_PATH . 'includes/admin';
        if (!is_dir($admin_dir)) {
            wp_mkdir_p($admin_dir);
        }
        
        // Schedule cron
        if (!wp_next_scheduled('yaa_cache_refresh_event')) {
            wp_schedule_event(time(), 'twicedaily', 'yaa_cache_refresh_event');
        }
        
        flush_rewrite_rules();
    }
    
    public function deactivate(): void {
        wp_clear_scheduled_hook('yaa_cache_refresh_event');
        flush_rewrite_rules();
    }
    
    public function enqueue_frontend_assets(): void {
        global $post;
        
        if (!($post instanceof WP_Post)) {
            return;
        }
        
        $shortcodes = [
            'yaa_products', 
            'yadore_products', 
            'amazon_products', 
            'combined_products',
            'custom_products',
            'all_products',
            'fuzzy_products',
        ];
        
        $has_shortcode = false;
        
        foreach ($shortcodes as $shortcode) {
            if (has_shortcode($post->post_content, $shortcode)) {
                $has_shortcode = true;
                break;
            }
        }
        
        if ($has_shortcode) {
            $this->load_assets();
        }
    }
    
    public function load_assets(): void {
        $disable_css = yaa_get_option('disable_default_css', 'no') === 'yes';

        if (!$disable_css) {
            wp_enqueue_style(
                'yaa-frontend-grid',
                YAA_PLUGIN_URL . 'assets/css/frontend-grid.css',
                [],
                YAA_VERSION
            );
            
            $primary = esc_attr((string) yaa_get_option('color_primary', '#ff00cc'));
            $secondary = esc_attr((string) yaa_get_option('color_secondary', '#00ffff'));
            $amazon = esc_attr((string) yaa_get_option('color_amazon', '#ff9900'));
            $custom = esc_attr((string) yaa_get_option('color_custom', '#4CAF50'));
            $columns_desktop = (int) yaa_get_option('grid_columns_desktop', 3);
            $columns_tablet = (int) yaa_get_option('grid_columns_tablet', 2);
            $columns_mobile = (int) yaa_get_option('grid_columns_mobile', 1);
            
            $custom_css = "
                .yaa-grid-container {
                    --yaa-primary: {$primary};
                    --yaa-secondary: {$secondary};
                    --yaa-amazon: {$amazon};
                    --yaa-custom: {$custom};
                    --yaa-columns-desktop: {$columns_desktop};
                    --yaa-columns-tablet: {$columns_tablet};
                    --yaa-columns-mobile: {$columns_mobile};
                }
            ";
            wp_add_inline_style('yaa-frontend-grid', $custom_css);
        } else {
            wp_register_style('yaa-minimal-functional', false);
            wp_enqueue_style('yaa-minimal-functional');
            
            $minimal_css = "
                .yaa-description {
                    overflow: hidden;
                    position: relative;
                    max-height: 4.8em; 
                    transition: max-height 0.4s ease-out;
                }
                .yaa-description.expanded {
                    max-height: none !important;
                    -webkit-line-clamp: unset !important;
                    line-clamp: unset !important;
                    display: block !important;
                    overflow: visible !important;
                }
                .yaa-read-more { cursor: pointer; display: inline-block; }
                .yaa-grid-container {
                    display: grid;
                    gap: 20px;
                    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                }
            ";
            wp_add_inline_style('yaa-minimal-functional', $minimal_css);
        }
        
        wp_enqueue_script(
            'yaa-frontend-grid',
            YAA_PLUGIN_URL . 'assets/js/frontend-grid.js',
            [],
            YAA_VERSION,
            true
        );
    }
    
    public function preload_cache(): void {
        $cached_keywords = get_option('yaa_cached_keywords', []);
        
        if (empty($cached_keywords)) {
            return;
        }
        
        foreach ($cached_keywords as $entry) {
            $source = $entry['source'] ?? 'yadore';
            
            if ($source === 'amazon' && $this->amazon_api->is_configured()) {
                $this->amazon_api->search_items(
                    $entry['keyword'] ?? '',
                    $entry['category'] ?? 'All',
                    $entry['limit'] ?? 10
                );
            } elseif ($this->yadore_api->is_configured()) {
                $this->yadore_api->fetch([
                    'keyword' => $entry['keyword'] ?? '',
                    'limit'   => $entry['limit'] ?? 9,
                    'market'  => $entry['market'] ?? yaa_get_option('yadore_market', 'de'),
                ]);
            }
            
            sleep(1);
        }
    }
}

/**
 * Initialize Plugin
 */
function yaa_init(): Yadore_Amazon_API_Plugin {
    return Yadore_Amazon_API_Plugin::get_instance();
}
add_action('plugins_loaded', 'yaa_init', 10);

/**
 * Plugin action links
 */
function yaa_plugin_action_links(array $links): array {
    $settings_link = '<a href="' . admin_url('admin.php?page=yaa-settings') . '">' . 
                     __('Einstellungen', 'yadore-amazon-api') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . YAA_PLUGIN_BASENAME, 'yaa_plugin_action_links');
